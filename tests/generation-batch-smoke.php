<?php

declare(strict_types=1);

$batchDatabaseDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-batch-smoke-' . bin2hex(random_bytes(6));
if (!mkdir($batchDatabaseDirectory, 0700, true) && !is_dir($batchDatabaseDirectory)) {
    throw new RuntimeException('Nie można utworzyć izolowanej bazy testu batch.');
}
$batchDatabaseFile = $batchDatabaseDirectory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $batchDatabaseFile);
register_shutdown_function(static function () use ($batchDatabaseFile, $batchDatabaseDirectory): void {
    foreach ([$batchDatabaseFile, $batchDatabaseFile . '-wal', $batchDatabaseFile . '-shm'] as $file) {
        if (is_file($file)) @unlink($file);
    }
    if (is_dir($batchDatabaseDirectory)) @rmdir($batchDatabaseDirectory);
});

if (getenv('CMS_ALLOW_BATCH_SMOKE') !== '1') { fwrite(STDERR, "Ustaw CMS_ALLOW_BATCH_SMOKE=1, aby uruchomić test na lokalnej bazie.\n"); exit(2); }
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_PUBLIC_URL=https://example.com');
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_API_MOCK=true');
putenv('GEMINI_MAX_ATTEMPTS=1');
putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');
putenv('CMS_SOURCE_IMAGE_MOCK=true');
putenv('CMS_BATCH_NO_SPAWN=1');
putenv('CMS_BATCH_WORKER_CONCURRENCY=2');
putenv('FULL_AUTO_ENABLED=true');
require_once dirname(__DIR__) . '/php/admin-database.php';

function batch_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function batch_expect(callable $callback, string $part): void {
    try { $callback(); } catch (Throwable $exception) { batch_assert(str_contains($exception->getMessage(), $part), 'Nieoczekiwany błąd: ' . $exception->getMessage()); return; }
    throw new RuntimeException('Oczekiwany wyjątek nie wystąpił.');
}

