<?php

declare(strict_types=1);

$databaseFile = sys_get_temp_dir() . '/mamona-gemini-quota-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('GEMINI_API_MOCK=1');
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_RPM_TARGET=10');
putenv('GEMINI_MODEL_FALLBACKS=gemini-backup');
require_once dirname(__DIR__) . '/php/admin-database.php';

function quota_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$database = bueno_database();
$schema = ['type'=>'object','properties'=>['ok'=>['type'=>'boolean']],'required'=>['ok'],'additionalProperties'=>false];
$operationId = prepare_generation_operation('quota_fixture', ['fixture'=>'quota'], $schema);
$base = strtotime('2026-08-01 10:00:00 UTC');
$first = gemini_quota_acquire($database, 'fixture', 'gemini-fixture', $operationId, 'research', 'fp-0', 10, $base);
$secondConnection = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$secondConnection->exec('PRAGMA busy_timeout=5000');
try {
    gemini_quota_acquire($secondConnection, 'fixture', 'gemini-fixture', $operationId, 'draft', 'fp-concurrent', 10, $base);
    throw new RuntimeException('Cross-process concurrency lease did not block a second request.');
} catch (GeminiQuotaWaitException $exception) {
    quota_assert($exception->quotaDimension === 'concurrency', 'Wrong shared concurrency dimension.');
}
gemini_quota_release($database, 'fixture', 'gemini-fixture', $first, 'completed', 10);
for ($index=1;$index<10;$index++) {
    $admission=gemini_quota_acquire($database,'fixture','gemini-fixture',$operationId,'qc','fp-'.$index,10,$base+($index*6));
    gemini_quota_release($database,'fixture','gemini-fixture',$admission,'completed',10);
}
try {
    gemini_quota_acquire($database,'fixture','gemini-fixture',$operationId,'qc','fp-10',10,$base+59);
    throw new RuntimeException('10 RPM sliding window admitted request 11.');
} catch (GeminiQuotaWaitException $exception) {
    quota_assert($exception->quotaDimension === 'RPM', 'Wrong RPM classification.');
}
$afterWindow=gemini_quota_acquire($database,'fixture','gemini-fixture',$operationId,'qc','fp-after',10,$base+60);
gemini_quota_release($database,'fixture','gemini-fixture',$afterWindow,'completed',10);
for($index=11;$index<20;$index++) {
    $at=$base+60+(($index-10)*6);
    $admission=gemini_quota_acquire($database,'fixture','gemini-fixture',$operationId,'qc','fp-'.$index,10,$at);
    gemini_quota_release($database,'fixture','gemini-fixture',$admission,'completed',10);
}
$minutePeaks=$database->query('SELECT substr(created_at,1,16) minute,COUNT(*) calls FROM gemini_quota_events WHERE project_key="fixture" AND model="gemini-fixture" GROUP BY minute')->fetchAll();
quota_assert(max(array_column($minutePeaks,'calls'))<=10, 'Twenty jobs exceeded the configured 10 RPM target.');

$rpd = gemini_quota_response_details(['body'=>json_encode(['error'=>['message'=>'Quota exceeded for requests per day','details'=>[]]]),'headers'=>[]], 'gemini-fixture', $base);
quota_assert($rpd['dimension']==='RPD' && strtotime($rpd['next_retry_at'])>$base, 'RPD reset was not classified/scheduled.');
$rpm = gemini_quota_response_details(['body'=>json_encode(['error'=>['message'=>'requests per minute','details'=>[['retryDelay'=>'17s']]]]),'headers'=>[]], 'gemini-fixture', $base);
quota_assert($rpm['dimension']==='RPM' && strtotime($rpm['next_retry_at'])===$base+17, 'RetryInfo delay was not honored.');

$oldScope=gemini_quota_project_identity('old-project-key','project-old');
$newScope=gemini_quota_project_identity('new-project-key','project-new');
quota_assert($oldScope!==$newScope && !str_contains($oldScope,'old-project-key') && !str_contains($newScope,'new-project-key'),'Quota project identity leaks a key or collides across projects.');
gemini_mark_quota_state($database,$oldScope,'scope-model','RPD',gmdate('c',$base+3600),429,['fixture'=>'old-project']);
$newScopeAdmission=gemini_quota_acquire($database,$newScope,'scope-model',$operationId,'research','new-scope-fp',10,$base+500);
gemini_quota_release($database,$newScope,'scope-model',$newScopeAdmission,'completed',10);

