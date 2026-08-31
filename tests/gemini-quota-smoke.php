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
$draftId=prepare_generation_operation('quota_fixture',['fixture'=>'central-budget'],$schema,$postId,$topicId);
$responses=0;
$transport=static function() use (&$responses): array { $responses++; return ['status'=>200,'body'=>generation_json(['responseId'=>'budget-'.$responses,'candidates'=>[['content'=>['parts'=>[['text'=>generation_json(['ok'=>true])]]]]],'usageMetadata'=>['totalTokenCount'=>1]]),'headers'=>[],'network_error'=>'']; };
execute_generation_operation($draftId,$transport,'fixture-key');
$qcId=prepare_generation_operation('quota_fixture',['fixture'=>'central-budget-qc'],$schema,$postId,$topicId);
execute_generation_operation($qcId,$transport,'fixture-key');
$budget=gemini_article_budget_state($postId);
quota_assert($responses===2 && (int)$budget['used_calls']===2,'Two actual controlled Gemini responses did not consume exactly two shared budget points.');
$database->prepare('UPDATE article_generation_budget SET max_calls=20,used_calls=20,is_exhausted=1 WHERE article_id=:post')->execute([':post'=>$postId]);
$blockedId=prepare_generation_operation('quota_fixture',['fixture'=>'budget-blocked'],$schema,$postId,$topicId);
$blockedTransportCalls=0;
try {
    execute_generation_operation($blockedId,static function() use (&$blockedTransportCalls): array { $blockedTransportCalls++; return ['status'=>200,'body'=>'{}','headers'=>[],'network_error'=>'']; },'fixture-key');
    throw new RuntimeException('Request 21 reached the controlled transport.');
} catch (GeminiArticleBudgetException) {
    quota_assert($blockedTransportCalls===0,'Request 21 reached the controlled transport before the budget gate.');
    quota_assert((string)find_generation_operation($blockedId)['status']==='failed', 'Rejected request 21 left generation operation in running/prepared state.');
    quota_assert((int)find_generation_operation($blockedId)['live_request_count']===0, 'Rejected request 21 was recorded as a live provider call.');
}

// The article budget claim is durable and atomic across independent SQLite
// connections: the second contender cannot claim call 21, and a no-response
// transport release makes exactly that single point available again.
$raceArticleId = $postId + 100000;
$database->prepare('INSERT INTO article_generation_budget (article_id,max_calls,used_calls,convergence_threshold,calls_log_json,is_exhausted,convergence_active,created_at,updated_at) VALUES (:id,20,19,16,"[]",0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')->execute([':id'=>$raceArticleId]);
$firstClaim = gemini_article_budget_claim($database, $raceArticleId, 'image_vision_assessment', 'images', 1, 'race-first');
try {
    gemini_article_budget_claim($secondConnection, $raceArticleId, 'image_vision_assessment', 'images', 1, 'race-second');
    throw new RuntimeException('Second SQLite contender claimed the same final Gemini budget point.');
} catch (GeminiArticleBudgetException) {
    quota_assert((int)gemini_article_budget_state($raceArticleId)['used_calls']===20, 'Atomic first claim was not persisted before the second contender.');
}
quota_assert(gemini_article_budget_reconcile_claim($database, $raceArticleId, (string)$firstClaim['claim_token'], 'released'), 'No-response claim was not reconciled.');
quota_assert((int)gemini_article_budget_state($raceArticleId)['used_calls']===19, 'Released no-response claim did not return exactly one budget point.');
$finalClaim = gemini_article_budget_claim($database, $raceArticleId, 'image_vision_assessment', 'images', 1, 'race-final');
gemini_article_budget_reconcile_claim($database, $raceArticleId, (string)$finalClaim['claim_token'], 'completed');
$secondConnection = null;
quota_assert((int)gemini_article_budget_state($raceArticleId)['used_calls']===20, 'Reconciled provider response did not consume exactly one final budget point.');

// Direct Vision has to stop at the closure floor, even if another worker asks
// the central budget directly after the allowance was calculated.
$closureArticleId = $postId + 100001;
$database->prepare('INSERT INTO article_generation_budget (article_id,max_calls,used_calls,convergence_threshold,calls_log_json,is_exhausted,convergence_active,created_at,updated_at) VALUES (:id,20,14,16,"[]",0,0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')->execute([':id'=>$closureArticleId]);
$secondConnection = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$secondConnection->exec('PRAGMA busy_timeout=5000');
try {
    gemini_article_budget_claim($secondConnection, $closureArticleId, 'image_vision_assessment', 'images', 1, 'closure-direct', 7);
    throw new RuntimeException('Direct Vision consumed closure-reserved Gemini budget.');
} catch (GeminiArticleBudgetException) {
    $secondConnection = null;
    quota_assert((int)gemini_article_budget_state($closureArticleId)['used_calls']===14, 'Closure-floor rejection mutated article budget.');
}

