<?php

declare(strict_types=1);

const FULL_AUTO_ACTIVE_BATCH_STATUSES = ['queued', 'research', 'draft', 'auto_repair', 'quality_check', 'images', 'rate_limited', 'auto_retry_scheduled'];

function full_auto_config(): array
{
    $risks = app_environment_list('FULL_AUTO_ALLOWED_RISKS', ['low']);
    return [
        'enabled' => app_environment_bool('FULL_AUTO_ENABLED', false),
        'max_topics_per_run' => app_environment_int('FULL_AUTO_MAX_TOPICS_PER_RUN', 3, 1, 25),
        'max_topics_per_day' => app_environment_int('FULL_AUTO_MAX_TOPICS_PER_DAY', 10, 1, 100),
        'minimum_score' => app_environment_int('FULL_AUTO_MINIMUM_SCORE', 70, 0, 100),
        'minimum_independent_sources' => app_environment_int('FULL_AUTO_MINIMUM_INDEPENDENT_SOURCES', 2, 1, 20),
        'require_primary_source' => app_environment_bool('FULL_AUTO_REQUIRE_PRIMARY_SOURCE', true),
        'maximum_age_hours' => app_environment_int('FULL_AUTO_MAXIMUM_AGE_HOURS', 72, 1, 720),
        'allowed_categories' => app_environment_list('FULL_AUTO_ALLOWED_CATEGORIES', (array) app_config('preferred_topic_categories')),
        'allowed_risks' => $risks === [] ? ['low'] : $risks,
    ];
}

function full_auto_ensure_schema(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS full_auto_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_key TEXT NOT NULL UNIQUE,
            mode TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "running",
            candidate_count INTEGER NOT NULL DEFAULT 0,
            selected_count INTEGER NOT NULL DEFAULT 0,
            error_count INTEGER NOT NULL DEFAULT 0,
            audit_json TEXT NOT NULL DEFAULT "{}",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        );
        CREATE TABLE IF NOT EXISTS full_auto_reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            topic_id INTEGER NOT NULL UNIQUE,
            idempotency_key TEXT NOT NULL UNIQUE,
            batch_id INTEGER,
            status TEXT NOT NULL DEFAULT "reserved",
            error_message TEXT NOT NULL DEFAULT "",
            reserved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT,
            FOREIGN KEY (run_id) REFERENCES full_auto_runs(id) ON DELETE CASCADE,
            FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES generation_batches(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS full_auto_reservations_day_idx
            ON full_auto_reservations(reserved_at, status);'
    );
}

function full_auto_candidate_rows(PDO $database): array
{
    $hasReservations = (int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='full_auto_reservations'")->fetchColumn() === 1;
    $active = implode(',', array_fill(0, count(FULL_AUTO_ACTIVE_BATCH_STATUSES), '?'));
    $statement = $database->prepare(
        'SELECT topics.id, topics.title, topics.score, topics.risk_level, topics.automatic_eligible,
                topics.event_at, topics.trashed_at, topics.purged_at, posts.status AS post_status,
                posts.deleted_at, COALESCE(MAX(items.category), "") AS category,
                COUNT(DISTINCT items.technical_source_id) AS independent_source_count,
                COALESCE(MAX(sources.is_primary), 0) AS has_primary_source,
                EXISTS(SELECT 1 FROM generation_batch_items batch_items
                       WHERE batch_items.topic_id = topics.id AND batch_items.status IN (' . $active . ')) AS has_active_batch,
                ' . ($hasReservations
                    ? 'EXISTS(SELECT 1 FROM full_auto_reservations reservations WHERE reservations.topic_id = topics.id)'
                    : '0') . ' AS already_reserved
         FROM editorial_topics topics
         INNER JOIN posts ON posts.id = topics.primary_post_id
         LEFT JOIN feed_topic_memberships memberships ON memberships.topic_id = topics.id
         LEFT JOIN discovered_feed_items items ON items.id = memberships.feed_item_id
         LEFT JOIN technical_sources sources ON sources.id = items.technical_source_id
         GROUP BY topics.id
         ORDER BY topics.score DESC, topics.event_at DESC, topics.id ASC'
    );
    $statement->execute(FULL_AUTO_ACTIVE_BATCH_STATUSES);
    return $statement->fetchAll();
}

