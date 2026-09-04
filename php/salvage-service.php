<?php

declare(strict_types=1);

/** Creates an idempotent deterministic draft from approved claims only. */
function salvage_prepare_safe_composer(int $sourceDraftId): int
{
    $source = find_article_draft_by_id($sourceDraftId);
    if (!is_array($source) || (string) $source['status'] !== 'completed') throw new RuntimeException('Safe composer wymaga ukończonego szkicu źródłowego.');
    $package = find_research_package((int) $source['research_package_id']);
    if (!is_array($package) || (string) $package['status'] !== 'approved') throw new RuntimeException('Safe composer wymaga zatwierdzonego researchu.');
    $existing = bueno_database()->prepare('SELECT generation_operation_id FROM article_draft_versions WHERE parent_version_id=:parent AND repair_strategy="safe_composer" ORDER BY id DESC LIMIT 1');
    $existing->execute([':parent' => $sourceDraftId]);
    if (($id = $existing->fetchColumn()) !== false) return (int) $id;
    $research = json_decode((string) $package['package_json'], true) ?: [];
    $researchInput = json_decode((string) $package['research_input_json'], true) ?: [];
    $claims = array_values(array_filter((array) ($research['claims'] ?? []), static fn (array $claim): bool => in_array((string) ($claim['confidence'] ?? ''), ['high', 'medium'], true)));
    if ($claims === []) throw new RuntimeException('Safe composer nie ma zwalidowanych twierdzeń do opisania.');
    $research['claims'] = $claims;
    $sourceIds = array_values(array_filter(array_map(static fn (array $v): string => (string) ($v['source_id'] ?? ''), (array) ($researchInput['numbered_sources'] ?? []))));
    $claimIds = array_values(array_map(static fn (array $v): string => (string) $v['claim_id'], $claims));
    $unknowns = array_map(static fn (mixed $text, int $id): array => ['id' => $id, 'text' => (string) $text,
        ], array_values((array) ($research['unknowns'] ?? [])), array_keys(array_values((array) ($research['unknowns'] ?? []))));
    $input = ['revision_of_draft_version_id' => $sourceDraftId, 'composition_mode' => 'informational',
        'research_package' => $research, 'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'allowed_research_unknowns' => $unknowns, 'qc_auto_repair' => ['strategy' => 'safe_composer', 'attempt' => 3],
        'safe_composer_contract' => ['deterministic' => true, 'quotes_allowed' => false,
            'facts' => 'validated_claims_event_summary_unknowns_only', 'publication' => false]];
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $number = $database->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM article_draft_versions WHERE research_package_id=:package');
        $number->execute([':package' => (int) $source['research_package_id']]);
        $operationId = prepare_generation_operation('article_draft', $input,
            article_draft_schema($sourceIds, $claimIds, 'informational', array_column($unknowns, 'id')),
            (int) $source['post_id'], (int) $source['topic_id']);
        $database->prepare('INSERT INTO article_draft_versions (research_package_id,topic_id,post_id,generation_operation_id,version_number,composition_mode,execution_mode,parent_version_id,change_source,repair_strategy,is_active)
            VALUES (:package,:topic,:post,:operation,:version,"informational",:execution,:parent,"auto_salvage","safe_composer",0)')
            ->execute([':package' => (int) $source['research_package_id'], ':topic' => (int) $source['topic_id'], ':post' => (int) $source['post_id'],
                ':operation' => $operationId, ':version' => (int) $number->fetchColumn(), ':execution' => generation_mode(), ':parent' => $sourceDraftId]);
        $database->commit();
        return $operationId;
    } catch (Throwable $exception) { if ($database->inTransaction()) $database->rollBack(); throw $exception; }
}

/** A real QC result may be routed to a human, but never manufactured locally. */
function salvage_is_provider_proven_quality_check(array $check): bool
{
    $operationId = (int) ($check['generation_operation_id'] ?? 0);
    if ($operationId <= 0 || (string) ($check['execution_mode'] ?? '') !== 'api') return false;
    $operation = find_generation_operation($operationId);
    if (!is_array($operation) || (int) ($operation['live_request_count'] ?? 0) <= 0) return false;
    $responseId = trim((string) ($operation['provider_response_id'] ?? ''));
    return $responseId !== ''
        && !str_starts_with($responseId, 'deterministic-')
        && !str_starts_with($responseId, 'resp_local_mock');
}

/**
 * Classifies the only production-safe use of the old safe-composer route.
 * It preserves a provider-proven QC and asks for the existing human decision;
 * it never creates draft/QC/image artifacts or changes publication readiness.
 */