$fallbackId=prepare_generation_operation('quota_fallback_fixture',['fixture'=>'rpd-fallback'],$schema);
$modelsSeen=[];
$fallbackResult=execute_generation_operation($fallbackId,static function(array $payload,string $key,string $operationKey,string $model) use (&$modelsSeen): array {
    $modelsSeen[]=$model;
    if($model!=='gemini-backup') return ['status'=>429,'body'=>generation_json(['error'=>['message'=>'Quota exceeded for requests per day']]),'headers'=>[],'network_error'=>''];
    return ['status'=>200,'body'=>generation_json(['responseId'=>'fallback','candidates'=>[['content'=>['parts'=>[['text'=>generation_json(['ok'=>true])]]]]],'usageMetadata'=>['totalTokenCount'=>1]]),'headers'=>[],'network_error'=>''];
},'fixture-key');
quota_assert($fallbackResult['ok']===true && $modelsSeen===[(string)app_config('gemini_model'),'gemini-backup'], 'RPD did not switch to configured fallback exactly once.');
quota_assert((string)find_generation_operation($fallbackId)['model_used']==='gemini-backup', 'Fallback model_used was not persisted.');

$sourceId=save_technical_source(['name'=>'Quota fixture','website_url'=>'https://example.com/quota','feed_url'=>'https://example.com/quota.xml','source_type'=>'rss','topic_category'=>'science','language'=>'pl','credibility_level'=>5,'is_primary'=>1,'is_active'=>1]);
$source=find_technical_source($sourceId);
$postId=persist_discovered_feed_item($source,['external_id'=>'quota-topic','source_url'=>'https://example.com/quota/article','title'=>'Kontrolowany budzet tematu','source_name'=>'Quota fixture','published_at'=>gmdate('Y-m-d H:i:s'),'summary'=>'Zweryfikowany material testowy.','category'=>'science','content_hash'=>hash('sha256','quota-topic')]);
$topicQuery=$database->prepare('SELECT topic_id FROM feed_topic_memberships m INNER JOIN discovered_feed_items i ON i.id=m.feed_item_id WHERE i.post_id=:post');$topicQuery->execute([':post'=>$postId]);$topicId=(int)$topicQuery->fetchColumn();
for($number=1;$number<=14;$number++) $database->prepare('INSERT INTO gemini_quota_events(project_key,model,topic_id,stage,attempt,call_reason,fingerprint,status,created_at) VALUES("budget-fixture","budget-model",:topic,"repair",:attempt,"repair",:fingerprint,"completed",:created)')->execute([':topic'=>$topicId,':attempt'=>$number,':fingerprint'=>'budget-'.$number,':created'=>gmdate('Y-m-d H:i:s',$base+$number)]);
$finalizerId=prepare_generation_operation('article_draft',['fixture'=>'source-bounded-finalizer'],$schema,$postId,$topicId);
$database->exec('DELETE FROM gemini_quota_events WHERE topic_id=' . $topicId . ' AND attempt=14');
$finalizerAdmission=gemini_quota_acquire($database,'budget-fixture','budget-model',$finalizerId,'article_draft','finalizer',10,$base+9994);
$finalizerEvent=$database->query('SELECT attempt,call_reason FROM gemini_quota_events ORDER BY id DESC LIMIT 1')->fetch();
quota_assert((int)$finalizerEvent['attempt']===14 && $finalizerEvent['call_reason']==='source_bounded_finalizer','Request 14 was not reserved for the source-bounded finalizer.');
gemini_quota_release($database,'budget-fixture','budget-model',$finalizerAdmission,'completed',10);
$finalQcId=prepare_generation_operation('quality_check',['fixture'=>'final-qc'],$schema,$postId,$topicId);
$finalAdmission=gemini_quota_acquire($database,'budget-fixture','budget-model',$finalQcId,'quality_check','final-qc',10,$base+10000);
$finalEvent=$database->query('SELECT attempt,call_reason FROM gemini_quota_events ORDER BY id DESC LIMIT 1')->fetch();
quota_assert((int)$finalEvent['attempt']===15 && $finalEvent['call_reason']==='final_quality_check','Request 15 was not reserved exclusively for final QC.');
gemini_quota_release($database,'budget-fixture','budget-model',$finalAdmission,'completed',10);
try { gemini_quota_acquire($database,'budget-fixture','budget-model',$finalQcId,'quality_check','request-16',10,$base+10006); throw new RuntimeException('Request 16 was admitted.'); }
catch(GeminiTopicBudgetException $exception){ quota_assert($exception->usedRequests===15,'Topic budget did not stop exactly after 15 sent calls.'); }

$sameA=prepare_generation_operation('field_text_repair', ['fixture'=>'idempotent'], $schema);
$sameB=prepare_generation_operation('field_text_repair', ['fixture'=>'idempotent'], $schema);
quota_assert($sameA===$sameB, 'Identical stage fingerprint created duplicate operations.');
$eventsBefore=(int)$database->query('SELECT COUNT(*) FROM gemini_quota_events')->fetchColumn();
execute_generation_operation($sameA);
$eventsAfter=(int)$database->query('SELECT COUNT(*) FROM gemini_quota_events')->fetchColumn();
quota_assert($eventsBefore===$eventsAfter, 'Built-in test mock consumed a live ledger request.');

echo "GEMINI_QUOTA_SMOKE_OK\n";
@unlink($databaseFile);