function full_auto_evaluate_candidate(array $row, array $config, DateTimeImmutable $now): array
{
    $reasons = [];
    $eventAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['event_at'], new DateTimeZone('UTC'));
    if ((int) $row['automatic_eligible'] !== 1) $reasons[] = 'not_automatic_eligible';
    if ($row['trashed_at'] !== null || $row['purged_at'] !== null || in_array((string) $row['post_status'], ['rejected', 'trash', 'published', 'scheduled'], true) || $row['deleted_at'] !== null) $reasons[] = 'already_processed_or_rejected';
    if ((int) $row['already_reserved'] === 1) $reasons[] = 'duplicate_or_already_processed';
    if ((int) $row['has_active_batch'] === 1) $reasons[] = 'active_batch';
    if ($row['score'] === null || (int) $row['score'] < (int) $config['minimum_score']) $reasons[] = 'score_below_minimum';
    if ((int) $row['independent_source_count'] < (int) $config['minimum_independent_sources']) $reasons[] = 'insufficient_independent_sources';
    if ($config['require_primary_source'] && (int) $row['has_primary_source'] !== 1) $reasons[] = 'primary_source_required';
    if (!in_array(strtolower((string) $row['risk_level']), $config['allowed_risks'], true)) $reasons[] = 'risk_not_allowed';
    if (!in_array(strtolower((string) $row['category']), $config['allowed_categories'], true)) $reasons[] = 'category_not_allowed';
    if (!$eventAt || $eventAt < $now->modify('-' . (int) $config['maximum_age_hours'] . ' hours')) $reasons[] = 'topic_too_old';
    return ['topic_id' => (int) $row['id'], 'title' => (string) $row['title'], 'score' => $row['score'] === null ? null : (int) $row['score'], 'event_at' => (string) $row['event_at'], 'category' => (string) $row['category'], 'risk' => (string) $row['risk_level'], 'independent_sources' => (int) $row['independent_source_count'], 'has_primary_source' => (int) $row['has_primary_source'] === 1, 'selected' => $reasons === [], 'reasons' => $reasons === [] ? ['selected'] : $reasons];
}

