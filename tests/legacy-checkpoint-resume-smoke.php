<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_LEGACY_CHECKPOINT_SMOKE') !== '1' || trim((string) getenv('CMS_TEST_DATABASE_FILE')) === '') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_LEGACY_CHECKPOINT_SMOKE=1 i izolowaną CMS_TEST_DATABASE_FILE.\n");
    exit(2);
}
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_API_MOCK=true');
putenv('CMS_BATCH_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function legacy_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

function legacy_topic(string $token, int $index): array
{
    $titles = [
        1 => 'Astronomowie analizują kontrolowany rozbłysk gwiazdy neutronowej',
        2 => 'Chemicy opisują stabilniejszy katalizator reakcji przemysłowej',
        3 => 'Ekologowie mierzą odbudowę populacji ptaków na mokradłach',
        4 => 'Archiwalny temat bez dostępnego materiału źródłowego',
        5 => 'Ręcznie odrzucony temat kontrolny nie może wrócić do kolejki',
        6 => 'Walidator długości pola wznawia zapisany obiekt bez czekania',
    ];
    $sourceId = save_technical_source([
        'name' => "Legacy {$index}", 'website_url' => "https://legacy{$index}.example.com/",
        'feed_url' => "https://legacy{$index}.example.com/rss.xml", 'source_type' => 'rss',
        'topic_category' => 'science', 'language' => 'pl', 'credibility_level' => 5,
        'is_primary' => 1, 'is_active' => 1,
    ]);
    $source = find_technical_source($sourceId);
    $postId = persist_discovered_feed_item($source, [
        'external_id' => "{$token}-{$index}", 'source_url' => "https://legacy{$index}.example.com/a",
        'title' => $titles[$index], 'source_name' => "Legacy {$index}", 'published_at' => gmdate('Y-m-d H:i:s'),
        'summary' => 'Opis RSS przedstawia kontrolowany wynik, metodę i ograniczenia wystarczające do bezpiecznego szkicu testowego bez nowych twierdzeń.',
        'category' => 'science', 'content_hash' => hash('sha256', "legacy-{$token}-{$index}"),
    ]);
    $statement = bueno_database()->prepare('SELECT m.topic_id FROM discovered_feed_items i INNER JOIN feed_topic_memberships m ON m.feed_item_id=i.id WHERE i.post_id=:post');
    $statement->execute([':post' => $postId]);
    return ['source_id' => $sourceId, 'post_id' => $postId, 'topic_id' => (int) $statement->fetchColumn()];
}

function legacy_batch_item(array $fixture, string $key): array
{
    $batch = create_generation_batch([(int) $fixture['topic_id']], $key);
    return generation_batch_item_rows((int) $batch['id'])[0];
}

function legacy_run_stage(int $itemId): array
{
    $token = bin2hex(random_bytes(8));
    generation_batch_update_item($itemId, ['lease_token' => $token, 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 60)]);
    generation_batch_process_item($itemId, $token);
    return generation_batch_find_item($itemId);
}

function legacy_mark(array $item, string $message = 'Nie można bezpiecznie odtworzyć zwalidowanego stanu korekty.'): array
{
    generation_batch_update_item((int) $item['id'], [
        'status' => 'auto_rejected', 'outcome' => 'reconcile_missing_state', 'progress_percent' => 100,
        'wait_reason' => 'Automatycznie odrzucony: ' . $message, 'completed_at' => gmdate('Y-m-d H:i:s'),
        'lease_token' => null, 'lease_expires_at' => null,
    ]);
    generation_batch_audit((int) $item['batch_id'], (int) $item['id'], 'legacy_original_audit', 'worker', ['message' => $message]);
    return generation_batch_find_item((int) $item['id']);
}

$token = bin2hex(random_bytes(5));
$draftFixture = legacy_topic($token, 1);
$draftItem = legacy_batch_item($draftFixture, 'legacy-draft-' . $token);
$draftItem = legacy_run_stage((int) $draftItem['id']);
legacy_assert($draftItem['stage'] === 'draft' && (int) $draftItem['research_package_id'] > 0, 'Fixture nie utworzył zatwierdzonego researchu.');
$draftItem = legacy_run_stage((int) $draftItem['id']);
legacy_assert($draftItem['stage'] === 'quality_check' && (int) $draftItem['draft_version_id'] > 0, 'Fixture nie utworzył zwalidowanego szkicu.');
$draftItem = legacy_mark($draftItem);
legacy_assert(generation_batch_legacy_checkpoint((int) $draftFixture['topic_id'])['checkpoint'] === 'validated_active_draft', 'Nie wybrano aktywnego ukończonego szkicu.');
$draftResume = generation_batch_resume_legacy_item((int) $draftItem['id'], 'test');
$draftResumeItem = generation_batch_find_item((int) $draftResume['item_id']);
legacy_assert($draftResume['created'] === true && $draftResume['checkpoint'] === 'validated_active_draft'
    && $draftResumeItem['stage'] === 'quality_check'
    && (int) $draftResumeItem['research_package_id'] === (int) $draftItem['research_package_id'],
    'Wznowienie szkicu powtórzyłoby ukończony research/draft.');
$draftAgain = generation_batch_resume_legacy_item((int) $draftItem['id'], 'test');
legacy_assert($draftAgain['created'] === false && (int) $draftAgain['item']['id'] === (int) $draftResume['item_id'], 'Dwukrotne wznowienie utworzyło duplikat.');
legacy_assert((int) bueno_database()->query('SELECT COUNT(*) FROM generation_batch_audit WHERE item_id=' . (int) $draftItem['id'] . ' AND action="legacy_original_audit"')->fetchColumn() === 1,
    'Migracja usunęła stary audyt.');

