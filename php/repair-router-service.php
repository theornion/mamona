<?php

declare(strict_types=1);

const REPAIR_ROUTER_STAGE_BUDGET = 3;
const REPAIR_ROUTER_GLOBAL_BUDGET = 9;

/** Converts QC output into stable, machine-readable gates and repair routes. */
function repair_router_assess(array $check, bool $convergenceActive = false): array
{
    $result = json_decode((string) ($check['result_json'] ?? '{}'), true) ?: $check;
    $issues = [];
    $add = static function (string $code, string $gate, string $stage, string $strategy, string $message) use (&$issues): void {
        $issues[] = ['code' => $code, 'gate' => $gate, 'suggested_stage' => $stage,
            'repair_strategy' => $strategy, 'message' => $message];
    };
    $issueMessage = static function (mixed $issue): string {
        if (!is_array($issue)) {
            return (string) $issue;
        }
        if (isset($issue['message']) && !is_array($issue['message'])) {
            return (string) $issue['message'];
        }
        return json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Nieprawidłowy szczegół QC.';
    };
    foreach ((array) ($result['unsupported_claims'] ?? []) as $issue) {
        $add('unsupported_claim', 'factual_source', 'draft', 'claim_prune_or_research', $issueMessage($issue));
    }
    foreach ((array) ($result['false_quotes'] ?? []) as $issue) {
        $add('false_quote', 'factual_source', 'draft', 'quote_sanitize', $issueMessage($issue));
    }
    if (($result['title_supported'] ?? true) !== true) $add('unsupported_title', 'title_lead_seo', 'draft', 'title_ladder', 'Tytuł zawiera niewsparty fakt.');
    foreach ((array) ($result['clickbait_phrases'] ?? []) as $issue) $add('clickbait', 'title_lead_seo', 'draft', 'title_ladder', $issueMessage($issue));
    foreach ((array) ($result['missing_elements'] ?? []) as $issue) $add('missing_element', 'completeness_length', 'draft', 'topic_b_expansion', $issueMessage($issue));
    foreach ((array) ($result['language_issues'] ?? []) as $issue) $add('language_issue', 'structure_education', 'draft', 'composition_switch', $issueMessage($issue));
    foreach ((array) ($result['risk_flags'] ?? []) as $issue) $add('high_risk', 'factual_source', 'draft', 'safe_composer', $issueMessage($issue));
    if ($issues === [] && (($result['recommendation'] ?? 'pass') !== 'pass' || (int) ($check['passed'] ?? 1) !== 1)) {
        $add('quality_below_threshold', 'final_package', 'draft', 'targeted_repair', (string) ($result['justification'] ?? 'QC nie zaliczyło pakietu.'));
    }
    if ($convergenceActive) {
        foreach ($issues as &$issue) {
            if (in_array((string)($issue['repair_strategy'] ?? ''), ['fresh_conservative_rewrite', 'safe_composer'], true)) {
                $issue['repair_strategy'] = 'targeted_repair';
            }
        }
        unset($issue);
    }
    return ['issues' => $issues, 'passed' => $issues === [] && (int) ($check['passed'] ?? 1) === 1, 'convergence_mode' => $convergenceActive];
}

function repair_router_title_ladder(string $eventSummary, array $claims): array
{
    $fact = trim((string) (($claims[0]['claim'] ?? '') ?: $eventSummary));
    $fact = rtrim(preg_replace('/\s+/u', ' ', $fact) ?? $fact, '.!?');
    $candidates = [
        $fact,
        'Co wiadomo o tym wydarzeniu: ' . $fact,
        $fact . ' — fakty, znaczenie i ograniczenia',
        'Najważniejsze ustalenia: ' . $fact,
        'Wyjaśniamy potwierdzone informacje: ' . $fact,
    ];
    $candidates = array_values(array_unique(array_map(static fn (string $title): string => mb_substr(trim($title), 0, 100), $candidates)));
    $valid = array_values(array_filter($candidates, static fn (string $title): bool => mb_strlen($title) >= 25 && preg_match('/[!?]{2,}|szok|niewiarygodn/iu', $title) !== 1));
    $selected = $valid[0] ?? mb_substr('Potwierdzone informacje: ' . ($eventSummary ?: 'aktualny stan wiedzy'), 0, 100);
    return ['candidates' => array_slice($candidates, 0, 5), 'selected' => $selected, 'fallback_used' => $valid === []];
}

