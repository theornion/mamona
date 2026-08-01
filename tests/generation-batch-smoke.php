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
$sourceId = 0; $postIds = []; $batchIds = [];
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
    $claim = generation_batch_claim_items(1); batch_assert(count($claim) === 1, 'Współbieżność 1 nie działa.');
    generation_batch_process_item((int) $claim[0]['id'], (string) $claim[0]['lease_token']);
    while (!(($payload = generation_batch_payload($batch['id']))['terminal'])) {
        $claims = generation_batch_claim_items(1); batch_assert($claims !== [], 'Worker nie wznowił elementu: ' . generation_json($payload));
        generation_batch_process_item((int) $claims[0]['id'], (string) $claims[0]['lease_token']);
    }
    batch_assert(in_array($payload['items'][0]['status'], ['ready_for_preview', 'ready_with_notes'], true), 'Pipeline nie utworzył pakietu do podglądu: ' . $payload['items'][0]['status'] . ' / ' . $payload['items'][0]['wait_reason']);
    batch_assert((int) $payload['items'][0]['research_operation_id'] > 0 && (int) $payload['items'][0]['draft_operation_id'] > 0 && (int) $payload['items'][0]['quality_operation_id'] > 0, 'Etapy pipeline nie wykonały się w pełnej kolejności.');
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
    batch_assert(count($workflowStatus) === 1 && in_array($workflowStatus[0]['status'], ['ready_for_preview', 'ready_with_notes'], true), 'Lokalny fallback nie domknął pakietu preview.');
    batch_assert($workflowStatus[0]['proposal_url'] !== null, 'Kompletny temat powinien być dostępny do przeglądu.');
    $researchStep = create_generation_workflow_batch([$topicIds[0]], 'research', 'research-step-' . $token);
    $batchIds[] = (int) $researchStep['batch']['id'];
    batch_assert($researchStep['batch']['items'][0]['status'] === 'already_complete', 'Akcja krokowa nie wykorzystała poprawnego researchu.');
    batch_assert(create_generation_workflow_batch([$topicIds[0]], 'research', 'research-step-' . $token)['batch']['id'] === $researchStep['batch']['id'], 'Akcja krokowa nie jest idempotentna.');
    batch_expect(static fn () => generation_batch_validate_topic_ids(range(900001, 900011)), 'nie istnieje');
    $database->prepare('UPDATE editorial_topics SET automatic_eligible = 1 WHERE id = :id')->execute([':id' => $topicIds[1]]);
    foreach (['research' => 'completed', 'draft' => 'completed', 'quality' => 'completed', 'images' => 'completed'] as $stepAction => $expectedStatus) {
        $stepResult = create_generation_workflow_batch([$topicIds[1]], $stepAction, 'step-' . $stepAction . '-' . $token);
        $stepBatch = $stepResult['batch']; $batchIds[] = (int) $stepBatch['id'];
        batch_assert($stepBatch['terminal'] === false, 'Krok ' . $stepAction . ' nie trafił do kolejki.');
        $stepClaim = generation_batch_claim_items(1);
        batch_assert(count($stepClaim) === 1, 'Worker nie podjął kroku ' . $stepAction . '.');
        generation_batch_process_item((int) $stepClaim[0]['id'], (string) $stepClaim[0]['lease_token']);
        $stepPayload = generation_batch_payload((int) $stepBatch['id']);
        batch_assert($stepPayload['items'][0]['status'] === $expectedStatus, 'Nieprawidłowy wynik kroku ' . $stepAction . '.');
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
                'article_draft' => article_draft_mock_generation_value($operation),
                'quality_check' => quality_check_mock_generation_value(),
                default => throw new RuntimeException('Nieoczekiwany etap testu auto-repair.'),
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
        batch_assert($stageClaims !== [], 'Brak claimu TASK-24 na kroku ' . $stage . '; status: ' . (string) ($currentRepairItem['status'] ?? 'missing') . ', powód: ' . (string) ($currentRepairItem['wait_reason'] ?? ''));
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
    batch_assert(in_array($successPayload['items'][0]['status'], ['ready_for_preview', 'ready_with_notes'], true) && (int) $successPayload['items'][0]['retry_count'] === 1, 'Pipeline nie przeszedł po pierwszej korekcie QC.');
    $activeRepairCount = (int) $database->query('SELECT COUNT(*) FROM article_draft_versions WHERE post_id = ' . (int) $postIds[2] . ' AND is_active = 1')->fetchColumn();
    batch_assert($activeRepairCount === 1, 'Po korekcie istnieje więcej niż jedna aktywna wersja.');

    $manyFailures = 99;
    $limitBatch = create_generation_batch([$topicIds[3]], 'task24-limit-' . $token); $batchIds[] = (int) $limitBatch['id'];
    $limitPayload = $drainRepairBatch((int) $limitBatch['id'], $makeRepairTransport($manyFailures));
    batch_assert($limitPayload['items'][0]['status'] === 'ready_with_notes'
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
    batch_assert($riskPayload['items'][0]['status'] === 'ready_with_notes'
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
    batch_assert(in_array($quoteRepairPayload['items'][0]['status'], ['ready_for_preview', 'ready_with_notes'], true)
        && (int) $quoteRepairPayload['items'][0]['auto_repair_count'] === 1,
        'Fałszywy cytat nie został automatycznie naprawiony i ponownie zweryfikowany.');

    $persistentFalseQuote = 99;
    $quoteRejectBatch = create_generation_batch([$topicIds[6]], 'task24-full-auto-quote-reject-' . $token, 'full-auto');
    $batchIds[] = (int) $quoteRejectBatch['id'];
    $quoteRejectPayload = $drainRepairBatch((int) $quoteRejectBatch['id'], $makeRepairTransport($persistentFalseQuote, false, 'false_quote'));
    batch_assert($quoteRejectPayload['items'][0]['status'] === 'ready_with_notes'
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
    batch_assert($quoteWorkflowStatus['status'] === 'ready_with_notes'
        && generation_workflow_queue_state($quoteWorkflowStatus) === 'ready',
        'Status full-auto błędnie wzywa do decyzji po salvage.');

    $noFullAutoFailures = 0;
    $continueBatch = create_generation_batch([$topicIds[7], $topicIds[8]], 'task24-full-auto-continue-' . $token, 'full-auto');
    $batchIds[] = (int) $continueBatch['id'];
    $continuePayload = $drainRepairBatch((int) $continueBatch['id'], $makeRepairTransport($noFullAutoFailures, true, 'language', $topicIds[7]));
    $continueStatuses = array_column($continuePayload['items'], 'status', 'topic_id');
    batch_assert(($continueStatuses[$topicIds[7]] ?? '') === 'ready_with_notes'
        && in_array(($continueStatuses[$topicIds[8]] ?? ''), ['ready_for_preview', 'ready_with_notes'], true),
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
    batch_assert(in_array($researchRetryPayload['items'][0]['status'], ['ready_for_preview', 'ready_with_notes'], true)
        && (int) $researchRetryPayload['items'][0]['auto_repair_count'] === 1,
        'Ponowny research nie wrócił do bezpiecznej gotowej propozycji.');

    $safeResearch = ['validation_json' => generation_json(['valid' => true, 'known_source_count' => 1, 'cited_source_count' => 1]), 'package_json' => generation_json(['recommendation' => ['decision' => 'continue', 'source_coverage' => 'sufficient'], 'contradictions' => []]), 'policy_json' => generation_json(['decision' => 'continue'])];
    batch_assert(generation_batch_research_allows_auto_approval($safeResearch), 'Bezpieczny research nie przechodzi automatycznie.');
    $unsafeResearch = $safeResearch; $unsafeResearch['package_json'] = generation_json(['recommendation' => ['decision' => 'continue', 'source_coverage' => 'insufficient'], 'contradictions' => []]);
    batch_assert(!generation_batch_research_allows_auto_approval($unsafeResearch), 'Brak pokrycia nie prowadzi do waiting_review.');
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
    $failureTransport = static fn (): array => ['status' => 400, 'body' => '{"error":{"message":"bad request"}}', 'headers' => [], 'network_error' => ''];
    generation_batch_process_item((int) $failureClaim['id'], (string) $failureClaim['lease_token'], $failureTransport);
    batch_assert(generation_batch_find_item((int) $failureClaim['id'])['status'] === 'auto_retry_scheduled', 'Błąd autonomicznego generate_all nie zaplanował wznowienia.');
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
    if ($batchIds !== []) $database->exec('DELETE FROM generation_batches WHERE id IN (' . implode(',', array_map('intval', $batchIds)) . ')');
    if ($postIds !== []) $database->exec('DELETE FROM posts WHERE id IN (' . implode(',', array_map('intval', $postIds)) . ')');
    if ($sourceId > 0) $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
}
