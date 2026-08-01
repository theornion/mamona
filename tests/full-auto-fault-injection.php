<?php

declare(strict_types=1);
require_once __DIR__ . '/full-auto-harness.php';

function fault_expect(FullAutoHarness $pipeline, string $stage, string $id, array $fixture, string $category): void
{
    try { $pipeline->runStage($stage, $id, fn () => $fixture); }
    catch (FullAutoFailure $e) { if ($e->category === $category) { return; } throw $e; }
    throw new RuntimeException("Nie wykryto {$category} na etapie {$stage}.");
}

// Każdy przypadek zaczyna się od ostatniego bezpiecznego snapshotu.
$base = new FullAutoHarness();
$base->runStage('ingestion', 'i', fn () => full_auto_fixture('ingestion'));
$base->runStage('scoring', 's', fn () => full_auto_fixture('scoring'));
$safe = $base->snapshot();

$p = FullAutoHarness::restore($safe);
$bad = full_auto_fixture('research'); $bad['claims'][0]['source_id'] = 'S404';
fault_expect($p, 'research', 'bad-source', $bad, 'source_id'); $p->repair('research');
$bad = full_auto_fixture('research'); $bad['claims'][0]['evidence'] = 'parafraza nieobecna w źródle';
fault_expect($p, 'research', 'bad-evidence', $bad, 'exact-evidence'); $p->repair('research');
$p->runStage('research', 'r-good', fn () => full_auto_fixture('research'));
$restart = FullAutoHarness::restore($p->snapshot());
$restart->runStage('research', 'r-good', fn () => throw new RuntimeException('Duplikat po restarcie.'));

$badDraft = full_auto_fixture('draft'); $badDraft['unknown_ids'] = ['U404'];
fault_expect($restart, 'draft', 'bad-unknown', $badDraft, 'unknown_id'); $restart->repair('draft');
$empty = full_auto_fixture('draft'); $empty['text'] = '';
fault_expect($restart, 'draft', 'empty', $empty, 'quality'); $restart->repair('draft');
fault_expect($restart, 'draft', 'timeout', ['_transport' => 'timeout'], 'transport'); $restart->repair('draft');
fault_expect($restart, 'draft', 'rate', ['_transport' => '429'], 'transport'); $restart->repair('draft');
$restart->runStage('draft', 'd-good', fn () => full_auto_fixture('draft'));
foreach (['q1', 'q2'] as $id) {
    fault_expect($restart, 'qc', $id, ['pass' => false, 'hard_blocks' => ['unsupported_claim']], 'quality');
    $restart->repair('qc');
}
$state = $restart->snapshot();
if ($state['stage'] !== 'draft' || $state['published'] !== false || count($state['operations']) !== 4) {
    throw new RuntimeException('Powrót do bezpiecznego etapu utworzył duplikat lub publikację.');
}
echo "FULL_AUTO_FAULT_INJECTION_OK\n";
