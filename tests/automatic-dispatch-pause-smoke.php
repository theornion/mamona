<?php

declare(strict_types=1);

$databaseFile = sys_get_temp_dir() . '/mamona-dispatch-pause-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_API_MOCK=true');
putenv('CMS_BATCH_NO_SPAWN=1');
putenv('FULL_AUTO_ENABLED=true');
require_once dirname(__DIR__) . '/php/admin-database.php';
require_once dirname(__DIR__) . '/php/full-auto-service.php';

function pause_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function pause_topic(string $token,int $index): int {
    $sourceId=save_technical_source(['name'=>'Pause '.$index,'website_url'=>'https://example.com/pause-'.$token.'-'.$index,'feed_url'=>'https://example.com/pause-'.$token.'-'.$index.'.xml','source_type'=>'rss','topic_category'=>'science','language'=>'pl','credibility_level'=>5,'is_primary'=>1,'is_active'=>1]);
    $source=find_technical_source($sourceId);
    $postId=persist_discovered_feed_item($source,['external_id'=>'pause-'.$token.'-'.$index,'source_url'=>'https://example.com/pause/'.$token.'/'.$index,'title'=>'Temat pauzy '.$index,'source_name'=>'Pause','published_at'=>gmdate('Y-m-d H:i:s'),'summary'=>'Legalny wpis RSS do testu pauzy.','category'=>'science','content_hash'=>hash('sha256',$token.'-'.$index)]);
    $query=bueno_database()->prepare('SELECT topic_id FROM feed_topic_memberships m INNER JOIN discovered_feed_items i ON i.id=m.feed_item_id WHERE i.post_id=:post');
    $query->execute([':post'=>$postId]);
    return (int)$query->fetchColumn();
}

$database=bueno_database();
$token=bin2hex(random_bytes(4));
$automaticTopic=pause_topic($token,1);
$manualTopic=pause_topic($token,2);
$automatic=create_topic_workflow_batch([$automaticTopic],'generate_all','pause-auto-'.$token,'full-auto');
$automaticItem=(int)$automatic['items'][0]['id'];
$database->prepare('UPDATE generation_batch_items SET lease_token="fixture-lease",lease_expires_at=datetime(CURRENT_TIMESTAMP,"+10 minutes") WHERE id=:id')->execute([':id'=>$automaticItem]);
$beforeResearch=(int)$database->query('SELECT COUNT(*) FROM research_packages')->fetchColumn();
$beforeDrafts=(int)$database->query('SELECT COUNT(*) FROM article_draft_versions')->fetchColumn();
$pause=generation_set_automatic_dispatch_paused(true,'test',true);
$paused=generation_batch_find_item($automaticItem);
pause_assert($pause['paused_items']===1 && $paused['status']==='paused_by_operator' && $paused['outcome']==='manual_ready_to_resume','Existing automatic item was not paused.');
pause_assert($paused['lease_token']===null && $paused['next_retry_at']===null,'Pause retained a lease/countdown.');
pause_assert(generation_batch_claim_items()===[],'Paused automatic dispatcher claimed an old item after restart.');
try { full_auto_execute(false); throw new RuntimeException('Full-auto scheduler ignored the operator pause.'); }
catch (RuntimeException $exception) { pause_assert(str_contains(strtolower($exception->getMessage()),'paused'),'Unexpected full-auto pause error.'); }
pause_assert((int)$database->query('SELECT COUNT(*) FROM gemini_quota_events')->fetchColumn()===0,'Pausing consumed Gemini quota.');

$manualA=create_topic_workflow_batch([$manualTopic],'generate_all','manual-a-'.$token,'admin');
$manualB=create_topic_workflow_batch([$manualTopic],'generate_all','manual-b-'.$token,'admin');
pause_assert((int)$manualA['id']===(int)$manualB['id'],'Double-click created a second manual batch.');
$manualBatch=$database->query('SELECT dispatch_mode FROM generation_batches WHERE id='.(int)$manualA['id'])->fetchColumn();
pause_assert($manualBatch==='operator_manual','Manual generate_all was not marked operator_manual.');
$claims=generation_batch_claim_items();
pause_assert(count($claims)===1 && (int)$claims[0]['id']===(int)$manualA['items'][0]['id'],'Manual generate_all did not run during dispatcher pause.');
$database->prepare('UPDATE generation_batch_items SET lease_token=NULL,lease_expires_at=NULL,status="paused_by_operator",outcome="manual_ready_to_resume" WHERE id=:id')->execute([':id'=>(int)$claims[0]['id']]);
pause_assert((int)$database->query('SELECT COUNT(*) FROM research_packages')->fetchColumn()===$beforeResearch && (int)$database->query('SELECT COUNT(*) FROM article_draft_versions')->fetchColumn()===$beforeDrafts,'Pause/restart changed preserved checkpoints.');

$resume=generation_set_automatic_dispatch_paused(false,'test');
pause_assert($resume['after']['paused']===false,'Explicit resume did not clear dispatcher pause.');
pause_assert(generation_batch_find_item($automaticItem)['status']==='paused_by_operator','Resume silently requeued a paused item.');

echo "AUTOMATIC_DISPATCH_PAUSE_SMOKE_OK\n";
@unlink($databaseFile);
