<?php
declare(strict_types=1);

function topic_status_resilience_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/php/admin-editorial-topics-api.php');
$service = (string) file_get_contents($root . '/php/generation-batch-service.php');
$js = (string) file_get_contents($root . '/assets/js/admin-editorial-topics.js');

topic_status_resilience_assert(str_contains($api, "'error' => 'worker_busy'") && str_contains($api, 'topics_api_json(['), 'Status SQLite busy still escapes as a non-JSON response.');
topic_status_resilience_assert(str_contains($service, 'j.updated_at job_updated_at') && str_contains($service, "'workflow_revision'"), 'Backend status payload has no monotonic workflow revision.');
topic_status_resilience_assert(str_contains($js, 'function mergeTopicStates') && str_contains($js, 'function acceptsTopicState'), 'Client still applies stale status snapshots blindly.');
topic_status_resilience_assert(str_contains($js, 'await response.text()') && str_contains($js, 'JSON.parse(rawPayload)'), 'Client does not distinguish malformed status responses.');
topic_status_resilience_assert(str_contains($js, "payload.error === 'worker_busy'") && str_contains($js, 'stan pozostaje widoczny'), 'Temporary worker lock still becomes a lost-sync banner.');
topic_status_resilience_assert(str_contains($js, "Hero: ' + (topic.job.image_hero_allowed ? 'OK' : 'wymagany')") && str_contains($service, "'image_missing_slots'"), 'Image stage does not expose the required hero and missing slots.');
topic_status_resilience_assert(str_contains($js, 'function clearSyncWarnings') && str_contains($js, 'syncErrorTopicIds.clear()'), 'Recovered sync does not clear its stale error message.');
topic_status_resilience_assert(str_contains($js, "if (!hasActiveJobs() && !networkFailures) return;") && str_contains($js, "window.addEventListener('pagehide'"), 'Polling does not stop after completion or clean up its lifecycle.');
topic_status_resilience_assert(str_contains($js, 'window.clearTimeout(pollTimer); refresh();'), 'Retry does not immediately switch monitoring to the new workflow.');
topic_status_resilience_assert((bool) preg_match('/filterCards\(\);\R\s*refresh\(\);/', $js), 'Opening topics does not fetch one authoritative status snapshot.');
topic_status_resilience_assert(str_contains($js, 'function syncDebug') && str_contains($js, "try { applyTopicState(topic); }"), 'One broken topic card can still stop the entire live render without diagnostics.');
topic_status_resilience_assert(str_contains($js, "if (!jobBox.querySelector('span')) jobBox.appendChild(document.createElement('span'))"), 'A server-rendered terminal card can still break its live update when it has no message element.');

echo "topic-status-resilience-smoke: OK\n";
