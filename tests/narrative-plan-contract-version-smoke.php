<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_NARRATIVE_PLAN_CONTRACT_VERSION_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_NARRATIVE_PLAN_CONTRACT_VERSION_SMOKE=1, aby uruchomić test.\n");
    exit(2);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-narrative-contract-version-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databaseFile = $directory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
register_shutdown_function(static function () use ($databaseFile, $directory): void {
    foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm'] as $file) if (is_file($file)) @unlink($file);
    if (is_dir($directory)) @rmdir($directory);
});

require_once dirname(__DIR__) . '/php/admin-database.php';

function narrative_contract_version_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = bueno_database();
$categoryId = create_post_category('Narrative contract version ' . bin2hex(random_bytes(3)));
$postId = create_post($categoryId, 'Narrative contract version fixture', 'Excerpt', 'Content');
$database->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at) VALUES (:post,"Versioned topic","versioned topic",CURRENT_TIMESTAMP)')
    ->execute([':post' => $postId]);
$topicId = (int) $database->lastInsertId();

$researchInput = ['numbered_sources' => [['source_id' => 'S1', 'title' => 'Fixture source', 'material' => 'Fixture material']]];
$researchOutput = [
    'claims' => [['claim_id' => 'C1']],
    'primary_story' => ['id'=>'A','title'=>'Główna historia fixture','claim_ids'=>['C1']],
    'context_topics' => [],
    'curiosity_topics' => [],
    'source_claims' => ['C1'],
    'source_map' => [['claim_id'=>'C1','source_ids'=>['S1']]],
];
$database->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES (:key,:post,:topic,"research_package","api","completed","fixture",:input,"{}",:hash)')
    ->execute([':key'=>bin2hex(random_bytes(16)), ':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($researchInput), ':hash'=>hash('sha256', generation_json($researchInput))]);
$researchOperationId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,validation_json,approved_at) VALUES (:topic,:post,:operation,"approved","api",:package,"{}",CURRENT_TIMESTAMP)')
    ->execute([':topic'=>$topicId, ':post'=>$postId, ':operation'=>$researchOperationId, ':package'=>generation_json($researchOutput)]);
$researchPackageId = (int) $database->lastInsertId();

$legacyInput = [
    'topic_id' => $topicId,
    'research_package_id' => $researchPackageId,
    'output_language' => ['code'=>'pl-PL', 'name'=>'język polski', 'rule'=>'Cała treść planu narracyjnego musi być napisana naturalnym językiem polskim.'],
    'research_package' => $researchOutput,
    'numbered_sources' => $researchInput['numbered_sources'],
];
$database->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES (:key,:post,:topic,"narrative_plan","api","completed","fixture",:input,:schema,:hash)')
    ->execute([':key'=>bin2hex(random_bytes(16)), ':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($legacyInput), ':schema'=>generation_json(narrative_plan_schema(['S1'], ['C1'])), ':hash'=>hash('sha256', generation_json($legacyInput))]);
$legacyOperationId = (int) $database->lastInsertId();

$newOperationId = prepare_narrative_plan_operation($topicId, $researchPackageId);
$retryOperationId = prepare_narrative_plan_operation($topicId, $researchPackageId);
$newOperation = find_generation_operation($newOperationId) ?? [];
$newInput = json_decode((string) ($newOperation['input_json'] ?? '{}'), true) ?: [];

narrative_contract_version_assert($newOperationId !== $legacyOperationId, 'P02 contract upgrade reused legacy completed NarrativePlan.');
narrative_contract_version_assert($retryOperationId === $newOperationId, 'Retry with same P02 VisualPlan contract must be idempotent.');
narrative_contract_version_assert(($newInput['visual_plan_contract_version'] ?? '') === NARRATIVE_PLAN_VISUAL_PLAN_CONTRACT_VERSION, 'Operation input does not carry the P02 VisualPlan contract version.');
narrative_contract_version_assert((string) ($newOperation['status'] ?? '') === 'prepared', 'Contract upgrade must prepare one new NarrativePlan operation.');
$mockPlan = narrative_plan_mock_generation_value($newOperation);
validate_generation_value($mockPlan, narrative_plan_schema(['S1'], ['C1']));
$mockValidation = validate_narrative_plan_output($newOperation, $mockPlan);
narrative_contract_version_assert(is_array($mockValidation) && ($mockValidation['valid'] ?? false) === true, 'V2 NarrativePlan mock does not satisfy source-backed selection and visual floor.');
narrative_contract_version_assert((int) ($mockValidation['target_length'] ?? 0) === 6500, 'V2 NarrativePlan mock does not use the longform target.');
$providerAmbiguity = $mockPlan;
$providerAmbiguity['selected_curiosity_topics'] = [['id'=>'CU1']];
$providerAmbiguity['curiosity_omitted_reason'] = 'Wszystkie tematy ciekawości zostały uwzględnione.';
narrative_contract_version_assert(narrative_plan_normalize_curiosity_omission($providerAmbiguity) && $providerAmbiguity['curiosity_omitted_reason'] === '', 'Selected C does not canonicalize contradictory omission prose.');
$providerAmbiguity = $mockPlan;
$providerAmbiguity['sections'][0]['section_id'] = 'opening_section';
$providerAmbiguity['visual_plan']['inline_slots'] = array_slice($providerAmbiguity['visual_plan']['inline_slots'], 1);
$providerAmbiguity['visual_plan']['hero_slot']['search_queries_direct'] = ['single canonical direct query'];
$providerAmbiguity['visual_slots_planned'] = 4;
$idMap = narrative_plan_normalize_section_ids($providerAmbiguity);
$addedSlots = narrative_plan_normalize_visual_floor($providerAmbiguity);
narrative_contract_version_assert(
    ($idMap['opening_section'] ?? '') === 'opening-section'
    && $addedSlots !== []
    && count($providerAmbiguity['visual_plan']['inline_slots']) === 3,
    'A donor with one canonical direct query did not restore the visual floor deterministically.'
);
narrative_contract_version_assert(validate_narrative_plan_output($newOperation, $providerAmbiguity) !== null, 'Normalized provider ambiguity does not satisfy the canonical NarrativePlan contract.');
$planId = persist_narrative_plan($newOperationId, $mockPlan);
$otherPostId = create_post($categoryId, 'Colliding topic/post identifier fixture', 'Excerpt', 'Content');
narrative_contract_version_assert(
    find_narrative_plan_for_post($otherPostId, $postId) === null,
    'Post resolver reused a different post NarrativePlan because its article_id matched topic_id.'
);
narrative_contract_version_assert(
    (int) (find_narrative_plan_for_post($postId, $topicId)['id'] ?? 0) === $planId,
    'Post resolver did not return the exact post-owned NarrativePlan.'
);
narrative_contract_version_assert(
    (int) (find_narrative_plan_for_topic($topicId)['id'] ?? 0) === $planId,
    'Topic resolver did not resolve NarrativePlan through its generation operation.'
);

echo "NARRATIVE_PLAN_CONTRACT_VERSION_SMOKE_OK\n";
