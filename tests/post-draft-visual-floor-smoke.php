<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function post_draft_floor_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$deficit = editorial_v2_visual_target_state(9323, 3);
post_draft_floor_assert($deficit === [
    'final_article_length'=>9323,
    'visual_target'=>4,
    'visual_slot_count'=>3,
    'visual_deficit'=>1,
    'publication_visual_floor'=>3,
], '9323 characters with three slots did not persist target=4, deficit=1 and floor=3.');

$complete = editorial_v2_visual_target_state(9323, 4);
post_draft_floor_assert($complete['visual_target'] === 4 && $complete['visual_deficit'] === 0,
    'Four slots for 9323 characters still report a deficit.');

$sixThousand = editorial_v2_visual_target_state(6000, 3);
post_draft_floor_assert($sixThousand['visual_target'] === 4 && $sixThousand['visual_deficit'] === 1,
    '6000 characters did not produce visual target four.');

$draftSource = file_get_contents(dirname(__DIR__) . '/php/article-draft-service.php');
post_draft_floor_assert(is_string($draftSource)
    && !str_contains($draftSource, 'VisualPlan nie spełnia floor'),
    'Draft validation still contains the final-length VisualPlan hard failure.');

$db = bueno_database();
$db->exec('PRAGMA foreign_keys=OFF');
$db->exec('INSERT INTO post_categories(title,description,slug,sort_order) VALUES ("Final plan","","final-plan",0)');
$categoryId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO posts(category_id,title,excerpt,content,slug,status,is_published) VALUES (:category,"Final plan fixture","","","final-plan-fixture","draft",0)')
    ->execute([':category'=>$categoryId]);
$postId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO editorial_topics(primary_post_id,title,normalized_title,event_at,automatic_eligible) VALUES (:post,"Final plan topic","final plan topic",CURRENT_TIMESTAMP,1)')
    ->execute([':post'=>$postId]);
$topicId = (int)$db->lastInsertId();
$operationInsert = $db->prepare('INSERT INTO generation_operations(operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash,output_json) VALUES (:key,:post,:topic,:type,"manual",:status,"",:input,"{}",:hash,:output)');
$researchInput = ['numbered_sources'=>[['source_id'=>'S1','title'=>'Source','url'=>'https://example.test/source','excerpt'=>'Source excerpt']]];
$operationInsert->execute([':key'=>'final-plan-research',':post'=>$postId,':topic'=>$topicId,':type'=>'research_package',':status'=>'completed',':input'=>generation_json($researchInput),':hash'=>'research',':output'=>'{}']);
$researchOperationId = (int)$db->lastInsertId();
$researchPackage = ['claims'=>[['claim_id'=>'C1','source_ids'=>['S1']]]];
$db->prepare('INSERT INTO research_packages(topic_id,post_id,generation_operation_id,status,execution_mode,package_json,validation_json,approved_at) VALUES (:topic,:post,:operation,"approved","manual",:package,"{}",CURRENT_TIMESTAMP)')
    ->execute([':topic'=>$topicId,':post'=>$postId,':operation'=>$researchOperationId,':package'=>generation_json($researchPackage)]);
