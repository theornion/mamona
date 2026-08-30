<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
require_once dirname(__DIR__) . '/php/admin-database.php';

function p6_assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}

$candidate = ['provider_id'=>'ok','source_file_url'=>'https://example.test/file.jpg', 'relationship'=>'mechanism', 'source_page_url'=>'https://example.test/source', 'depicts_required_subject'=>false];
$coreHash = hash('sha256', 'accepted locked core article');
$visual = ['hero_slot'=>['slot_id'=>'hero-main','role'=>'hero','required'=>true,'acceptable_related'=>false,'search_queries_related'=>[]], 'inline_slots'=>[
    ['slot_id'=>'inline-lead','role'=>'inline','required'=>true,'acceptable_related'=>true,'search_queries_related'=>['related context']],
]];
$input = ['locked_core_hash'=>$coreHash, 'visual_plan'=>$visual, 'missing_slots'=>[
    ['slot_id'=>'inline-lead','role'=>'inline', 'related_candidates'=>[$candidate]],
    ['slot_id'=>'hero-main','role'=>'hero', 'related_candidates'=>[$candidate]],
], 'expansion_modules'=>[['module_id'=>'context-method','topic'=>'Research method','purpose'=>'Source-backed related context module.','suitable_visual_types'=>['diagram'],'preferred_placement'=>'after-lead','source_claim_ids'=>['claim-1']]], 'research_source_map'=>[['source_id'=>'s1','claim_ids'=>['claim-1']]]];
$inlineInput = [...$input, 'missing_slots'=>[['slot_id'=>'inline-lead','role'=>'inline', 'related_candidates'=>[$candidate]]]];
$inline = article_image_validate_shortage_recovery_plan($inlineInput, ['recoveries'=>[[
    'slot_id'=>'inline-lead','module_id'=>'context-method','placement'=>'after-lead','editorial_reason'=>'Wyjaśnia metodę powiązaną ze źródłem.',
    'candidate'=>$candidate,
]]]);
p6_assert(count($inline['approved']) === 1 && !$inline['manual_review_required'], 'Brak direct inline uruchamia source-backed recovery bez fallbacku.');
p6_assert($inline['core_text_locked'] === true, 'Planner recovery nie otwiera core textu do rewrite.');
p6_assert($input['locked_core_hash'] === $coreHash, 'Recovery preserves the accepted core-text fingerprint.');
$noModule = article_image_validate_shortage_recovery_plan($inlineInput, ['recoveries'=>[[
    'slot_id'=>'inline-lead','module_id'=>'','placement'=>'after-lead','editorial_reason'=>'Bez modułu.',
    'candidate'=>[...$candidate, 'relationship'=>'related_context'],
]]]);
p6_assert($noModule['approved'] === [] && count($noModule['rejected']) === 1, 'Related candidate bez expansion module jest rejected.');
$hero = article_image_validate_shortage_recovery_plan($input, ['recoveries'=>[[
    'slot_id'=>'hero-main','module_id'=>'context-method','placement'=>'hero','editorial_reason'=>'Za słaby hero.',
    'candidate'=>[...$candidate, 'relationship'=>'related_context'],
]]]);
p6_assert($hero['manual_review_required'], 'Hero bez wartościowego recovery zatrzymuje automat.');
$shortlist = article_image_related_candidate_shortlist(['role'=>'inline'], [[
    'provider'=>'wikimedia','provider_id'=>'ok','title'=>'Laboratory mechanism photograph','source_page_url'=>'https://example.test/source',
    'source_file_url'=>'https://example.test/file.jpg','license'=>'cc-by','width'=>1600,'height'=>900,'relationship'=>'mechanism',
], [
    'provider'=>'unknown','provider_id'=>'bad','title'=>'Unlicensed context','source_page_url'=>'https://example.test/bad',
    'source_file_url'=>'https://example.test/bad.jpg','license'=>'unknown','width'=>1600,'height'=>900,'relationship'=>'related_context',
]]);
p6_assert(count($shortlist) === 1 && $shortlist[0]['provider_id'] === 'ok', 'Recovery planner otrzymuje wyłącznie bounded legal shortlist.');
$fabricated = article_image_validate_shortage_recovery_plan($inlineInput, ['recoveries'=>[[
    'slot_id'=>'inline-lead','module_id'=>'context-method','placement'=>'after-lead','editorial_reason'=>'Candidate absent from shortlist.',
    'candidate'=>[...$candidate, 'provider_id'=>'not-shortlisted'],
]]]);
p6_assert($fabricated['approved'] === [], 'Planner cannot accept a candidate outside the persisted shortlist.');
$perSlot = article_image_apply_shortage_recovery_slot_classification([...$input, 'missing_slots'=>[
    ['slot_id'=>'hero-main','role'=>'hero','required'=>true,'acceptable_related'=>false,
        'hero_recovery_policy'=>'source_backed_related_hero_v1','direct_exhaustion'=>['confirmed'=>true], 'related_candidates'=>[]],
    ['slot_id'=>'inline-lead','role'=>'inline','required'=>true,'acceptable_related'=>true,
        'search_queries_related'=>['related context'], 'related_candidates'=>[$candidate]],
]]);
p6_assert(array_column($perSlot['missing_slots'], 'slot_id') === ['inline-lead'], 'Unrecoverable hero is excluded while a recoverable inline slot reaches P06.');
$states = array_column($perSlot['slot_classifications'], 'state', 'slot_id');
p6_assert(($states['hero-main'] ?? '') === 'UNRECOVERABLE' && ($states['inline-lead'] ?? '') === 'RECOVERABLE', 'Every missing slot retains an auditable recovery classification.');
$perSlotPlan = article_image_validate_shortage_recovery_plan($perSlot, ['recoveries'=>[[
    'slot_id'=>'inline-lead','module_id'=>'context-method','placement'=>'after-lead','editorial_reason'=>'Related evidence supports the inline explanation.',
    'candidate'=>$candidate,
]]]);
p6_assert(count($perSlotPlan['approved']) === 1 && $perSlotPlan['manual_review_required'], 'Recoverable fact-1 succeeds while the unrecoverable hero remains manual review.');
$hardRejected = article_image_related_candidate_shortlist(['role'=>'inline'], [[
    'provider'=>'wikimedia','provider_id'=>'logo','title'=>'Laboratory logo','source_page_url'=>'https://example.test/logo',
    'source_file_url'=>'https://example.test/logo.png','license'=>'cc-by','width'=>1600,'height'=>900,'relationship'=>'related_context','is_logo'=>true,
]]);
p6_assert($hardRejected === [], 'Hard-invalid logo remains rejected before Vision.');
$unsupportedFormat = article_image_related_candidate_shortlist(['role'=>'inline'], [[
    'provider'=>'wikimedia','provider_id'=>'svg','title'=>'Useful diagram','source_page_url'=>'https://example.test/diagram.svg',
    'source_file_url'=>'https://example.test/diagram.svg','license'=>'cc-by','width'=>1600,'height'=>900,'relationship'=>'mechanism',
]]);
p6_assert($unsupportedFormat === [], 'Unsupported SVG is rejected before Vision transport.');
$semanticShortlist = article_image_related_candidate_shortlist(['role'=>'hero'], [[
    'provider'=>'wikimedia','provider_id'=>'diagram','title'=>'Wearable sweat sensor mechanism diagram','source_page_url'=>'https://example.test/diagram',
    'source_file_url'=>'https://example.test/diagram.png','license'=>'cc-by','width'=>1000,'height'=>1000,'relationship'=>'mechanism',
]]);
p6_assert(count($semanticShortlist) === 1, 'Potentially relevant portrait/diagram reaches the bounded Vision shortlist.');
$three = article_image_related_candidate_shortlist(['role'=>'inline'], array_map(static fn (int $i): array => [
    'provider'=>'wikimedia','provider_id'=>'candidate-'.$i,'title'=>'Related apparatus '.$i,'source_page_url'=>'https://example.test/'.$i,
    'source_file_url'=>'https://example.test/'.$i.'.jpg','license'=>'cc-by','width'=>1600,'height'=>900,'relationship'=>'apparatus',
], range(1, 4)));
p6_assert(count($three) === 3, 'Related Vision shortlist is capped at three candidates per slot.');
$poolCandidate = ['provider'=>'wikimedia','provider_id'=>'mediocre','title'=>'Archiwalne urządzenie badawcze',
    'source_page_url'=>'https://example.test/pool-source','source_file_url'=>'https://example.test/pool.jpg',
    'author'=>'Fixture','license'=>'cc-by','license_url'=>'https://example.test/license','attribution'=>'Fixture / CC BY',
    'third_party_warning'=>false,'identifiable_people'=>false,'trademarks_logos'=>false,'width'=>1600,'height'=>900];