$researchFixture = legacy_topic($token, 2);
$researchItem = legacy_run_stage((int) legacy_batch_item($researchFixture, 'legacy-research-' . $token)['id']);
$researchItem = legacy_mark($researchItem);
$researchResume = generation_batch_resume_legacy_item((int) $researchItem['id'], 'test');
$researchResumeItem = generation_batch_find_item((int) $researchResume['item_id']);
legacy_assert($researchResume['checkpoint'] === 'approved_research' && $researchResumeItem['stage'] === 'draft'
    && (int) $researchResumeItem['research_package_id'] > 0 && empty($researchResumeItem['draft_version_id']),
    'Checkpoint zatwierdzonego researchu nie tworzy nowego szkicu od właściwego etapu.');

$feedFixture = legacy_topic($token, 3);
$feedItem = legacy_mark(legacy_batch_item($feedFixture, 'legacy-feed-' . $token));
$feedResume = generation_batch_resume_legacy_item((int) $feedItem['id'], 'test');
$feedResumeItem = generation_batch_find_item((int) $feedResume['item_id']);
legacy_assert($feedResume['checkpoint'] === 'safe_feed' && $feedResumeItem['stage'] === 'research' && $feedResumeItem['status'] === 'queued',
    'Legalny RSS nie wznowił nowego researchu.');

$emptyFixture = legacy_topic($token, 4);
bueno_database()->prepare('UPDATE technical_sources SET is_active=0 WHERE id=:id')->execute([':id' => (int) $emptyFixture['source_id']]);
$emptyItem = legacy_mark(legacy_batch_item($emptyFixture, 'legacy-empty-' . $token));
$emptyResume = generation_batch_resume_legacy_item((int) $emptyItem['id'], 'test');
$emptyResumeItem = generation_batch_find_item((int) $emptyResume['item_id']);
legacy_assert($emptyResume['checkpoint'] === 'no_material' && $emptyResumeItem['status'] === 'auto_retry_scheduled'
    && $emptyResumeItem['outcome'] === 'source_discovery_scheduled', 'Brak danych nie zaplanował autonomicznego pozyskania.');

$manualFixture = legacy_topic($token, 5);
$manualItem = legacy_mark(legacy_batch_item($manualFixture, 'legacy-manual-' . $token));
bueno_database()->prepare('UPDATE posts SET status="rejected" WHERE id=:id')->execute([':id' => (int) $manualFixture['post_id']]);
$blocked = false;
try { generation_batch_resume_legacy_item((int) $manualItem['id'], 'test'); } catch (DomainException) { $blocked = true; }
legacy_assert($blocked, 'Ręcznie odrzucony temat został wznowiony.');

$fieldFixture = legacy_topic($token, 6);
$fieldItem = legacy_batch_item($fieldFixture, 'legacy-field-' . $token);
$fieldSchema = ['type' => 'object', 'properties' => [
    'brief' => ['type' => 'string', 'minLength' => 80, 'maxLength' => 220],
    'marker' => ['type' => 'string'],
], 'required' => ['brief', 'marker'], 'additionalProperties' => false];
$fieldOperation = prepare_generation_operation('contract_test', ['event_summary' => 'Bezpieczny kontekst testowy.'], $fieldSchema,
    (int) $fieldFixture['post_id'], (int) $fieldFixture['topic_id']);
bueno_database()->prepare('UPDATE generation_operations SET status="failed",output_json=:output,error_message=:error WHERE id=:id')->execute([
    ':output' => generation_json(['brief' => str_repeat('ą', 79), 'marker' => 'zachowaj']),
    ':error' => 'Nieprawidłowa odpowiedź Gemini API: Brief musi mieć od 80 do 220 znaków.', ':id' => $fieldOperation,
]);
generation_batch_update_item((int) $fieldItem['id'], [
    'status' => 'auto_retry_scheduled', 'stage' => 'draft', 'outcome' => 'infrastructure_retry_scheduled',
    'draft_operation_id' => $fieldOperation, 'available_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    'wait_reason' => 'Automatyczne wznowienie operacji po błędzie infrastruktury/kontraktu.',
]);
$fieldReconcile = generation_batch_reconcile_field_constraint_retries([(int) $fieldItem['id']]);
$fieldAfter = generation_batch_find_item((int) $fieldItem['id']);
legacy_assert(count($fieldReconcile) === 1 && $fieldAfter['status'] === 'queued'
    && $fieldAfter['outcome'] === 'field_constraint_repair_queued'
    && strtotime((string) $fieldAfter['available_at']) <= time() + 2
    && str_contains((string) $fieldAfter['wait_reason'], 'pola brief'),
    'Legacy validation_retry_scheduled nie został natychmiast odblokowany do field repair.');

$ui = (string) file_get_contents(dirname(__DIR__) . '/php/admin-editorial-topics.php')
    . (string) file_get_contents(dirname(__DIR__) . '/assets/js/admin-editorial-topics.js');
legacy_assert(str_contains($ui, 'Wznów nowym algorytmem') && str_contains($ui, 'topic-resume-legacy'), 'UI nie udostępnia bezpiecznej akcji wznowienia.');

echo "LEGACY_CHECKPOINT_RESUME_SMOKE_OK\n";
