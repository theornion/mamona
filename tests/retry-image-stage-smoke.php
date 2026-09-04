<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_BATCH_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function retry_image_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = bueno_database();
$db->exec("UPDATE generation_settings SET generation_mode='api' WHERE id=1");
$db->exec("INSERT INTO post_categories (title,description,slug,sort_order) VALUES ('Retry image','','retry-image',0)");
$categoryId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO posts (category_id,title,excerpt,content,slug,status,is_published,created_at,updated_at) VALUES (:category,"Retry image fixture","","","retry-image-fixture","draft",0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')
    ->execute([':category'=>$categoryId]);
$postId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at,automatic_eligible) VALUES (:post,"Retry image topic","retry image topic",CURRENT_TIMESTAMP,1)')
    ->execute([':post' => $postId]);
$topicId = (int) $db->lastInsertId();

$operation = $db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,input_hash) VALUES (:key,:post,:topic,:type,"api","completed","","{}","{}",:hash)');
$operation->execute([':key'=>'retry-research', ':post'=>$postId, ':topic'=>$topicId, ':type'=>'research', ':hash'=>hash('sha256', 'research')]);
$researchOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,validation_json,approved_at) VALUES (:topic,:post,:operation,"approved","api","{}","{}",CURRENT_TIMESTAMP)')->execute([':topic'=>$topicId, ':post'=>$postId, ':operation'=>$researchOperationId]);
$researchId = (int) $db->lastInsertId();
$operation->execute([':key'=>'retry-draft', ':post'=>$postId, ':topic'=>$topicId, ':type'=>'article_draft', ':hash'=>hash('sha256', 'draft')]);
$draftOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO article_draft_versions (research_package_id,topic_id,post_id,generation_operation_id,version_number,composition_mode,execution_mode,status,draft_json,validation_json,completed_at,is_active) VALUES (:research,:topic,:post,:operation,1,"informational","api","frozen","{}","{}",CURRENT_TIMESTAMP,1)')->execute([':research'=>$researchId, ':topic'=>$topicId, ':post'=>$postId, ':operation'=>$draftOperationId]);
$draftId = (int) $db->lastInsertId();
$operation->execute([':key'=>'retry-quality', ':post'=>$postId, ':topic'=>$topicId, ':type'=>'quality_check', ':hash'=>hash('sha256', 'quality')]);
$qualityOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO quality_check_runs (draft_version_id,post_id,generation_operation_id,check_number,execution_mode,status,passed,hard_blocks_json,validation_json,completed_at) VALUES (:draft,:post,:operation,1,"api","completed",1,"[]","{}",CURRENT_TIMESTAMP)')->execute([':draft'=>$draftId, ':post'=>$postId, ':operation'=>$qualityOperationId]);

$db->exec("INSERT INTO generation_batches (batch_key,request_key,action,item_count,created_by,execution_mode,status) VALUES ('retry-image-batch','retry-image-request','generate_all',1,'test','api','completed')");
$batchId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO generation_batch_items (batch_id,topic_id,post_id,status,stage,progress_percent,outcome,wait_reason,completed_at) VALUES (:batch,:topic,:post,"manual_review","images",100,"hero_recovery_manual_review","Fixture review",CURRENT_TIMESTAMP)')
    ->execute([':batch'=>$batchId, ':topic'=>$topicId, ':post'=>$postId]);
$itemId = (int) $db->lastInsertId();

$status = generation_workflow_statuses([$topicId])[0];
retry_image_assert($status['retryable'] === true, 'UI does not expose stage retry for manual_review/images.');
retry_image_assert(!generation_batch_item_is_retryable(['status'=>'waiting_review','stage'=>'research']), 'Research review gate was loosened.');

$result = retry_generation_batch_item_from_ui($itemId, 'test');
retry_image_assert(is_array($result['batch'] ?? null), 'UI retry did not create a fresh image-stage attempt.');
$newBatchId = (int) $result['batch']['id'];
$retried = $db->query('SELECT * FROM generation_batch_items WHERE batch_id=' . $newBatchId)->fetch();
retry_image_assert(is_array($retried), 'Fresh image-stage retry item is missing.');
retry_image_assert((string) $retried['status'] === 'queued' && (string) $retried['stage'] === 'images', 'Fresh attempt did not queue the image stage.');
retry_image_assert($retried['completed_at'] === null && (string) $retried['outcome'] === 'queued', 'Fresh image attempt remained terminal.');
retry_image_assert($newBatchId !== $batchId, 'Image retry reused the exhausted batch budget.');
retry_image_assert((string) $db->query('SELECT status FROM generation_batches WHERE id=' . $newBatchId)->fetchColumn() === 'running', 'Fresh batch is not ready for the worker.');

$db->prepare('INSERT INTO posts (category_id,title,excerpt,content,slug,status,is_published,created_at,updated_at) VALUES (:category,"Retry narrative fixture","","","retry-narrative-fixture","draft",0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')
    ->execute([':category'=>$categoryId]);
