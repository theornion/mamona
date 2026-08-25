<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

$passed = 0;
$failed = 0;
function p10_hero_assert(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$label}\n"; return; }
    $failed++; echo "FAIL: {$label}\n";
}

function p10_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function p10_fixture_png(int $width, int $height): string
{
    $row = "\0" . str_repeat("\x40\x70\x90", $width);
    return "\x89PNG\r\n\x1a\n"
        . p10_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . p10_png_chunk('IDAT', gzcompress(str_repeat($row, $height), 9))
        . p10_png_chunk('IEND', '');
}

$db = bueno_database();
$db->exec('PRAGMA foreign_keys=OFF');
$db->exec('INSERT INTO post_categories (title,description,slug,sort_order) VALUES ("Hero","","hero",0)');
$categoryId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO posts (category_id,title,excerpt,content,slug,status,is_published) VALUES (:category,"Hero","","Locked core","hero-related","draft",0)')
    ->execute([':category' => $categoryId]);
$postId = (int) $db->lastInsertId();
$topicId = 91001;
$visual = ['hero_slot' => [
    'slot_id'=>'hero-main','role'=>'hero','section_anchor'=>'article','visual_need'=>'hero',
    'must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['direct hero'],
    'search_queries_related'=>[],'required'=>true,
], 'inline_slots'=>[]];
p10_hero_assert($visual['hero_slot']['must_be_direct'] === true && $visual['hero_slot']['acceptable_related'] === false,
    'P02 hero contract remains direct-first and does not advertise related acceptance');
$db->prepare('INSERT INTO narrative_plans (article_id,visual_slots_planned,visual_plan_json,expansion_modules_json,status) VALUES (:post,1,:visual,"[]","accepted")')
    ->execute([':post'=>$postId, ':visual'=>generation_json($visual)]);
$db->prepare('INSERT INTO article_draft_versions (research_package_id,topic_id,post_id,generation_operation_id,version_number,composition_mode,execution_mode,status,draft_json,is_active) VALUES (1,:topic,:post,1,1,"informational","manual","frozen",:draft,1)')
    ->execute([':topic'=>$topicId, ':post'=>$postId, ':draft'=>generation_json(['body'=>'Locked core'])]);
$draftId = (int) $db->lastInsertId();
$coreBefore = core_text_lock_state($draftId)['core_hash'];
$researchInput = ['numbered_sources' => [[
    'source_id' => 'rss-hero', 'title' => 'Primary sweat-sensor source',
    'url' => 'https://example.test/rss-hero', 'excerpt' => 'Source-backed device context',
]]];
$researchPackage = ['claims' => [['claim_id' => 'claim-hero', 'source_ids' => ['rss-hero']]]];
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash) VALUES ("p10-related-hero-research",:post,:topic,"research_package","mock","completed","fixture",:input,"{}",:output,"fixture")')
    ->execute([':post' => $postId, ':topic' => $topicId, ':input' => generation_json($researchInput), ':output' => generation_json($researchPackage)]);
$researchOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,approved_at) VALUES (:topic,:post,:operation,"approved","mock",:package,CURRENT_TIMESTAMP)')
    ->execute([':topic' => $topicId, ':post' => $postId, ':operation' => $researchOperationId, ':package' => generation_json($researchPackage)]);

$asset = app_path('images/posts/p10-related-hero.png');
if (!is_dir(dirname($asset))) mkdir(dirname($asset), 0777, true);
file_put_contents($asset, p10_fixture_png(1600, 900));
$initialAssessment = ['related_supported'=>true, 'hero_recovery'=>[
    'policy'=>'source_backed_related_hero_v1','status'=>'context_pending',
]];
$db->prepare('INSERT INTO article_images (post_id,role,section_id,visual_intent,expected_content,search_queries_json,source_page_url,source_file_url,local_path,author,license,license_url,attribution,alt,caption,layout,status,width,height,relationship,is_fallback,editorial_rejected,multimodal_accepted,multimodal_assessment_json) VALUES (:post,"hero","article","hero","hero","[]","https://example.test/source","https://example.test/hero.jpg",:path,"Author","cc0","https://creativecommons.org/publicdomain/zero/1.0/","Author","alt","caption","full","downloaded",1600,900,"mechanism",0,0,1,:assessment)')
    ->execute([':post'=>$postId, ':path'=>'images/posts/p10-related-hero.png', ':assessment'=>generation_json($initialAssessment)]);
$imageId = (int) $db->lastInsertId();

$coverage = article_image_coverage_state($postId, $topicId);
p10_hero_assert(!$coverage['coverage_complete'] && empty($coverage['hero_is_allowed']), 'related hero without approved P07 block and final Vision is blocked');