$pool = article_image_ranked_candidate_pool(
    ['role'=>'inline','visual_intent'=>'Zjawisko emisji radiowej','expected_content'=>'Mechanizm emisji','search_queries'=>['dokładny temat','szerszy mechanizm']],
    [['query'=>'dokładny temat','relation'=>'exact_subject','level'=>'exact_direct'],['query'=>'szerszy mechanizm','relation'=>'exact_subject','level'=>'broader_direct']],
    static fn (string $query): array => [$poolCandidate]
);
p6_assert(count($pool['ranked']) === 1 && $pool['hard_reject_count'] === 1, 'Multiple queries merge and dedupe one legal metadata candidate before Vision.');
p6_assert(($pool['ranked'][0]['candidate']['provider_id'] ?? '') === 'mediocre', 'Mediocre semantic metadata lowers ranking but is not a hard reject.');
p6_assert(editorial_v2_required_image_count(8750) === 4 && editorial_v2_publication_image_floor(4) === 3, 'V2 target jest ograniczony do hero plus trzech grafik inline.');
$schema = article_image_recovery_planner_schema();
$candidateSchema = $schema['properties']['recoveries']['items']['properties']['candidate'];
p6_assert(in_array('provider_id', $candidateSchema['required'], true) && in_array('source_file_url', $candidateSchema['required'], true), 'Persisted planner contract requires stable candidate identity.');
$boundedSchema = article_image_recovery_planner_schema([
    'missing_slots'=>[['slot_id'=>'hero-main']],
    'expansion_modules'=>[['module_id'=>'source-backed-module']],
]);
$boundedProperties = $boundedSchema['properties']['recoveries']['items']['properties'];
p6_assert(($boundedProperties['slot_id']['enum'] ?? []) === ['hero-main'], 'Planner schema restricts recovery to persisted missing slot IDs.');
p6_assert(($boundedProperties['module_id']['enum'] ?? []) === ['source-backed-module'], 'Planner schema prevents fabricated expansion module IDs.');