function salvage_classify_manual_review(int $draftId): array
{
    $draft = find_article_draft_by_id($draftId);
    if (!is_array($draft) || !in_array((string) ($draft['status'] ?? ''), ['completed', 'frozen'], true)) {
        return ['eligible' => false, 'reason' => 'Brak ukończonego szkicu źródłowego.'];
    }
    $package = find_research_package((int) ($draft['research_package_id'] ?? 0));
    if (!is_array($package) || (string) ($package['status'] ?? '') !== 'approved') {
        return ['eligible' => false, 'reason' => 'Brak zatwierdzonego researchu.'];
    }
    $statement = bueno_database()->prepare(
        'SELECT q.* FROM quality_check_runs q
         INNER JOIN generation_operations o ON o.id=q.generation_operation_id
         WHERE q.draft_version_id=:draft AND q.status="completed" AND q.execution_mode="api"
           AND o.live_request_count>0 AND o.provider_response_id<>""
           AND o.provider_response_id NOT LIKE "deterministic-%"
           AND o.provider_response_id NOT LIKE "resp_local_mock%"
         ORDER BY q.id DESC LIMIT 1'
    );
    $statement->execute([':draft' => $draftId]);
    $check = $statement->fetch();
    if (!is_array($check) || !salvage_is_provider_proven_quality_check($check)) {
        return ['eligible' => false, 'reason' => 'Brak kompletnego, rzeczywiście wykonanego QC dla aktywnego szkicu.'];
    }
    $blocks = json_decode((string) ($check['hard_blocks_json'] ?? '[]'), true);
    $blocks = is_array($blocks) ? $blocks : [];
    if ($blocks === [] || count(array_filter($blocks, static fn (mixed $block): bool => !is_array($block)
        || (string) ($block['code'] ?? '') !== 'high_risk_without_human_approval')) > 0) {
        return ['eligible' => false, 'quality' => $check,
            'reason' => 'QC zawiera blokadę, której nie wolno przekazać do samej decyzji redakcyjnej.'];
    }
    return ['eligible' => true, 'draft' => $draft, 'quality' => $check, 'blocks' => $blocks];
}

function salvage_execute_safe_composer(array $item): array
{
    if (!generation_explicit_test_mode()) {
        throw new RuntimeException('Bezpieczny kompozytor używa fixture i nie może działać w normalnym pipeline. Wymagany jest ręczny przegląd.');
    }
    $operationId = salvage_prepare_safe_composer((int) $item['draft_version_id']);
    $operation = find_generation_operation($operationId);
    if (!is_array($operation)) throw new RuntimeException('Nie znaleziono operacji safe composer.');
    if ((string) $operation['status'] !== 'completed') {
        $value = article_draft_mock_generation_value($operation);
        $input = json_decode((string) $operation['input_json'], true) ?: [];
        $summaryValue = $input['research_package']['event_summary'] ?? '';
        $eventSummary = is_array($summaryValue)
            ? implode(' ', array_values(array_filter(array_map(static fn (mixed $part): string => is_scalar($part) ? trim((string) $part) : '', $summaryValue))))
            : (string) $summaryValue;
        $title = repair_router_title_ladder($eventSummary, (array) ($input['research_package']['claims'] ?? []));
        $safeTitle = (string) $value['title'];
        $safeWords = preg_split('/\s+/u', trim($safeTitle)) ?: ['Fakty'];
        $groundedPrefix = (string) $safeWords[0];
        $value['title_variants'] = [];
        foreach ([46, 45, 44, 43, 42] as $index => $score) {
            $variantTitle = $index === 0 ? $safeTitle : mb_substr(str_repeat($groundedPrefix . ' ', $index) . $safeTitle, 0, 100);
            $value['title_variants'][] = ['title' => $variantTitle, 'relevance_score' => 10 - $index,
                'specificity_score' => 9, 'curiosity_score' => 9, 'naturalness_score' => 9,
                'click_potential_score' => $score - (37 - $index), 'total_score' => $score,
                'selected' => $index === 0, 'rationale' => 'Konserwatywny wariant powtarza wyłącznie obietnicę wspartą zwalidowanym twierdzeniem.'];
        }
        $value['title_selection_reason'] = 'Safe composer zachowuje konserwatywny tytuł oparty wyłącznie na zwalidowanym twierdzeniu i treści leadu.';
        complete_generation_operation($operationId, generation_json($value), (string) $operation['execution_mode'], ['response_id' => 'deterministic-safe-composer']);
    }
    $draft = find_article_draft_by_operation($operationId);
    if (!is_array($draft)) throw new RuntimeException('Safe composer nie utworzył wersji.');
    activate_completed_article_qc_repair((int) $draft['id']);
    $qcOperation = prepare_quality_check_operation((int) $draft['id']);
    $qc = find_generation_operation($qcOperation);
    if (is_array($qc) && (string) $qc['status'] !== 'completed') complete_generation_operation($qcOperation, generation_json(quality_check_mock_generation_value()), (string) $qc['execution_mode'], ['response_id' => 'deterministic-factual-gate']);
    $check = find_quality_check_by_operation($qcOperation);
    if (!is_array($check) || (int) $check['passed'] !== 1 || quality_active_hard_blocks($check) !== []) throw new RuntimeException('Safe composer nie przeszedł factual gate.');
    return ['draft' => $draft, 'quality' => $check, 'title' => $title ?? null];
}

