<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

$passed = 0;
$failed = 0;
function p4_assert(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$label}\n"; return; }
    $failed++; echo "FAIL: {$label}\n";
}

$db = bueno_database();
$db->exec('PRAGMA foreign_keys = OFF');
$db->exec('INSERT INTO post_categories (title, description, slug, sort_order) VALUES ("P4", "", "p4", 0)');
$categoryId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO posts (category_id, title, excerpt, content, slug, status, is_published) VALUES (:category, "P4", "", "", "p4", "draft", 0)')->execute([':category' => $categoryId]);
$postId = (int) $db->lastInsertId();
$topicId = 9001;
$visualPlan = ['hero_slot' => ['slot_id'=>'hero-main','role'=>'hero','section_anchor'=>'article','topic_source'=>'A','visual_need'=>'Direct hero subject','must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['hero subject'],'search_queries_related'=>[],'required'=>true], 'inline_slots' => []];
foreach (['lead'=>'Direct contextual observation','fact-1'=>'Technical mechanism explanation','takeaway'=>'Evidence and outcome visual'] as $anchor => $need) {
    $visualPlan['inline_slots'][] = ['slot_id'=>'inline-' . $anchor,'role'=>'inline','section_anchor'=>$anchor,'topic_source'=>'A','visual_need'=>$need,'must_be_direct'=>false,'acceptable_related'=>true,'search_queries_direct'=>[$need],'search_queries_related'=>['related ' . $anchor . ' context'],'required'=>true];
}
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES ("p4-v2-plan",:post,:topic,"narrative_plan","manual","completed","fixture",:input,"{}","p4-v2-plan")')->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json(['workflow_version'=>2])]);
$planOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO narrative_plans (article_id, visual_slots_planned, visual_plan_json, expansion_modules_json, status, batch_stage_ref) VALUES (:article, 4, :visual, "[]", "accepted", :operation)')->execute([':article'=>$postId, ':visual'=>json_encode($visualPlan, JSON_THROW_ON_ERROR), ':operation'=>$planOperationId]);
$planId = (int) $db->lastInsertId();
$coverageDraftJson = ['lead'=>['text'=>str_repeat('a', 6000)], 'illustration_plan'=>narrative_visual_plan_to_illustration_plan($visualPlan)];
$db->prepare('INSERT INTO article_draft_versions (research_package_id, topic_id, post_id, generation_operation_id, version_number, composition_mode, execution_mode, status, draft_json, is_active) VALUES (1,:topic,:post,1,1,"informational","manual","frozen",:draft,1)')
    ->execute([':topic'=>$topicId, ':post'=>$postId, ':draft'=>generation_json($coverageDraftJson)]);
$draftId = (int) $db->lastInsertId();
$lock = core_text_lock_state($draftId);
$finalInput = ['draft_version_id'=>$draftId,'locked_core_hash'=>$lock['core_hash'],'visual_target_total'=>4,'dynamic_sections'=>[
    ['id'=>'lead'],['id'=>'fact-1'],['id'=>'takeaway'],
]];
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash,output_json,completed_at) VALUES ("p4-final-plan",:post,:topic,"final_visual_plan","manual","completed","",:input,"{}","p4-final-plan",:output,CURRENT_TIMESTAMP)')
    ->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($finalInput), ':output'=>generation_json($visualPlan)]);
$duplicateVisualPlan = $visualPlan;
$duplicateVisualPlan['inline_slots'][1]['visual_need'] = $duplicateVisualPlan['inline_slots'][0]['visual_need'];
$duplicateVisualPlan['inline_slots'][1]['search_queries_direct'] = $duplicateVisualPlan['inline_slots'][0]['search_queries_direct'];
try {
    article_final_visual_plan_validate($finalInput, $duplicateVisualPlan);
    p4_assert(false, 'FinalVisualPlan rejects duplicate illustrative intents before acquisition');
} catch (InvalidArgumentException $exception) {
    p4_assert(str_contains($exception->getMessage(), 'redundant'), 'FinalVisualPlan rejects duplicate illustrative intents before acquisition');
}