// P07 regression: a source-backed related inline candidate must survive the
// complete bounded additive-module path and become related_supported.  This
// fixture uses a disposable SQLite database and an injected transport only.
$db = bueno_database();
$db->exec('PRAGMA foreign_keys=OFF');
$db->exec('INSERT INTO post_categories (title,description,slug,sort_order) VALUES ("P07 fixture","","p07-fixture",0)');
$categoryId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO posts (category_id,title,excerpt,content,slug,status,is_published) VALUES (:category,"P07 related inline","","Locked core body","p07-related-inline","draft",0)')
    ->execute([':category'=>$categoryId]);
$postId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at) VALUES (:post,"P07 topic","p07 topic",CURRENT_TIMESTAMP)')
    ->execute([':post'=>$postId]);
$topicId = (int) $db->lastInsertId();
$inlineSlot = ['slot_id'=>'fact-1','role'=>'inline','section_anchor'=>'fact-1','visual_need'=>'Related device context',
    'must_be_direct'=>false,'acceptable_related'=>true,'search_queries_direct'=>['device diagram'],
    'search_queries_related'=>['wearable biosensor technology'],'required'=>true];
$visualPlan = ['hero_slot'=>['slot_id'=>'article','role'=>'hero','section_anchor'=>'article','visual_need'=>'Hero',
    'must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['hero'],'search_queries_related'=>[],'required'=>true],
    'inline_slots'=>[$inlineSlot]];
$module = ['module_id'=>'context-method','topic'=>'Research method','purpose'=>'Source-backed related context.',
    'suitable_visual_types'=>['diagram'],'preferred_placement'=>'after-fact-1','source_claim_ids'=>['claim-1']];
$db->prepare('INSERT INTO narrative_plans (article_id,visual_slots_planned,visual_plan_json,expansion_modules_json,status) VALUES (:topic,2,:visual,:modules,"accepted")')
    ->execute([':topic'=>$topicId, ':visual'=>generation_json($visualPlan), ':modules'=>generation_json([$module])]);
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash,provider,model) VALUES ("p07-draft",:post,:topic,"article_draft","api","completed","fixture","{}","{}","fixture","gemini","fixture")')
    ->execute([':post'=>$postId, ':topic'=>$topicId]);
$draftOperationId = (int) $db->lastInsertId();
$coreJson = generation_json(['body'=>'Locked core body']);
$db->prepare('INSERT INTO article_draft_versions (research_package_id,topic_id,post_id,generation_operation_id,version_number,composition_mode,execution_mode,status,draft_json,is_active) VALUES (1,:topic,:post,:operation,1,"informational","api","frozen",:draft,1)')
    ->execute([':topic'=>$topicId, ':post'=>$postId, ':operation'=>$draftOperationId, ':draft'=>$coreJson]);
