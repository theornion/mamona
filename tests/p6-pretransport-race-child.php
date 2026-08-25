<?php

declare(strict_types=1);

$mode = (string) ($argv[1] ?? 'run');
$databaseFile = (string) ($argv[2] ?? '');
if ($databaseFile === '') exit(64);

putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
require_once dirname(__DIR__) . '/php/admin-database.php';
bueno_database()->exec('PRAGMA busy_timeout=5000');

if ($mode === 'setup') {
    $resultFile = (string) ($argv[3] ?? '');
    $db = bueno_database();
    $db->exec('PRAGMA foreign_keys=OFF');
    $db->exec('INSERT INTO post_categories (title,description,slug,sort_order) VALUES ("Race","","race",0)');
    $categoryId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO posts (category_id,title,excerpt,content,slug,status,is_published) VALUES (:category,"Race","","","race-post","draft",0)')->execute([':category'=>$categoryId]);
    $postId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at) VALUES (:post,"Race","race",CURRENT_TIMESTAMP)')->execute([':post'=>$postId]);
    $topicId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES ("race-draft",:post,:topic,"article_draft","mock","completed","fixture","{}","{}","fixture")')->execute([':post'=>$postId,':topic'=>$topicId]);
    $draftOperationId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO article_draft_versions (research_package_id,topic_id,post_id,generation_operation_id,version_number,composition_mode,execution_mode,status,draft_json,is_active) VALUES (1,:topic,:post,:operation,1,"informational","api","frozen",:draft,1)')->execute([':topic'=>$topicId,':post'=>$postId,':operation'=>$draftOperationId,':draft'=>generation_json(['lead'=>['text'=>'locked core']])]);
    $draftId = (int) $db->lastInsertId();
    $lock = core_text_lock_state($draftId);
    $source = [['source_id'=>'s1','title'=>'RSS','url'=>'https://example.test/rss','excerpt'=>'Evidence','claim_ids'=>['c1'],'claim_trace'=>['research_package_id'=>1,'claim_ids'=>['c1']]]];
    $module = ['module_id'=>'m1','topic'=>'Method','purpose'=>'Explain evidence.','suitable_visual_types'=>['diagram'],'preferred_placement'=>'after-lead','source_claim_ids'=>['c1']];
    $slot = ['slot_id'=>'inline-lead','role'=>'inline','section_anchor'=>'lead','visual_need'=>'Apparatus','must_be_direct'=>false,'acceptable_related'=>true,'search_queries_direct'=>['apparatus'],'search_queries_related'=>['research apparatus'],'required'=>true];
    $hero = ['slot_id'=>'hero-main','role'=>'hero','section_anchor'=>'article','visual_need'=>'Subject','must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['subject'],'search_queries_related'=>[],'required'=>true];
    $visual = ['hero_slot'=>$hero,'inline_slots'=>[$slot]];
    $db->prepare('INSERT INTO narrative_plans (article_id,visual_slots_planned,visual_plan_json,expansion_modules_json,status) VALUES (:topic,2,:visual,:modules,"accepted")')->execute([':topic'=>$topicId,':visual'=>generation_json($visual),':modules'=>generation_json([$module])]);
    $planId = (int) $db->lastInsertId();
    $researchInput = ['numbered_sources'=>[['source_id'=>'s1','title'=>'RSS','url'=>'https://example.test/rss','excerpt'=>'Evidence']]];
    $researchPackage = ['claims'=>[['claim_id'=>'c1','source_ids'=>['s1']]]];
    $db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash) VALUES ("race-research",:post,:topic,"research_package","mock","completed","fixture",:input,"{}",:output,"fixture")')->execute([':post'=>$postId,':topic'=>$topicId,':input'=>generation_json($researchInput),':output'=>generation_json($researchPackage)]);
    $researchOperationId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,approved_at) VALUES (:topic,:post,:operation,"approved","mock",:package,CURRENT_TIMESTAMP)')->execute([':topic'=>$topicId,':post'=>$postId,':operation'=>$researchOperationId,':package'=>generation_json($researchPackage)]);
    $candidate = ['provider_id'=>'img1','source_file_url'=>'https://example.test/i.jpg','source_page_url'=>'https://example.test/page','relationship'=>'apparatus','depicts_required_subject'=>false];
    $base = ['post_id'=>$postId,'topic_id'=>$topicId,'draft_version_id'=>$draftId,'locked_core_hash'=>$lock['core_hash'],'narrative_plan_id'=>$planId,'research_source_map'=>$source,'expansion_modules'=>[$module],'visual_plan'=>$visual,'missing_slots'=>[[...$slot,'related_candidates'=>[$candidate]]]];
    $first = prepare_generation_operation('image_recovery',[...$base,'race_nonce'=>'one'],article_image_recovery_planner_schema(),$postId,$topicId);
    $second = prepare_generation_operation('image_recovery',[...$base,'race_nonce'=>'two'],article_image_recovery_planner_schema(),$postId,$topicId);
    file_put_contents($resultFile, generation_json(['first'=>$first,'second'=>$second,'post_id'=>$postId]));
    exit(0);
}

$operationId = (int) ($argv[3] ?? 0);
$markerFile = (string) ($argv[4] ?? '');
$resultFile = (string) ($argv[5] ?? '');
if ($operationId <= 0 || $markerFile === '' || $resultFile === '') exit(64);

$transport = static function () use ($markerFile): array {
    file_put_contents($markerFile, getmypid() . "\n", FILE_APPEND | LOCK_EX);
    usleep(900000);
    return [
        'status' => 200,
        'body' => generation_json([
            'responseId' => 'p6-race-' . getmypid(),
            'candidates' => [[
                'content' => ['parts' => [['text' => generation_json(['recoveries' => [[
                    'slot_id'=>'inline-lead',
                    'module_id'=>'m1',
                    'placement'=>'after-lead',
                    'editorial_reason'=>'Source-backed apparatus context.',
                    'candidate'=>[
                        'provider_id'=>'img1',
                        'source_file_url'=>'https://example.test/i.jpg',
                        'source_page_url'=>'https://example.test/page',
                        'relationship'=>'apparatus',
                        'depicts_required_subject'=>false,
                    ],
                ]]])]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount'=>1, 'candidatesTokenCount'=>1, 'totalTokenCount'=>2],
        ]),
        'headers' => [],
    ];
};

try {
    execute_generation_operation($operationId, $transport, 'test-key');
    file_put_contents($resultFile, generation_json(['status'=>'completed']));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultFile, generation_json([
        'status'=>'refused',
        'class'=>$exception::class,
        'message'=>$exception->getMessage(),
    ]));
    exit(0);
}