function repair_router_expansion_plan(bool $hasTopicB, bool $hasTopicC): array
{
    if (!$hasTopicB) return ['strategy' => 'research_topic_b', 'suggested_stage' => 'research', 'sequence' => ['A', 'B', 'A', 'B', 'A']];
    if (!$hasTopicC) return ['strategy' => 'aba_ba_rewrite', 'suggested_stage' => 'draft', 'sequence' => ['A', 'B', 'A', 'B', 'A']];
    return ['strategy' => 'knowledge_journey', 'suggested_stage' => 'draft', 'sequence' => ['A', 'B', 'A', 'C', 'A']];
}

function repair_router_budget_state(int $itemId, string $gate): array
{
    $events = repair_report_get($itemId)['events'];
    $stageUsed = count(array_filter($events, static fn (array $event): bool => (string) ($event['gate'] ?? '') === $gate));
    return ['stage_used' => $stageUsed, 'global_used' => count($events),
        'stage_remaining' => max(0, REPAIR_ROUTER_STAGE_BUDGET - $stageUsed),
        'global_remaining' => max(0, REPAIR_ROUTER_GLOBAL_BUDGET - count($events)),
        'exhausted' => $stageUsed >= REPAIR_ROUTER_STAGE_BUDGET || count($events) >= REPAIR_ROUTER_GLOBAL_BUDGET];
}

function repair_report_append(int $itemId, string $gate, string $strategy, array $details = [], array $unresolved = []): void
{
    $existing = repair_report_get($itemId);
    $events = (array) ($existing['events'] ?? []);
    $unresolved = array_values(array_unique([...array_map('strval', (array) ($existing['unresolved'] ?? [])), ...array_map('strval', $unresolved)]));
    $fingerprint = hash('sha256', generation_json([$gate, $strategy, $details]));
    foreach ($events as $event) if (($event['fingerprint'] ?? '') === $fingerprint) return;
    $events[] = ['at' => gmdate(DATE_ATOM), 'gate' => $gate, 'strategy' => $strategy,
        'details' => $details, 'fingerprint' => $fingerprint];
    bueno_database()->prepare(
        'INSERT INTO generation_repair_reports (item_id,report_json,unresolved_json)
         VALUES (:item,:report,:unresolved)
         ON CONFLICT(item_id) DO UPDATE SET report_json=excluded.report_json,
            unresolved_json=excluded.unresolved_json,updated_at=CURRENT_TIMESTAMP'
    )->execute([':item' => $itemId, ':report' => generation_json($events), ':unresolved' => generation_json($unresolved)]);
}

function repair_report_get(int $itemId): array
{
    $statement = bueno_database()->prepare('SELECT report_json,unresolved_json FROM generation_repair_reports WHERE item_id=:item');
    $statement->execute([':item' => $itemId]);
    $row = $statement->fetch();
    return is_array($row) ? ['events' => json_decode((string) $row['report_json'], true) ?: [],
        'unresolved' => json_decode((string) $row['unresolved_json'], true) ?: []] : ['events' => [], 'unresolved' => []];
}

function repair_report_for_draft(int $draftVersionId): array
{
    $statement = bueno_database()->prepare('SELECT id FROM generation_batch_items WHERE draft_version_id=:draft ORDER BY id DESC LIMIT 1');
    $statement->execute([':draft' => $draftVersionId]);
    $itemId = (int) ($statement->fetchColumn() ?: 0);
    return $itemId > 0 ? repair_report_get($itemId) : ['events' => [], 'unresolved' => []];
}
