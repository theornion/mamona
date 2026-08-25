<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_NARRATIVE_PLAN_COMPLETION_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_NARRATIVE_PLAN_COMPLETION_SMOKE=1, aby uruchomić test.\n");
    exit(2);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-narrative-completion-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databaseFile = $directory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
register_shutdown_function(static function () use ($databaseFile, $directory): void {
    foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm'] as $file) if (is_file($file)) @unlink($file);
    if (is_dir($directory)) @rmdir($directory);
});

require_once dirname(__DIR__) . '/php/admin-database.php';

function narrative_completion_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function narrative_completion_operation(PDO $database, array $output, string $status = 'completed', int $postId = 0, int $topicId = 0, ?array $schemaOverride = null): int
{
    $schema = $schemaOverride ?? narrative_plan_schema(['S1'], ['C1']);
    $statement = $database->prepare('INSERT INTO generation_operations
        (operation_key,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash,post_id,topic_id)
        VALUES (:key,"narrative_plan","api",:status,"fixture",:input,:schema,:output,"fixture",:post,:topic)');
    $statement->execute([
        ':key' => bin2hex(random_bytes(16)), ':status' => $status,
        ':input' => generation_json([
            'numbered_sources' => [['source_id' => 'S1']],
            'research_package' => ['claims' => [['claim_id' => 'C1']], 'primary_story' => ['id'=>'A','claim_ids'=>['C1']], 'context_topics'=>[], 'curiosity_topics'=>[]],
        ]),
        ':schema' => generation_json($schema), ':output' => generation_json($output), ':post' => $postId ?: null, ':topic' => $topicId ?: null,
    ]);
    return (int) $database->lastInsertId();
}

$database = bueno_database();
$validOutput = narrative_plan_mock_generation_value([
    'input_json' => generation_json([
        'numbered_sources' => [['source_id' => 'S1']],
        'research_package' => ['claims' => [['claim_id' => 'C1']], 'primary_story' => ['id'=>'A','claim_ids'=>['C1']], 'context_topics'=>[], 'curiosity_topics'=>[]],
    ]),
]);

$heroSchema = narrative_plan_schema(['S1'], ['C1'])['properties']['visual_plan']['properties']['hero_slot']['properties'];
narrative_completion_assert(($heroSchema['role']['enum'] ?? null) === ['hero'], 'Schema odpowiedzi musi wymagaÄ‡ roli hero dla hero_slot.');
narrative_completion_assert(($heroSchema['section_anchor']['enum'] ?? null) === ['article'], 'Schema odpowiedzi musi wymagaÄ‡ anchora article dla hero_slot.');
narrative_completion_assert(($heroSchema['must_be_direct']['enum'] ?? null) === [true], 'Schema odpowiedzi musi wymagaÄ‡ direct hero.');
narrative_completion_assert(($heroSchema['required']['enum'] ?? null) === [true], 'Schema odpowiedzi musi wymagaÄ‡ required hero.');

$operationId = narrative_completion_operation($database, $validOutput);
$completed = complete_narrative_plan_operation($operationId, '', 'api');
narrative_completion_assert((int) ($completed['plan_id'] ?? 0) > 0, 'Ukończona operacja bez planu nie utworzyła planu.');
narrative_completion_assert((int) $database->query('SELECT COUNT(*) FROM narrative_plans WHERE batch_stage_ref=' . $operationId)->fetchColumn() === 1,
    'Plan po ukończonej operacji nie został zapisany dokładnie raz.');

$again = complete_narrative_plan_operation($operationId, '', 'api');
narrative_completion_assert((int) $again['plan_id'] === (int) $completed['plan_id']
    && (int) $database->query('SELECT COUNT(*) FROM narrative_plans WHERE batch_stage_ref=' . $operationId)->fetchColumn() === 1,
    'Ponowne domknięcie ukończonej operacji utworzyło duplikat.');

$malformed = $validOutput;
unset($malformed['visual_plan']['hero_slot']['slot_id']);
$malformedOperationId = narrative_completion_operation($database, $malformed);
try {
    complete_narrative_plan_operation($malformedOperationId, '', 'api');
    throw new RuntimeException('Nieprawidłowy zapisany output nie został odrzucony.');
} catch (RuntimeException $exception) {
    narrative_completion_assert(str_contains($exception->getMessage(), 'Ukończona operacja NarrativePlan ma nieprawidłowy zapisany output'),
        'Nieprawidłowy zapisany output nie zwrócił jednoznacznego błędu.');
}

$missingModuleClaims = $validOutput;
unset($missingModuleClaims['expansion_modules'][0]['source_claim_ids']);
$missingModuleClaimsOperationId = narrative_completion_operation($database, $missingModuleClaims);
try {
    complete_narrative_plan_operation($missingModuleClaimsOperationId, '', 'api');
    throw new RuntimeException('ModuÅ‚ expansion bez source_claim_ids nie zostaÅ‚ odrzucony.');
} catch (RuntimeException $exception) {
    narrative_completion_assert(
        str_contains($exception->getMessage(), 'NarrativePlan'),
        'Brak source_claim_ids moduÅ‚u nie zwrÃ³ciÅ‚ jednoznacznego bÅ‚Ä™du NarrativePlan.'
    );
}

$emptyModuleClaims = $validOutput;
$emptyModuleClaims['expansion_modules'][0]['source_claim_ids'] = [];
$emptyModuleClaimsOperationId = narrative_completion_operation($database, $emptyModuleClaims);
try {
    complete_narrative_plan_operation($emptyModuleClaimsOperationId, '', 'api');
    throw new RuntimeException('ModuÅ‚ expansion z pustym source_claim_ids nie zostaÅ‚ odrzucony.');
} catch (RuntimeException $exception) {
    narrative_completion_assert(
        str_contains($exception->getMessage(), 'NarrativePlan'),
        'Puste source_claim_ids moduÅ‚u nie zwrÃ³ciÅ‚o jednoznacznego bÅ‚Ä™du NarrativePlan.'
    );
}

$categoryId = create_post_category('Narrative compatibility ' . bin2hex(random_bytes(3)));
$postId = create_post($categoryId, 'Narrative legacy fixture', 'Excerpt', 'Content');
$database->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at) VALUES (:post,"Legacy topic","legacy topic",CURRENT_TIMESTAMP)')
    ->execute([':post' => $postId]);