function p4_image(PDO $db, int $postId, string $role, string $section, string $relationship = 'exact_subject', int $fallback = 0, bool $relatedSupported = false): void
{
    $label = $role . ' ' . $section;
    $db->prepare('INSERT INTO article_images (post_id, role, section_id, visual_intent, expected_content, search_queries_json, source_page_url, source_file_url, local_path, author, license, license_url, attribution, alt, caption, layout, status, relationship, is_fallback, editorial_rejected, multimodal_accepted, multimodal_assessment_json) VALUES (:post,:role,:section,:intent,:intent,"[]",:source,"https://example.test/file.jpg","images/posts/p4-coverage-test.jpg","Test","cc0","https://example.test/license","Test",:caption,:caption,"full","downloaded",:relationship,:fallback,0,1,:assessment)')->execute([':post'=>$postId, ':role'=>$role, ':section'=>$section, ':intent'=>$label . ' distinct function', ':caption'=>$label . ' caption', ':relationship'=>$relationship, ':fallback'=>$fallback, ':source'=>$relatedSupported ? 'https://example.test/context' : 'https://example.test/source', ':assessment'=>json_encode(['related_supported'=>$relatedSupported, 'visual_subject'=>$label . ' subject', 'visual_function'=>$label . ' function', 'visual_type'=>$role === 'hero' ? 'illustration' : 'photo', 'relationship_level'=>'direct'], JSON_THROW_ON_ERROR)]);
}

p4_image($db, $postId, 'hero', 'article');
p4_image($db, $postId, 'inline', 'lead');
p4_image($db, $postId, 'inline', 'fact-1');
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert($coverage['narrative_plan_id'] === $planId, 'coverage resolves NarrativePlan through topic without confusing post_id');
p4_assert(!$coverage['coverage_complete'] && $coverage['publication_floor_met'] && count($coverage['filled_slots']) === 3, '3/4 misses target but satisfies publication floor');
$db->prepare('UPDATE generation_operations SET input_json=:input WHERE id=:id')->execute([':input'=>generation_json(['workflow_version'=>1]), ':id'=>$planOperationId]);
$legacyCoverage = article_image_coverage_state($postId, $topicId, false);
p4_assert(!$legacyCoverage['publication_floor_met'], 'Legacy coverage still requires every planned slot');
$db->prepare('UPDATE generation_operations SET input_json=:input WHERE id=:id')->execute([':input'=>generation_json(['workflow_version'=>2]), ':id'=>$planOperationId]);

$db->exec('DELETE FROM article_images');
foreach (['lead', 'fact-1', 'takeaway', 'extra'] as $anchor) p4_image($db, $postId, 'inline', $anchor);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert(!$coverage['coverage_complete'] && $coverage['hero_status'] === 'missing', 'four images without hero are blocked');

$db->exec('DELETE FROM article_images');
p4_image($db, $postId, 'hero', 'article', 'exact_subject', 1);
foreach (['lead', 'fact-1', 'takeaway'] as $anchor) p4_image($db, $postId, 'inline', $anchor);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert(!$coverage['coverage_complete'] && $coverage['hero_status'] === 'fallback', 'technical fallback hero is blocked');

$db->exec('DELETE FROM article_images');
p4_image($db, $postId, 'hero', 'article');
foreach (['lead', 'fact-1', 'takeaway'] as $anchor) p4_image($db, $postId, 'inline', $anchor);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert($coverage['coverage_complete'], 'all required direct slots complete coverage');
$redundantMacaque = [
    'id'=>701, 'caption'=>'Makak japoński jako historyczny kontekst badań neuronów lustrzanych.',
    'expected_content'=>'Historyczny kontekst badań na makakach.',
    'multimodal_assessment'=>['visual_subject'=>'Makak japoński', 'visual_function'=>'Historyczny kontekst badań na makakach.', 'visual_type'=>'photo', 'relationship_level'=>'domain_related'],
];
$sameMacaque = [
    'id'=>702, 'caption'=>'Makaki japońskie jako historyczny kontekst badań neuronów lustrzanych.',
    'expected_content'=>'Inny opis tego samego kontekstu makaków.',
    'multimodal_assessment'=>['visual_subject'=>'Makaki japońskie', 'visual_function'=>'Historyczny kontekst badań na makakach.', 'visual_type'=>'photo', 'relationship_level'=>'domain_related'],
];
$mechanismDiagram = [
    'id'=>703, 'caption'=>'Schemat przepływu sygnału w sieci neuronów lustrzanych.',
    'expected_content'=>'Mechanizm sieci neuronów lustrzanych.',
    'multimodal_assessment'=>['visual_subject'=>'Schemat sieci neuronów', 'visual_function'=>'Wyjaśnienie mechanizmu przepływu sygnału.', 'visual_type'=>'diagram', 'relationship_level'=>'broader_direct'],
];
p4_assert((article_image_semantic_duplicate_reason($sameMacaque, $redundantMacaque)['code'] ?? '') === 'semantic_duplicate',
    'different assets with the same semantic subject and near-identical caption fail diversity');
