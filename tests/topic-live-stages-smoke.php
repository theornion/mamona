<?php

declare(strict_types=1);

function topic_live_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/admin-editorial-topics.js');
$service = (string) file_get_contents($root . '/php/generation-batch-service.php');
$layout = (string) file_get_contents($root . '/php/admin-ui.php');

topic_live_assert(str_contains($js, "window.clearTimeout(pollTimer); refresh();"), 'Workflow does not request immediate state after start.');
topic_live_assert(str_contains($js, 'w toku…') && str_contains($js, "item.classList.toggle('is-active'"), 'Active workflow stage is not rendered.');
topic_live_assert(str_contains($js, 'image_hero_allowed') && str_contains($js, 'Wymagany poprawny hero'), 'Missing hero is not a fail-closed UI state.');
topic_live_assert(str_contains($service, "'image_hero_allowed'"), 'Workflow payload does not expose hero coverage.');
topic_live_assert(str_contains($layout, 'admin-editorial-topics.js?v=topics-live-sync-20260904e'), 'Browser cache can still serve the old topic client.');

echo "TOPIC_LIVE_STAGES_SMOKE_OK\n";
