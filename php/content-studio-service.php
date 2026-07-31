<?php

declare(strict_types=1);

defined('CONTENT_STUDIO_BATCH_LIMIT') || define(
    'CONTENT_STUDIO_BATCH_LIMIT',
    app_environment_int('CMS_TOPICS_BATCH_LIMIT', 10, 1, 25)
);
const CONTENT_STUDIO_HEARTBEAT_TIMEOUT_SECONDS = 90;
const CONTENT_STUDIO_TERMINAL_STATUSES = ['partial_success', 'success', 'failed', 'interrupted'];

function content_studio_active_rss_count(): int
{
    $statement = bueno_database()->query(
        'SELECT COUNT(*) FROM technical_sources WHERE is_active = 1 AND source_type = "rss"'
    );

    return (int) $statement->fetchColumn();
}

function content_studio_expire_stale_jobs(?DateTimeImmutable $now = null): int
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $cutoff = $now->modify('-' . CONTENT_STUDIO_HEARTBEAT_TIMEOUT_SECONDS . ' seconds')->format('Y-m-d H:i:s');
    $statement = bueno_database()->prepare(
        'UPDATE editorial_ingestion_jobs
         SET status = "interrupted", stage = "interrupted",
             error_message = "Zadanie utraciło heartbeat i może zostać bezpiecznie ponowione.",
             finished_at = :now
         WHERE status IN ("queued", "running")
           AND datetime(COALESCE(heartbeat_at, started_at, created_at)) < datetime(:cutoff)'
    );
    $statement->execute([':now' => $now->format('Y-m-d H:i:s'), ':cutoff' => $cutoff]);

    return $statement->rowCount();
}

function content_studio_find_job(int $jobId): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM editorial_ingestion_jobs WHERE id = :id');
    $statement->execute([':id' => $jobId]);
    $job = $statement->fetch();

    return is_array($job) ? $job : null;
}

function content_studio_latest_job(): ?array
{
    content_studio_expire_stale_jobs();
    $job = bueno_database()->query(
        'SELECT * FROM editorial_ingestion_jobs ORDER BY id DESC LIMIT 1'
    )->fetch();

    return is_array($job) ? $job : null;
}

function content_studio_create_job(string $requestedBy = 'admin'): array
{
    $database = bueno_database();
    content_studio_expire_stale_jobs();
    $database->exec('BEGIN IMMEDIATE');
    $transactionOpen = true;
    try {
        $active = $database->query(
            'SELECT * FROM editorial_ingestion_jobs
             WHERE status IN ("queued", "running") ORDER BY id DESC LIMIT 1'
        )->fetch();
        if (is_array($active)) {
            $database->exec('ROLLBACK');
            $transactionOpen = false;
            throw new DomainException('Pobieranie RSS już trwa.', 409);
        }
        $sourceCount = content_studio_active_rss_count();
        $statement = $database->prepare(
            'INSERT INTO editorial_ingestion_jobs (
                status, stage, total_units, active_source_count, requested_by, heartbeat_at
             ) VALUES ("queued", "queued", :total_units, :source_count, :requested_by, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':total_units' => $sourceCount + 2,
            ':source_count' => $sourceCount,
            ':requested_by' => mb_substr(trim($requestedBy), 0, 100),
        ]);
        $jobId = (int) $database->lastInsertId();
        $database->exec('COMMIT');
        $transactionOpen = false;
    } catch (Throwable $exception) {
        if ($transactionOpen) {
            $database->exec('ROLLBACK');
        }
        throw $exception;
    }

    return content_studio_find_job($jobId) ?? throw new RuntimeException('Nie udało się utworzyć zadania.');
}

function content_studio_update_job(int $jobId, array $changes): void
{
    $allowed = [
        'status', 'stage', 'current_source', 'processed_units', 'total_units',
        'active_source_count', 'created_count', 'duplicate_count', 'failed_source_count',
        'succeeded_source_count', 'not_modified_source_count', 'retried_source_count',
        'source_results_json', 'grouping_result_json', 'scoring_result_json',
        'error_message', 'started_at', 'heartbeat_at', 'finished_at',
    ];
    $sets = [];
    $parameters = [':id' => $jobId];
    foreach ($changes as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            throw new InvalidArgumentException('Niedozwolone pole stanu zadania.');
        }
        $sets[] = $column . ' = :' . $column;
        $parameters[':' . $column] = $value;
    }
    if ($sets === []) {
        return;
    }
    $statement = bueno_database()->prepare(
        'UPDATE editorial_ingestion_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id'
    );
    $statement->execute($parameters);
}

