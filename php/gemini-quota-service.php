<?php

declare(strict_types=1);

final class GeminiQuotaWaitException extends RuntimeException
{
    public function __construct(
        public readonly string $quotaDimension,
        public readonly string $quotaModel,
        public readonly string $nextRetryAt,
        string $message
    ) {
        parent::__construct($message);
    }
}

final class GeminiTopicBudgetException extends RuntimeException
{
    public function __construct(public readonly int $topicId, public readonly int $usedRequests)
    {
        parent::__construct('Budzet 15 wywolan Gemini dla tematu zostal wykorzystany; uruchamiam deterministyczny safe composer.');
    }
}

function gemini_configured_models(?string $primary = null): array
{
    $models = array_merge([$primary ?: (string) app_config('gemini_model')], (array) app_config('gemini_model_fallbacks'));
    $models = array_map('trim', $models);
    $models = array_filter($models, static fn (string $model): bool => preg_match('/^[a-zA-Z0-9._-]{2,100}$/', $model) === 1);
    return array_values(array_unique($models));
}

/** Opaque quota scope: a changed project/key cannot inherit cooldown state and no secret is persisted. */
function gemini_quota_project_identity(?string $apiKey = null, ?string $projectLabel = null): string
{
    $projectLabel = trim($projectLabel ?? (string) app_config('gemini_quota_project')) ?: 'default';
    $keyDigest = hash('sha256', (string) $apiKey);
    return 'scope-' . substr(hash('sha256', $projectLabel . "\0" . $keyDigest), 0, 24);
}

function gemini_call_reason(array $operation): string
{
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    return mb_substr((string) ($input['repair_strategy'] ?? $input['strategy'] ?? $operation['operation_type'] ?? 'generation'), 0, 100);
}

function gemini_call_fingerprint(array $operation, string $model): string
{
    return hash('sha256', implode("\n", [$model, (string) ($operation['operation_type'] ?? ''), (string) ($operation['input_hash'] ?? ''), (string) ($operation['prompt_text'] ?? ''), (string) ($operation['output_schema_json'] ?? '')]));
}

function gemini_estimated_tokens(array $payload): int
{
    return max(1, (int) ceil(mb_strlen(generation_json($payload), 'UTF-8') / 4));
}

function gemini_next_daily_reset(int $now): int
{
    try {
        $zone = new DateTimeZone((string) app_config('gemini_quota_reset_timezone'));
    } catch (Throwable) {
        $zone = new DateTimeZone('UTC');
    }
    $date = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->modify('tomorrow')->setTime(0, 0);
    return $date->getTimestamp();
}

function gemini_quota_wait_message(string $dimension, string $model): string
{
    return match ($dimension) {
        'RPD' => 'Wyczerpano dzienny limit modelu ' . $model . '.',
        'TPM' => 'Oczekiwanie na odnowienie limitu tokenów modelu ' . $model . '.',
        'concurrency' => 'Inny proces używa obecnie modelu ' . $model . '.',
        default => 'Oczekiwanie na limit zapytań modelu ' . $model . '.',
    };
}