$topicId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO generation_operations (operation_key,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES (:key,"research_package","api","completed","fixture","{}","{}","fixture")')
    ->execute([':key' => bin2hex(random_bytes(16))]);
$researchOperationId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,validation_json,approved_at) VALUES (:topic,:post,:operation,"approved","api","{}","{}",CURRENT_TIMESTAMP)')
    ->execute([':topic'=>$topicId, ':post'=>$postId, ':operation'=>$researchOperationId]);
$researchId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO generation_operations (operation_key,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES (:key,"article_draft","api","completed","fixture","{}","{}","fixture")')
    ->execute([':key' => bin2hex(random_bytes(16))]);
$draftOperationId = (int) $database->lastInsertId();
$frozenDraft = ['illustration_plan'=>['hero'=>['role'=>'hero','section_id'=>'article','visual_intent'=>'Bezpośrednia fotografia głównego zjawiska naukowego.','expected_content'=>'Główny obiekt badania','alt'=>'Główny obiekt badania','search_queries'=>['scientific subject photograph']], 'inline'=>[
    ['role'=>'inline','section_id'=>'opening','visual_intent'=>'Kontekst otwierający opis badania.','expected_content'=>'Kontekst badania','alt'=>'Kontekst badania','search_queries'=>['research context photograph']],
    ['role'=>'inline','section_id'=>'mechanism','visual_intent'=>'Metoda i dane pomiarowe badania.','expected_content'=>'Metoda badania','alt'=>'Metoda badania','search_queries'=>['research method photograph']],
    ['role'=>'inline','section_id'=>'meaning','visual_intent'=>'Znaczenie wyniku badania.','expected_content'=>'Znaczenie wyniku','alt'=>'Znaczenie wyniku','search_queries'=>['research result context']],
]]];
$database->prepare('INSERT INTO article_draft_versions (research_package_id,topic_id,post_id,generation_operation_id,version_number,composition_mode,execution_mode,status,draft_json,validation_json,completed_at) VALUES (:research,:topic,:post,:operation,1,"informational","api","frozen",:draft,"{}",CURRENT_TIMESTAMP)')
    ->execute([':research'=>$researchId, ':topic'=>$topicId, ':post'=>$postId, ':operation'=>$draftOperationId, ':draft'=>generation_json($frozenDraft)]);
$legacy = $validOutput;
$legacy['visual_plan']['hero_slot']['must_be_direct'] = false;
$legacy['visual_plan']['hero_slot']['section_anchor'] = 'intro';
$legacy['visual_plan']['inline_slots'] = [reset($legacy['visual_plan']['inline_slots'])];
$legacy['visual_plan']['inline_slots'][0]['slot_id'] = 'hero-main';
$relaxedSchema = narrative_plan_schema(['S1'], ['C1']);
$relaxedHero = &$relaxedSchema['properties']['visual_plan']['properties']['hero_slot']['properties'];
$relaxedHero['role'] = ['type'=>'string', 'enum'=>['hero', 'inline']];
$relaxedHero['section_anchor'] = ['type'=>'string', 'minLength'=>1, 'maxLength'=>100];
$relaxedHero['must_be_direct'] = ['type'=>'boolean'];
$relaxedHero['required'] = ['type'=>'boolean'];
unset($relaxedHero);
$relaxedSchema['properties']['visual_plan']['properties']['inline_slots']['minItems'] = 1;
$legacyOperationId = narrative_completion_operation($database, $legacy, 'completed', $postId, $topicId, $relaxedSchema);
$legacyCompleted = complete_narrative_plan_operation($legacyOperationId, '', 'api');
narrative_completion_assert(($legacyCompleted['visual_plan']['hero_slot']['must_be_direct'] ?? false) === true
    && ($legacyCompleted['visual_plan']['hero_slot']['section_anchor'] ?? '') === 'article'
    && count((array) ($legacyCompleted['visual_plan']['inline_slots'] ?? [])) === 3,
    'Historyczny plan nie odzyskał P02 VisualPlan z zamrożonego szkicu.');
$adapterUsage = json_decode((string) find_generation_operation($legacyOperationId)['usage_json'], true) ?: [];
narrative_completion_assert(($adapterUsage['legacy_visual_plan_adapter']['source'] ?? '') === 'frozen_draft_illustration_plan', 'Brakuje audytu kompatybilnej adaptacji historycznego planu.');

// P02 contract version must create exactly one new operation instead of reusing
// a completed NarrativePlan generated before the strict VisualPlan contract.
$versionedCategoryId = create_post_category('Narrative contract version ' . bin2hex(random_bytes(3)));
$versionedPostId = create_post($versionedCategoryId, 'Narrative contract version fixture', 'Excerpt', 'Content');
$database->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at) VALUES (:post,"Versioned topic","versioned topic",CURRENT_TIMESTAMP)')
    ->execute([':post' => $versionedPostId]);