$db->prepare('INSERT INTO article_related_context_blocks (post_id,image_id,slot_id,module_id,placement_after_section,block_type,heading,body,caption,reader_attention_note,source_claim_ids_json,status) VALUES (:post,:image,"hero-main","module-hero","lead","context","Context","Source-backed context","Caption","Note",:claims,"approved")')
    ->execute([':post'=>$postId, ':image'=>$imageId, ':claims'=>generation_json(['claim-hero'])]);
$blockId = (int) $db->lastInsertId();
$weak = ['semantic_relevance'=>8,'editorial_fit'=>10,'hero_fit'=>10,'misleading'=>false,'inappropriate'=>false,'decision'=>'accept','reason'=>'weak'];
try {
    article_image_finalize_related_hero($postId, $imageId, $blockId, $weak);
    p10_hero_assert(false, 'score below 9 cannot validate related hero');
} catch (RuntimeException) {
    p10_hero_assert(true, 'score below 9 cannot validate related hero');
}
$unsafe = ['semantic_relevance'=>10,'editorial_fit'=>10,'hero_fit'=>10,'misleading'=>true,'inappropriate'=>false,'decision'=>'accept','reason'=>'misleading'];
try {
    article_image_finalize_related_hero($postId, $imageId, $blockId, $unsafe);
    p10_hero_assert(false, 'misleading final Vision evidence cannot validate related hero');
} catch (RuntimeException) {
    p10_hero_assert(true, 'misleading final Vision evidence cannot validate related hero');
}

$heroFailure = article_image_resume_pending_related_hero($postId, $topicId, static fn (): array => $weak);
$storedHero = $db->prepare('SELECT multimodal_assessment_json FROM article_images WHERE id=:id');
$storedHero->execute([':id'=>$imageId]);
$storedHeroAssessment = json_decode((string)$storedHero->fetchColumn(), true) ?: [];
p10_hero_assert(($heroFailure['status'] ?? '') === 'manual_review_required'
    && ($storedHeroAssessment['hero_recovery']['status'] ?? '') === 'final_rejected',
    'final related-hero failure persists a per-slot manual-review result without a global exception');
$legalRelated = ['provider_id'=>'inline-related','source_page_url'=>'https://example.test/inline-source',
    'source_file_url'=>'https://example.test/inline.jpg'];
$continued = article_image_classify_shortage_recovery_slots([
    ['slot_id'=>'hero-main','role'=>'hero','hero_recovery_terminal'=>true,'related_candidates'=>[]],
    ['slot_id'=>'inline-01','role'=>'inline','acceptable_related'=>true,'search_queries_related'=>['inline one'],'related_candidates'=>[$legalRelated]],
    ['slot_id'=>'inline-02','role'=>'inline','acceptable_related'=>true,'search_queries_related'=>['inline two'],'related_candidates'=>[$legalRelated]],
]);
p10_hero_assert(count($continued['slot_classifications']) === 3
    && array_column($continued['recoverable_slots'], 'slot_id') === ['inline-01','inline-02'],
    'hero terminal failure does not veto independent inline recovery before aggregate coverage decision');

// Restore the independent strong-path fixture; retry orchestration itself never rewrites this state.
$db->prepare('UPDATE article_images SET multimodal_assessment_json=:assessment,multimodal_accepted=1 WHERE id=:id')
    ->execute([':assessment'=>generation_json($initialAssessment), ':id'=>$imageId]);

$strong = ['semantic_relevance'=>9,'editorial_fit'=>9,'hero_fit'=>9,'misleading'=>false,'inappropriate'=>false,'decision'=>'accept','reason'=>'strong'];
$resumeCalls = 0;
$resumed = article_image_resume_pending_related_hero($postId, $topicId,
    static function () use (&$resumeCalls, $strong): array { $resumeCalls++; return $strong; });
$coverage = article_image_coverage_state($postId, $topicId);
p10_hero_assert(($resumed['status'] ?? '') === 'validated' && $resumeCalls === 1,
    'existing context_pending related hero resumes only at final Vision validation');
p10_hero_assert($coverage['hero_status'] === 'controlled_related_supported' && !empty($coverage['hero_is_allowed']), 'fully validated source-backed related hero is allowed');
p10_hero_assert($coverage['coverage_complete'], 'validated related hero completes required hero coverage');
p10_hero_assert($coreBefore === core_text_lock_state($draftId)['core_hash'], 'hero recovery preserves locked core hash');

$candidate = ['provider_id'=>'hero-1','source_file_url'=>'https://example.test/hero.png','source_page_url'=>'https://example.test/source',
    'relationship'=>'mechanism','depicts_required_subject'=>false];
