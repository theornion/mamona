<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/repair-router-service.php';

function router_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$assessment = repair_router_assess([
    'passed' => 0,
    'result_json' => json_encode([
        'false_quotes' => ['Niedosłowny cytat'],
        'unsupported_claims' => [['message' => 'Brak evidence']],
        'missing_elements' => ['Brak kontekstu B'],
        'recommendation' => 'revise',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
]);
$codes = array_column($assessment['issues'], 'code');
router_assert(in_array('false_quote', $codes, true) && in_array('unsupported_claim', $codes, true), 'Factual gate nie zwrócił stabilnych issue codes.');
router_assert(in_array('missing_element', $codes, true), 'Gate kompletności nie skierował naprawy do B/researchu.');

$titles = repair_router_title_ladder('Sonda wykryła lód w kraterze', [['claim' => 'Sonda wykryła lód w kraterze']]);
router_assert(count($titles['candidates']) === 5 && count(array_unique($titles['candidates'])) === 5, 'Title ladder nie zwrócił pięciu różnych kandydatów.');
router_assert(!str_contains(mb_strtolower($titles['selected']), 'szok'), 'Title ladder wybrał clickbait.');

$needsB = repair_router_expansion_plan(false, false);
$hasB = repair_router_expansion_plan(true, false);
$hasC = repair_router_expansion_plan(true, true);
router_assert($needsB['suggested_stage'] === 'research' && $needsB['strategy'] === 'research_topic_b', 'Brak B nie wraca do researchu.');
router_assert($hasB['sequence'] === ['A', 'B', 'A', 'B', 'A'], 'Ekspansja nie zachowuje A-B-A-B-A.');
router_assert($hasC['sequence'] === ['A', 'B', 'A', 'C', 'A'], 'Knowledge journey nie używa zweryfikowanego C.');

$batchSource = (string) file_get_contents(dirname(__DIR__) . '/php/generation-batch-service.php');
$salvageSource = (string) file_get_contents(dirname(__DIR__) . '/php/salvage-service.php');
$previewSource = (string) file_get_contents(dirname(__DIR__) . '/php/admin-proposals.php');
router_assert(str_contains($batchSource, 'auto_retry_scheduled') && !str_contains($batchSource, 'function generation_batch_auto_reject'), 'Autonomiczny kontrakt nadal zawiera auto-reject lub nie ma retry infrastruktury.');
router_assert(str_contains($salvageSource, 'Lokalna ilustracja redakcyjna') && str_contains($salvageSource, 'deterministic-safe-composer'), 'Brakuje końcowych fallbacków tekstu lub obrazu.');
router_assert(str_contains($previewSource, 'Automatyczne decyzje i wątpliwości'), 'Raport nie jest renderowany pod podglądem.');

echo "REPAIR_ROUTER_SMOKE_OK\n";