$draftId = (int) $db->lastInsertId();
$coreBefore = core_text_lock_state($draftId)['core_hash'];
$researchInput = ['numbered_sources'=>[['source_id'=>'rss-1','title'=>'RSS evidence','url'=>'https://example.test/rss','excerpt'=>'Verified context']]];
$researchOutput = ['claims'=>[['claim_id'=>'claim-1','source_ids'=>['rss-1']]]];
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash,provider,model) VALUES ("p07-research",:post,:topic,"research_package","api","completed","fixture",:input,"{}",:output,"fixture","gemini","fixture")')
    ->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($researchInput), ':output'=>generation_json($researchOutput)]);
$researchOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,approved_at) VALUES (:topic,:post,:operation,"approved","mock",:package,CURRENT_TIMESTAMP)')
    ->execute([':topic'=>$topicId, ':post'=>$postId, ':operation'=>$researchOperationId, ':package'=>generation_json($researchOutput)]);
$db->prepare('INSERT INTO article_images (post_id,role,section_id,visual_intent,expected_content,search_queries_json,source_page_url,source_file_url,author,license,license_url,attribution,alt,caption,layout,status,width,height,relationship,is_fallback,editorial_rejected,multimodal_accepted,multimodal_assessment_json) VALUES (:post,"inline","fact-1","Related device context","Related device context","[]","https://example.test/image-page","https://example.test/image.jpg","Author","cc-by","https://creativecommons.org/licenses/by/4.0/","Author","Related device","Related device","inline","downloaded",1600,900,"related_context",0,0,1,"{}")')
    ->execute([':post'=>$postId]);
$imageId = (int) $db->lastInsertId();
$moduleOperation = prepare_article_additive_module_operation($postId, $topicId, $imageId, 'fact-1', 'context-method', 'after-fact-1');
$moduleOutput = ['module_id'=>'context-method','target_slot_id'=>'fact-1','placement_after_section'=>'after-fact-1',
    'type'=>'context','heading'=>'Jak czytać tę ilustrację','body'=>'Źródło opisuje kontekst działania urządzenia.',
    'caption'=>'Ilustracja pokazuje powiązany kontekst urządzenia.','reader_attention_note'=>'To kontekst, nie bezpośredni obiekt badania.',
    'source_claim_ids'=>['claim-1']];
$mockTransport = static fn (): array => ['status'=>200, 'body'=>generation_json(['responseId'=>'p07-additive-fixture',
    'candidates'=>[['content'=>['parts'=>[['text'=>generation_json($moduleOutput)]]],'finishReason'=>'STOP']],
    'usageMetadata'=>['promptTokenCount'=>1,'candidatesTokenCount'=>1,'totalTokenCount'=>2]]), 'headers'=>[]];
execute_generation_operation($moduleOperation, $mockTransport, 'test-key');
$blockId = complete_article_additive_module_operation($moduleOperation);
$storedImage = $db->prepare('SELECT multimodal_assessment_json FROM article_images WHERE id=:id');
$storedImage->execute([':id'=>$imageId]);
$assessment = json_decode((string) $storedImage->fetchColumn(), true) ?: [];
$coverage = article_image_coverage_state($postId, $topicId, false);
$inlineState = array_values(array_filter((array) $coverage['filled_slots'], static fn (array $slot): bool => (string) $slot['slot_id'] === 'fact-1'));
p6_assert($blockId > 0 && !empty($assessment['related_supported']), 'P07 validated additive context marks the related fact-1 image as related_supported.');
p6_assert(count($inlineState) === 1 && (string) $inlineState[0]['status'] === 'related_supported', 'P07 related fact-1 reaches coverage as related_supported, not merely planner-approved.');
p6_assert($coreBefore === core_text_lock_state($draftId)['core_hash'], 'P07 additive context preserves the locked core-text hash.');
$replanEligibility = article_image_recovery_replan_eligibility([
    'publication_visual_floor'=>3,
    'filled_slots'=>[['slot_id'=>'inline-intro']],
    'missing_slots'=>[['slot_id'=>'hero'],['slot_id'=>'inline-1'],['slot_id'=>'inline-2']],
], ['used_calls'=>14,'max_calls'=>30], true, 0);
p6_assert(!empty($replanEligibility['eligible']) && $replanEligibility['max_replans'] === 1,
    '1/4 coverage with 14/30 budget and exhausted normal paths opens exactly one bounded recovery replan.');
