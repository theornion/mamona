<?php

declare(strict_types=1);
require_once __DIR__ . '/full-auto-harness.php';

function fa_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

$pipeline = new FullAutoHarness();
foreach (['ingestion', 'scoring', 'research', 'draft', 'qc', 'images'] as $stage) {
    $first = $pipeline->runStage($stage, 'det-' . $stage, fn () => full_auto_fixture($stage));
    $again = $pipeline->runStage($stage, 'det-' . $stage, fn () => throw new RuntimeException('Idempotencja nie zadziałała.'));
    fa_assert($first === $again && $first['version'] === 1, "Błąd wersji/idempotencji: {$stage}");
}
$state = $pipeline->snapshot();
fa_assert($state['stage'] === 'images', 'Pipeline nie doszedł do obrazów.');
fa_assert($state['published'] === false, 'Test nie może publikować.');
fa_assert(count($state['operations']) === 6, 'Powstały duplikaty operacji.');
fa_assert(FullAutoHarness::restore($state)->snapshot() === $state, 'Restart zmienił stan.');
echo "FULL_AUTO_DETERMINISTIC_OK\n";
