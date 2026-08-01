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
pipeline_status_assert(str_contains($service, 'j.next_retry_at') && str_contains($service, "'quota_dimension'") && str_contains($service, "'quota_model'"), 'Canonical quota retry metadata is missing.');
pipeline_status_assert(str_contains($service, "'retry_after_seconds' => \$retryAfterSeconds"), 'The status payload omits retry_after_seconds.');
pipeline_status_assert(str_contains($service, "'progress' => \$row['job_id'] ? \$progress"), 'Terminal status loses recorded progress.');
pipeline_status_assert(str_contains($service, 'generation_batch_has_due_items'), 'Due rate-limited work is not detected for automatic resume.');
pipeline_status_assert(str_contains($service, '$proposalReviewable') && str_contains($service, "'publication_readiness'"), 'Reviewability is not separated from publication readiness.');
pipeline_status_assert(str_contains($service, '$allStagesReady') && str_contains($service, "'readiness' => \$allStagesReady"), 'Gotowe nie wymaga zaliczenia wszystkich etapów.');
pipeline_status_assert(str_contains($service, 'human_review_status') && str_contains($service, 'high_risk_without_human_approval'), 'Ręczna akceptacja QC nie jest uwzględniana w gotowości.');
pipeline_status_assert(str_contains($service, 'function generation_workflow_queue_state'), 'Missing shared backend queue classifier.');
pipeline_status_assert(str_contains($service, "'proposal_url' => \$proposalReviewable"), 'A reviewable blocked draft has no direct proposal URL.');
pipeline_status_assert(str_contains($service, 'ORDER BY d.is_active DESC, d.id DESC'), 'Workflow does not prefer the active draft.');
pipeline_status_assert(str_contains($api, "'server_time' => gmdate('c')"), 'The API omits its authoritative clock.');
pipeline_status_assert(str_contains($api, 'generation_batch_launch_worker'), 'Status synchronization cannot resume due work.');
pipeline_status_assert(str_contains($client, 'changes.forEach(function (change) { cardMessage(change.id'), 'Workflow transitions are not announced in their topic cards.');
pipeline_status_assert(str_contains($client, "job.status === 'rate_limited'") && str_contains($client, 'Automatyczne wznowienie za'), 'The client omits the rate-limit countdown.');
pipeline_status_assert(str_contains($client, 'job.next_retry_at || job.available_at'), 'SSR/polling do not share canonical next_retry_at.');
pipeline_status_assert(!str_contains($service, "'Oczekiwanie na limit API ('"), 'Backend still embeds an independent countdown in wait_reason.');
pipeline_status_assert(str_contains($client, 'networkFailures++') && str_contains($client, 'visibilitychange'), 'The client does not recover from network/tab suspension.');

echo "PIPELINE_STATUS_CONTRACT_SMOKE_OK\n";