// A final Vision validation needs its own call plus layout and final QC. The
// equality boundary is legal: only a smaller remaining budget is blocked.
$boundaryArticleId = $postId + 100002;
$insertBoundary = $database->prepare('INSERT INTO article_generation_budget (article_id,max_calls,used_calls,convergence_threshold,calls_log_json,is_exhausted,convergence_active,created_at,updated_at) VALUES (:id,:max,:used,24,"[]",0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
$insertBoundary->execute([':id'=>$boundaryArticleId, ':max'=>30, ':used'=>28]);
try {
    gemini_article_budget_claim($database, $boundaryArticleId, 'image_vision_assessment', 'images', 1, 'boundary-less', 2);
    throw new RuntimeException('Vision was admitted with fewer than its three required remaining calls.');
} catch (GeminiArticleBudgetException) {
    quota_assert((int)gemini_article_budget_state($boundaryArticleId)['used_calls']===28, 'Below-boundary rejection mutated article budget.');
}
$boundaryArticleId++;
$insertBoundary->execute([':id'=>$boundaryArticleId, ':max'=>31, ':used'=>28]);
$equalClaim = gemini_article_budget_claim($database, $boundaryArticleId, 'image_vision_assessment', 'images', 1, 'boundary-equal', 2);
quota_assert((int)$equalClaim['used_after']===29, 'Vision was not admitted when exactly Vision, layout, and final QC remained.');
gemini_article_budget_reconcile_claim($database, $boundaryArticleId, (string)$equalClaim['claim_token'], 'released');
$boundaryArticleId++;
$insertBoundary->execute([':id'=>$boundaryArticleId, ':max'=>32, ':used'=>28]);
$greaterClaim = gemini_article_budget_claim($database, $boundaryArticleId, 'image_vision_assessment', 'images', 1, 'boundary-greater', 2);
quota_assert((int)$greaterClaim['used_after']===29, 'Vision was not admitted with more than its three required remaining calls.');
gemini_article_budget_reconcile_claim($database, $boundaryArticleId, (string)$greaterClaim['claim_token'], 'released');

// Layout itself reserves the final multimodal QC, so the same equality rule
// applies to the last two-step closure chain.
$layoutBoundaryArticleId = $postId + 100005;
$insertBoundary->execute([':id'=>$layoutBoundaryArticleId, ':max'=>31, ':used'=>30]);
try {
    gemini_article_budget_claim($database, $layoutBoundaryArticleId, 'layout_plan', 'layout', 1, 'layout-less', article_recovery_protected_closure_calls('layout_plan'));
    throw new RuntimeException('Layout was admitted without the final QC budget point.');
} catch (GeminiArticleBudgetException) {
    quota_assert((int)gemini_article_budget_state($layoutBoundaryArticleId)['used_calls']===30, 'Layout below-boundary rejection mutated article budget.');
}
$layoutBoundaryArticleId++;
$insertBoundary->execute([':id'=>$layoutBoundaryArticleId, ':max'=>31, ':used'=>29]);
$layoutEqualClaim = gemini_article_budget_claim($database, $layoutBoundaryArticleId, 'layout_plan', 'layout', 1, 'layout-equal', article_recovery_protected_closure_calls('layout_plan'));
quota_assert((int)$layoutEqualClaim['used_after']===30, 'Layout was not admitted when layout and final QC exactly remained.');
gemini_article_budget_reconcile_claim($database, $layoutBoundaryArticleId, (string)$layoutEqualClaim['claim_token'], 'released');

$sameA=prepare_generation_operation('field_text_repair', ['fixture'=>'idempotent'], $schema);
$sameB=prepare_generation_operation('field_text_repair', ['fixture'=>'idempotent'], $schema);
quota_assert($sameA===$sameB, 'Identical stage fingerprint created duplicate operations.');
$eventsBefore=(int)$database->query('SELECT COUNT(*) FROM gemini_quota_events')->fetchColumn();
execute_generation_operation($sameA);
$eventsAfter=(int)$database->query('SELECT COUNT(*) FROM gemini_quota_events')->fetchColumn();
quota_assert($eventsBefore===$eventsAfter, 'Built-in test mock consumed a live ledger request.');

echo "GEMINI_QUOTA_SMOKE_OK\n";
@unlink($databaseFile);