$database = bueno_database(); $originalMode = generation_mode(); $token = bin2hex(random_bytes(6));
$sourceId = 0; $postIds = []; $batchIds = []; $fixtureImagePaths = [];
try {
    update_generation_mode('api');
    $sourceId = save_technical_source(['name' => 'Batch smoke ' . $token, 'website_url' => 'https://example.com/' . $token, 'feed_url' => 'https://example.com/' . $token . '.xml', 'source_type' => 'rss', 'topic_category' => 'science', 'language' => 'pl', 'credibility_level' => 5, 'is_primary' => 1, 'is_active' => 1]);
    $source = find_technical_source($sourceId); $topicIds = [];
    for ($i = 1; $i <= 11; $i++) {
        $postId = persist_discovered_feed_item($source, ['external_id' => $token . '-' . $i, 'source_url' => 'https://example.com/' . $token . '/article-' . $i, 'title' => 'Kontrolowany temat batch ' . $token . ' numer ' . $i, 'source_name' => 'Batch smoke', 'published_at' => gmdate('Y-m-d H:i:s'), 'summary' => 'Badacze opisali kontrolowany wynik i metodę pomiaru potrzebną do kompletnego artykułu testowego.', 'category' => 'science', 'content_hash' => hash('sha256', $token . '-' . $i)]);
        batch_assert(is_int($postId), 'Nie utworzono wpisu testowego.'); $postIds[] = $postId;
        $statement = $database->prepare('SELECT topic_id FROM feed_topic_memberships m INNER JOIN discovered_feed_items i ON i.id = m.feed_item_id WHERE i.post_id = :post_id');
        $statement->execute([':post_id' => $postId]);
        $topicId = (int) $statement->fetchColumn();
        $topicIds[] = $topicId;
        $feedStatement = $database->prepare('SELECT id FROM discovered_feed_items WHERE post_id = :post');
        $feedStatement->execute([':post' => $postId]);
        persist_verified_research_source($topicId, (int) $feedStatement->fetchColumn(), [
            'source_kind' => 'primary', 'is_primary' => 1, 'is_peer_reviewed' => 0,
            'publisher' => 'Batch smoke', 'title' => 'Kontrolowany temat batch ' . $token . ' numer ' . $i,
            'published_at' => gmdate('Y-m-d H:i:s'), 'identifier_type' => 'url',
            'identifier_value' => 'batch-' . $token . '-' . $i,
            'canonical_url' => 'https://example.com/' . $token . '/article-' . $i,
            'verification_method' => 'local_test_fixture', 'verification_status' => 'verified',
            'completeness' => 'complete', 'evidence' => ['fixture' => true],
            'content_excerpt' => 'Badacze opisali kontrolowany wynik i metodę pomiaru potrzebną do kompletnego artykułu testowego.',
        ]);
    }
    batch_expect(static fn () => create_generation_batch([], 'empty-' . $token), 'od 1 do 10');
    batch_expect(static fn () => create_generation_batch([999999], 'invalid-' . $token), 'nie istnieje');
    $batch = create_generation_batch([$topicIds[0]], 'one-' . $token); $batchIds[] = $batch['id'];
    batch_assert(create_generation_batch([$topicIds[0]], 'one-' . $token)['id'] === $batch['id'], 'Start nie jest idempotentny.');
    batch_expect(static fn () => create_generation_batch([$topicIds[0]], 'duplicate-' . $token), 'aktywny');
    // Regression: confirm public contract allows null transport for draft calls.
    // The first batch item call below passes no explicit transport; it must succeed without TypeError.
    $claim = generation_batch_claim_items(1); batch_assert(count($claim) === 1, 'Współbieżność 1 nie działa.');
    try {
        generation_batch_process_item((int) $claim[0]['id'], (string) $claim[0]['lease_token']);
    } catch (TypeError $e) { throw new RuntimeException('Draft call without transport threw TypeError: ' . $e->getMessage()); }
    $initialIterations = 0;
    while (!(($payload = generation_batch_payload($batch['id']))['terminal'])) {
        batch_assert(++$initialIterations <= 15, 'Initial batch did not terminate: ' . generation_json($payload));
        $claims = generation_batch_claim_items(1); batch_assert($claims !== [], 'Worker nie wznowił elementu: ' . generation_json($payload));
        generation_batch_process_item((int) $claims[0]['id'], (string) $claims[0]['lease_token']);
    }
    batch_assert($payload['items'][0]['status'] === 'manual_review'
        && $payload['items'][0]['outcome'] === 'completed_with_warnings', 'Incomplete image coverage must stop in manual_review: '
            . $payload['items'][0]['status'] . ' / ' . $payload['items'][0]['wait_reason'] . ' / ' . $payload['items'][0]['error_message']);
    batch_assert((int) $payload['items'][0]['research_operation_id'] > 0 && (int) $payload['items'][0]['draft_operation_id'] > 0 && (int) $payload['items'][0]['quality_operation_id'] > 0, 'Etapy pipeline nie wykonały się w pełnej kolejności.');
    $recoveryAuditStatement = $database->prepare('SELECT details_json FROM generation_batch_audit WHERE item_id=:item AND action="recovery_preflight_refused" ORDER BY id DESC LIMIT 1');
    $recoveryAuditStatement->execute([':item' => (int) $payload['items'][0]['id']]);
    $recoveryAudit = json_decode((string) $recoveryAuditStatement->fetchColumn(), true) ?: [];
    batch_assert(($recoveryAudit['status'] ?? '') === 'refused_pretransport'
        && ($recoveryAudit['reason_code'] ?? '') === 'recovery_no_supported_modules'
        && ($recoveryAudit['provider_error'] ?? true) === false
        && ($recoveryAudit['transport_attempted'] ?? true) === false,
        'Brak kandydatów recovery nie zakończył się audytowalną typed refusal przed transportem.');
    $recoveryOperationStatement = $database->prepare('SELECT COALESCE(SUM(live_request_count),0) live_calls,
        COALESCE(SUM(CASE WHEN status="running" THEN 1 ELSE 0 END),0) running_count
        FROM generation_operations WHERE post_id=:post AND operation_type="image_recovery"');
    $recoveryOperationStatement->execute([':post' => (int) $postIds[0]]);
    $recoveryOperationState = $recoveryOperationStatement->fetch() ?: [];
    batch_assert((int) ($recoveryOperationState['live_calls'] ?? -1) === 0
        && (int) ($recoveryOperationState['running_count'] ?? -1) === 0,
        'Typed refusal bez kandydatów wysłała provider call albo pozostawiła osieroconą operację running.');
    batch_assert((int) find_post($postIds[0], true)['is_published'] === 0, 'Batch opublikował artykuł.');
    $materializedPost = find_post($postIds[0], true);
    $materializedDraft = find_article_draft_by_operation((int) $payload['items'][0]['draft_operation_id']);
    $materializedDraftJson = json_decode((string) $materializedDraft['draft_json'], true, 128, JSON_THROW_ON_ERROR);
    $materializedBlocks = json_decode((string) $materializedPost['content_blocks'], true, 128, JSON_THROW_ON_ERROR);
    batch_assert(
        $materializedPost['title'] === $materializedDraftJson['title']
        && $materializedPost['status'] === 'draft'
        && $materializedBlocks !== [],
        'Gotowa propozycja pokazuje pomysł RSS zamiast ukończonego szkicu.'
    );
    batch_assert(
        count(array_filter(
            $materializedBlocks,
            static fn (array $block): bool => (string) ($block['type'] ?? '') === 'section'
        )) >= 3,
        'Gotowa propozycja nie zawiera pełnej kompozycji artykułu.'
    );
    batch_assert((int) $database->query('SELECT COUNT(*) FROM thumbnail_versions WHERE post_id = ' . (int) $postIds[0])->fetchColumn() === 0, 'Batch wywołał generator obrazu.');
    batch_assert(count(list_article_images($postIds[0])) === 4, 'Nie zapisano planu hero i ilustracji inline.');
    $database->prepare('UPDATE editorial_topics SET automatic_eligible = 1 WHERE id = :id')->execute([':id' => $topicIds[0]]);
    $workflowStatus = generation_workflow_statuses([$topicIds[0]]);
    batch_assert(count($workflowStatus) === 1 && ($workflowStatus[0]['steps']['images']['status'] ?? '') === 'manual_review', 'Incomplete image coverage has unexpected workflow state: ' . generation_json($workflowStatus));
    batch_assert($workflowStatus[0]['proposal_url'] !== null, 'Kompletny temat powinien być dostępny do przeglądu.');
    $storedPlan = find_narrative_plan_for_topic($topicIds[0]);
    $postBoundPlan = find_narrative_plan_for_post((int) $postIds[0], (int) $topicIds[0]);
    $operationCountBeforePostBoundResolve = (int) $database->query('SELECT COUNT(*) FROM generation_operations')->fetchColumn();
    $resolvedPostBoundPlan = generation_batch_finalize_stored_narrative_plan((int) $topicIds[0], (int) $postIds[0]);
    batch_assert(is_array($postBoundPlan) && is_array($resolvedPostBoundPlan)
        && (int) $resolvedPostBoundPlan['id'] === (int) $postBoundPlan['id']
        && (int) $database->query('SELECT COUNT(*) FROM generation_operations')->fetchColumn() === $operationCountBeforePostBoundResolve,
        'Post-bound plan resolution unexpectedly created a duplicate NarrativePlan operation.');
    $planContract = narrative_plan_draft_illustration_contract($storedPlan ?? []);
    $draftOperation = find_generation_operation((int) $payload['items'][0]['draft_operation_id']);
    $draftInput = json_decode((string) $draftOperation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    batch_assert((int) ($draftInput['narrative_plan']['id'] ?? 0) === (int) ($storedPlan['id'] ?? 0), 'Batch did not pass its persisted NarrativePlan to draft generation.');
    batch_assert($materializedDraftJson['illustration_plan'] === $planContract['illustration_plan'], 'Draft did not preserve the exact VisualPlan illustration contract.');
    $unmappedPlan = $storedPlan;
    $unmappedVisualPlan = json_decode((string) $unmappedPlan['visual_plan_json'], true, 128, JSON_THROW_ON_ERROR);
    $unmappedVisualPlan['inline_slots'][0]['section_anchor'] = 'body';
    $unmappedPlan['visual_plan_json'] = generation_json($unmappedVisualPlan);
    batch_expect(static fn () => narrative_plan_draft_illustration_contract($unmappedPlan), 'bez mapowania rendererowego');

    /* D3-D7: batch-level closure gate.  Start from the already locked fixture,
       supply four local, direct, accepted assets, then exercise P08/P09 through
       the same images-stage worker path used by a real batch. */
    $fixtureImageDirectory = app_path('images/posts');
    if (!is_dir($fixtureImageDirectory) && !mkdir($fixtureImageDirectory, 0775, true) && !is_dir($fixtureImageDirectory)) {
        throw new RuntimeException('Nie utworzono katalogu grafik fixture batch.');
    }
    $validImageStatement = $database->prepare(
        'UPDATE article_images
         SET source_page_url=:page, source_file_url=:file, local_path=:path,
             author="Batch smoke", license="cc0", license_url="https://creativecommons.org/publicdomain/zero/1.0/",
             attribution="Batch smoke", status="downloaded", width=1600, height=900,
             relationship="exact_subject", is_fallback=0, editorial_rejected=0,
             multimodal_accepted=1, multimodal_assessment_json=:assessment,
             rights_manifest_json=:rights, downloaded_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
         WHERE post_id=:post AND id=:id'
    );
    foreach (list_article_images($postIds[0]) as $fixtureImage) {
        $relativePath = 'images/posts/batch-smoke-' . $token . '-' . (int) $fixtureImage['id'] . '.jpg';
        $absolutePath = app_path($relativePath);
        file_put_contents($absolutePath, 'batch-smoke-image');
        $fixtureImagePaths[] = $absolutePath;
        $validImageStatement->execute([
            ':page'=>'https://example.test/batch/' . (int)$fixtureImage['id'],
            ':file'=>'https://example.test/batch/' . (int)$fixtureImage['id'] . '.jpg', ':path'=>$relativePath,
            ':assessment'=>generation_json(['decision'=>'accept']),
            ':rights'=>generation_json(['provider'=>'batch-smoke','source_page_url'=>'https://example.test/batch/' . (int)$fixtureImage['id'], 'source_file_url'=>'https://example.test/batch/' . (int)$fixtureImage['id'] . '.jpg', 'license'=>'cc0', 'attribution'=>'Batch smoke', 'topic_role'=>(string)$fixtureImage['role']]),
            ':post'=>$postIds[0], ':id'=>(int)$fixtureImage['id'],
        ]);
    }
    $floorOnlyImageId = (int) $database->query('SELECT id FROM article_images WHERE post_id=' . (int) $postIds[0] . ' AND role="inline" ORDER BY id DESC LIMIT 1')->fetchColumn();
    $database->prepare('UPDATE article_images SET status="missing", multimodal_accepted=0 WHERE id=:id')->execute([':id'=>$floorOnlyImageId]);
    $floorOnlyCoverage = article_image_coverage_state($postIds[0], $topicIds[0]);
    batch_assert(!empty($floorOnlyCoverage['publication_floor_met']) && empty($floorOnlyCoverage['coverage_complete']),
        'Fixture floor-only must reproduce incomplete 3/4 coverage before the final-stage gate.');
    $floorOnlyBatch = create_generation_batch([$topicIds[0]], 'floor-only-no-final-qc-' . $token); $batchIds[] = (int) $floorOnlyBatch['id'];
    while (!(($floorOnlyPayload = generation_batch_payload((int)$floorOnlyBatch['id']))['terminal'])) {
        $floorOnlyClaim = generation_batch_claim_items(1); batch_assert(count($floorOnlyClaim) === 1, 'Floor-only worker did not claim the images stage.');
        generation_batch_process_item((int)$floorOnlyClaim[0]['id'], (string)$floorOnlyClaim[0]['lease_token']);
    }
    batch_assert((int) $database->query('SELECT COUNT(*) FROM generation_operations WHERE post_id=' . (int) $postIds[0] . ' AND operation_type IN ("layout_plan","final_multimodal_qc")')->fetchColumn() === 0,
        'Floor-only incomplete coverage invoked LayoutPlan or Final Multimodal QC.');
    $database->prepare('UPDATE article_images SET status="downloaded", multimodal_accepted=1 WHERE id=:id')->execute([':id'=>$floorOnlyImageId]);
    batch_assert(article_image_coverage_state($postIds[0], $topicIds[0])['coverage_complete'] === true,
        'Fixture D3 nie uzyskaÅ‚a peÅ‚nego coverage przed P08/P09.');
    batch_assert((int) $database->query('SELECT COUNT(*) FROM generation_operations WHERE post_id=' . (int) $postIds[0] . ' AND operation_type="layout_plan"')->fetchColumn() === 0,
        'Fixture D3 zawieraÅ‚a LayoutPlan przed testem batch.');
    $d3Batch = create_generation_batch([$topicIds[0]], 'd3-layout-before-qc-' . $token); $batchIds[] = (int) $d3Batch['id'];
    while (!(($d3Payload = generation_batch_payload((int)$d3Batch['id']))['terminal'])) {
        $d3Claim = generation_batch_claim_items(1); batch_assert(count($d3Claim) === 1, 'D3 worker nie podjÄ…Å‚ etapu batch: ' . generation_json($d3Payload));
        generation_batch_process_item((int)$d3Claim[0]['id'], (string)$d3Claim[0]['lease_token']);
    }
    $d3Payload = generation_batch_payload((int) $d3Batch['id']); $d3Item = $d3Payload['items'][0];
    $d3AuditStatement = $database->prepare('SELECT action, details_json FROM generation_batch_audit WHERE item_id=:item AND action IN ("item_ready","item_manual_review") ORDER BY id DESC LIMIT 1');
    $d3AuditStatement->execute([':item'=>(int)$d3Item['id']]); $d3AuditRow = $d3AuditStatement->fetch() ?: []; $d3Audit = json_decode((string)($d3AuditRow['details_json'] ?? ''), true) ?: [];
    batch_assert((int)($d3Audit['layout_operation_id'] ?? 0) > 0 && (int)($d3Audit['final_multimodal_qc_operation_id'] ?? 0) > 0,
        'D3 nie utrwaliÅ‚ P08 przed P09 w audycie batch: ' . generation_json(['item'=>$d3Item, 'audit'=>$d3AuditRow]));
    $d3Layout = find_generation_operation((int)$d3Audit['layout_operation_id']);
    $d3Final = find_generation_operation((int)$d3Audit['final_multimodal_qc_operation_id']);
    batch_assert((string)($d3Layout['status'] ?? '') === 'completed' && (string)($d3Final['status'] ?? '') === 'completed'
        && (int)$d3Layout['id'] < (int)$d3Final['id'], 'D3 nie wykonaÅ‚/nie utrwaliÅ‚ P08 przed P09.');
    batch_assert(($d3Audit['final_multimodal_qc_readiness'] ?? '') === 'ready_for_manual_publish'
        && ($d3Item['status'] ?? '') === 'ready_for_preview',
        'D5: PASS P09 i gate deterministyczny nie daÅ‚y kanonicznej niepublicznej gotowoÅ›ci.');

    /* D4: retain the persisted LayoutPlan, remove the old P09 record, and
       confirm the worker runs P09 again before it exposes readiness. */
    $database->prepare('DELETE FROM final_multimodal_qc_runs WHERE post_id=:post')->execute([':post'=>$postIds[0]]);
    $d4Batch = create_generation_batch([$topicIds[0]], 'd4-persisted-layout-needs-qc-' . $token); $batchIds[] = (int) $d4Batch['id'];
    while (!(($d4Payload = generation_batch_payload((int)$d4Batch['id']))['terminal'])) {
        $d4Claim = generation_batch_claim_items(1); batch_assert(count($d4Claim) === 1, 'D4 worker nie podjÄ…Å‚ etapu batch.');
        generation_batch_process_item((int)$d4Claim[0]['id'], (string)$d4Claim[0]['lease_token']);
    }
    $d4Item = generation_batch_payload((int)$d4Batch['id'])['items'][0];
    $d4AuditStatement = $database->prepare('SELECT details_json FROM generation_batch_audit WHERE item_id=:item AND action IN ("item_ready","item_manual_review") ORDER BY id DESC LIMIT 1');
    $d4AuditStatement->execute([':item'=>(int)$d4Item['id']]); $d4Audit = json_decode((string)$d4AuditStatement->fetchColumn(), true) ?: [];
    batch_assert((int)($d4Audit['layout_operation_id'] ?? 0) > 0 && (int)($d4Audit['final_multimodal_qc_operation_id'] ?? 0) > 0
        && ($d4Audit['final_multimodal_qc_readiness'] ?? '') === 'ready_for_manual_publish',
        'D4: istniejący LayoutPlan bez P09 nie wymusiÅ‚ P09 przed gotowoÅ›ciÄ….');

    /* D6: a model FAIL must force manual review despite complete deterministic coverage. */
    $database->prepare('DELETE FROM final_multimodal_qc_runs WHERE post_id=:post')->execute([':post'=>$postIds[0]]);
    $d6Batch = create_generation_batch([$topicIds[0]], 'd6-final-qc-fail-' . $token); $batchIds[] = (int) $d6Batch['id'];
    $d6Transport = static function (array $payload, string $apiKey, string $operationKey) use ($database): array {
        $statement = $database->prepare('SELECT operation_type,input_json,output_schema_json FROM generation_operations WHERE operation_key=:key');
        $statement->execute([':key'=>$operationKey]); $operation = $statement->fetch();
        if (!is_array($operation)) throw new RuntimeException('D6 nie znalazÅ‚ operacji.');
        if ((string)$operation['operation_type'] === 'article_title_repair') {
            $repairInput = json_decode((string)$operation['input_json'], true) ?: [];
            $knownClaims = [];
            foreach ((array)($repairInput['verified_claims'] ?? []) as $claim) $knownClaims[(string)($claim['claim_id'] ?? '')] = $claim;
            $value = article_title_deterministic_fallback([
                'title'=>(string)($repairInput['current_title'] ?? ''),
                'seo_description'=>'Kontrolowany opis D6.',
            ], $knownClaims);
        } else $value = match ((string)$operation['operation_type']) {
            'research_package' => research_mock_generation_value($operation),
            'narrative_plan' => narrative_plan_mock_generation_value($operation),
            'article_draft' => article_draft_mock_generation_value($operation),
            'quality_check' => quality_check_mock_generation_value(),
            'layout_plan' => generation_mock_value(json_decode((string)$operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR)),
            'final_multimodal_qc' => array_replace(final_multimodal_qc_mock_generation_value(), ['decision'=>'FAIL', 'notes'=>['Kontrolowany fail P09.'], 'justification'=>'Kontrolowany fail P09.']),
            default => throw new RuntimeException('D6 unexpected operation ' . (string)$operation['operation_type']),
        };
        return ['status'=>200, 'body'=>generation_json(['responseId'=>'d6-final-qc-fail','candidates'=>[['content'=>['parts'=>[['text'=>generation_json($value)]]],'finishReason'=>'STOP']]]), 'headers'=>[], 'network_error'=>''];
    };
    while (!(($d6Payload = generation_batch_payload((int)$d6Batch['id']))['terminal'])) {
        $d6Claim = generation_batch_claim_items(1); batch_assert(count($d6Claim) === 1, 'D6 worker nie podjÄ…Å‚ etapu batch.');
        generation_batch_process_item((int)$d6Claim[0]['id'], (string)$d6Claim[0]['lease_token'], $d6Transport);
    }
    $d6Item = generation_batch_payload((int)$d6Batch['id'])['items'][0];
    batch_assert(($d6Item['status'] ?? '') === 'manual_review' && ($d6Item['outcome'] ?? '') === 'final_multimodal_qc_manual_review'
        && final_multimodal_qc_readiness($postIds[0]) === 'manual_review',
        'D6: FAIL P09 nie zatrzymaÅ‚ batch w manual_review: ' . generation_json($d6Item));

    /* D7: a previous mocked PASS cannot override a newly missing hero. */
    $database->prepare('UPDATE article_images SET status="missing", local_path="", multimodal_accepted=0 WHERE post_id=:post AND role="hero"')->execute([':post'=>$postIds[0]]);
    $d7Batch = create_generation_batch([$topicIds[0]], 'd7-missing-hero-after-pass-' . $token); $batchIds[] = (int) $d7Batch['id'];
    while (!(($d7Payload = generation_batch_payload((int)$d7Batch['id']))['terminal'])) {
        $d7Claim = generation_batch_claim_items(1); batch_assert(count($d7Claim) === 1, 'D7 worker nie podjÄ…Å‚ etapu batch.');
        generation_batch_process_item((int)$d7Claim[0]['id'], (string)$d7Claim[0]['lease_token']);
    }
    $d7Item = generation_batch_payload((int)$d7Batch['id'])['items'][0];
    batch_assert(($d7Item['status'] ?? '') === 'manual_review'
        && article_image_coverage_state($postIds[0], $topicIds[0])['hero_is_allowed'] === false
        && final_multimodal_qc_readiness($postIds[0]) === 'manual_review',
        'D7: brak hero zostaÅ‚ nadpisany przez wczeÅ›niejszy PASS P09.');
    batch_assert(is_array($storedPlan), 'Pełny pipeline nie utrwalił NarrativePlan.');
    $narrativeOperation = $database->prepare('SELECT id FROM generation_operations WHERE topic_id=:topic AND operation_type="narrative_plan" AND status="completed" ORDER BY id DESC LIMIT 1');
    $narrativeOperation->execute([':topic' => $topicIds[0]]);
    $narrativeOperationId = (int) $narrativeOperation->fetchColumn();
    $operationCountBeforePlanRecovery = (int) $database->query('SELECT COUNT(*) FROM generation_operations')->fetchColumn();
    $database->prepare('DELETE FROM narrative_plans WHERE id=:id')->execute([':id' => (int) $storedPlan['id']]);
    $recoveredPlan = generation_batch_finalize_stored_narrative_plan($topicIds[0]);
    batch_assert(is_array($recoveredPlan) && (int) $recoveredPlan['id'] > 0, 'Ukończona operacja NarrativePlan nie odtworzyła brakującego trwałego planu.');
    batch_assert((int) $database->query('SELECT COUNT(*) FROM generation_operations')->fetchColumn() === $operationCountBeforePlanRecovery
        && (int) find_generation_operation($narrativeOperationId)['live_request_count'] === 0,
        'Finalizacja zapisanego NarrativePlan uruchomiła nowe zapytanie zamiast użyć trwałego outputu.');
    $resumeResearch = generation_workflow_latest_approved_research($topicIds[0]);
    $resumeDraft = generation_workflow_latest('article_draft_versions', $topicIds[0]);
    batch_assert(is_array($resumeResearch) && is_array($resumeDraft)
        && in_array((string) $resumeDraft['status'], ['completed', 'frozen'], true),
        'Fixture wznowienia P06 nie zachowała zatwierdzonego researchu i locked szkicu: ' . generation_json(['research' => $resumeResearch, 'draft' => $resumeDraft]));
    $imageResume = create_topic_workflow_batch([$topicIds[0]], 'generate_all', 'locked-images-' . $token); $batchIds[] = (int) $imageResume['id'];
    $imageResumeItem = $imageResume['items'][0];
    batch_assert($imageResumeItem['status'] === 'queued' && $imageResumeItem['stage'] === 'images'
        && (int) $imageResumeItem['research_operation_id'] === (int) $resumeResearch['generation_operation_id']
        && (int) $imageResumeItem['draft_operation_id'] === (int) $resumeDraft['generation_operation_id']
        && (int) $imageResumeItem['quality_operation_id'] === (int) generation_workflow_latest_quality((int)$resumeDraft['id'])['generation_operation_id'],
        'Locked tekst z niepełnym coverage nie wznowił P06 od grafik lub ponowił wcześniejsze etapy: ' . generation_json($imageResumeItem));
    cancel_generation_batch_item((int) $imageResumeItem['id']);
    $pauseBatch = create_generation_batch([$topicIds[1]], 'pause-' . $token); $batchIds[] = (int) $pauseBatch['id'];
    $pauseItemId = (int) $pauseBatch['items'][0]['id'];
    $pauseBefore = generation_batch_find_item($pauseItemId);
    $pausedItem = generation_batch_pause_item($pauseItemId, 'batch-smoke');
    batch_assert($pausedItem['status'] === 'paused_by_operator'
        && $pausedItem['paused_from_status'] === 'queued'
        && $pausedItem['stage'] === $pauseBefore['stage']
        && $pausedItem['research_operation_id'] === $pauseBefore['research_operation_id'],
        'Pauza nie zachowała checkpointu elementu.');
    batch_expect(static fn () => generation_batch_pause_item($pauseItemId, 'batch-smoke'), 'aktywny element');
    $resumedItem = resume_generation_batch_item($pauseItemId, 'batch-smoke');
    batch_assert($resumedItem['status'] === 'research' && $resumedItem['paused_from_status'] === ''
        && $resumedItem['paused_at'] === null && $resumedItem['error_message'] === '',
        'Wznowienie nie przywróciło oczekującego etapu bez metadanych pauzy.');
    $pauseAudit = $database->prepare('SELECT COUNT(*) FROM generation_batch_audit WHERE item_id=:item AND action IN ("item_paused_by_operator","item_resumed_by_operator")');
    $pauseAudit->execute([':item' => $pauseItemId]);
    batch_assert((int) $pauseAudit->fetchColumn() === 2, 'Brakuje audytu pauzy albo wznowienia.');
    generation_batch_update_item($pauseItemId, ['status' => 'cancelled', 'completed_at' => gmdate('Y-m-d H:i:s')]);
    generation_batch_refresh_status((int) $pauseBatch['id']);

    $raceBatch = create_generation_batch([$topicIds[10]], 'pause-race-' . $token); $batchIds[] = (int) $raceBatch['id'];
    $raceItemId = (int) $raceBatch['items'][0]['id'];
    $raceClaim = generation_batch_claim_items(1); batch_assert(count($raceClaim) === 1 && (int) $raceClaim[0]['id'] === $raceItemId, 'Nie przejęto elementu do testu race pauzy.');
    $pauseDuringProvider = static function (array $payload, string $apiKey, string $operationKey) use ($raceItemId): array {
        generation_batch_pause_item($raceItemId, 'batch-smoke-race');
        return ['status' => 200, 'body' => generation_json(['responseId' => 'pause_race', 'candidates' => [[
            'content' => ['parts' => [['text' => generation_json(['claims' => [], 'sources' => [], 'recommendation' => ['decision' => 'continue', 'source_coverage' => 'sufficient'], 'contradictions' => []])]]],
            'finishReason' => 'STOP',
        ]]]), 'headers' => [], 'network_error' => ''];
    };
    generation_batch_process_item($raceItemId, (string) $raceClaim[0]['lease_token'], $pauseDuringProvider);
    $racePaused = generation_batch_find_item($raceItemId);
    batch_assert($racePaused['status'] === 'paused_by_operator' && $racePaused['stage'] === 'research'
        && (int) $racePaused['draft_operation_id'] === 0,
        'Worker przeszedł do kolejnego etapu mimo pauzy podczas odpowiedzi providera.');
    resume_generation_batch_item($raceItemId, 'batch-smoke-race');
    generation_batch_update_item($raceItemId, ['status' => 'cancelled', 'completed_at' => gmdate('Y-m-d H:i:s')]);
    generation_batch_refresh_status((int) $raceBatch['id']);

    $researchStep = create_generation_workflow_batch([$topicIds[0]], 'research', 'research-step-' . $token);
    $batchIds[] = (int) $researchStep['batch']['id'];
    batch_assert($researchStep['batch']['items'][0]['status'] === 'already_complete', 'Akcja krokowa nie wykorzystała poprawnego researchu.');
    batch_assert(create_generation_workflow_batch([$topicIds[0]], 'research', 'research-step-' . $token)['batch']['id'] === $researchStep['batch']['id'], 'Akcja krokowa nie jest idempotentna.');
    batch_expect(static fn () => generation_batch_validate_topic_ids(range(900001, 900011)), 'nie istnieje');
    $database->prepare('UPDATE editorial_topics SET automatic_eligible = 1 WHERE id = :id')->execute([':id' => $topicIds[1]]);
    foreach (['research' => 'completed', 'draft' => 'completed', 'quality' => 'completed', 'images' => 'manual_review'] as $stepAction => $expectedStatus) {
        $stepResult = create_generation_workflow_batch([$topicIds[1]], $stepAction, 'step-' . $stepAction . '-' . $token);
        $stepBatch = $stepResult['batch']; $batchIds[] = (int) $stepBatch['id'];
        batch_assert($stepBatch['terminal'] === false, 'Krok ' . $stepAction . ' nie trafił do kolejki.');
        $stepClaim = generation_batch_claim_items(1);
        batch_assert(count($stepClaim) === 1, 'Worker nie podjął kroku ' . $stepAction . '.');
        generation_batch_process_item((int) $stepClaim[0]['id'], (string) $stepClaim[0]['lease_token']);
        $stepPayload = generation_batch_payload((int) $stepBatch['id']);
        batch_assert($stepPayload['items'][0]['status'] === $expectedStatus, 'Nieprawidłowy wynik kroku ' . $stepAction . ': ' . json_encode($stepPayload['items'][0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $makeRepairTransport = static function (int &$qualityFailuresRemaining, bool $highRisk = false, string $failureType = 'language', ?int $onlyTopicId = null) use ($database): callable {
        return static function (array $payload, string $apiKey, string $operationKey) use (&$qualityFailuresRemaining, $highRisk, $failureType, $onlyTopicId, $database): array {
            $statement = $database->prepare('SELECT * FROM generation_operations WHERE operation_key = :key');
            $statement->execute([':key' => $operationKey]);
            $operation = $statement->fetch();
            if (!is_array($operation)) throw new RuntimeException('Test nie odnalazł operacji po kluczu.');
            $operationType = (string) $operation['operation_type'];
            if ($operationType === 'article_title_repair') {
                $repairInput = json_decode((string) $operation['input_json'], true) ?: [];
                $knownClaims = [];
                foreach ((array) ($repairInput['verified_claims'] ?? []) as $claim) $knownClaims[(string) ($claim['claim_id'] ?? '')] = $claim;
                $value = article_title_deterministic_fallback([
                    'title' => (string) ($repairInput['current_title'] ?? ''),
                    'seo_description' => 'Kontrolowany opis testowej wersji artykułu.',
                ], $knownClaims);
            } else $value = match ($operationType) {
                'research_package' => research_mock_generation_value($operation),
                'narrative_plan' => narrative_plan_mock_generation_value($operation),
                'article_draft' => article_draft_mock_generation_value($operation),
                'quality_check' => quality_check_mock_generation_value(),
                'image_recovery' => ['recoveries' => []],
                default => throw new RuntimeException('Nieoczekiwany etap testu auto-repair: ' . $operationType),
            };
            $matchesTopic = $onlyTopicId === null || (int) ($operation['topic_id'] ?? 0) === $onlyTopicId;
            if ($operationType === 'quality_check' && $highRisk && $matchesTopic) {
                $value['risk_flags'] = [['type' => 'medical', 'description' => 'Treść zawiera poradę medyczną wysokiego ryzyka.']];
                $value['recommendation'] = 'block';
            } elseif ($operationType === 'quality_check' && $qualityFailuresRemaining > 0 && $matchesTopic) {
                $qualityFailuresRemaining--;
                if ($failureType === 'false_quote') {
                    $value['false_quotes'] = ['„Niezweryfikowany cytat testowy”'];
                } else {
                    $value['scores']['language_readability'] = 0;
                    $value['language_issues'] = ['Skróć zawiłe zdania i uporządkuj tok wyjaśnienia.'];
                }
                $value['total_score'] = array_sum($value['scores']);
                $value['recommendation'] = 'revise';
                $value['justification'] = $failureType === 'false_quote'
                    ? 'Cytat trzeba zastąpić dosłownym evidence albo bezpieczną parafrazą.'
                    : 'Język i struktura wymagają konkretnej korekty.';
            }
            return [
                'status' => 200,
                'body' => generation_json(['responseId' => 'task24_mock', 'candidates' => [[
                    'content' => ['parts' => [['text' => generation_json($value)]]], 'finishReason' => 'STOP',
                ]]]),
                'headers' => [], 'network_error' => '',
            ];
        };
    };
    $drainRepairBatch = static function (int $batchId, callable $transport): array {
        for ($guard = 0; $guard < 20; $guard++) {
            $payload = generation_batch_payload($batchId);
            if ($payload['terminal']) return $payload;
            $claims = generation_batch_claim_items(1);
            batch_assert($claims !== [], 'Worker nie wznowił etapu auto_repair: ' . generation_json($payload['items'][0]));
            generation_batch_process_item((int) $claims[0]['id'], (string) $claims[0]['lease_token'], $transport);
        }
        throw new RuntimeException('Test wykrył potencjalną nieskończoną pętlę auto_repair.');
    };

    $database->exec('UPDATE editorial_topics SET automatic_eligible = 1 WHERE id IN (' . (int) $topicIds[2] . ',' . (int) $topicIds[3] . ',' . (int) $topicIds[4] . ')');
    $oneFailure = 1;
    $successRepair = create_generation_batch([$topicIds[2]], 'task24-success-' . $token); $batchIds[] = (int) $successRepair['id'];
    $successTransport = $makeRepairTransport($oneFailure);
    for ($stage = 0; $stage < 3; $stage++) {
        $stageClaims = generation_batch_claim_items(1);
        $currentRepairItem = generation_batch_find_item((int) $successRepair['items'][0]['id']);
        batch_assert($stageClaims !== [], 'Brak claimu TASK-24 na kroku ' . $stage . '; status: ' . (string) ($currentRepairItem['status'] ?? 'missing') . ', powód: ' . (string) ($currentRepairItem['wait_reason'] ?? '') . ', błąd: ' . (string) ($currentRepairItem['error_message'] ?? ''));
        $stageClaim = $stageClaims[0];
        generation_batch_process_item((int) $stageClaim['id'], (string) $stageClaim['lease_token'], $successTransport);
    }
    $repairItem = generation_batch_find_item((int) $successRepair['items'][0]['id']);
    batch_assert($repairItem['status'] === 'auto_repair' && (int) $repairItem['retry_count'] === 1, 'Pierwsze odwracalne QC nie ustawiło auto_repair.');
    $repairDraft = find_article_draft_by_operation((int) $repairItem['draft_operation_id']);
    batch_assert((int) $repairDraft['is_active'] === 0 && (json_decode((string) $repairDraft['draft_json'], true) ?: []) === [], 'Pusta korekta została aktywowana przed walidacją.');
    $repairCheck = generation_workflow_latest_quality((int) $repairDraft['parent_version_id']);
    $sameRepairOperation = prepare_article_qc_repair_operation((int) $repairDraft['parent_version_id'], $repairCheck, quality_check_auto_repair_decision($repairCheck), 1);
    batch_assert($sameRepairOperation === (int) $repairItem['draft_operation_id'], 'Wznowienie utworzyło podwójny draft korekty.');
    $successPayload = $drainRepairBatch((int) $successRepair['id'], $successTransport);
    batch_assert(in_array($successPayload['items'][0]['status'], ['ready_for_preview', 'ready_with_notes', 'manual_review'], true) && (int) $successPayload['items'][0]['retry_count'] === 1, 'Pipeline did not preserve QC repair result: ' . generation_json($successPayload['items'][0]));
    $activeRepairCount = (int) $database->query('SELECT COUNT(*) FROM article_draft_versions WHERE post_id = ' . (int) $postIds[2] . ' AND is_active = 1')->fetchColumn();
    batch_assert($activeRepairCount === 1, 'Po korekcie istnieje więcej niż jedna aktywna wersja.');

    $manyFailures = 99;
    $limitBatch = create_generation_batch([$topicIds[3]], 'task24-limit-' . $token); $batchIds[] = (int) $limitBatch['id'];
    $limitPayload = $drainRepairBatch((int) $limitBatch['id'], $makeRepairTransport($manyFailures));
    batch_assert(in_array($limitPayload['items'][0]['status'], ['ready_with_notes', 'manual_review'], true)
        && (int) $limitPayload['items'][0]['retry_count'] === 2,
        'Admin generate_all + API nie zakończył dwóch porażek bezpiecznym pakietem: ' . generation_json($limitPayload['items'][0]));
    batch_assert((int) $database->query('SELECT COUNT(*) FROM generation_batch_items WHERE batch_id=' . (int) $limitBatch['id'] . ' AND status="waiting_review"')->fetchColumn() === 0,
        'Admin generate_all + API pozostawił waiting_review po dwóch próbach.');
    $limitDraftCount = (int) $database->query('SELECT COUNT(*) FROM article_draft_versions WHERE post_id = ' . (int) $postIds[3])->fetchColumn();
    batch_assert($limitDraftCount === 4, 'Safe composer nie zachował wszystkich wersji naprawczych.');
    $strategyStatement = $database->prepare(
        'SELECT drafts.repair_strategy, drafts.composition_mode, operations.input_json, operations.prompt_text
         FROM article_draft_versions drafts INNER JOIN generation_operations operations ON operations.id=drafts.generation_operation_id
         WHERE drafts.post_id=:post AND drafts.change_source="auto_qc_repair" ORDER BY drafts.id'
    );
    $strategyStatement->execute([':post' => $postIds[3]]);
    $strategyVersions = $strategyStatement->fetchAll();
    batch_assert(count($strategyVersions) === 2
        && $strategyVersions[0]['repair_strategy'] === 'targeted_repair'
        && $strategyVersions[1]['repair_strategy'] === 'fresh_conservative_rewrite',
        'Dwie próby nie zapisały odrębnych strategii.');
    $targetedInput = json_decode((string) $strategyVersions[0]['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $fallbackInput = json_decode((string) $strategyVersions[1]['input_json'], true, 128, JSON_THROW_ON_ERROR);
    batch_assert(isset($targetedInput['current_version']) && !isset($fallbackInput['current_version'])
        && ($fallbackInput['fresh_rewrite_contract']['discard_previous_draft_text'] ?? false) === true
        && ($fallbackInput['fresh_rewrite_contract']['direct_quotes_allowed'] ?? true) === false
        && $strategyVersions[1]['composition_mode'] === 'informational'
        && $strategyVersions[0]['prompt_text'] !== $strategyVersions[1]['prompt_text'],
        'Druga próba powtórzyła targeted repair zamiast fresh conservative rewrite.');
    batch_assert(count(array_filter(
        (array) ($fallbackInput['research_package']['claims'] ?? []),
        static fn (array $claim): bool => ($claim['confidence'] ?? '') !== 'high'
    )) === 0, 'Fallback zawiera twierdzenia o pewności niższej niż high.');
    batch_assert((int) $database->query('SELECT COUNT(*) FROM research_packages WHERE topic_id=' . (int) $topicIds[3])->fetchColumn() === 1,
        'Fallback niepotrzebnie wygenerował wcześniejszy research ponownie.');

    $noLanguageFailures = 0;
    $riskBatch = create_generation_batch([$topicIds[4]], 'task24-risk-' . $token); $batchIds[] = (int) $riskBatch['id'];
    $riskPayload = $drainRepairBatch((int) $riskBatch['id'], $makeRepairTransport($noLanguageFailures, true));
    batch_assert(in_array($riskPayload['items'][0]['status'], ['ready_with_notes', 'manual_review'], true)
        && (int) $riskPayload['items'][0]['retry_count'] === 0, 'Wysokie ryzyko nie zostało usunięte przez safe composer.');

    $database->prepare('UPDATE editorial_topics SET automatic_eligible=1 WHERE id=:id')->execute([':id' => $topicIds[4]]);
    $manualDraftFixture = generation_workflow_latest('article_draft_versions', $topicIds[4]);
    $database->prepare('DELETE FROM quality_check_runs WHERE draft_version_id=:draft')->execute([':draft' => (int) $manualDraftFixture['id']]);
    $manualRiskBatch = create_topic_workflow_batch([$topicIds[4]], 'quality', 'task24-single-stage-risk-' . $token, 'admin');
    $batchIds[] = (int) $manualRiskBatch['id'];
    $manualRiskPayload = $drainRepairBatch((int) $manualRiskBatch['id'], $makeRepairTransport($noLanguageFailures, true));
    batch_assert($manualRiskPayload['items'][0]['status'] === 'waiting_review'
        && $manualRiskPayload['items'][0]['outcome'] === 'human_review_required',
        'Pojedynczy etap quality nie zachował waiting_review.');

    $database->prepare(
        'INSERT INTO generation_batches (batch_key,request_key,action,item_count,created_by,execution_mode,status)
         VALUES (:key,:request,"generate_all",1,"admin","api","running")'
    )->execute([':key' => 'legacy-' . $token, ':request' => 'legacy-request-' . $token]);
    $legacyBatchId = (int) $database->lastInsertId(); $batchIds[] = $legacyBatchId;
    $database->prepare(
        'INSERT INTO generation_batch_items (batch_id,topic_id,status,stage,progress_percent,research_operation_id,research_package_id,
         draft_operation_id,draft_version_id,quality_operation_id,quality_check_id,post_id,retry_count,auto_repair_count,outcome,wait_reason,completed_at)
         VALUES (:batch,:topic,"waiting_review","quality_check",82,:research_operation,:research_package,
         :draft_operation,:draft_version,:quality_operation,:quality_check,:post,2,2,"auto_repair_limit",
         "Wyczerpano limit dwóch automatycznych korekt QC.",CURRENT_TIMESTAMP)'
    )->execute([
        ':batch' => $legacyBatchId, ':topic' => $topicIds[3],
        ':research_operation' => $limitPayload['items'][0]['research_operation_id'], ':research_package' => $limitPayload['items'][0]['research_package_id'],
        ':draft_operation' => $limitPayload['items'][0]['draft_operation_id'], ':draft_version' => $limitPayload['items'][0]['draft_version_id'],
        ':quality_operation' => $limitPayload['items'][0]['quality_operation_id'], ':quality_check' => $limitPayload['items'][0]['quality_check_id'],
        ':post' => $postIds[3],
    ]);
    $legacyItemId = (int) $database->lastInsertId();
    foreach ([1, 2] as $attempt) generation_batch_audit($legacyBatchId, $legacyItemId, 'auto_repair_draft_validated', 'legacy-fixture', ['attempt' => $attempt]);
    $researchCountBeforeReconcile = (int) $database->query('SELECT COUNT(*) FROM research_packages WHERE topic_id=' . (int) $topicIds[3])->fetchColumn();
    $reconciled = generation_batch_reconcile_autonomous_items([$legacyItemId]);
    batch_assert(($reconciled[0]['decision'] ?? '') === 'safe_composer_queued'
        && generation_batch_find_item($legacyItemId)['status'] === 'auto_repair'
        && generation_batch_reconcile_autonomous_items([$legacyItemId]) === [],
        'Stary zatrzymany item nie został idempotentnie reconciled.');
    batch_assert($researchCountBeforeReconcile === (int) $database->query('SELECT COUNT(*) FROM research_packages WHERE topic_id=' . (int) $topicIds[3])->fetchColumn(),
        'Reconcile ponownie wykonał zatwierdzony research.');

    $database->exec('UPDATE editorial_topics SET automatic_eligible = 1 WHERE id IN ('
        . implode(',', array_map('intval', array_slice($topicIds, 5, 5))) . ')');
    $oneFalseQuote = 1;
    $quoteRepairBatch = create_generation_batch([$topicIds[5]], 'task24-full-auto-quote-pass-' . $token, 'full-auto');
    $batchIds[] = (int) $quoteRepairBatch['id'];
    $quoteRepairPayload = $drainRepairBatch((int) $quoteRepairBatch['id'], $makeRepairTransport($oneFalseQuote, false, 'false_quote'));
    batch_assert(in_array($quoteRepairPayload['items'][0]['status'], ['ready_for_preview', 'ready_with_notes', 'manual_review'], true)
        && (int) $quoteRepairPayload['items'][0]['auto_repair_count'] === 1,
        'Fałszywy cytat nie został automatycznie naprawiony i ponownie zweryfikowany.');

    $persistentFalseQuote = 99;
    $quoteRejectBatch = create_generation_batch([$topicIds[6]], 'task24-full-auto-quote-reject-' . $token, 'full-auto');
    $batchIds[] = (int) $quoteRejectBatch['id'];
    $quoteRejectPayload = $drainRepairBatch((int) $quoteRejectBatch['id'], $makeRepairTransport($persistentFalseQuote, false, 'false_quote'));
    batch_assert(in_array($quoteRejectPayload['items'][0]['status'], ['ready_with_notes', 'manual_review'], true)
        && (int) $quoteRejectPayload['items'][0]['auto_repair_count'] === 2,
        'Dwa nieskuteczne cykle cytatu nie zakończyły się safe composerem: '
            . generation_json([
                'status' => $quoteRejectPayload['items'][0]['status'],
                'outcome' => $quoteRejectPayload['items'][0]['outcome'],
                'auto_repair_count' => $quoteRejectPayload['items'][0]['auto_repair_count'],
                'reason' => $quoteRejectPayload['items'][0]['wait_reason'],
            ]));
    batch_assert((int) $database->query('SELECT COUNT(*) FROM generation_batch_items WHERE batch_id=' . (int) $quoteRejectBatch['id'] . ' AND status="waiting_review"')->fetchColumn() === 0,
        'Full auto pozostawił fałszywy cytat w waiting_review.');
    $quoteWorkflowStatus = generation_workflow_statuses([$topicIds[6]])[0];
    batch_assert(($quoteWorkflowStatus['steps']['images']['status'] ?? '') === 'manual_review'
        && generation_workflow_queue_state($quoteWorkflowStatus) === 'action',
        'Niekompletne grafiki muszą pozostać w kolejce wymagającej akcji: ' . generation_json($quoteWorkflowStatus));

    $noFullAutoFailures = 0;
    $continueBatch = create_generation_batch([$topicIds[7], $topicIds[8]], 'task24-full-auto-continue-' . $token, 'full-auto');
    $batchIds[] = (int) $continueBatch['id'];
    $continuePayload = $drainRepairBatch((int) $continueBatch['id'], $makeRepairTransport($noFullAutoFailures, true, 'language', $topicIds[7]));
    $continueStatuses = array_column($continuePayload['items'], 'status', 'topic_id');
    batch_assert(in_array(($continueStatuses[$topicIds[7]] ?? ''), ['ready_with_notes', 'manual_review'], true)
        && in_array(($continueStatuses[$topicIds[8]] ?? ''), ['ready_for_preview', 'ready_with_notes', 'manual_review'], true),
        'Wysokie ryzyko nie zostało bezpiecznie przepisane albo batch nie przeszedł do następnego tematu.');
    batch_assert(count(array_filter($continuePayload['items'], static fn (array $row): bool => $row['status'] === 'waiting_review')) === 0,
        'Full auto utworzył kolejkę decyzji człowieka dla ryzyka wysokiego.');

    $noResearchFailures = 1;
    $researchRetryBatch = create_generation_batch([$topicIds[9]], 'task24-full-auto-research-retry-' . $token, 'full-auto');
    $batchIds[] = (int) $researchRetryBatch['id'];
    $researchTransport = $makeRepairTransport($noResearchFailures);
    for ($stage = 0; $stage < 2; $stage++) {
        $researchClaims = generation_batch_claim_items(1);
        batch_assert($researchClaims !== [], 'Brak etapu przygotowującego regresję research retry.');
        generation_batch_process_item((int) $researchClaims[0]['id'], (string) $researchClaims[0]['lease_token'], $researchTransport);
    }
    $researchRetryItem = generation_batch_find_item((int) $researchRetryBatch['items'][0]['id']);
    $researchPackage = find_research_package_by_operation((int) $researchRetryItem['research_operation_id']);
    $researchJson = json_decode((string) $researchPackage['package_json'], true, 128, JSON_THROW_ON_ERROR);
    $researchJson['contradictions'] = [['description' => 'Kontrolowana sprzeczność źródeł wymagająca enrichmentu.']];
    $database->prepare('UPDATE research_packages SET package_json=:package WHERE id=:id')->execute([
        ':package' => generation_json($researchJson), ':id' => (int) $researchPackage['id'],
    ]);
    $qcClaim = generation_batch_claim_items(1);
    batch_assert($qcClaim !== [], 'Brak QC dla regresji sprzecznych źródeł.');
    generation_batch_process_item((int) $qcClaim[0]['id'], (string) $qcClaim[0]['lease_token'], $researchTransport);
    $queuedResearch = generation_batch_find_item((int) $researchRetryBatch['items'][0]['id']);
    batch_assert($queuedResearch['status'] === 'auto_repair' && $queuedResearch['stage'] === 'research'
        && str_contains((string) $queuedResearch['wait_reason'], 'Ponowny research 1/2'),
        'Sprzeczne źródła nie wróciły automatycznie do researchu.');
    $researchRetryPayload = $drainRepairBatch((int) $researchRetryBatch['id'], $researchTransport);
    batch_assert(in_array($researchRetryPayload['items'][0]['status'], ['ready_for_preview', 'ready_with_notes', 'manual_review'], true)
        && (int) $researchRetryPayload['items'][0]['auto_repair_count'] === 1,
        'Ponowny research nie wrócił do bezpiecznej gotowej propozycji.');

    $safeResearch = ['validation_json' => generation_json(['valid' => true, 'known_source_count' => 1, 'cited_source_count' => 1]), 'package_json' => generation_json(['recommendation' => ['decision' => 'continue', 'source_coverage' => 'sufficient'], 'contradictions' => []]), 'policy_json' => generation_json(['decision' => 'continue'])];
    batch_assert(generation_batch_research_allows_auto_approval($safeResearch), 'Bezpieczny research nie przechodzi automatycznie.');
    $unsafeResearch = $safeResearch; $unsafeResearch['package_json'] = generation_json(['recommendation' => ['decision' => 'continue', 'source_coverage' => 'insufficient'], 'contradictions' => []]);
    batch_assert(!generation_batch_research_allows_auto_approval($unsafeResearch), 'Brak pokrycia nie prowadzi do waiting_review.');
    /* Earlier branches intentionally leave retry/review items behind.  Close only
       this disposable fixture state before testing the independent 10-item lease. */
    $activeFixtureItems = $database->prepare('SELECT id FROM generation_batch_items WHERE topic_id IN (' . implode(',', array_map('intval', $topicIds)) . ') AND status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled")');
    $activeFixtureItems->execute();
    foreach ($activeFixtureItems->fetchAll() as $activeFixtureItem) {
        generation_batch_update_item((int)$activeFixtureItem['id'], ['status'=>'cancelled','stage'=>'', 'lease_token'=>null, 'lease_expires_at'=>null, 'completed_at'=>gmdate('Y-m-d H:i:s')]);
    }
    $ten = create_generation_batch(array_slice($topicIds, 0, 10), 'ten-' . $token); $batchIds[] = $ten['id'];
    $claims = generation_batch_claim_items(2); batch_assert(count($claims) === 2, 'Współbieżność 2 nie działa.'); batch_assert(generation_batch_claim_items(2) === [], 'Leasing dopuścił ponad 2 elementy.');
    foreach ($claims as $claimed) generation_batch_update_item((int) $claimed['id'], ['lease_token' => null, 'lease_expires_at' => null]);
    $database->exec('UPDATE editorial_topics SET automatic_eligible = 1 WHERE id IN (' . implode(',', array_map('intval', $topicIds)) . ')');
    $largeWorkflow = create_topic_workflow_batch($topicIds, 'research', 'eleven-' . $token); $batchIds[] = (int) $largeWorkflow['id'];
    batch_assert($largeWorkflow['item_count'] === 11, 'Konfigurowalna partia większa niż top 10 nie została przyjęta.');
    batch_assert(count(array_filter($largeWorkflow['items'], static fn (array $item): bool => $item['status'] === 'queued')) === 1, 'Większa mieszana partia nie zwróciła per-item outcomes.');
    foreach ($largeWorkflow['items'] as $largeItem) if ($largeItem['status'] === 'queued') cancel_generation_batch_item((int) $largeItem['id']);
    $firstItem = (int) $ten['items'][0]['id']; cancel_generation_batch_item($firstItem); batch_assert(generation_batch_find_item($firstItem)['status'] === 'cancelled', 'Cancel queued nie działa.');
    batch_expect(static fn () => retry_generation_batch_item($firstItem), 'Fingerprint źródeł nie zmienił się');
    $failureClaim = generation_batch_claim_items(1)[0];
    $failureTransport = static fn (): array => ['status' => 400, 'body' => '{"error":{"message":"Request contains an invalid argument."}}', 'headers' => [], 'network_error' => ''];
    generation_batch_process_item((int) $failureClaim['id'], (string) $failureClaim['lease_token'], $failureTransport);
    $failedProviderItem = generation_batch_find_item((int) $failureClaim['id']);
    batch_assert($failedProviderItem['status'] === 'failed' && $failedProviderItem['outcome'] === 'non_retryable_provider_error', 'Gemini HTTP 400 został automatycznie wznowiony zamiast zatrzymany do ręcznej decyzji.');
    batch_assert((int) gemini_article_budget_state((int) $failedProviderItem['post_id'])['used_calls'] === 1, 'Jedna odpowiedź Gemini została naliczona więcej niż raz do wspólnego budżetu.');
    batch_assert((generation_error_classification(new RuntimeException("Failed to connect to generativelanguage.googleapis.com: Couldn't connect to server"))['retryable'] ?? false) === true,
        'Błąd połączenia z Gemini nie został sklasyfikowany jako retryable transport.');
    batch_assert(count(array_filter(generation_batch_item_rows($ten['id']), static fn (array $item): bool => $item['status'] === 'queued')) > 0, 'Błąd jednego elementu zatrzymał pozostałe.');
    foreach (generation_batch_item_rows((int) $ten['id']) as $queuedItem) {
        if ((string) $queuedItem['status'] === 'queued') cancel_generation_batch_item((int) $queuedItem['id']);
    }
    $rate = create_generation_batch([$topicIds[9]], 'rate-' . $token); $batchIds[] = $rate['id']; $rateClaim = generation_batch_claim_items(1)[0];
    $rateTransport = static fn (): array => ['status' => 429, 'body' => '{"error":{"message":"quota"}}', 'headers' => ['retry-after' => '1'], 'network_error' => ''];
    generation_batch_process_item((int) $rateClaim['id'], (string) $rateClaim['lease_token'], $rateTransport);
    batch_assert(generation_batch_find_item((int) $rateClaim['id'])['status'] === 'auto_retry_scheduled', '429 nie ustawiło auto_retry_scheduled.');
    $rateStatus = generation_workflow_statuses([$topicIds[9]])[0];
    batch_assert($rateStatus['status'] === 'auto_retry_scheduled', 'Status contract does not expose autonomous retry scheduling.');
    batch_assert(is_string($rateStatus['available_at']) && strtotime($rateStatus['available_at']) > time(), 'Status contract does not expose the backend resume time.');
    batch_assert(is_int($rateStatus['retry_after_seconds']) && $rateStatus['retry_after_seconds'] >= 0, 'Status contract does not expose remaining backend wait time.');
    batch_assert((int) $rateStatus['progress'] === (int) generation_batch_find_item((int) $rateClaim['id'])['progress_percent'], 'Rate limit returned inconsistent progress.');
    update_generation_mode('manual'); $before = (int) $database->query('SELECT COUNT(*) FROM generation_batches')->fetchColumn();
    batch_expect(static fn () => create_generation_batch([$topicIds[8]], 'manual-' . $token), 'trybu API');
    batch_assert($before === (int) $database->query('SELECT COUNT(*) FROM generation_batches')->fetchColumn(), 'Manual utworzył połowiczny batch.');
    echo "GENERATION_BATCH_SMOKE_OK\n";
} finally {
    update_generation_mode($originalMode);
    foreach ($fixtureImagePaths as $fixtureImagePath) {
        if (is_file($fixtureImagePath)) @unlink($fixtureImagePath);
    }
    if ($batchIds !== []) $database->exec('DELETE FROM generation_batches WHERE id IN (' . implode(',', array_map('intval', $batchIds)) . ')');
    if ($postIds !== []) $database->exec('DELETE FROM posts WHERE id IN (' . implode(',', array_map('intval', $postIds)) . ')');
    if ($sourceId > 0) $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
}