/** Transactional, cross-process admission control. Returns a lease and event identifier. */
function gemini_quota_acquire(PDO $database, string $project, string $model, int $operationId, string $reason, string $fingerprint, int $estimatedTokens, ?int $now = null): array
{
    $now ??= time();
    $rpm = (int) app_config('gemini_rpm_target');
    $tpm = (int) app_config('gemini_tpm_target');
    $rpd = (int) app_config('gemini_rpd_target');
    $leaseSeconds = (int) app_config('gemini_quota_lease_seconds');
    $database->exec('BEGIN IMMEDIATE');
    try {
        $database->prepare('DELETE FROM gemini_model_leases WHERE expires_at <= :now')->execute([':now' => gmdate('Y-m-d H:i:s', $now)]);
        $state = $database->prepare('SELECT quota_dimension,next_retry_at FROM gemini_quota_state WHERE project_key=:project AND model=:model');
        $state->execute([':project' => $project, ':model' => $model]);
        $blocked = $state->fetch();
        if (is_array($blocked) && !empty($blocked['next_retry_at']) && strtotime((string) $blocked['next_retry_at'] . ' UTC') > $now) {
            throw new GeminiQuotaWaitException((string) $blocked['quota_dimension'], $model, gmdate('c', strtotime((string) $blocked['next_retry_at'] . ' UTC')), gemini_quota_wait_message((string) $blocked['quota_dimension'], $model));
        }
        $lease = $database->prepare('SELECT expires_at FROM gemini_model_leases WHERE project_key=:project AND model=:model');
        $lease->execute([':project' => $project, ':model' => $model]);
        if ($lease->fetch()) {
            $retryAt = $now + 1;
            throw new GeminiQuotaWaitException('concurrency', $model, gmdate('c', $retryAt), gemini_quota_wait_message('concurrency', $model));
        }
        $window = gmdate('Y-m-d H:i:s', $now - 59);
        $query = $database->prepare('SELECT COUNT(*) calls,COALESCE(SUM(CASE WHEN actual_tokens>0 THEN actual_tokens ELSE estimated_tokens END),0) tokens,MIN(created_at) oldest,MAX(created_at) newest FROM gemini_quota_events WHERE project_key=:project AND model=:model AND status<>"cancelled" AND created_at>=:window');
        $query->execute([':project' => $project, ':model' => $model, ':window' => $window]);
        $usage = $query->fetch() ?: [];
        $spacing = max(1, (int) ceil(60 / max(1, $rpm)));
        $newest = !empty($usage['newest']) ? strtotime((string) $usage['newest'] . ' UTC') : 0;
        if ((int) ($usage['calls'] ?? 0) >= $rpm || ($newest > 0 && $newest + $spacing > $now)) {
            $retryAt = max($now + 1, $newest + $spacing, strtotime((string) ($usage['oldest'] ?? '') . ' UTC') + 60);
            throw new GeminiQuotaWaitException('RPM', $model, gmdate('c', $retryAt), gemini_quota_wait_message('RPM', $model));
        }
        if ((int) ($usage['tokens'] ?? 0) + $estimatedTokens > $tpm) {
            $retryAt = max($now + 1, strtotime((string) ($usage['oldest'] ?? '') . ' UTC') + 60);
            throw new GeminiQuotaWaitException('TPM', $model, gmdate('c', $retryAt), gemini_quota_wait_message('TPM', $model));
        }
        $dayStart = gmdate('Y-m-d 00:00:00', $now);
        $daily = $database->prepare('SELECT COUNT(*) FROM gemini_quota_events WHERE project_key=:project AND model=:model AND status<>"cancelled" AND created_at>=:day_start');
        $daily->execute([':project' => $project, ':model' => $model, ':day_start' => $dayStart]);
        if ((int) $daily->fetchColumn() >= $rpd) {
            $retryAt = gemini_next_daily_reset($now);
            throw new GeminiQuotaWaitException('RPD', $model, gmdate('c', $retryAt), gemini_quota_wait_message('RPD', $model));
        }
        $meta = $database->prepare('SELECT o.topic_id,o.operation_type,i.id item_id,i.batch_id,COALESCE(i.stage,o.operation_type) stage FROM generation_operations o LEFT JOIN generation_batch_items i ON i.topic_id=o.topic_id AND i.status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled") WHERE o.id=:operation ORDER BY i.id DESC LIMIT 1');
        $meta->execute([':operation' => $operationId]);
        $call = $meta->fetch() ?: ['topic_id'=>null,'item_id'=>null,'batch_id'=>null,'stage'=>''];
        $topicUsed = 0;
        if ((int) ($call['topic_id'] ?? 0) > 0) {
            $count = $database->prepare('SELECT COUNT(*) FROM gemini_quota_events WHERE topic_id=:topic AND status IN ("reserved","completed","failed")');
            $count->execute([':topic' => (int) $call['topic_id']]);
            $topicUsed = (int) $count->fetchColumn();
            $operationType = (string) ($call['operation_type'] ?? '');
            if ($topicUsed >= 15
                || ($topicUsed === 13 && $operationType !== 'article_draft')
                || ($topicUsed === 14 && $operationType !== 'quality_check')) {
                throw new GeminiTopicBudgetException((int) $call['topic_id'], $topicUsed);
            }
            if ($topicUsed === 13) $reason = 'source_bounded_finalizer';
            if ($topicUsed === 14) $reason = 'final_quality_check';
        }
        $token = bin2hex(random_bytes(16));
        $insert = $database->prepare('INSERT INTO gemini_quota_events(project_key,model,operation_id,topic_id,batch_id,item_id,stage,attempt,call_reason,fingerprint,estimated_tokens,created_at) VALUES(:project,:model,:operation,:topic,:batch,:item,:stage,:attempt,:reason,:fingerprint,:tokens,:created)');
        $insert->execute([':project'=>$project,':model'=>$model,':operation'=>$operationId,':topic'=>$call['topic_id'],':batch'=>$call['batch_id'],':item'=>$call['item_id'],':stage'=>(string)$call['stage'],':attempt'=>$topicUsed+1,':reason'=>$reason,':fingerprint'=>$fingerprint,':tokens'=>$estimatedTokens,':created'=>gmdate('Y-m-d H:i:s',$now)]);
        $eventId = (int) $database->lastInsertId();
        $database->prepare('INSERT INTO gemini_model_leases(project_key,model,lease_token,operation_id,expires_at) VALUES(:project,:model,:token,:operation,:expires)')->execute([':project' => $project, ':model' => $model, ':token' => $token, ':operation' => $operationId, ':expires' => gmdate('Y-m-d H:i:s', $now + $leaseSeconds)]);
        $database->exec('COMMIT');
        return ['lease_token' => $token, 'event_id' => $eventId];
    } catch (Throwable $exception) {
        try { $database->exec('ROLLBACK'); } catch (Throwable) {}
        throw $exception;
    }
}