$plannerInput = ['visual_plan'=>$visual, 'missing_slots'=>[array_merge($visual['hero_slot'], [
    'hero_recovery_policy'=>'source_backed_related_hero_v1',
    'direct_exhaustion'=>['confirmed'=>true,'evidence'=>[['level'=>'exhausted','result'=>'missing']]],
    'related_candidates'=>[$candidate],
])], 'expansion_modules'=>[['module_id'=>'module-hero','topic'=>'Mechanism','purpose'=>'Explain context',
    'suitable_visual_types'=>['photo'],'preferred_placement'=>'lead','source_claim_ids'=>['claim-hero']]],
    'research_source_map'=>[['source_id'=>'rss-1','claim_ids'=>['claim-hero']]]];
$planner = article_image_validate_shortage_recovery_plan($plannerInput, ['recoveries'=>[[
    'slot_id'=>'hero-main','module_id'=>'module-hero','placement'=>'lead','editorial_reason'=>'Source-backed mechanism context',
    'candidate'=>$candidate,
]]]);
p10_hero_assert(count($planner['approved']) === 1 && !$planner['manual_review_required'], 'auditable direct exhaustion opens only the separate hero recovery policy');
$noHero = article_image_validate_shortage_recovery_plan($plannerInput, ['recoveries'=>[]]);
p10_hero_assert($noHero['manual_review_required'], 'no sensible related hero remains manual review without retry loop');

$db->prepare('UPDATE article_images SET relationship="apparatus" WHERE id=:id')->execute([':id'=>$imageId]);
$coverage = article_image_coverage_state($postId, $topicId);
p10_hero_assert(!$coverage['coverage_complete'] && empty($coverage['hero_is_allowed']), 'non-allowlisted hero relationship is blocked');
$db->prepare('UPDATE article_images SET relationship="mechanism",is_fallback=1 WHERE id=:id')->execute([':id'=>$imageId]);
$coverage = article_image_coverage_state($postId, $topicId);
p10_hero_assert(!$coverage['coverage_complete'] && $coverage['hero_status'] === 'fallback', 'fallback never satisfies hero policy');

// P06 is allowed to derive one emergency related-hero query only after the direct
// pass has recorded exhaustion.  This must not loosen the primary VisualPlan.
$db->prepare('UPDATE narrative_plans SET expansion_modules_json=:modules WHERE article_id=:post')
    ->execute([':post' => $postId, ':modules' => generation_json([[
        'module_id' => 'module-hero', 'topic' => 'Wearable sweat sensor mechanism',
        'purpose' => 'Explain source-backed device context', 'suitable_visual_types' => ['photo'],
        'preferred_placement' => 'lead', 'source_claim_ids' => ['claim-hero'],
    ]])]);
$db->prepare('UPDATE article_images SET status="missing", is_fallback=0, multimodal_accepted=0, search_audit_json=:audit WHERE id=:id')
    ->execute([':id' => $imageId, ':audit' => generation_json([[
        'query' => 'direct hero', 'level' => 'exhausted', 'result' => 'missing',
        'reason' => 'all_legal_candidates_exhausted',
    ]])]);
$derivedQueries = [];
$recoveryInput = article_image_shortage_recovery_input_with_candidates(
    $postId,
    $topicId,
    static function (string $query) use (&$derivedQueries): array {
        $derivedQueries[] = $query;
        return [[
            'provider_id' => 'related-hero-1', 'title' => 'Wearable sweat sensor patch on athlete',
            'source_page_url' => 'https://example.test/wearable-sweat-sensor',
            'source_file_url' => 'https://example.test/wearable-sweat-sensor.jpg',
            'license' => 'cc-by', 'width' => 1600, 'height' => 900,
            'relationship' => 'related_context',
        ]];
    }
);
$heroRecovery = array_values(array_filter(
    (array) $recoveryInput['missing_slots'],
    static fn (array $slot): bool => (string) ($slot['slot_id'] ?? '') === 'hero-main'
));
p10_hero_assert($derivedQueries === ['Wearable sweat sensor mechanism'],
    'documented direct exhaustion derives the controlled related-hero recovery query');
p10_hero_assert(count($heroRecovery) === 1
    && (array) ($heroRecovery[0]['related_candidates'] ?? []) !== [],
    'controlled related-hero recovery produces a legal related candidate shortlist');
$stillMissing = article_image_coverage_state($postId, $topicId);
p10_hero_assert(!$stillMissing['coverage_complete'] && empty($stillMissing['hero_is_allowed']),
    'related hero remains missing and manual-review-bound until final related validation passes');

if (is_file($asset)) unlink($asset);
if ($failed > 0) { echo "P10_RELATED_HERO_RECOVERY_FAIL {$failed}\n"; exit(1); }
echo "P10_RELATED_HERO_RECOVERY_OK {$passed}\n";