p4_assert(article_image_semantic_duplicate_reason($mechanismDiagram, $redundantMacaque) === null,
    'similar topic with a distinct illustrative function passes diversity');
$db->prepare('UPDATE article_images SET caption=:caption,multimodal_assessment_json=:assessment WHERE post_id=:post AND role="inline" AND section_id="fact-1"')
    ->execute([':post'=>$postId, ':caption'=>$sameMacaque['caption'], ':assessment'=>generation_json($sameMacaque['multimodal_assessment'])]);
$db->prepare('UPDATE article_images SET caption=:caption,multimodal_assessment_json=:assessment WHERE post_id=:post AND role="inline" AND section_id="lead"')
    ->execute([':post'=>$postId, ':caption'=>$redundantMacaque['caption'], ':assessment'=>generation_json($redundantMacaque['multimodal_assessment'])]);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert(count($coverage['filled_slots']) === 3
    && count($coverage['diversity_rejected_slots']) === 1
    && ($coverage['diversity_rejected_slots'][0]['slot_id'] ?? '') === 'inline-fact-1',
    'redundant asset opens recovery only for its own slot without removing valid images');
$db->prepare('UPDATE article_images SET caption=:caption,multimodal_assessment_json=:assessment WHERE post_id=:post AND role="inline" AND section_id="lead"')
    ->execute([':post'=>$postId, ':caption'=>'inline lead caption', ':assessment'=>generation_json(['visual_subject'=>'inline lead subject', 'visual_function'=>'inline lead function', 'visual_type'=>'photo', 'relationship_level'=>'direct'])]);
$db->prepare('UPDATE article_images SET caption=:caption,multimodal_assessment_json=:assessment WHERE post_id=:post AND role="inline" AND section_id="fact-1"')
    ->execute([':post'=>$postId, ':caption'=>'inline fact-1 caption', ':assessment'=>generation_json(['visual_subject'=>'inline fact-1 subject', 'visual_function'=>'inline fact-1 function', 'visual_type'=>'photo', 'relationship_level'=>'direct'])]);
$db->prepare('UPDATE article_images SET search_audit_json=:audit WHERE post_id=:post AND role="hero"')
    ->execute([':post'=>$postId, ':audit'=>generation_json([['result'=>'selected','level'=>'broader_direct']])]);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert($coverage['hero_status'] === 'broader_direct_ok' && $coverage['hero_is_allowed'], 'broader direct hero is an explicit allowed recovery level');

$db->exec('DELETE FROM article_images');
p4_image($db, $postId, 'hero', 'article');
p4_image($db, $postId, 'inline', 'lead', 'related_context', 0, true);
foreach (['fact-1', 'takeaway'] as $anchor) p4_image($db, $postId, 'inline', $anchor);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert(!$coverage['coverage_complete'] && count($coverage['filled_slots']) === 3, 'related metadata alone does not fill a slot without approved source-backed context');

$strictVisualPlan = $visualPlan;
$strictVisualPlan['inline_slots'][0]['must_be_direct'] = true;
$strictVisualPlan['inline_slots'][0]['acceptable_related'] = false;
$db->prepare('UPDATE generation_operations SET output_json=:output WHERE id=(SELECT id FROM generation_operations WHERE post_id=:post AND operation_type="final_visual_plan" ORDER BY id DESC LIMIT 1)')
    ->execute([':post'=>$postId, ':output'=>generation_json($strictVisualPlan)]);