function gemini_quota_release(PDO $database, string $project, string $model, array $admission, string $status, int $actualTokens = 0): void
{
    $database->prepare('DELETE FROM gemini_model_leases WHERE project_key=:project AND model=:model AND lease_token=:token')->execute([':project' => $project, ':model' => $model, ':token' => (string) ($admission['lease_token'] ?? '')]);
    $database->prepare('UPDATE gemini_quota_events SET status=:status,actual_tokens=:tokens,completed_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':status' => $status, ':tokens' => max(0, $actualTokens), ':id' => (int) ($admission['event_id'] ?? 0)]);
}

function gemini_mark_quota_state(PDO $database, string $project, string $model, string $dimension, string $nextRetryAt, int $httpStatus, array $details = []): void
{
    $database->prepare('INSERT INTO gemini_quota_state(project_key,model,quota_dimension,next_retry_at,last_http_status,details_json,updated_at) VALUES(:project,:model,:dimension,:retry,:http,:details,CURRENT_TIMESTAMP) ON CONFLICT(project_key,model) DO UPDATE SET quota_dimension=excluded.quota_dimension,next_retry_at=excluded.next_retry_at,last_http_status=excluded.last_http_status,details_json=excluded.details_json,updated_at=CURRENT_TIMESTAMP')->execute([':project' => $project, ':model' => $model, ':dimension' => $dimension, ':retry' => gmdate('Y-m-d H:i:s', strtotime($nextRetryAt)), ':http' => $httpStatus, ':details' => generation_json($details)]);
}

function gemini_quota_response_details(array $response, string $model, ?int $now = null): array
{
    $now ??= time();
    $decoded = json_decode((string) ($response['body'] ?? ''), true) ?: [];
    $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
    $message = (string) ($error['message'] ?? '');
    $haystack = mb_strtolower($message . ' ' . generation_json($error['details'] ?? []));
    $dimension = str_contains($haystack, 'requests per day') || preg_match('/\brpd\b|daily|per day/', $haystack) ? 'RPD'
        : (str_contains($haystack, 'tokens per minute') || preg_match('/\btpm\b/', $haystack) ? 'TPM' : 'RPM');
    $seconds = 0;
    $header = trim((string) (($response['headers']['retry-after'] ?? $response['headers']['Retry-After'] ?? '')));
    if (preg_match('/^\d+$/', $header)) $seconds = (int) $header;
    foreach ((array) ($error['details'] ?? []) as $detail) {
        $retryDelay = (string) ($detail['retryDelay'] ?? '');
        if (preg_match('/^(\d+(?:\.\d+)?)s$/', $retryDelay, $match)) $seconds = max($seconds, (int) ceil((float) $match[1]));
    }
    $retryAt = $dimension === 'RPD' && $seconds === 0 ? gemini_next_daily_reset($now) : $now + max(1, $seconds);
    return ['dimension' => $dimension, 'model' => $model, 'next_retry_at' => gmdate('c', $retryAt), 'message' => $message];
}

function gemini_cached_call(PDO $database, string $project, string $model, string $fingerprint): ?array
{
    $statement = $database->prepare('SELECT output_json,provider_response_id,usage_json FROM gemini_call_cache WHERE project_key=:project AND model=:model AND fingerprint=:fingerprint');
    $statement->execute([':project' => $project, ':model' => $model, ':fingerprint' => $fingerprint]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function gemini_store_cached_call(PDO $database, string $project, string $model, string $fingerprint, string $output, string $responseId, array $usage): void
{
    $database->prepare('INSERT OR REPLACE INTO gemini_call_cache(project_key,model,fingerprint,output_json,provider_response_id,usage_json,created_at) VALUES(:project,:model,:fingerprint,:output,:response,:usage,CURRENT_TIMESTAMP)')->execute([':project' => $project, ':model' => $model, ':fingerprint' => $fingerprint, ':output' => $output, ':response' => $responseId, ':usage' => generation_json($usage)]);
}

function gemini_calls_per_article(int $postId): array
{
    $statement = bueno_database()->prepare('SELECT COUNT(*) calls,COALESCE(SUM(e.actual_tokens),0) tokens,GROUP_CONCAT(DISTINCT e.call_reason) reasons FROM gemini_quota_events e INNER JOIN generation_operations o ON o.id=e.operation_id WHERE o.post_id=:post_id AND e.status="completed"');
    $statement->execute([':post_id' => $postId]);
    $row = $statement->fetch() ?: [];
    return ['calls_per_article' => (int) ($row['calls'] ?? 0), 'tokens' => (int) ($row['tokens'] ?? 0), 'reasons' => array_values(array_filter(explode(',', (string) ($row['reasons'] ?? ''))))];
}

/** Counts only requests that were actually reserved for transport; cache hits never create these rows. */
function gemini_topic_live_request_count(int $topicId): int
{
    $statement = bueno_database()->prepare(
        'SELECT COUNT(*) FROM gemini_quota_events
         WHERE topic_id=:topic AND status IN ("reserved","completed","failed")'
    );
    $statement->execute([':topic' => $topicId]);
    return (int) $statement->fetchColumn();
}
