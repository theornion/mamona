<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_GENERATE_ALL_REGRESSION') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_GENERATE_ALL_REGRESSION=1; test używa wyłącznie lokalnej bazy i atrap.\n"); exit(2);
}
putenv('CMS_SKIP_PUBLIC_SYNC=1'); putenv('CMS_GENERATION_MODE=api'); putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_API_MOCK=true'); putenv('GEMINI_MAX_ATTEMPTS=1'); putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');
putenv('CMS_SOURCE_IMAGE_MOCK=true'); putenv('CMS_BATCH_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function ga_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function ga_counts(PDO $db, int $topicId): array {
    $result=[]; foreach (['research_packages'=>'research_package','article_draft_versions'=>'draft_version'] as $table=>$key) {
        $q=$db->prepare("SELECT id FROM {$table} WHERE topic_id=:topic ORDER BY id"); $q->execute([':topic'=>$topicId]); $result[$key.'_ids']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }
    $q=$db->prepare('SELECT q.id FROM quality_check_runs q INNER JOIN article_draft_versions d ON d.id=q.draft_version_id WHERE d.topic_id=:topic ORDER BY q.id'); $q->execute([':topic'=>$topicId]);
    $result['quality_check_ids']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $result['generation_operations']=(int)$db->query('SELECT COUNT(*) FROM generation_operations')->fetchColumn(); return $result;
}
function ga_selection(int $topicId, string $expected, array &$matrix): array {
    $item=generation_workflow_initial_item($topicId,'generate_all');
    $matrix[]=['expected'=>$expected,'actual'=>(string)$item['stage'],'status'=>(string)$item['status']];
    ga_assert((string)$item['stage']===$expected, "generate_all: oczekiwano {$expected}, jest {$item['stage']} ({$item['status']})."); return $item;
}