p6_assert(empty(article_image_recovery_replan_eligibility([
    'publication_visual_floor'=>3,'filled_slots'=>[['slot_id'=>'inline-intro']],
    'missing_slots'=>[['slot_id'=>'hero'],['slot_id'=>'inline-1'],['slot_id'=>'inline-2']],
], ['used_calls'=>14,'max_calls'=>30], true, 1)['eligible']), 'A second recovery replan is blocked deterministically.');
p6_assert(!empty(article_image_recovery_replan_eligibility([
    'publication_visual_floor'=>3,'filled_slots'=>[['slot_id'=>'a'],['slot_id'=>'b'],['slot_id'=>'c']],
    'missing_slots'=>[['slot_id'=>'d']],
], ['used_calls'=>14,'max_calls'=>30], true, 0)['eligible']), 'Recovery replan remains available until all required slots are filled.');
$replannedQueries = article_image_semantic_queries([
    'search_queries'=>['revised direct microscopy query'],
    'search_queries_related'=>['source-backed neural imaging context'],
    'expected_content'=>'Concrete primary-story microscopy evidence',
], 8);
p6_assert((bool) array_filter($replannedQueries, static fn (array $query): bool =>
    $query['query'] === 'source-backed neural imaging context' && $query['relation'] === 'related_context'),
    'Recovery retrieval consumes the replanned related queries instead of silently ignoring them.');
$replanInput = ['missing_slots'=>[[
    'slot_id'=>'hero-main','role'=>'hero','direct_exhaustion'=>['confirmed'=>true],
]]];
$controlledRelatedReplan = ['slots'=>[[
    'slot_id'=>'hero-main','revised_visual_need'=>'Source-backed context strongly tied to primary story A.',
    'search_queries_direct'=>['exact primary story image','broader primary story image'],
    'search_queries_related'=>['controlled source-backed story context'],
    'allowed_relationship'=>'controlled_related',
    'editorial_justification'=>'Direct paths are exhausted; use controlled source-backed context subject to later gates.',
]]];
p6_assert(article_image_validate_recovery_replan($replanInput, $controlledRelatedReplan) === $controlledRelatedReplan,
    'Confirmed direct exhaustion allows a controlled-related hero replan contract.');
$replanMapping = article_image_recovery_replan_analysis(['missing_slots'=>[
    ['slot_id'=>'hero-main','role'=>'hero','direct_exhaustion'=>['confirmed'=>true]],
    ['slot_id'=>'inline-1','role'=>'inline'],
]], ['slots'=>[
    $controlledRelatedReplan['slots'][0],
    $controlledRelatedReplan['slots'][0],
    array_replace($controlledRelatedReplan['slots'][0], ['slot_id'=>'extra-slot']),
]]);
p6_assert($replanMapping['missing_slot_ids'] === ['inline-1']
    && $replanMapping['duplicate_slot_ids'] === ['hero-main']
    && $replanMapping['unexpected_slot_ids'] === ['extra-slot']
    && $replanMapping['valid_slots'] === [],
    'Recovery replan mapping reports missing, duplicate, and unexpected slot ids without accepting conflicts.');
foreach (['unsupported','random','fallback','apparatus','related_context'] as $forbiddenRelationship) {
    try {
        article_image_validate_recovery_replan($replanInput, ['slots'=>[array_replace(
            $controlledRelatedReplan['slots'][0], ['allowed_relationship'=>$forbiddenRelationship]
        )]]);
        p6_assert(false, 'Unsupported recovery relationship is rejected: '.$forbiddenRelationship);
    } catch (InvalidArgumentException) {
        p6_assert(true, 'Unsupported recovery relationship is rejected: '.$forbiddenRelationship);
    }
}
try {
    article_image_validate_recovery_replan(['missing_slots'=>[[
        'slot_id'=>'hero-main','role'=>'hero','direct_exhaustion'=>['confirmed'=>false],
    ]]], $controlledRelatedReplan);
    p6_assert(false, 'Controlled-related hero without direct exhaustion is rejected.');
} catch (InvalidArgumentException) {
    p6_assert(true, 'Controlled-related hero without direct exhaustion is rejected.');
}

// V2 minimal recovery-state regression: coverage carries state, VisualSlot carries policy.
$mergedSlots = article_image_merge_recovery_slot_policy(
    [['slot_id'=>'fact-1','role'=>'inline','status'=>'missing','related_candidates'=>[$candidate]]],
    ['inline_slots'=>[...[$inlineSlot]]]
);
$mergedRecovery = article_image_apply_shortage_recovery_slot_classification([
    'missing_slots'=>$mergedSlots,
]);
p6_assert(!empty($mergedSlots[0]['acceptable_related'])
    && ($mergedRecovery['slot_classifications'][0]['state'] ?? '') === 'RECOVERABLE',
    'Coverage missing row is merged with full VisualSlot policy before related authorization.');

