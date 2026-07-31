<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_BATCH_SMOKE') !== '1') { fwrite(STDERR, "Ustaw CMS_ALLOW_BATCH_SMOKE=1, aby uruchomić test na lokalnej bazie.\n"); exit(2); }
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_API_MOCK=true');
putenv('GEMINI_MAX_ATTEMPTS=1');
putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');
putenv('CMS_SOURCE_IMAGE_MOCK=true');
putenv('CMS_BATCH_NO_SPAWN=1');
putenv('CMS_BATCH_WORKER_CONCURRENCY=2');
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
        $statement->execute([':post_id' => $postId]); $topicIds[] = (int) $statement->fetchColumn();
    }
    batch_expect(static fn () => create_generation_batch([], 'empty-' . $token), 'od 1 do 10');
    batch_expect(static fn () => create_generation_batch([999999], 'invalid-' . $token), 'nie istnieje');
    $batch = create_generation_batch([$topicIds[0]], 'one-' . $token); $batchIds[] = $batch['id'];
    batch_assert(create_generation_batch([$topicIds[0]], 'one-' . $token)['id'] === $batch['id'], 'Start nie jest idempotentny.');
    batch_expect(static fn () => create_generation_batch([$topicIds[0]], 'duplicate-' . $token), 'aktywny');
    $claim = generation_batch_claim_items(1); batch_assert(count($claim) === 1, 'Współbieżność 1 nie działa.');
    generation_batch_process_item((int) $claim[0]['id'], (string) $claim[0]['lease_token']);
    while (!(($payload = generation_batch_payload($batch['id']))['terminal'])) {
        $claims = generation_batch_claim_items(1); batch_assert($claims !== [], 'Worker nie wznowił elementu.');
        generation_batch_process_item((int) $claims[0]['id'], (string) $claims[0]['lease_token']);
    }
    batch_assert($payload['items'][0]['status'] === 'ready', 'Placeholdery zablokowały gotową propozycję: ' . $payload['items'][0]['status'] . ' / ' . $payload['items'][0]['wait_reason']);
    batch_assert($payload['items'][0]['outcome'] === 'completed_with_warnings', 'Braki grafik nie zostały oznaczone ostrzeżeniem.');
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
    batch_assert(count($workflowStatus) === 1 && $workflowStatus[0]['status'] === 'ready', 'Agregowany status nie pokazuje gotowej propozycji z ostrzeżeniami.');
    batch_assert($workflowStatus[0]['readiness'] === true && $workflowStatus[0]['proposal_url'] !== null, 'Niekompletne grafiki ukryły gotową propozycję.');
    batch_assert($workflowStatus[0]['publication_readiness'] === false, 'Placeholdery nie zablokowały publikacji.');
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
    $safeResearch = ['validation_json' => generation_json(['valid' => true, 'known_source_count' => 1, 'cited_source_count' => 1]), 'package_json' => generation_json(['recommendation' => ['decision' => 'continue', 'source_coverage' => 'sufficient'], 'contradictions' => []])];
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
    $firstItem = (int) $ten['items'][0]['id']; cancel_generation_batch_item($firstItem); batch_assert(generation_batch_find_item($firstItem)['status'] === 'cancelled', 'Cancel queued nie działa.'); retry_generation_batch_item($firstItem);
    $failureClaim = generation_batch_claim_items(1)[0];
    $failureTransport = static fn (): array => ['status' => 400, 'body' => '{"error":{"message":"bad request"}}', 'headers' => [], 'network_error' => ''];
    generation_batch_process_item((int) $failureClaim['id'], (string) $failureClaim['lease_token'], $failureTransport);
    batch_assert(generation_batch_find_item((int) $failureClaim['id'])['status'] === 'failed', 'Błąd elementu nie został odizolowany.');
    batch_assert(count(array_filter(generation_batch_item_rows($ten['id']), static fn (array $item): bool => $item['status'] === 'queued')) > 0, 'Błąd jednego elementu zatrzymał pozostałe.');
    $lastItem = (int) $ten['items'][9]['id']; cancel_generation_batch_item($lastItem);
    $rate = create_generation_batch([$topicIds[9]], 'rate-' . $token); $batchIds[] = $rate['id']; $rateClaim = generation_batch_claim_items(1)[0];
    $rateTransport = static fn (): array => ['status' => 429, 'body' => '{"error":{"message":"quota"}}', 'headers' => ['retry-after' => '1'], 'network_error' => ''];
    generation_batch_process_item((int) $rateClaim['id'], (string) $rateClaim['lease_token'], $rateTransport);
    batch_assert(generation_batch_find_item((int) $rateClaim['id'])['status'] === 'rate_limited', '429 nie ustawiło rate_limited.');
    $rateStatus = generation_workflow_statuses([$topicIds[9]])[0];
    batch_assert($rateStatus['status'] === 'rate_limited', 'Status contract does not distinguish an API rate limit.');
    batch_assert(is_string($rateStatus['available_at']) && strtotime($rateStatus['available_at']) > time(), 'Status contract does not expose the backend resume time.');
    batch_assert(is_int($rateStatus['retry_after_seconds']) && $rateStatus['retry_after_seconds'] >= 0, 'Status contract does not expose remaining backend wait time.');
    batch_assert((int) $rateStatus['progress'] === (int) $rateClaim['progress_percent'], 'Rate limit returned inconsistent progress.');
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