$db=bueno_database(); $original=generation_mode(); $token=bin2hex(random_bytes(6)); $sourceId=0; $postId=0; $batchIds=[]; $matrix=[]; $spy=[];
try {
    update_generation_mode('api');
    $sourceId=save_technical_source(['name'=>'Generate all '.$token,'website_url'=>'https://example.com/'.$token,'feed_url'=>'https://example.com/'.$token.'.xml','source_type'=>'rss','topic_category'=>'science','language'=>'pl','credibility_level'=>5,'is_primary'=>1,'is_active'=>1]);
    $source=find_technical_source($sourceId);
    $postId=(int)persist_discovered_feed_item($source,['external_id'=>$token,'source_url'=>'https://example.com/'.$token.'/article','title'=>'Kontrolowana macierz generate all '.$token,'source_name'=>'Generate all','published_at'=>gmdate('Y-m-d H:i:s'),'summary'=>'Badacze opisali kontrolowany wynik i metodę pomiaru potrzebną do kompletnego artykułu testowego.','category'=>'science','content_hash'=>hash('sha256',$token)]);
    $q=$db->prepare('SELECT m.topic_id,i.id feed_id FROM discovered_feed_items i INNER JOIN feed_topic_memberships m ON m.feed_item_id=i.id WHERE i.post_id=:post'); $q->execute([':post'=>$postId]); $row=$q->fetch(); $topicId=(int)$row['topic_id'];
    $db->prepare('UPDATE editorial_topics SET automatic_eligible=1 WHERE id=:id')->execute([':id'=>$topicId]);
    persist_verified_research_source($topicId,(int)$row['feed_id'],['source_kind'=>'primary','is_primary'=>1,'is_peer_reviewed'=>0,'publisher'=>'Generate all','title'=>'Kontrolowana macierz','published_at'=>gmdate('Y-m-d H:i:s'),'identifier_type'=>'url','identifier_value'=>'ga-'.$token,'canonical_url'=>'https://example.com/'.$token.'/article','verification_method'=>'local_test_fixture','verification_status'=>'verified','completeness'=>'complete','evidence'=>['fixture'=>true],'content_excerpt'=>'Badacze opisali kontrolowany wynik i metodę pomiaru potrzebną do kompletnego artykułu testowego.']);
    $transport=static function(array $payload,string $key,string $operationKey) use($db,&$spy): array {
        $q=$db->prepare('SELECT * FROM generation_operations WHERE operation_key=:key'); $q->execute([':key'=>$operationKey]); $op=$q->fetch(); ga_assert(is_array($op),'Spy nie znalazł operacji.');
        $type=(string)$op['operation_type']; $spy[]=$type;
        if($type==='article_title_repair'){
            $input=json_decode((string)$op['input_json'],true)?:[]; $claims=[];
            foreach((array)($input['verified_claims']??[]) as $claim)$claims[(string)($claim['claim_id']??'')]=$claim;
            $value=article_title_deterministic_fallback(['title'=>(string)($input['current_title']??''),'seo_description'=>'Kontrolowany opis testowej wersji artykułu.'],$claims);
        }else $value=match($type){'research_package'=>research_mock_generation_value($op),'article_draft'=>article_draft_mock_generation_value($op),'quality_check'=>quality_check_mock_generation_value(),default=>throw new RuntimeException('Nieoczekiwany transport: '.$type)};
        return ['status'=>200,'body'=>generation_json(['responseId'=>'generate_all_spy','candidates'=>[['content'=>['parts'=>[['text'=>generation_json($value)]]],'finishReason'=>'STOP']]]),'headers'=>[],'network_error'=>''];
    };

    $before=ga_counts($db,$topicId); ga_selection($topicId,'research',$matrix);
    $op=prepare_research_package_operation($topicId); execute_generation_operation($op); $research=find_research_package_by_operation($op); approve_research_package((int)$research['id']);
    $afterResearch=ga_counts($db,$topicId); $sel=ga_selection($topicId,'draft',$matrix);
    ga_assert($sel['research_package_id']===(int)$research['id'] && $afterResearch['research_package_ids']===[(int)$research['id']], 'Draft utworzył/wybrał nowy research.');

    $op=prepare_article_draft_operation((int)$research['id'],'informational'); $draft=find_article_draft_by_operation($op);
    $db->prepare('UPDATE article_draft_versions SET status="completed",draft_json=:json,validation_json=:validation,is_active=1,completed_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':json'=>generation_json(['title'=>'Kontrolowany draft','text'=>'Ukończona aktywna wersja testowa.']),':validation'=>generation_json(['valid'=>true]),':id'=>(int)$draft['id']]);
    $draft=find_article_draft_by_operation($op);
    $afterDraft=ga_counts($db,$topicId); $sel=ga_selection($topicId,'quality_check',$matrix);
    ga_assert($sel['draft_version_id']===(int)$draft['id'] && $afterDraft['research_package_ids']===$afterResearch['research_package_ids'], 'QC ponowiło wcześniejszy etap.');

    $op=prepare_quality_check_operation((int)$draft['id']); $quality=find_quality_check_by_operation($op);
    $db->prepare('UPDATE quality_check_runs SET status="completed",passed=1,model_score=100,final_score=100,hard_blocks_json="[]",completed_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id'=>(int)$quality['id']]);
    $quality=find_quality_check_by_operation($op);
    $afterQc=ga_counts($db,$topicId); $callsBefore=count($spy); $sel=ga_selection($topicId,'images',$matrix);
    ga_assert($sel['quality_check_id']===(int)$quality['id'] && count($spy)===$callsBefore, 'Wybór images ponowił Gemini.');

    $db->prepare('INSERT INTO article_images(post_id,role,section_id,visual_intent,alt,status,width,height) VALUES(:post,"hero","hero","fixture","Kontrolowany obraz","downloaded",1280,720)')->execute([':post'=>$postId]);
    $complete=ga_selection($topicId,'ready',$matrix); ga_assert($complete['status']==='already_complete','Kompletne obrazy nie dały already_complete.');
    $preClick=ga_counts($db,$topicId); $click1=create_generation_workflow_batch([$topicId],'generate_all','ga-same-'.$token); $batchIds[]=(int)$click1['batch']['id'];
    $click2=create_generation_workflow_batch([$topicId],'generate_all','ga-same-'.$token); $postClick=ga_counts($db,$topicId);
    ga_assert((int)$click1['batch']['id']===(int)$click2['batch']['id'] && $preClick===$postClick && count($spy)===$callsBefore,'Idempotency key utworzył duplikaty lub wywołał transport.');

    // Pusty, nieaktywny placeholder korekty nie może zasłonić ukończonej aktywnej wersji.
    $repairOp=prepare_article_draft_operation((int)$research['id'],'informational');
    $placeholder=find_article_draft_by_operation($repairOp); ga_assert((int)$placeholder['is_active']===0 && (string)$placeholder['status']==='prepared','Fixture nie utworzył pustego placeholdera.');
    $placeholderSelection=generation_workflow_initial_item($topicId,'generate_all');
    $matrix[]=['expected'=>'ready','actual'=>(string)$placeholderSelection['stage'],'status'=>(string)$placeholderSelection['status'],'case'=>'inactive_placeholder'];
    ga_assert((string)$placeholderSelection['stage']==='ready' && (int)$placeholderSelection['draft_version_id']===(int)$draft['id'], 'Pusty placeholder unieważnił aktywny ukończony draft.');
    echo generation_json(['matrix'=>$matrix,'ids'=>['before'=>$before,'research'=>$afterResearch,'draft'=>$afterDraft,'qc'=>$afterQc,'final'=>$postClick],'transport_calls'=>$spy])."\nGENERATE_ALL_REGRESSION_OK\n";
} finally {
    update_generation_mode($original);
    if($batchIds!==[])$db->exec('DELETE FROM generation_batches WHERE id IN ('.implode(',',array_map('intval',$batchIds)).')');
    if($postId>0){$post=find_post($postId,true);if($post!==null&&$post['deleted_at']===null)delete_post($postId);permanently_delete_post($postId);}
    if($sourceId>0)$db->prepare('DELETE FROM technical_sources WHERE id=:id')->execute([':id'=>$sourceId]);
}
