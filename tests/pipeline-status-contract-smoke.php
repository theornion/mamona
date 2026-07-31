<?php

declare(strict_types=1);

function pipeline_status_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$service = (string) file_get_contents(__DIR__ . '/../php/generation-batch-service.php');
$api = (string) file_get_contents(__DIR__ . '/../php/admin-editorial-topics-api.php');
$client = (string) file_get_contents(__DIR__ . '/../assets/js/admin-editorial-topics.js');

pipeline_status_assert(str_contains($service, 'j.available_at'), 'The status query omits available_at.');
pipeline_status_assert(str_contains($service, "'retry_after_seconds' => \$retryAfterSeconds"), 'The status payload omits retry_after_seconds.');
pipeline_status_assert(str_contains($service, "'progress' => \$row['job_id'] ? \$progress"), 'Terminal status loses recorded progress.');
pipeline_status_assert(str_contains($service, 'generation_batch_has_due_items'), 'Due rate-limited work is not detected for automatic resume.');
pipeline_status_assert(str_contains($api, "'server_time' => gmdate('c')"), 'The API omits its authoritative clock.');
pipeline_status_assert(str_contains($api, 'generation_batch_launch_worker'), 'Status synchronization cannot resume due work.');
pipeline_status_assert(str_contains($client, 'changes.slice(0, 3)'), 'aria-live transition announcements are not bounded.');
pipeline_status_assert(str_contains($client, "job.status === 'rate_limited'") && str_contains($client, 'Automatyczne wznowienie za'), 'The client omits the rate-limit countdown.');
pipeline_status_assert(str_contains($client, 'networkFailures++') && str_contains($client, 'visibilitychange'), 'The client does not recover from network/tab suspension.');

echo "PIPELINE_STATUS_CONTRACT_SMOKE_OK\n";