function content_studio_claim_job(int $jobId): bool
{
    $statement = bueno_database()->prepare(
        'UPDATE editorial_ingestion_jobs
         SET status = "running", stage = "rss", started_at = CURRENT_TIMESTAMP,
             heartbeat_at = CURRENT_TIMESTAMP, error_message = ""
         WHERE id = :id AND status = "queued"'
    );
    $statement->execute([':id' => $jobId]);

    return $statement->rowCount() === 1;
}

function content_studio_run_job(int $jobId, ?callable $fetcher = null): array
{
    if (!content_studio_claim_job($jobId)) {
        throw new RuntimeException('Zadanie nie oczekuje na uruchomienie.');
    }
    $sourceResults = [];
    try {
        $feedResult = run_feed_ingestion(
            $fetcher,
            static function (array $source, int $processed, int $total, string $event, ?array $result) use ($jobId, &$sourceResults): void {
                if ($result !== null) {
                    $sourceResults[] = $result;
                }
                content_studio_update_job($jobId, [
                    'stage' => 'rss',
                    'current_source' => $event === 'started' ? (string) $source['name'] : '',
                    'processed_units' => $processed,
                    'total_units' => $total + 2,
                    'active_source_count' => $total,
                    'source_results_json' => json_encode($sourceResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'heartbeat_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
        );
        content_studio_update_job($jobId, [
            'created_count' => $feedResult['created'],
            'duplicate_count' => $feedResult['duplicates'],
            'failed_source_count' => $feedResult['failed'],
            'succeeded_source_count' => $feedResult['succeeded'],
            'not_modified_source_count' => $feedResult['not_modified'],
            'retried_source_count' => $feedResult['retried'],
            'stage' => 'grouping',
            'current_source' => '',
            'processed_units' => count($feedResult['sources']),
            'heartbeat_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $grouping = run_topic_grouping();
        content_studio_update_job($jobId, [
            'grouping_result_json' => json_encode($grouping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'stage' => 'scoring',
            'processed_units' => count($feedResult['sources']) + 1,
            'heartbeat_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $scoring = run_topic_scoring();
        $finished = gmdate('Y-m-d H:i:s');
        $status = $feedResult['failed'] > 0 ? 'partial_success' : 'success';
        content_studio_update_job($jobId, [
            'scoring_result_json' => json_encode($scoring, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'stage' => 'completed',
            'processed_units' => count($feedResult['sources']) + 2,
            'heartbeat_at' => $finished,
            'finished_at' => $finished,
        ]);
    } catch (Throwable $exception) {
        content_studio_update_job($jobId, [
            'status' => 'failed',
            'stage' => 'failed',
            'current_source' => '',
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ]);
        throw $exception;
    }

    return content_studio_find_job($jobId) ?? throw new RuntimeException('Utracono stan zadania.');
}

function content_studio_php_cli(): string
{
    $configured = trim((string) getenv('CMS_PHP_CLI'));
    $candidates = array_filter([
        $configured,
        str_ends_with(strtolower(PHP_BINARY), 'php.exe') || basename(PHP_BINARY) === 'php' ? PHP_BINARY : '',
        rtrim(PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'),
    ]);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Nie znaleziono PHP CLI. Ustaw CMS_PHP_CLI na ścieżkę do php.exe.');
}

function content_studio_launch_worker(int $jobId): void
{
    if (getenv('CMS_CONTENT_STUDIO_NO_SPAWN') === '1') {
        return;
    }
    $php = content_studio_php_cli();
    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'content-studio-worker.php';
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' '
            . $jobId . ' > NUL 2>&1';
        $handle = popen($command, 'r');
        if ($handle === false) {
            throw new RuntimeException('Nie udało się uruchomić workera RSS.');
        }
        pclose($handle);
        return;
    }
    $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . $jobId . ' > /dev/null 2>&1 &';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Nie udało się uruchomić workera RSS.');
    }
}

function content_studio_decode_json(string $value, array $fallback = []): array
{
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : $fallback;
    } catch (Throwable) {
        return $fallback;
    }
}

function content_studio_job_payload(?array $job): ?array
{
    if ($job === null) {
        return null;
    }
    $processed = (int) $job['processed_units'];
    $total = (int) $job['total_units'];
    return [
        'id' => (int) $job['id'],
        'status' => (string) $job['status'],
        'stage' => (string) $job['stage'],
        'current_source' => (string) $job['current_source'],
        'processed' => $processed,
        'total' => $total,
        'percent' => $total > 0 ? min(100, (int) floor($processed * 100 / $total)) : 0,
        'active_sources' => (int) $job['active_source_count'],
        'created' => (int) $job['created_count'],
        'duplicates' => (int) $job['duplicate_count'],
        'failed_sources' => (int) $job['failed_source_count'],
        'succeeded_sources' => (int) ($job['succeeded_source_count'] ?? 0),
        'not_modified_sources' => (int) ($job['not_modified_source_count'] ?? 0),
        'retried_sources' => (int) ($job['retried_source_count'] ?? 0),
        'source_results' => content_studio_decode_json((string) $job['source_results_json']),
        'error' => (string) $job['error_message'],
        'created_at' => (string) $job['created_at'],
        'started_at' => $job['started_at'],
        'heartbeat_at' => $job['heartbeat_at'],
        'finished_at' => $job['finished_at'],
        'terminal' => in_array((string) $job['status'], CONTENT_STUDIO_TERMINAL_STATUSES, true),
    ];
}

function content_studio_topics(int $limit = 100): array
{
    $topics = list_editorial_topics(max(1, min(200, $limit)), 'active');
    return array_map(static function (array $topic): array {
        $items = topic_feed_items((int) $topic['id']);
        $categories = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => (string) $item['category'],
            $items
        ))));
        $breakdown = content_studio_decode_json((string) ($topic['scoring_breakdown_json'] ?? '{}'));
        $visual = (int) ($breakdown['components']['visual_potential']['points'] ?? 0);
        $eventTimestamp = strtotime((string) $topic['event_at']) ?: time();
        $ageHours = max(0, (int) floor((time() - $eventTimestamp) / 3600));
        return [
            'id' => (int) $topic['id'],
            'post_id' => (int) $topic['primary_post_id'],
            'title' => (string) $topic['title'],
            'category' => $categories !== [] ? implode(', ', $categories) : '—',
            'score' => $topic['score'] === null ? null : (int) $topic['score'],
            'freshness_hours' => $ageHours,
            'source_count' => (int) $topic['source_count'],
            'risk' => (string) $topic['risk_level'],
            'visual_potential' => $visual,
        ];
    }, $topics);
}

function content_studio_validate_topic_ids(mixed $rawIds): array
{
    if (!is_array($rawIds)) {
        throw new InvalidArgumentException('Wybierz co najmniej jeden temat.');
    }
    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Lista tematów zawiera nieprawidłowy identyfikator.');
        }
        $ids[(int) $id] = (int) $id;
    }
    $ids = array_values($ids);
    if ($ids === [] || count($ids) > CONTENT_STUDIO_BATCH_LIMIT) {
        throw new InvalidArgumentException('Wybierz od 1 do ' . CONTENT_STUDIO_BATCH_LIMIT . ' tematów.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = bueno_database()->prepare(
        'SELECT topics.id FROM editorial_topics AS topics
         INNER JOIN posts ON posts.id = topics.primary_post_id
         WHERE topics.id IN (' . $placeholders . ') AND posts.status != "rejected"
           AND topics.trashed_at IS NULL AND topics.purged_at IS NULL'
    );
    $statement->execute($ids);
    $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    sort($found);
    $expected = $ids;
    sort($expected);
    if ($found !== $expected) {
        throw new InvalidArgumentException('Co najmniej jeden temat nie istnieje lub nie jest aktywny.');
    }

    return $ids;
}