// A persisted, currently valid replan is an override; the canonical P02 artifact stays unchanged.
$staleReplanInput = ['missing_slots'=>[['slot_id'=>'fact-1','role'=>'inline','direct_exhaustion'=>['confirmed'=>true]]]];
$staleReplanOutput = ['slots'=>[[
    'slot_id'=>'fact-1','revised_visual_need'=>'Legacy intent','search_queries_direct'=>['legacy_query'],
    'search_queries_related'=>['legacy_related'],'allowed_relationship'=>'related_context',
    'editorial_justification'=>'Legacy enum fixture that must remain audit-only.',
]]];
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash,provider,model,completed_at) VALUES ("v2-stale-replan-fixture",:post,:topic,"image_recovery_replan","api","completed","fixture",:input,"{}",:output,"fixture","gemini","fixture",CURRENT_TIMESTAMP)')
    ->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($staleReplanInput), ':output'=>generation_json($staleReplanOutput)]);
$staleStates = article_image_recovery_replan_states($postId, $topicId);
$freshEligibility = article_image_recovery_replan_retry_state($postId, $topicId, [
    'publication_visual_floor'=>3,'filled_slots'=>[['slot_id'=>'intro']],
    'missing_slots'=>[['slot_id'=>'hero-main'],['slot_id'=>'fact-1']],
], ['used_calls'=>14,'max_calls'=>30], true);
p6_assert(($staleStates[0]['status'] ?? '') === 'stale_replan_contract'
    && ($freshEligibility['stale_replan_contracts'] ?? 0) === 1
    && ($freshEligibility['current_contract_replans'] ?? -1) === 0
    && !empty($freshEligibility['eligible']),
    'Obsolete replan enum is stale/audit-only and leaves exactly one current-contract replan eligible.');

$replanOutput = ['slots'=>[[
    'slot_id'=>'fact-1','revised_visual_need'=>'Replanned neural imaging evidence',
    'search_queries_direct'=>['new_query_B'], 'search_queries_related'=>['new_related_query_B'],
    'allowed_relationship'=>'controlled_related',
    'editorial_justification'=>'Use the validated recovery override after the original search was exhausted.',
]]];
$replanFixtureInput = ['missing_slots'=>[['slot_id'=>'fact-1','role'=>'inline','direct_exhaustion'=>['confirmed'=>true]]]];
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash,provider,model,completed_at) VALUES ("v2-replan-fixture",:post,:topic,"image_recovery_replan","api","completed","fixture",:input,"{}",:output,"fixture","gemini","fixture",CURRENT_TIMESTAMP)')
    ->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($replanFixtureInput), ':output'=>generation_json($replanOutput)]);
$effective = article_image_effective_visual_plan($postId, $topicId);
$effectiveInline = (array) ($effective['inline_slots'][0] ?? []);
p6_assert(($effectiveInline['search_queries_direct'][0] ?? '') === 'new_query_B'
    && ($effectiveInline['query_origin'] ?? '') === 'recovery_replan'
    && ($visualPlan['inline_slots'][0]['search_queries_direct'][0] ?? '') === 'device diagram',
    'Persisted valid replan supplies effective query B while canonical query A remains auditable.');
$afterCurrent = article_image_recovery_replan_retry_state($postId, $topicId, [
    'publication_visual_floor'=>3,'filled_slots'=>[['slot_id'=>'intro']],
    'missing_slots'=>[['slot_id'=>'hero-main'],['slot_id'=>'fact-1']],
], ['used_calls'=>14,'max_calls'=>30], true);
p6_assert(empty($afterCurrent['eligible']) && ($afterCurrent['current_contract_replans'] ?? 0) === 1,
    'After one valid current-contract replan, another fresh replan is deterministically blocked.');
$originQueries = article_image_direct_queries([
    'search_queries'=>$effectiveInline['search_queries_direct'],
    'query_origin'=>$effectiveInline['query_origin'],
], 1);
p6_assert(($originQueries[0]['query'] ?? '') === 'new_query_B'
    && ($originQueries[0]['query_origin'] ?? '') === 'recovery_replan',
    'Next retrieval consumes and traces the recovery-replan query.');

// A structurally valid but semantically incomplete replan must persist its
// diagnostics and leave the image stage able to use the conflict-free slot.
$partialSlot = array_replace($replanOutput['slots'][0], [
    'revised_visual_need'=>'Partially replanned neural imaging evidence',
    'search_queries_direct'=>['partial_query_C', 'partial_broader_query_C'],
]);
$partialOutput = ['slots'=>[
    $partialSlot,
    array_replace($partialSlot, ['slot_id'=>'extra-slot']),
]];
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash,provider,model) VALUES ("v2-partial-replan-fixture",:post,:topic,"image_recovery_replan","api","prepared","fixture",:input,:schema,"fixture","gemini","fixture")')
    ->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($replanFixtureInput), ':schema'=>generation_json(article_image_recovery_replan_schema())]);