$narrativePostId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at,automatic_eligible) VALUES (:post,"Retry narrative topic","retry narrative topic",CURRENT_TIMESTAMP,1)')
    ->execute([':post'=>$narrativePostId]);
$narrativeTopicId = (int) $db->lastInsertId();
$operation->execute([':key'=>'retry-narrative-research', ':post'=>$narrativePostId, ':topic'=>$narrativeTopicId, ':type'=>'research', ':hash'=>hash('sha256', 'narrative-research')]);
$narrativeResearchOperationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,validation_json,approved_at) VALUES (:topic,:post,:operation,"approved","api","{}","{}",CURRENT_TIMESTAMP)')
    ->execute([':topic'=>$narrativeTopicId, ':post'=>$narrativePostId, ':operation'=>$narrativeResearchOperationId]);
$narrativeResearchId = (int) $db->lastInsertId();
$db->exec("INSERT INTO generation_batches (batch_key,request_key,action,item_count,created_by,execution_mode,status) VALUES ('retry-narrative-batch','retry-narrative-request','generate_all',1,'test','api','completed')");
$narrativeBatchId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO generation_batch_items (batch_id,topic_id,post_id,research_operation_id,research_package_id,status,stage,progress_percent,outcome,wait_reason,error_message,completed_at) VALUES (:batch,:topic,:post,:research_operation,:research,"failed","draft",45,"non_retryable_provider_error","Błąd NarrativePlan wymaga ręcznego wznowienia.","Ukończona operacja NarrativePlan ma nieprawidłowy zapisany output.",CURRENT_TIMESTAMP)')
    ->execute([':batch'=>$narrativeBatchId, ':topic'=>$narrativeTopicId, ':post'=>$narrativePostId, ':research_operation'=>$narrativeResearchOperationId, ':research'=>$narrativeResearchId]);
$narrativeItemId = (int) $db->lastInsertId();

$narrativeRetry = retry_generation_batch_item_from_ui($narrativeItemId, 'test');
retry_image_assert(is_array($narrativeRetry['batch'] ?? null), 'NarrativePlan retry did not create a fresh workflow attempt.');
$freshNarrativeItem = $db->query('SELECT * FROM generation_batch_items WHERE batch_id=' . (int) $narrativeRetry['batch']['id'])->fetch();
retry_image_assert(is_array($freshNarrativeItem), 'Fresh NarrativePlan retry item is missing.');
retry_image_assert((string) $freshNarrativeItem['status'] === 'queued' && (string) $freshNarrativeItem['stage'] === 'draft', 'NarrativePlan retry did not resume from the first incomplete stage.');
retry_image_assert((int) $freshNarrativeItem['id'] !== $narrativeItemId, 'NarrativePlan retry reused the poisoned batch item.');

$db->exec('UPDATE generation_batch_items SET status="cancelled", completed_at=CURRENT_TIMESTAMP WHERE topic_id=' . $topicId . ' AND status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled")');
$db->exec("INSERT INTO generation_batches (batch_key,request_key,action,item_count,created_by,execution_mode,status) VALUES ('retry-invalid-replan-batch','retry-invalid-replan-request','generate_all',1,'test','api','completed')");
$invalidReplanBatchId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO generation_batch_items (batch_id,topic_id,post_id,status,stage,progress_percent,outcome,wait_reason,error_message,completed_at) VALUES (:batch,:topic,:post,"failed","images",85,"validation_contract","Nieprawidłowy recovery replan wymaga świeżej próby grafik.","Nieprawidłowa odpowiedź Gemini API: Recovery replan.",CURRENT_TIMESTAMP)')
    ->execute([':batch'=>$invalidReplanBatchId, ':topic'=>$topicId, ':post'=>$postId]);
$invalidReplanItemId = (int) $db->lastInsertId();
retry_image_assert(generation_batch_item_is_retryable(['status'=>'failed','stage'=>'images','outcome'=>'validation_contract']), 'Błędny kontrakt replan nie jest widoczny jako ręcznie wznawialny.');
$invalidReplanRetry = retry_generation_batch_item_from_ui($invalidReplanItemId, 'test');
retry_image_assert(is_array($invalidReplanRetry['batch'] ?? null), 'Błędny kontrakt replan nie utworzył świeżej próby grafik.');
$freshInvalidReplan = $db->query('SELECT * FROM generation_batch_items WHERE batch_id=' . (int) $invalidReplanRetry['batch']['id'])->fetch();
retry_image_assert(is_array($freshInvalidReplan) && (string) $freshInvalidReplan['status'] === 'queued'
    && (string) $freshInvalidReplan['stage'] === 'images', 'Fresh retry po błędnym replan nie zaczyna od etapu grafik: ' . generation_json($freshInvalidReplan ?: []));

echo "RETRY_IMAGE_STAGE_SMOKE_OK\n";
