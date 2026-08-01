<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_FULL_AUTO_SMOKE') !== '1') { fwrite(STDERR, "Ustaw CMS_ALLOW_FULL_AUTO_SMOKE=1, aby uruchomić test na lokalnej bazie.\n"); exit(2); }
putenv('FULL_AUTO_ENABLED=false');
putenv('CMS_BATCH_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';
require_once dirname(__DIR__) . '/php/full-auto-service.php';

function full_auto_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
$base = ['id' => 25, 'title' => 'Technologia testowa', 'score' => 90, 'risk_level' => 'low', 'automatic_eligible' => 1, 'event_at' => '2026-08-01 11:00:00', 'trashed_at' => null, 'purged_at' => null, 'post_status' => 'idea', 'deleted_at' => null, 'category' => 'new-technologies', 'independent_source_count' => 2, 'has_primary_source' => 1, 'has_active_batch' => 0, 'already_reserved' => 0];
$config = ['minimum_score' => 70, 'minimum_independent_sources' => 2, 'require_primary_source' => true, 'maximum_age_hours' => 72, 'allowed_categories' => ['new-technologies'], 'allowed_risks' => ['low']];
full_auto_assert(full_auto_evaluate_candidate($base, $config, $now)['selected'], 'Bezpieczny temat nie został wybrany.');
foreach ([
    'score' => [69, 'score_below_minimum'],
    'has_primary_source' => [0, 'primary_source_required'],
    'risk_level' => ['high', 'risk_not_allowed'],
    'has_active_batch' => [1, 'active_batch'],
    'already_reserved' => [1, 'duplicate_or_already_processed'],
] as $field => [$value, $reason]) {
    $candidate = $base; $candidate[$field] = $value;
    full_auto_assert(in_array($reason, full_auto_evaluate_candidate($candidate, $config, $now)['reasons'], true), 'Brak powodu: ' . $reason);
}
$candidate = $base; $candidate['event_at'] = '2026-07-20 00:00:00';
full_auto_assert(in_array('topic_too_old', full_auto_evaluate_candidate($candidate, $config, $now)['reasons'], true), 'Nie odrzucono starego tematu.');

$database = bueno_database();
full_auto_ensure_schema($database);
$before = [(int) $database->query('SELECT COUNT(*) FROM full_auto_runs')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM full_auto_reservations')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM generation_batches')->fetchColumn()];
$dry = full_auto_execute(true, $now);
$after = [(int) $database->query('SELECT COUNT(*) FROM full_auto_runs')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM full_auto_reservations')->fetchColumn(), (int) $database->query('SELECT COUNT(*) FROM generation_batches')->fetchColumn()];
full_auto_assert($dry['mutated'] === false && $before === $after, 'Dry-run zmienił bazę.');
try { full_auto_execute(false, $now); throw new RuntimeException('Wyłączona flaga nie zablokowała runu.'); }
catch (RuntimeException $exception) { full_auto_assert(str_contains($exception->getMessage(), 'FULL_AUTO_ENABLED=false'), 'Nieoczekiwany błąd disabled.'); }

$topicIds = array_map('intval', $database->query('SELECT id FROM editorial_topics ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
if (count($topicIds) === 2) {
    $runIds = []; $reservationIds = [];
    try {
        $plan = ['candidates' => [['topic_id' => $topicIds[0]]], 'selected' => [['topic_id' => $topicIds[0]]]];
        $used = (int) $database->query("SELECT COUNT(*) FROM full_auto_reservations WHERE reserved_at >= datetime('now', 'start of day') AND status != 'failed'")->fetchColumn();
        $limits = ['max_topics_per_run' => 1, 'max_topics_per_day' => $used + 1];
        $first = full_auto_reserve($database, $plan, $limits, 'full-auto-smoke-a-' . bin2hex(random_bytes(4))); $runIds[] = $first['run_id'];
        $second = full_auto_reserve($database, $plan, $limits, 'full-auto-smoke-b-' . bin2hex(random_bytes(4))); $runIds[] = $second['run_id'];
        full_auto_assert(count($first['reserved']) === 1 && $second['reserved'] === [], 'Równoległa/idempotentna rezerwacja nie zadziałała.');
        full_auto_assert((int) $database->query('SELECT COUNT(*) FROM full_auto_reservations WHERE run_id IN (' . implode(',', $runIds) . ')')->fetchColumn() === 1, 'Dzienny cap został przekroczony.');
    } finally {
        if ($runIds !== []) $database->exec('DELETE FROM full_auto_runs WHERE id IN (' . implode(',', $runIds) . ')');
    }
}

$service = (string) file_get_contents(dirname(__DIR__) . '/php/full-auto-service.php');
full_auto_assert(str_contains($service, "'generate_all'") && str_contains($service, 'generation_batch_launch_worker'), 'Run nie uruchamia pełnego batcha i workera.');
full_auto_assert(!str_contains($service, 'publish_post(') && !str_contains($service, 'publish_scheduled_posts('), 'Selektor zawiera ścieżkę publikacji.');
echo "FULL_AUTO_SELECTOR_SMOKE_OK\n";