function full_auto_plan(PDO $database, array $config, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $evaluated = array_map(static fn (array $row): array => full_auto_evaluate_candidate($row, $config, $now), full_auto_candidate_rows($database));
    $eligible = array_values(array_filter($evaluated, static fn (array $item): bool => $item['selected']));
    $hasReservations = (int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='full_auto_reservations'")->fetchColumn() === 1;
    $usedToday = $hasReservations
        ? (int) $database->query("SELECT COUNT(*) FROM full_auto_reservations WHERE reserved_at >= datetime('now', 'start of day') AND status != 'failed'")->fetchColumn()
        : 0;
    $limit = min((int) $config['max_topics_per_run'], max(0, (int) $config['max_topics_per_day'] - $usedToday));
    foreach ($eligible as $index => $item) {
        if ($index >= $limit) {
            foreach ($evaluated as &$candidate) if ($candidate['topic_id'] === $item['topic_id']) { $candidate['selected'] = false; $candidate['reasons'] = [$usedToday + $index >= (int) $config['max_topics_per_day'] ? 'daily_cap' : 'run_limit']; }
            unset($candidate);
        }
    }
    return ['candidates' => $evaluated, 'selected' => array_values(array_filter($evaluated, static fn (array $item): bool => $item['selected'])), 'daily_used' => $usedToday, 'daily_remaining' => max(0, (int) $config['max_topics_per_day'] - $usedToday)];
}

function full_auto_reserve(PDO $database, array $plan, array $config, string $runKey): array
{
    $database->exec('BEGIN IMMEDIATE');
    try {
        $database->prepare('INSERT INTO full_auto_runs (run_key, mode, candidate_count) VALUES (:key, "run", :count)')->execute([':key' => $runKey, ':count' => count($plan['candidates'])]);
        $runId = (int) $database->lastInsertId();
        $reserved = [];
        $usedToday = (int) $database->query("SELECT COUNT(*) FROM full_auto_reservations WHERE reserved_at >= datetime('now', 'start of day') AND status != 'failed'")->fetchColumn();
        $remaining = min((int) $config['max_topics_per_run'], max(0, (int) $config['max_topics_per_day'] - $usedToday));
        $insert = $database->prepare('INSERT OR IGNORE INTO full_auto_reservations (run_id, topic_id, idempotency_key) VALUES (:run, :topic, :key)');
        foreach ($plan['selected'] as $item) {
            if (count($reserved) >= $remaining) break;
            $key = 'full-auto:topic:' . (int) $item['topic_id'];
            $insert->execute([':run' => $runId, ':topic' => (int) $item['topic_id'], ':key' => $key]);
            if ($insert->rowCount() === 1) $reserved[] = ['topic_id' => (int) $item['topic_id'], 'idempotency_key' => $key];
        }
        $database->exec('COMMIT');
        return ['run_id' => $runId, 'reserved' => $reserved];
    } catch (Throwable $exception) {
        try { $database->exec('ROLLBACK'); } catch (Throwable) { }
        throw $exception;
    }
}

function full_auto_execute(bool $dryRun = false, ?DateTimeImmutable $now = null): array
{
    $database = bueno_database();
    $config = full_auto_config();
    if (!$dryRun && !$config['enabled']) throw new RuntimeException('FULL_AUTO_ENABLED=false; rzeczywisty run jest wyłączony.');
    if (!$dryRun && generation_automatic_dispatch_paused()) throw new RuntimeException('Automatic dispatcher is paused by operator.');
    if (!$dryRun) generation_batch_assert_api_available();
    if (!$dryRun) full_auto_ensure_schema($database);
    $plan = full_auto_plan($database, $config, $now);
    if ($dryRun) return ['mode' => 'dry-run', 'enabled' => $config['enabled'], 'mutated' => false, ...$plan, 'batches' => [], 'errors' => []];
    $runKey = 'full-auto-run-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    $reservation = full_auto_reserve($database, $plan, $config, $runKey);
    $batches = []; $errors = [];
    foreach ($reservation['reserved'] as $item) {
        try {
            $batch = create_topic_workflow_batch([$item['topic_id']], 'generate_all', $item['idempotency_key'], 'full-auto');
            $batchId = (int) ($batch['id'] ?? $batch['batch']['id']);
            $database->prepare('UPDATE full_auto_reservations SET batch_id=:batch, status="batch_created", completed_at=CURRENT_TIMESTAMP WHERE run_id=:run AND topic_id=:topic')->execute([':batch' => $batchId, ':run' => $reservation['run_id'], ':topic' => $item['topic_id']]);
            $batches[] = ['topic_id' => $item['topic_id'], 'batch_id' => $batchId];
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);
            $database->prepare('UPDATE full_auto_reservations SET status="failed", error_message=:error, completed_at=CURRENT_TIMESTAMP WHERE run_id=:run AND topic_id=:topic')->execute([':error' => $message, ':run' => $reservation['run_id'], ':topic' => $item['topic_id']]);
            $errors[] = ['topic_id' => $item['topic_id'], 'error' => $message];
        }
    }
    if ($batches !== []) generation_batch_launch_worker();
    $audit = ['candidates' => $plan['candidates'], 'batches' => $batches, 'errors' => $errors];
    $database->prepare('UPDATE full_auto_runs SET status=:status, selected_count=:selected, error_count=:errors, audit_json=:audit, completed_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':status' => $errors === [] ? 'completed' : 'completed_with_errors', ':selected' => count($batches), ':errors' => count($errors), ':audit' => generation_json($audit), ':id' => $reservation['run_id']]);
    return ['mode' => 'run', 'enabled' => true, 'mutated' => true, 'run_id' => $reservation['run_id'], ...$plan, 'batches' => $batches, 'errors' => $errors];
}