$versionedTopicId = (int) $database->lastInsertId();
$researchInput = ['numbered_sources' => [['source_id' => 'S1', 'title' => 'Fixture source', 'material' => 'Fixture material']]];
$researchOutput = ['claims' => [['claim_id' => 'C1']]];
$database->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES (:key,:post,:topic,"research_package","api","completed","fixture",:input,"{}",:hash)')
    ->execute([':key' => bin2hex(random_bytes(16)), ':post' => $versionedPostId, ':topic' => $versionedTopicId, ':input' => generation_json($researchInput), ':hash' => hash('sha256', generation_json($researchInput))]);
$versionedResearchOperationId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,validation_json,approved_at) VALUES (:topic,:post,:operation,"approved","api",:package,"{}",CURRENT_TIMESTAMP)')
    ->execute([':topic'=>$versionedTopicId, ':post'=>$versionedPostId, ':operation'=>$versionedResearchOperationId, ':package'=>generation_json($researchOutput)]);
$versionedResearchId = (int) $database->lastInsertId();
$legacyPlanInput = [
    'topic_id' => $versionedTopicId,
    'research_package_id' => $versionedResearchId,
    'output_language' => ['code'=>'pl-PL', 'name'=>'j\u0119zyk polski', 'rule'=>'fixture'],
    'research_package' => $researchOutput,
    'numbered_sources' => $researchInput['numbered_sources'],
];
$database->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES (:key,:post,:topic,"narrative_plan","api","completed","fixture",:input,:schema,:hash)')
    ->execute([':key'=>bin2hex(random_bytes(16)), ':post'=>$versionedPostId, ':topic'=>$versionedTopicId, ':input'=>generation_json($legacyPlanInput), ':schema'=>generation_json(narrative_plan_schema(['S1'], ['C1'])), ':hash'=>hash('sha256', generation_json($legacyPlanInput))]);
