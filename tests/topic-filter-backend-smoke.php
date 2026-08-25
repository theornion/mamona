<?php
declare(strict_types=1);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';
function topic_filter_backend_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
topic_filter_backend_assert(generation_workflow_queue_state(['readiness' => true, 'status' => 'waiting_review']) === 'ready', 'Ready must take precedence.');
foreach (['waiting_review', 'manual_review', 'failed', 'rate_limited'] as $status) {
    topic_filter_backend_assert(generation_workflow_queue_state(['readiness' => false, 'status' => $status]) === 'action', 'Misclassified action: ' . $status);
}
foreach (['ready', 'ready_for_preview', 'ready_with_notes'] as $status) {
    topic_filter_backend_assert(generation_workflow_queue_state(['readiness' => false, 'status' => $status]) === 'action', 'Unready proposal was classified as ready: ' . $status);
}
foreach (['eligible', 'queued', 'research', 'draft', 'quality_check', 'images'] as $status) {
    topic_filter_backend_assert(generation_workflow_queue_state(['readiness' => false, 'status' => $status]) === 'work', 'Misclassified work: ' . $status);
}
$fixture = [['queue_state' => 'work'], ['queue_state' => 'action'], ['queue_state' => 'ready']];
topic_filter_backend_assert(generation_topic_queue_counts($fixture) === ['work' => 1, 'action' => 1, 'ready' => 1], 'Queue counters must use the shared classification.');
$visible = static fn (bool $ready, bool $action): array => array_values(array_filter(array_column($fixture, 'queue_state'), static fn (string $state): bool => generation_topic_queue_visible($state, $ready, $action)));
topic_filter_backend_assert($visible(false, false) === ['work'], 'SSR default must show work only.');
topic_filter_backend_assert($visible(true, false) === ['work', 'ready'], 'SSR ready filter mismatch.');
topic_filter_backend_assert($visible(false, true) === ['work', 'action'], 'SSR action filter mismatch.');
topic_filter_backend_assert($visible(true, true) === ['work', 'action', 'ready'], 'SSR combined filter mismatch.');
echo "TOPIC_FILTER_BACKEND_SMOKE_OK\n";