/**
 * P2-D: salvage records a fallback SVG as an internal error signal only.
 * The record is marked is_fallback=1 and status='manual_review' so that
 * the publication gate blocks it and the renderer never outputs it as a final asset.
 */
function salvage_local_editorial_images(int $postId): array
{
    $created = [];
    $directory = app_post_image_path('editorial-fallback');
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Nie można utworzyć katalogu ilustracji redakcyjnych.');
    foreach (list_article_images($postId) as $image) {
        if ((string) $image['status'] === 'downloaded' && is_file(app_path((string) $image['local_path']))) continue;
        $slot = preg_replace('/[^a-z0-9-]+/i', '-', (string) $image['role'] . '-' . (string) $image['section_id']);
        $filename = 'post-' . $postId . '-' . trim((string) $slot, '-') . '.svg';
        $absolute = $directory . '/' . $filename;
        $label = htmlspecialchars('Ilustracja redakcyjna: ' . (string) $image['visual_intent'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720"><rect width="1280" height="720" fill="#10253f"/><circle cx="260" cy="360" r="150" fill="#3aaed8" opacity=".7"/><path d="M430 480 L650 210 L900 470 L1080 300" fill="none" stroke="#f4c95d" stroke-width="30"/><text x="80" y="650" fill="white" font-family="sans-serif" font-size="28">' . $label . '</text></svg>';
        if (!is_file($absolute) && file_put_contents($absolute, $svg, LOCK_EX) === false) throw new RuntimeException('Nie można zapisać lokalnej ilustracji.');
        $relative = trim(app_post_image_directory(), '/') . '/editorial-fallback/' . $filename;
        $localUrl = app_public_url($relative);
        $localCandidate = validate_source_image_candidate([
            'title' => 'Neutralna ilustracja redakcyjna', 'source_page_url' => $localUrl,
            'source_file_url' => $localUrl, 'author' => 'Mamona', 'license' => 'CC0 1.0',
            'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'attribution' => 'Ilustracja redakcyjna Mamona — CC0',
            'rights_statement_raw' => 'Lokalna ilustracja redakcyjna Mamona udostępniona jako CC0; nie jest zdjęciem wydarzenia.',
            'width' => 1280, 'height' => 720, 'provider' => 'local-editorial',
            'provider_id' => 'post-' . $postId . '-' . trim((string) $slot, '-'),
            'chosen_query' => 'local editorial fallback', 'topic_role' => (string) $image['role'],
            'third_party_warning' => false, 'identifiable_people' => false, 'trademarks_logos' => false,
        ]);
        persist_article_image($postId, ['role' => $image['role'], 'section_id' => $image['section_id'],
            'visual_intent' => $image['visual_intent'], 'expected_content' => $image['expected_content'],
            'search_queries' => json_decode((string) $image['search_queries_json'], true) ?: [], 'local_path' => $relative,
            'source_page_url' => $localUrl, 'source_file_url' => $localUrl,
            'author' => 'Mamona', 'license' => 'CC0 1.0',
            'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'attribution' => 'Ilustracja redakcyjna Mamona — CC0',
            'alt' => $image['alt'], 'caption' => 'Neutralna ilustracja redakcyjna.',
            'layout' => $image['layout'], 'status' => 'manual_review', 'width' => 1280, 'height' => 720,
            'downloaded_at' => gmdate(DATE_ATOM), 'relationship' => 'mechanism',
            'rights_manifest' => $localCandidate['rights_manifest'],
            'is_fallback' => 1,
            'search_audit' => [['level' => 'local_fallback', 'result' => 'generated_editorial_illustration']]]);
        $created[] = $relative;
    }
    refresh_article_image_rendering($postId);
    return $created;
}