$db->exec('DELETE FROM article_images');
p4_image($db, $postId, 'hero', 'article');
p4_image($db, $postId, 'inline', 'lead', 'related_context', 0, true);
foreach (['fact-1', 'takeaway'] as $anchor) p4_image($db, $postId, 'inline', $anchor);
$db->prepare('UPDATE article_images SET multimodal_assessment_json=:assessment WHERE post_id=:post AND role="inline" AND section_id="lead"')
    ->execute([':post'=>$postId, ':assessment'=>generation_json(['related_supported'=>true, 'contextual_policy'=>'ww_contextual_v1'])]);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert($coverage['coverage_complete'], 'validated W/W contextual inline fills a required direct-only slot after exhaustive closure');
$db->prepare('UPDATE generation_operations SET output_json=:output WHERE id=(SELECT id FROM generation_operations WHERE post_id=:post AND operation_type="final_visual_plan" ORDER BY id DESC LIMIT 1)')
    ->execute([':post'=>$postId, ':output'=>generation_json($visualPlan)]);

$db->exec('DELETE FROM article_images');
p4_image($db, $postId, 'hero', 'article');
foreach (['lead', 'fact-1', 'takeaway'] as $anchor) p4_image($db, $postId, 'inline', $anchor);
$asset = app_path('images/posts/p4-coverage-test.jpg');
if (!is_dir(dirname($asset))) mkdir(dirname($asset), 0777, true);
file_put_contents($asset, 'p4 test asset');
$db->prepare('INSERT INTO verified_research_sources (topic_id,source_kind,title,canonical_url,verification_method,verification_status,content_fingerprint) VALUES (:topic,"primary","P07 source","https://example.test/p07","fixture","verified","p07")')->execute([':topic'=>$topicId]);
$sourceId = (string) $db->lastInsertId();
$sourceClaimId = 'claim-p07-context';
$verifiedSources = [['id'=>$sourceId, 'claim_ids'=>[$sourceClaimId]]];
$db->exec('DELETE FROM article_images');
p4_image($db, $postId, 'hero', 'article');
p4_image($db, $postId, 'inline', 'lead', 'related_context', 0, false);
foreach (['fact-1', 'takeaway'] as $anchor) p4_image($db, $postId, 'inline', $anchor);
$relatedImage = (int) $db->query('SELECT id FROM article_images WHERE role="inline" AND section_id="lead"')->fetchColumn();
$beforeCore = core_text_lock_state($draftId)['core_hash'];
$blockId = persist_article_related_context_block($postId, $relatedImage, [
    'module_id'=>'context-method','target_slot_id'=>'inline-lead','placement_after_section'=>'lead',
    'type'=>'explainer','heading'=>'Context','body'=>'Source-backed additive context.','caption'=>'Caption','reader_attention_note'=>'Note','source_claim_ids'=>[$sourceClaimId],
], $verifiedSources);
$coverage = article_image_coverage_state($postId, $topicId, false);
p4_assert($blockId > 0 && $coverage['coverage_complete'], 'approved source-backed related block completes an allowed inline slot');
p4_assert($beforeCore === core_text_lock_state($draftId)['core_hash'], 'related context does not replace locked core text');
try {
    persist_article_related_context_block($postId, $relatedImage, [
        'module_id'=>'context-method','target_slot_id'=>'inline-lead','placement_after_section'=>'lead',
        'type'=>'explainer','heading'=>'Invalid','body'=>'No verified claim.','source_claim_ids'=>['999999'],
    ], $verifiedSources);
    p4_assert(false, 'related block without verified source is rejected');
} catch (InvalidArgumentException) {
    p4_assert(true, 'related block without verified source is rejected');
}
$db->prepare('INSERT INTO quality_check_runs (draft_version_id, post_id, generation_operation_id, check_number, execution_mode, status, model_score, final_score, passed, model_result_json, hard_blocks_json) VALUES (:draft,:post,2,1,"manual","completed",90,90,1,"{}","[]")')->execute([':draft'=>$draftId, ':post'=>$postId]);
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash) VALUES ("p8-layout-fixture",:post,"layout_plan","manual","completed","fixture","{}","{}",:output,"layout-fixture")')->execute([':post'=>$postId, ':output'=>generation_json(article_safe_layout_plan())]);
$finalPreflight = final_multimodal_qc_preflight($postId, $draftId);
p4_assert($finalPreflight['passed'], 'final multimodal deterministic preflight passes only after all prior hard gates');
$finalOutput = final_multimodal_qc_mock_generation_value();
$invalidFinalOutput = $finalOutput;
$invalidFinalOutput['allowed_repair_operations'] = ['re-run_image_search_for_missing_slot'];
try {
    validate_generation_value($invalidFinalOutput, final_multimodal_qc_schema());
    p4_assert(false, 'final multimodal schema rejects an unapproved repair operation');
} catch (InvalidArgumentException) {
    p4_assert(true, 'final multimodal schema rejects an unapproved repair operation');
}
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash) VALUES ("p9-final-fixture",:post,"final_multimodal_qc","manual","completed","fixture",:input,:schema,:output,"fixture")')->execute([':post'=>$postId, ':input'=>generation_json(['draft_version_id'=>$draftId]), ':schema'=>generation_json(final_multimodal_qc_schema()), ':output'=>generation_json($finalOutput)]);
$finalOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO final_multimodal_qc_runs (post_id,draft_version_id,generation_operation_id,deterministic_gates_json) VALUES (:post,:draft,:operation,"[]")')->execute([':post'=>$postId, ':draft'=>$draftId, ':operation'=>$finalOperationId]);
$finalResult = complete_final_multimodal_qc_operation($finalOperationId);
p4_assert($finalResult['decision'] === 'PASS', 'final multimodal operation persists PASS only after deterministic preflight');
$noneFinalOutput = $finalOutput;
$noneFinalOutput['allowed_repair_operations'] = ['none'];
try {
    validate_generation_value($noneFinalOutput, final_multimodal_qc_schema());
    p4_assert(true, 'final multimodal schema accepts a sole none repair operation');
} catch (InvalidArgumentException) {
    p4_assert(false, 'final multimodal schema accepts a sole none repair operation');
}
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash) VALUES ("p9-final-none-fixture",:post,"final_multimodal_qc","manual","completed","fixture",:input,:schema,:output,"fixture-none")')->execute([':post'=>$postId, ':input'=>generation_json(['draft_version_id'=>$draftId]), ':schema'=>generation_json(final_multimodal_qc_schema()), ':output'=>generation_json($noneFinalOutput)]);
$noneFinalOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO final_multimodal_qc_runs (post_id,draft_version_id,generation_operation_id,deterministic_gates_json) VALUES (:post,:draft,:operation,"[]")')->execute([':post'=>$postId, ':draft'=>$draftId, ':operation'=>$noneFinalOperationId]);
$noneFinalResult = complete_final_multimodal_qc_operation($noneFinalOperationId);
p4_assert($noneFinalResult['decision'] === 'PASS' && $noneFinalResult['result']['allowed_repair_operations'] === [], 'a sole none repair operation completes as PASS with no repair operations');
try {
    assert_post_quality_allows_publication($postId);
    p4_assert(final_multimodal_qc_readiness($postId) === 'ready_for_manual_publish', 'final QC PASS yields non-public ready_for_manual_publish');
} catch (Throwable $exception) {
    p4_assert(false, 'publication gate rejected full coverage: ' . $exception->getMessage());
}
$db->prepare('UPDATE final_multimodal_qc_runs SET decision="FAIL" WHERE post_id=:post')->execute([':post'=>$postId]);
try { assert_post_quality_allows_publication($postId); p4_assert(false, 'final QC FAIL blocks publication'); }
catch (RuntimeException) { p4_assert(true, 'final QC FAIL blocks publication'); }
p4_assert(final_multimodal_qc_readiness($postId) === 'manual_review', 'final QC FAIL yields manual_review readiness without publishing');
unlink($asset);

if ($failed > 0) { echo "P4_IMAGE_COVERAGE_SMOKE_FAIL {$failed}\n"; exit(1); }
echo "P4_IMAGE_COVERAGE_SMOKE_OK {$passed}\n";