$researchPackageId = (int)$db->lastInsertId();
$preliminary = ['hero_slot'=>['slot_id'=>'pre-hero','role'=>'hero','section_anchor'=>'article','topic_source'=>'A','visual_need'=>'Preliminary hero direction','must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['preliminary hero'], 'search_queries_related'=>[],'required'=>true], 'inline_slots'=>[]];
foreach (['s1','s2'] as $index=>$anchor) $preliminary['inline_slots'][] = ['slot_id'=>'pre-'.$anchor,'role'=>'inline','section_anchor'=>$anchor,'topic_source'=>$index===0?'A':'B','visual_need'=>'Preliminary inline direction','must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['preliminary inline'], 'search_queries_related'=>[],'required'=>true];
$operationInsert->execute([':key'=>'final-plan-narrative',':post'=>$postId,':topic'=>$topicId,':type'=>'narrative_plan',':status'=>'completed',':input'=>generation_json(['workflow_version'=>2]),':hash'=>'narrative',':output'=>'{}']);
$narrativeOperationId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO narrative_plans(article_id,visual_slots_planned,visual_plan_json,expansion_modules_json,status,batch_stage_ref) VALUES (:post,3,:visual,"[]","frozen",:operation)')
    ->execute([':post'=>$postId,':visual'=>generation_json($preliminary),':operation'=>$narrativeOperationId]);
$operationInsert->execute([':key'=>'final-plan-draft',':post'=>$postId,':topic'=>$topicId,':type'=>'article_draft',':status'=>'completed',':input'=>'{}',':hash'=>'draft',':output'=>'{}']);
$draftOperationId = (int)$db->lastInsertId();
$sections = [];
foreach (range(1,5) as $index) $sections[] = ['section_id'=>'s'.$index,'heading'=>'Sekcja '.$index,'body'=>str_repeat(chr(96+$index),1863),'topic_role'=>['A','A','B','C','A'][$index-1],'content_type'=>'prose'];
$draftJson = ['sections'=>$sections,'illustration_plan'=>narrative_visual_plan_to_illustration_plan($preliminary)];
post_draft_floor_assert(article_draft_main_content_length($draftJson) === 9323, 'Final draft fixture is not 9323 characters.');
$db->prepare('INSERT INTO article_draft_versions(research_package_id,topic_id,post_id,generation_operation_id,version_number,composition_mode,execution_mode,status,draft_json,validation_json,is_active) VALUES (:research,:topic,:post,:operation,1,"informational","manual","completed",:draft,"{}",1)')
    ->execute([':research'=>$researchPackageId,':topic'=>$topicId,':post'=>$postId,':operation'=>$draftOperationId,':draft'=>generation_json($draftJson)]);
$draftId = (int)$db->lastInsertId();

$preLockRejected = false;
try { prepare_article_final_visual_plan_operation($postId, $topicId); } catch (RuntimeException $exception) { $preLockRejected = str_contains($exception->getMessage(), 'core lock'); }
post_draft_floor_assert($preLockRejected, 'FinalVisualPlan was allowed before core lock.');
$db->exec('UPDATE article_draft_versions SET status="frozen" WHERE id='.$draftId);
$db->exec('UPDATE generation_settings SET generation_mode="api" WHERE id=1');
$finalOperationId = prepare_article_final_visual_plan_operation($postId, $topicId);
$finalOperation = find_generation_operation($finalOperationId);
$finalInput = json_decode((string)$finalOperation['input_json'], true) ?: [];
post_draft_floor_assert((int)$finalInput['final_article_chars'] === 9323 && (int)$finalInput['visual_target_total'] === 4 && (int)$finalInput['publication_floor'] === 3,
    'FinalVisualPlan input did not derive target=4 and floor=3 from locked 9323-character text.');
$finalPlan = ['hero_slot'=>['slot_id'=>'hero-main','role'=>'hero','section_anchor'=>'article','topic_source'=>'A','visual_need'=>'Final direct hero subject','must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['final hero subject'],'search_queries_related'=>[],'required'=>true], 'inline_slots'=>[]];
foreach ([['s1','A'],['s2','A'],['s3','B']] as $index=>$placement) $finalPlan['inline_slots'][] = ['slot_id'=>'final-'.$placement[0],'role'=>'inline','section_anchor'=>$placement[0],'topic_source'=>$placement[1],'visual_need'=>'Final visual need for '.$placement[0],'must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['final section '.$placement[0]],'search_queries_related'=>[],'required'=>true];
execute_generation_operation($finalOperationId, static fn(): array => ['status'=>200,'body'=>generation_json([
    'responseId'=>'local-final-plan-fixture','candidates'=>[['content'=>['parts'=>[['text'=>generation_json($finalPlan)]]],'finishReason'=>'STOP']],
    'usageMetadata'=>['promptTokenCount'=>1,'candidatesTokenCount'=>1,'totalTokenCount'=>2],
]),'headers'=>[],'network_error'=>'']);
$budget = gemini_article_budget_state($postId);
post_draft_floor_assert((int)$budget['used_calls'] === 1, 'FinalVisualPlan controlled response was not counted as exactly one Gemini call.');
$effective = article_image_effective_visual_plan($postId, $topicId);
post_draft_floor_assert(count((array)$effective['inline_slots']) === 3 && (string)$effective['hero_slot']['slot_id'] === 'hero-main',
    'V2 image pipeline did not select completed FinalVisualPlan.');
post_draft_floor_assert(generation_json($effective) !== generation_json($preliminary), 'V2 image pipeline still selected preliminary visual directions.');

echo "POST_DRAFT_VISUAL_FLOOR_SMOKE_OK\n";