$legacyVersionedOperationId = (int) $database->lastInsertId();
$newVersionedOperationId = prepare_narrative_plan_operation($versionedTopicId, $versionedResearchId);
$sameVersionedOperationId = prepare_narrative_plan_operation($versionedTopicId, $versionedResearchId);
narrative_completion_assert($newVersionedOperationId !== $legacyVersionedOperationId, 'P02 contract upgrade reused legacy completed NarrativePlan.');
narrative_completion_assert($sameVersionedOperationId === $newVersionedOperationId, 'Retry with the same P02 contract version did not reuse the prepared operation.');
$versionedInput = json_decode((string) find_generation_operation($newVersionedOperationId)['input_json'], true) ?: [];
narrative_completion_assert(($versionedInput['visual_plan_contract_version'] ?? '') === NARRATIVE_PLAN_VISUAL_PLAN_CONTRACT_VERSION, 'Nowa operacja nie zapisała wersji kontraktu VisualPlan.');

$canonicalCategoryId = create_post_category('Narrative canonicalization ' . bin2hex(random_bytes(3)));
$canonicalPostId = create_post($canonicalCategoryId, 'Narrative completed fixture', 'Excerpt', 'Content');
$database->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at) VALUES (:post,"Completed topic","completed topic",CURRENT_TIMESTAMP)')
    ->execute([':post' => $canonicalPostId]);
$canonicalTopicId = (int) $database->lastInsertId();
$canonical = $validOutput;
$canonical['visual_plan']['hero_slot']['must_be_direct'] = false;
$canonical['visual_plan']['hero_slot']['section_anchor'] = 'lead';
$canonicalOperationId = narrative_completion_operation($database, $canonical, 'completed', $canonicalPostId, $canonicalTopicId, $relaxedSchema);
$canonicalCompleted = complete_narrative_plan_operation($canonicalOperationId, '', 'api');
narrative_completion_assert(($canonicalCompleted['visual_plan']['hero_slot']['must_be_direct'] ?? false) === true
    && ($canonicalCompleted['visual_plan']['hero_slot']['section_anchor'] ?? '') === 'article', 'RozluÅºniony ukoÅ„czony hero nie zostaÅ‚ znormalizowany.');
$canonicalUsage = json_decode((string) find_generation_operation($canonicalOperationId)['usage_json'], true) ?: [];
narrative_completion_assert(($canonicalUsage['completed_hero_contract_normalization']['source'] ?? '') === 'stored_completed_narrative_plan', 'Brakuje audytu normalizacji ukoÅ„czonego hero.');
narrative_completion_assert((int) find_generation_operation($canonicalOperationId)['live_request_count'] === 0, 'Normalizacja ukoÅ„czonego planu nie moÅ¼e wykonywaÄ‡ Gemini.');

$noDirectHero = $canonical;
$noDirectHero['visual_plan']['hero_slot']['search_queries_direct'] = [];
$noDirectOperationId = narrative_completion_operation($database, $noDirectHero, 'completed', $canonicalPostId, $canonicalTopicId, $relaxedSchema);
try {
    complete_narrative_plan_operation($noDirectOperationId, '', 'api');
    throw new RuntimeException('Hero bez direct queries nie zostaÅ‚ odrzucony.');
} catch (RuntimeException $exception) {
    narrative_completion_assert(str_contains($exception->getMessage(), 'NarrativePlan'), 'Missing direct hero must be rejected.');
}

echo "NARRATIVE_PLAN_COMPLETION_SMOKE_OK\n";