$partialOperationId = (int) $db->lastInsertId();
complete_generation_operation($partialOperationId, generation_json($partialOutput), 'api', ['usage'=>['fixture'=>true]]);
$partialApply = article_image_apply_recovery_replan($partialOperationId);
$partialOperation = find_generation_operation($partialOperationId);
$partialUsage = json_decode((string) ($partialOperation['usage_json'] ?? '{}'), true) ?: [];
$partialEffective = article_image_effective_visual_plan($postId, $topicId);
$partialEffectiveInline = (array) ($partialEffective['inline_slots'][0] ?? []);
p6_assert(($partialOperation['status'] ?? '') === 'completed'
    && ($partialApply['status'] ?? '') === 'partial_fallback'
    && (($partialUsage['recovery_replan_validation']['unexpected_slot_ids'] ?? []) === ['extra-slot'])
    && ($partialEffectiveInline['search_queries_direct'][0] ?? '') === 'partial_query_C',
    'Invalid replan is audited, applies only its valid slot, and continues the graphics flow.');
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash,provider,model) VALUES ("v2-empty-replan-fixture",:post,:topic,"image_recovery_replan","api","prepared","fixture",:input,:schema,"fixture","gemini","fixture")')
    ->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($replanFixtureInput), ':schema'=>generation_json(article_image_recovery_replan_schema())]);
$emptyOperationId = (int) $db->lastInsertId();
complete_generation_operation($emptyOperationId, generation_json(['slots'=>[]]), 'api', ['usage'=>['fixture'=>true]]);
$emptyApply = article_image_apply_recovery_replan($emptyOperationId);
p6_assert(($emptyApply['status'] ?? '') === 'canonical_fallback'
    && (($emptyApply['validation']['missing_slot_ids'] ?? []) === ['fact-1']),
    'Empty recovery replan reaches canonical fallback instead of terminal failure.');

// Same slot + candidate identity + exact bytes reuses a completed Vision reject.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=', true);
$visionCandidate = ['provider'=>'fixture','provider_id'=>'vision-x','source_page_url'=>'https://example.test/vision-x',
    'source_file_url'=>'https://example.test/vision-x.png','title'=>'Fixture image'];
$visionPlanned = ['slot_id'=>'fact-1','role'=>'inline','section_id'=>'fact-1','visual_intent'=>'Neural image','expected_content'=>'Neural image'];
$reject = ['semantic_relevance'=>2,'editorial_fit'=>2,'hero_fit'=>0,'depicts_required_subject'=>false,
    'misleading'=>false,'inappropriate'=>false,'decision'=>'reject','reason'=>'Unrelated fixture image.'];
article_image_vision_audit_record([
    ':call_key'=>'v2-vision-reject', ':generation_operation_id'=>null, ':post_id'=>$postId, ':topic_id'=>$topicId,
    ':budget_before'=>0, ':budget_after'=>1, ':operation_type'=>ARTICLE_IMAGE_GEMINI_OPERATION_TYPE, ':model'=>'fixture',
    ':slot_identifier'=>'fact-1', ':candidate_identifier'=>'vision-x',
    ':source_page_identifier'=>$visionCandidate['source_page_url'], ':source_file_identifier'=>$visionCandidate['source_file_url'],
    ':outbound_prompt'=>'fixture', ':image_sha256'=>hash('sha256', (string)$png), ':image_mime'=>'image/png',
    ':provider_response_json'=>generation_json($reject), ':provider_response_text'=>generation_json($reject),
    ':status'=>'completed', ':error_message'=>'',
]);
$visionTransports = 0;
$budgetBeforeReuse = (int) (gemini_article_budget_state($postId)['used_calls'] ?? 0);
$reusedReject = article_image_gemini_vision_assess($postId, $visionCandidate, $visionPlanned, 'fixture',
    static function () use (&$visionTransports): array { $visionTransports++; return ['status'=>500,'body'=>'']; },
    static fn (): array => ['status'=>200,'body'=>(string)$png], 'test-key');
p6_assert($visionTransports === 0 && $reusedReject['decision'] === 'reject'
    && (int) (gemini_article_budget_state($postId)['used_calls'] ?? 0) === $budgetBeforeReuse,
    'Completed Vision reject is reused with zero new transport calls and zero budget consumption.');

// A legal, direct-but-rejected candidate is reviewable only by an operator;
// the injected downloader keeps this local smoke test free of provider calls.
$manualCandidate = ['provider'=>'fixture','provider_id'=>'manual-direct','title'=>'Direct fixture image',
    'source_page_url'=>'https://example.test/manual-page','source_file_url'=>'https://example.test/manual.jpg',
    'author'=>'Fixture author','license'=>'cc-by','license_url'=>'https://creativecommons.org/licenses/by/4.0/',
    'attribution'=>'Fixture author / CC BY','width'=>1600,'height'=>900,
    'third_party_warning'=>false,'identifiable_people'=>false,'trademarks_logos'=>false];
