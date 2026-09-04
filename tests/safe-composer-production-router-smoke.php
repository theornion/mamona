<?php

declare(strict_types=1);

function safe_composer_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$salvage = (string) file_get_contents($root . '/php/salvage-service.php');
$batch = (string) file_get_contents($root . '/php/generation-batch-service.php');
$proposal = (string) file_get_contents($root . '/php/proposal-review-service.php');

safe_composer_assert(str_contains($salvage, 'function salvage_classify_manual_review'), 'Brak bezpiecznej klasyfikacji manual review.');
safe_composer_assert(str_contains($salvage, 'function salvage_is_provider_proven_quality_check'), 'Brak kontroli pochodzenia QC.');
safe_composer_assert(str_contains($salvage, 'o.live_request_count>0') && str_contains($salvage, 'deterministic-%'), 'QC bez prawdziwego provider call może przejść przez router.');
safe_composer_assert(str_contains($salvage, 'high_risk_without_human_approval'), 'Router nie ogranicza się do ręcznie rozstrzygalnego ryzyka.');
safe_composer_assert(str_contains($batch, 'human_risk_review_required') && str_contains($batch, 'safe_composer_evidence_insufficient'), 'Brak rozdzielenia manualnego ryzyka od niewystarczających danych.');
safe_composer_assert(str_contains($batch, "'provider_calls_created' => 0") && str_contains($batch, "'publication_recommended' => false"), 'Router nie deklaruje zero-provider i fail-closed publikacji.');
safe_composer_assert(str_contains($batch, 'function generation_batch_reconcile_safe_composer_manual_items') && str_contains($batch, 'items.outcome="safe_composer_blocked"'), 'Nie ma idempotentnej migracji zatrzymanych elementów.');
safe_composer_assert(str_contains($proposal, 'ORDER BY CASE WHEN q.execution_mode="api" AND o.live_request_count>0'), 'Wybór QC nie preferuje rzeczywistego wyniku providerowego.');

echo "SAFE_COMPOSER_PRODUCTION_ROUTER_SMOKE_OK\n";