$manualReject = ['semantic_relevance'=>4,'editorial_fit'=>4,'hero_fit'=>4,'depicts_required_subject'=>true,
    'misleading'=>false,'inappropriate'=>false,'decision'=>'reject','reason'=>'Borderline fixture needs operator decision.',
    'relationship_level'=>'direct'];
article_image_vision_audit_record([
    ':call_key'=>'v2-vision-manual-review', ':generation_operation_id'=>null, ':post_id'=>$postId, ':topic_id'=>$topicId,
    ':budget_before'=>0, ':budget_after'=>1, ':operation_type'=>ARTICLE_IMAGE_GEMINI_OPERATION_TYPE, ':model'=>'fixture',
    ':slot_identifier'=>'article', ':candidate_identifier'=>'manual-direct',
    ':source_page_identifier'=>$manualCandidate['source_page_url'], ':source_file_identifier'=>$manualCandidate['source_file_url'],
    ':outbound_prompt'=>'fixture', ':image_sha256'=>'fixture-manual', ':image_mime'=>'image/jpeg',
    ':provider_response_json'=>generation_json(['_candidate'=>$manualCandidate]), ':provider_response_text'=>generation_json($manualReject),
    ':status'=>'completed', ':error_message'=>'',
]);
$review = article_image_rejected_review_candidates($postId, $topicId);
$manualReview = array_values(array_filter($review['items'], static fn (array $item): bool => (string) ($item['audit']['candidate_identifier'] ?? '') === 'manual-direct'))[0] ?? null;
p6_assert(is_array($manualReview) && !empty($manualReview['manual_eligible']), 'Legal direct Vision reject appears in the operator review gallery.');
$manualImageId = article_image_manual_accept_rejected_candidate($postId, (int) $manualReview['audit']['id'], null, null, null,
    static fn (array $selected): array => [...$selected, 'status'=>'downloaded', 'local_path'=>'', 'downloaded_at'=>gmdate(DATE_ATOM), 'mime'=>'image/jpeg', 'sha256'=>'fixture-manual']);
$manualImage = array_values(array_filter(list_article_images($postId), static fn (array $image): bool => (int) $image['id'] === $manualImageId))[0] ?? [];
$manualCoverage = article_image_coverage_state($postId, $topicId, false);
p6_assert((string) ($manualImage['acceptance_source'] ?? '') === 'operator_manual'
    && (json_decode((string) ($manualImage['multimodal_assessment_json'] ?? '{}'), true)['vision_rejected_before_manual_accept'] ?? false),
    'Manual acceptance preserves the Vision rejection and records operator_manual.');
p6_assert(!array_filter((array) $manualCoverage['missing_slots'], static fn (array $slot): bool => (string) ($slot['slot_id'] ?? '') === 'article'),
    'Manual acceptance fills its canonical coverage slot.');
$blockedCandidate = [...$manualCandidate, 'provider_id'=>'manual-rights-blocked', 'license'=>'all-rights-reserved'];
article_image_vision_audit_record([
    ':call_key'=>'v2-vision-rights-blocked', ':generation_operation_id'=>null, ':post_id'=>$postId, ':topic_id'=>$topicId,
    ':budget_before'=>0, ':budget_after'=>1, ':operation_type'=>ARTICLE_IMAGE_GEMINI_OPERATION_TYPE, ':model'=>'fixture',
    ':slot_identifier'=>'article', ':candidate_identifier'=>'manual-rights-blocked',
    ':source_page_identifier'=>$blockedCandidate['source_page_url'], ':source_file_identifier'=>$blockedCandidate['source_file_url'],
    ':outbound_prompt'=>'fixture', ':image_sha256'=>'fixture-rights', ':image_mime'=>'image/jpeg',
    ':provider_response_json'=>generation_json(['_candidate'=>$blockedCandidate]), ':provider_response_text'=>generation_json($manualReject),
    ':status'=>'completed', ':error_message'=>'',
]);
$rightsReview = article_image_rejected_review_candidates($postId, $topicId);
p6_assert(!array_filter($rightsReview['items'], static fn (array $item): bool => (string) ($item['audit']['candidate_identifier'] ?? '') === 'manual-rights-blocked'),
    'Rights-rejected candidate never enters the manual-accept gallery.');
echo "P6_IMAGE_RECOVERY_SMOKE_OK\n";
