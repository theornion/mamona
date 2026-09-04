<?php

declare(strict_types=1);

const GENERATION_BATCH_ACTIVE_STATUSES = ['queued', 'research', 'draft', 'auto_repair', 'quality_check', 'images', 'rate_limited', 'auto_retry_scheduled'];
const GENERATION_BATCH_TERMINAL_STATUSES = ['ready', 'ready_for_preview', 'ready_with_notes', 'completed', 'already_complete', 'auto_rejected', 'waiting_review', 'manual_review', 'paused_by_operator', 'skipped_prerequisite', 'invalid', 'failed', 'cancelled'];
const GENERATION_WORKFLOW_ACTIONS = ['research', 'draft', 'quality', 'images', 'generate_all', 'retry'];
const GENERATION_WORKFLOW_STAGES = ['research', 'draft', 'quality_check', 'images'];

final class GenerationBatchItemPausedException extends RuntimeException
{
}

function generation_batch_image_coverage_allows_finalization(array $coverage): bool
{
    return !empty($coverage['coverage_complete']);
}

function generation_automatic_dispatch_paused(): bool
{
    if ((bool) app_config('automatic_dispatch_paused')) return true;
    $value = bueno_database()->query('SELECT automatic_dispatch_paused FROM generation_settings WHERE id=1')->fetchColumn();
    return (int) $value === 1;
}

function generation_dispatch_pause_report(?PDO $database = null): array
{
    $database ??= bueno_database();
    $statusRows = $database->query('SELECT status,COUNT(*) count FROM generation_batch_items GROUP BY status ORDER BY status')->fetchAll();
    $statuses = [];
    foreach ($statusRows as $row) $statuses[(string) $row['status']] = (int) $row['count'];
    $active = array_sum(array_intersect_key($statuses, array_flip(GENERATION_BATCH_ACTIVE_STATUSES)));
    $activeRows = $database->query('SELECT i.id item_id,i.topic_id,i.status,i.stage,b.id batch_id,b.dispatch_mode,b.created_by
        FROM generation_batch_items i INNER JOIN generation_batches b ON b.id=i.batch_id
        WHERE i.status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled")
        ORDER BY i.id')->fetchAll();
    return [
        'paused' => (int) $database->query('SELECT automatic_dispatch_paused FROM generation_settings WHERE id=1')->fetchColumn() === 1,
        'statuses' => $statuses,
        'active_items' => $active,
        'active' => array_map(static fn (array $row): array => [
            'item_id' => (int) $row['item_id'], 'topic_id' => (int) $row['topic_id'], 'batch_id' => (int) $row['batch_id'],
            'status' => (string) $row['status'], 'stage' => (string) $row['stage'],
            'dispatch_mode' => (string) $row['dispatch_mode'], 'created_by' => (string) $row['created_by'],
        ], $activeRows),
        'item_leases' => (int) $database->query('SELECT COUNT(*) FROM generation_batch_items WHERE lease_token IS NOT NULL')->fetchColumn(),
        'model_leases' => (int) $database->query('SELECT COUNT(*) FROM gemini_model_leases')->fetchColumn(),
        'worker_guards' => (int) $database->query('SELECT COUNT(*) FROM generation_worker_guard')->fetchColumn(),
        'running_operations' => (int) $database->query('SELECT COUNT(*) FROM generation_operations WHERE status="running"')->fetchColumn(),
        'running_operations_for_paused_topics' => (int) $database->query('SELECT COUNT(*) FROM generation_operations o WHERE o.status="running"
            AND o.topic_id IN (SELECT DISTINCT topic_id FROM generation_batch_items WHERE status="paused_by_operator")')->fetchColumn(),
        'preserved' => [
            'approved_research' => (int) $database->query('SELECT COUNT(*) FROM research_packages WHERE status="approved"')->fetchColumn(),
            'completed_drafts' => (int) $database->query('SELECT COUNT(*) FROM article_draft_versions WHERE status="completed"')->fetchColumn(),
            'active_validated_drafts' => (int) $database->query('SELECT COUNT(*) FROM article_draft_versions WHERE status="completed" AND is_active=1')->fetchColumn(),
            'completed_qc' => (int) $database->query('SELECT COUNT(*) FROM quality_check_runs WHERE status="completed"')->fetchColumn(),
            'downloaded_images' => (int) $database->query('SELECT COUNT(*) FROM article_images WHERE status="downloaded"')->fetchColumn(),
        ],
    ];
}

function generation_set_automatic_dispatch_paused(bool $paused, string $actor = 'operator', bool $includeExistingManual = false): array
{
    $database = bueno_database();
    $before = generation_dispatch_pause_report($database);
    $database->exec('BEGIN IMMEDIATE');
    try {
        $database->prepare('UPDATE generation_settings SET automatic_dispatch_paused=:paused,
            automatic_dispatch_paused_at=CASE WHEN :paused=1 THEN CURRENT_TIMESTAMP ELSE NULL END,updated_at=CURRENT_TIMESTAMP WHERE id=1')
            ->execute([':paused' => $paused ? 1 : 0]);
        $pausedItems = [];
        $releasedItemLeases = 0;
        $releasedModelLeases = 0;
        $releasedWorkerGuards = 0;
        $resetRunningOperations = 0;
        $resetOrphanedOperations = 0;
        if ($paused) {
            $holders = implode(',', array_fill(0, count(GENERATION_BATCH_ACTIVE_STATUSES), '?'));
            $manualFilter = $includeExistingManual ? '' : ' AND COALESCE(b.dispatch_mode,"automatic")<>"operator_manual"';
            $select = $database->prepare('SELECT i.id,i.batch_id,i.topic_id,i.status,i.lease_token FROM generation_batch_items i
                INNER JOIN generation_batches b ON b.id=i.batch_id
                WHERE i.status IN (' . $holders . ')' . $manualFilter . ' ORDER BY i.id');
            $select->execute(GENERATION_BATCH_ACTIVE_STATUSES);
            $pausedItems = $select->fetchAll();
            $releasedItemLeases = count(array_filter($pausedItems, static fn (array $item): bool => !empty($item['lease_token'] ?? null)));
            foreach ($pausedItems as $item) {
                if (!empty($item['status'])) {
                    $database->prepare('UPDATE generation_batch_items SET
                        paused_from_status=status,status="paused_by_operator",outcome="manual_ready_to_resume",
                        wait_reason="Wstrzymany — uruchom ręcznie.",next_retry_at=NULL,quota_dimension="",quota_model="",
                        lease_token=NULL,lease_expires_at=NULL,paused_at=CURRENT_TIMESTAMP,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP
                        WHERE id=:id')->execute([':id' => (int) $item['id']]);
                    $database->prepare('INSERT INTO generation_batch_audit(batch_id,item_id,action,actor,details_json)
                        VALUES(:batch,:item,"automatic_dispatch_paused",:actor,:details)')->execute([
                        ':batch' => (int) $item['batch_id'], ':item' => (int) $item['id'], ':actor' => mb_substr($actor, 0, 100),
                        ':details' => generation_json(['previous_status' => (string) $item['status'], 'checkpoint_preserved' => true]),
                    ]);
                }
            }
            $database->exec('UPDATE generation_batches SET status="paused",updated_at=CURRENT_TIMESTAMP
                WHERE id IN (SELECT DISTINCT batch_id FROM generation_batch_items WHERE status="paused_by_operator")');
            $releasedModelLeases = $database->exec('DELETE FROM gemini_model_leases');
            $releasedWorkerGuards = $database->exec('DELETE FROM generation_worker_guard');
            $resetRunningOperations = $database->exec('UPDATE generation_operations SET status="prepared",next_retry_at=NULL,
                updated_at=CURRENT_TIMESTAMP WHERE status="running" AND topic_id IN
                (SELECT DISTINCT topic_id FROM generation_batch_items WHERE status="paused_by_operator")');
            $resetOrphanedOperations = $database->exec('UPDATE generation_operations SET status="prepared",next_retry_at=NULL,
                updated_at=CURRENT_TIMESTAMP WHERE status="running" AND NOT EXISTS
                (SELECT 1 FROM generation_batch_items i WHERE i.topic_id=generation_operations.topic_id
                 AND i.status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled"))');
            $database->exec('UPDATE gemini_quota_events SET status="cancelled",completed_at=CURRENT_TIMESTAMP
                WHERE status="reserved" AND topic_id IN
                (SELECT DISTINCT topic_id FROM generation_batch_items WHERE status="paused_by_operator")');
        }
        $database->exec('COMMIT');
    } catch (Throwable $exception) {
        try { $database->exec('ROLLBACK'); } catch (Throwable) {}
        throw $exception;
    }
    return [
        'before' => $before,
        'after' => generation_dispatch_pause_report($database),
        'changed' => $paused,
        'paused_items' => isset($pausedItems) ? count($pausedItems) : 0,
        'released_item_leases' => $releasedItemLeases ?? 0,
        'released_model_leases' => $releasedModelLeases ?? 0,
        'released_worker_guards' => $releasedWorkerGuards ?? 0,
        'reset_running_operations' => $resetRunningOperations ?? 0,
        'reset_orphaned_operations' => $resetOrphanedOperations ?? 0,
    ];
}

function generation_batch_audit(int $batchId, ?int $itemId, string $action, string $actor = 'system', array $details = []): void
{
    bueno_database()->prepare(
        'INSERT INTO generation_batch_audit (batch_id, item_id, action, actor, details_json)
         VALUES (:batch_id, :item_id, :action, :actor, :details)'
    )->execute([
        ':batch_id' => $batchId,
        ':item_id' => $itemId,
        ':action' => mb_substr($action, 0, 100),
        ':actor' => mb_substr($actor, 0, 100),
        ':details' => generation_json($details),
    ]);
}

function generation_batch_assert_api_available(): void
{
    if (generation_mode() !== 'api') {
        throw new RuntimeException('Pełny batch wymaga trybu API. W trybie manualnym nie utworzono żadnych operacji.');
    }
    $provider = (string) app_config('generation_provider');
    $mock = (bool) app_config($provider === 'gemini' ? 'gemini_mock' : 'openai_mock');
    $keyName = $provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY';
    if (!$mock && app_environment_value($keyName) === null) {
        throw new RuntimeException('Pełny batch wymaga klucza ' . $keyName . ' albo lokalnego trybu mock.');
    }
}

function create_generation_batch(mixed $rawTopicIds, ?string $requestKey = null, string $actor = 'admin'): array
{
    generation_batch_assert_api_available();
    $topicIds = function_exists('content_studio_validate_topic_ids')
        ? content_studio_validate_topic_ids($rawTopicIds)
        : generation_batch_validate_topic_ids($rawTopicIds);
    $requestKey = trim((string) $requestKey);
    if ($requestKey === '') {
        $requestKey = bin2hex(random_bytes(16));
    }
    if (strlen($requestKey) > 128 || preg_match('/^[a-zA-Z0-9._:-]+$/', $requestKey) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowy klucz idempotencji batcha.');
    }
    $database = bueno_database();
    $existing = $database->prepare('SELECT * FROM generation_batches WHERE request_key = :request_key');
    $existing->execute([':request_key' => $requestKey]);
    $batch = $existing->fetch();
    if (is_array($batch)) {
        return generation_batch_payload((int) $batch['id']);
    }

    $database->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count(GENERATION_BATCH_ACTIVE_STATUSES), '?'));
        $active = $database->prepare(
            'SELECT DISTINCT items.topic_id
             FROM generation_batch_items AS items
             WHERE items.topic_id IN (' . implode(',', array_fill(0, count($topicIds), '?')) . ')
               AND items.status IN (' . $placeholders . ')'
        );
        $active->execute([...$topicIds, ...GENERATION_BATCH_ACTIVE_STATUSES]);
        if ($active->fetchColumn() !== false) {
            throw new DomainException('Co najmniej jeden temat ma już aktywny element batcha.');
        }
        $database->prepare(
            'INSERT INTO generation_batches (batch_key, request_key, item_count, created_by, execution_mode, dispatch_mode)
             VALUES (:batch_key, :request_key, :item_count, :actor, :execution_mode, :dispatch_mode)'
        )->execute([
            ':batch_key' => bin2hex(random_bytes(16)),
            ':request_key' => $requestKey,
            ':item_count' => count($topicIds),
            ':actor' => mb_substr($actor, 0, 100),
            ':execution_mode' => generation_mode(),
            ':dispatch_mode' => $actor === 'full-auto' ? 'automatic' : 'operator_manual',
        ]);
        $batchId = (int) $database->lastInsertId();
        $insert = $database->prepare(
            'INSERT INTO generation_batch_items (batch_id, topic_id) VALUES (:batch_id, :topic_id)'
        );
        foreach ($topicIds as $topicId) {
            $insert->execute([':batch_id' => $batchId, ':topic_id' => $topicId]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    generation_batch_audit($batchId, null, 'batch_created', $actor, ['topic_ids' => $topicIds]);

    return generation_batch_payload($batchId);
}

function generation_batch_validate_topic_ids(mixed $rawTopicIds): array
{
    if (!is_array($rawTopicIds)) {
        throw new InvalidArgumentException('Wybierz co najmniej jeden temat.');
    }
    $ids = [];
    foreach ($rawTopicIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Lista tematów zawiera nieprawidłowy identyfikator.');
        }
        $ids[(int) $id] = (int) $id;
    }
    $ids = array_values($ids);
    $maximum = (int) app_config('batch_max_topics');
    if ($ids === [] || count($ids) > $maximum) {
        throw new InvalidArgumentException('Wybierz od 1 do 10 tematów.');
    }
    foreach ($ids as $id) {
        if (find_editorial_topic($id) === null) {
            throw new InvalidArgumentException('Co najmniej jeden temat nie istnieje.');
        }
    }
    return $ids;
}

function generation_topics_workflow_payload(array $topics): array
{
    if ($topics === []) return [];
    $statuses = [];
    foreach (generation_workflow_statuses(array_column($topics, 'id')) as $status) $statuses[(int) $status['topic_id']] = $status;
    return array_map(static function (array $topic) use ($statuses): array {
        $topicId = (int) $topic['id'];
        $status = $statuses[$topicId] ?? ['status' => 'eligible', 'steps' => [], 'readiness' => false, 'proposal_url' => null, 'active_job_id' => null, 'active_batch_id' => null, 'latest_job_id' => null, 'wait_reason' => '', 'error' => '', 'progress' => 0];
        $selectable = (int) ($topic['automatic_eligible'] ?? 0) === 1
            && !in_array((string) ($topic['status'] ?? ''), ['rejected', 'trash'], true)
            && $status['active_job_id'] === null;
        $manualGenerateAllEnabled = $status['active_job_id'] === null
            && !in_array((string) ($topic['primary_post_status'] ?? $topic['status'] ?? ''), ['rejected', 'trash', 'published', 'scheduled'], true);
        $researchDone = ($status['steps']['research']['status'] ?? '') === 'completed';
        $draftDone = in_array((string) ($status['steps']['draft']['status'] ?? ''), ['completed', 'frozen'], true);
        $qualityDone = ($status['steps']['quality']['status'] ?? '') === 'completed';
        $queueState = generation_workflow_queue_state($status);
        $requiresAction = $queueState === 'action';
        $canResumeLegacy = ($status['latest_job_status'] ?? '') === 'auto_rejected'
            && ($status['latest_action'] ?? '') === 'generate_all'
            && !in_array((string) ($topic['primary_post_status'] ?? $topic['status'] ?? ''), ['rejected','trash','published','scheduled'], true)
            && $status['active_job_id'] === null;
        $reason = $selectable ? '' : ((string) ($status['wait_reason'] ?: $status['error']) ?: 'Automatyzacja jest zablokowana przez stan, scoring albo poziom ryzyka tematu.');
        return [
            'id' => $topicId, 'post_id' => (int) $topic['primary_post_id'], 'title' => (string) $topic['title'],
            'score' => $topic['score'] === null ? null : (int) $topic['score'], 'risk' => (string) $topic['risk_level'],
            'automatic_eligible' => (int) $topic['automatic_eligible'] === 1, 'status' => (string) $topic['status'],
            'item_count' => (int) $topic['item_count'], 'source_count' => (int) $topic['source_count'],
            'source_names' => (string) $topic['source_names'], 'selectable' => $selectable, 'unavailable_reason' => $reason,
            'requires_action' => $requiresAction,
            'can_resume_legacy' => $canResumeLegacy,
            'queue_state' => $queueState,
            'actions' => [
                'research' => ['enabled' => $selectable, 'reason' => $reason],
                'draft' => ['enabled' => $selectable && $researchDone, 'reason' => $researchDone ? $reason : 'Najpierw ukończ i zatwierdź research.'],
                'quality' => ['enabled' => $selectable && $draftDone, 'reason' => $draftDone ? $reason : 'Najpierw wygeneruj szkic.'],
                'images' => ['enabled' => $selectable && $qualityDone, 'reason' => $qualityDone ? $reason : 'Najpierw zalicz kontrolę jakości.'],
                'generate_all' => ['enabled' => $manualGenerateAllEnabled || $canResumeLegacy, 'reason' => $canResumeLegacy ? 'Wznowienie nowym routerem od bezpiecznego checkpointu.' : ($manualGenerateAllEnabled ? '' : $reason)],
            ],
            'workflow' => ['research' => ['done' => $researchDone, 'version' => $status['steps']['research']['result_id'] ?? null],
                'draft' => ['done' => $draftDone, 'version' => $status['steps']['draft']['version'] ?? null],
                'quality' => ['done' => $qualityDone, 'version' => $status['steps']['quality']['version'] ?? null],
                'images' => ['done' => ($status['steps']['images']['status'] ?? '') === 'completed'], 'ready' => (bool) $status['readiness']],
            'workflow_status' => $status, 'job' => $status['latest_job_id'] === null ? null : [
                'id' => $status['latest_job_id'], 'batch_id' => $status['latest_batch_id'], 'status' => $status['latest_job_status'],
                'action' => $status['latest_action'], 'outcome' => $status['latest_outcome'],
                'stage' => $status['latest_stage'], 'progress' => (int) $status['progress'], 'reason' => (string) ($status['wait_reason'] ?: $status['error']),
                'technical_error' => (string) $status['error'],
                'retryable' => (bool) $status['retryable'], 'review_url' => $status['proposal_url'],
                'available_at' => $status['available_at'] ?? null,
                'next_retry_at' => $status['next_retry_at'] ?? null,
                'quota_dimension' => $status['quota_dimension'] ?? '',
                'quota_model' => $status['quota_model'] ?? '',
                'gemini_calls_used' => $status['gemini_calls_used'] ?? 0,
                'gemini_call_budget' => $status['gemini_call_budget'] ?? 15,
                'image_completed' => $status['steps']['images']['completed'] ?? 0,
                'image_total' => $status['steps']['images']['total'] ?? 0,
                'retry_after_seconds' => $status['retry_after_seconds'] ?? null],
            'automation_report' => $status['latest_job_id'] === null ? ['events' => [], 'unresolved' => []] : repair_report_get((int) $status['latest_job_id']),
            'proposal_url' => $status['proposal_url'],
        ];
    }, $topics);
}

function generation_workflow_queue_state(array $status): string
{
    if (!empty($status['readiness'])) return 'ready';
    if (($status['steps']['images']['status'] ?? '') === 'manual_review') return 'action';
    return in_array((string) ($status['status'] ?? ''), ['waiting_review', 'manual_review', 'failed', 'rate_limited', 'ready', 'ready_for_preview', 'ready_with_notes'], true)
        ? 'action'
        : 'work';
}

function generation_topic_queue_counts(array $topics): array
{
    $counts = ['work' => 0, 'action' => 0, 'ready' => 0];
    foreach ($topics as $topic) {
        $state = (string) ($topic['queue_state'] ?? 'work');
        $counts[array_key_exists($state, $counts) ? $state : 'work']++;
    }
    return $counts;
}

function generation_topic_queue_visible(string $queueState, bool $showReady, bool $showAction): bool
{
    if ($queueState === 'ready') return $showReady;
    if ($queueState === 'action') return $showAction;
    return true;
}

function generation_active_topic_queue_counts(): array
{
    if (isset($GLOBALS['adminTopicQueueCounts']) && is_array($GLOBALS['adminTopicQueueCounts'])) return $GLOBALS['adminTopicQueueCounts'];
    return generation_topic_queue_counts(generation_topics_workflow_payload(list_editorial_topics(1000, 'active')));
}

function list_action_required_topic_payload(): array
{
    return array_values(array_filter(
        generation_topics_workflow_payload(list_editorial_topics(1000, 'active')),
        static fn (array $topic): bool => !empty($topic['requires_action'])
    ));
}

function create_generation_workflow_batch(mixed $rawTopicIds, string $action, ?string $requestKey = null, string $actor = 'admin', ?string $retryStage = null): array
{
    $batch = create_topic_workflow_batch($rawTopicIds, $action, $requestKey, $actor, $retryStage);
    $skipped = array_values(array_map(
        static fn (array $item): array => ['topic_id' => (int) $item['topic_id'], 'reason' => (string) ($item['wait_reason'] ?: $item['error_message'])],
        array_filter($batch['items'], static fn (array $item): bool => in_array((string) $item['status'], ['invalid', 'skipped_prerequisite', 'waiting_review'], true))
    ));
    return ['batch' => $batch, 'skipped' => $skipped, 'idempotent' => false];
}

function generation_workflow_latest(string $table, int $topicId): ?array
{
    $allowed = ['research_packages', 'article_draft_versions'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Nieprawidłowy typ wyniku workflow.');
    }
    $order = $table === 'article_draft_versions' ? 'is_active DESC, id DESC' : 'id DESC';
    $statement = bueno_database()->prepare(
        'SELECT * FROM ' . $table . ' WHERE topic_id = :topic_id ORDER BY ' . $order . ' LIMIT 1'
    );
    $statement->execute([':topic_id' => $topicId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function generation_workflow_latest_approved_research(int $topicId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM research_packages WHERE topic_id = :topic_id AND status = "approved"
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':topic_id' => $topicId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function generation_workflow_latest_quality(int $draftId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM quality_check_runs WHERE draft_version_id = :draft_id ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':draft_id' => $draftId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function generation_workflow_images_state(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = "downloaded" THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = "manual_review" THEN 1 ELSE 0 END) AS manual,
                SUM(CASE WHEN status = "missing" THEN 1 ELSE 0 END) AS missing
         FROM article_images WHERE post_id = :post_id'
    );
    $statement->execute([':post_id' => $postId]);
    $row = $statement->fetch() ?: [];
    return array_map('intval', [
        'total' => $row['total'] ?? 0,
        'completed' => $row['completed'] ?? 0,
        'manual' => $row['manual'] ?? 0,
        'missing' => $row['missing'] ?? 0,
    ]);
}

/**
 * Recover a persisted NarrativePlan from an already completed operation without
 * dispatching Gemini again. A completed operation without a recoverable plan is
 * an explicit inconsistent artifact, never a reason to silently skip P06.
 */
function generation_batch_finalize_stored_narrative_plan(int $topicId, ?int $postId = null): ?array
{
    if ($postId !== null && $postId > 0) {
        $persisted = find_narrative_plan_for_post($postId, $topicId);
        if (is_array($persisted)) return $persisted;
    }
    $statement = bueno_database()->prepare(
        'SELECT id FROM generation_operations
         WHERE topic_id = :topic_id AND operation_type = "narrative_plan"
           AND status = "completed" AND output_json <> ""
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':topic_id' => $topicId]);
    $operationId = (int) $statement->fetchColumn();
    if ($operationId <= 0) return null;

    complete_narrative_plan_operation($operationId, '', generation_mode());
    return $postId !== null && $postId > 0
        ? find_narrative_plan_for_post($postId, $topicId)
        : find_narrative_plan_for_topic($topicId);
}

function generation_workflow_initial_item(int $topicId, string $action, ?string $retryStage = null): array
{
    $requestedStage = $action === 'quality' ? 'quality' : ($action === 'generate_all' ? '' : ($retryStage ?: $action));
    $topic = find_editorial_topic($topicId);
    $manualGenerateAll = $action === 'generate_all';
    $terminalPost = is_array($topic) && in_array((string) ($topic['primary_post_status'] ?? ''), ['rejected', 'trash', 'published', 'scheduled'], true);
    if (!is_array($topic) || $terminalPost || (!$manualGenerateAll && (int) ($topic['automatic_eligible'] ?? 0) !== 1)) {
        return ['status' => 'invalid', 'stage' => $retryStage ?: 'research', 'requested_stage' => $requestedStage,
            'outcome' => 'invalid', 'progress_percent' => 0,
            'wait_reason' => 'Automatyzacja jest zablokowana przez stan, scoring albo poziom ryzyka tematu.',
            'completed_at' => gmdate('Y-m-d H:i:s')];
    }
    $active = bueno_database()->prepare(
        'SELECT id, batch_id FROM generation_batch_items
         WHERE topic_id = :topic_id AND status IN ("queued", "research", "draft", "auto_repair", "quality_check", "images", "rate_limited", "auto_retry_scheduled")
         LIMIT 1'
    );
    $active->execute([':topic_id' => $topicId]);
    if (is_array($running = $active->fetch())) {
        return ['status' => 'invalid', 'stage' => $retryStage ?: 'research', 'requested_stage' => $requestedStage,
            'outcome' => 'invalid', 'progress_percent' => 0,
            'wait_reason' => 'Temat jest już aktywnie przetwarzany w batchu #' . (int) $running['batch_id'],
            'completed_at' => gmdate('Y-m-d H:i:s')];
    }

    if ($action === 'retry') {
        $failed = bueno_database()->prepare(
            'SELECT id FROM generation_batch_items WHERE topic_id = :topic_id AND stage = :stage AND status = "failed"
             ORDER BY id DESC LIMIT 1'
        );
        $failed->execute([':topic_id' => $topicId, ':stage' => $retryStage]);
        $technicalFailure = $failed->fetchColumn() !== false;
        $sourceDataChanged = false;
        if (!$technicalFailure && $retryStage === 'research') {
            $latestInput = bueno_database()->prepare('SELECT o.input_json FROM research_packages r INNER JOIN generation_operations o ON o.id=r.generation_operation_id WHERE r.topic_id=:topic ORDER BY r.id DESC LIMIT 1');
            $latestInput->execute([':topic'=>$topicId]);
            $previous = json_decode((string) ($latestInput->fetchColumn() ?: '{}'), true) ?: [];
            $sourceDataChanged = hash('sha256', generation_json($previous['numbered_sources'] ?? []))
                !== hash('sha256', generation_json(research_numbered_sources($topicId)));
        }
        if (!$technicalFailure && !$sourceDataChanged) {
            return ['status' => 'invalid', 'stage' => (string) $retryStage, 'requested_stage' => $requestedStage,
                'outcome' => 'invalid', 'progress_percent' => 0,
                'wait_reason' => 'Ponowienie jest dozwolone tylko po błędzie technicznym albo zmianie danych źródłowych.',
                'completed_at' => gmdate('Y-m-d H:i:s')];
        }
    }

    $research = generation_workflow_latest('research_packages', $topicId);
    $approved = generation_workflow_latest_approved_research($topicId);
    $draft = generation_workflow_latest('article_draft_versions', $topicId);
    $draftAccepted = is_array($draft) && in_array((string) $draft['status'], ['completed', 'frozen'], true);
    $quality = is_array($draft) ? generation_workflow_latest_quality((int) $draft['id']) : null;
    $qualityPassed = is_array($quality) && $quality['status'] === 'completed'
        && (int) $quality['passed'] === 1 && quality_active_hard_blocks($quality) === [];
    $images = is_array($draft) ? generation_workflow_images_state((int) $draft['post_id']) : ['total' => 0, 'completed' => 0, 'manual' => 0, 'missing' => 0];
    $coverage = is_array($draft) ? article_image_coverage_state((int) $draft['post_id'], $topicId) : null;
    $imageReady = is_array($coverage) && !empty($coverage['coverage_complete']);
    $stage = $action === 'quality' ? 'quality_check' : $action;
    if ($action === 'retry') $stage = (string) $retryStage;
    if ($action === 'generate_all') {
        $stage = !is_array($approved) ? 'research'
            : (!$draftAccepted ? 'draft'
            : (!$qualityPassed ? 'quality_check' : 'images'));
        if ($qualityPassed && $imageReady) {
            return ['status' => 'already_complete', 'stage' => 'ready', 'requested_stage' => '', 'outcome' => 'already_complete',
                'progress_percent' => 100, 'wait_reason' => '', 'completed_at' => gmdate('Y-m-d H:i:s'),
                'research_operation_id' => (int) $approved['generation_operation_id'], 'research_package_id' => (int) $approved['id'],
                'draft_operation_id' => (int) $draft['generation_operation_id'], 'draft_version_id' => (int) $draft['id'],
                'quality_operation_id' => (int) $quality['generation_operation_id'], 'quality_check_id' => (int) $quality['id'], 'post_id' => (int) $draft['post_id']];
        }
    }
    $prerequisite = match ($stage) {
        'draft' => is_array($approved),
        'quality_check' => $draftAccepted,
        'images' => $qualityPassed,
        default => true,
    };
    if (!$prerequisite && $action !== 'generate_all') {
        return ['status' => 'skipped_prerequisite', 'stage' => $stage, 'requested_stage' => $requestedStage,
            'outcome' => 'skipped_prerequisite', 'progress_percent' => 0,
            'wait_reason' => 'Nie jest ukończony wymagany wcześniejszy etap.', 'completed_at' => gmdate('Y-m-d H:i:s')];
    }
    if ($stage === 'research' && is_array($research) && $research['status'] === 'completed' && !is_array($approved) && $action !== 'generate_all' && $action !== 'retry') {
        return ['status' => 'waiting_review', 'stage' => 'research', 'requested_stage' => $requestedStage,
            'outcome' => 'waiting_review', 'progress_percent' => 100,
            'wait_reason' => 'Ukończony research wymaga decyzji redakcyjnej.', 'completed_at' => gmdate('Y-m-d H:i:s'),
            'research_operation_id' => (int) $research['generation_operation_id'], 'research_package_id' => (int) $research['id'],
            'post_id' => (int) $research['post_id']];
    }
    $already = ($stage === 'research' && is_array($research) && in_array($research['status'], ['completed', 'approved'], true))
        || ($stage === 'draft' && $draftAccepted)
        || ($stage === 'quality_check' && $qualityPassed)
        || ($stage === 'images' && $imageReady);
    if ($already && $action !== 'generate_all' && $action !== 'retry') {
        return ['status' => 'already_complete', 'stage' => $stage, 'requested_stage' => '', 'outcome' => 'already_complete',
            'progress_percent' => 100, 'wait_reason' => '', 'completed_at' => gmdate('Y-m-d H:i:s')];
    }
    $researchForItem = $stage === 'research' && is_array($research) ? $research : $approved;
    return ['status' => 'queued', 'stage' => $stage, 'requested_stage' => $requestedStage, 'outcome' => 'queued',
        'progress_percent' => 0, 'wait_reason' => '', 'completed_at' => null,
        'research_operation_id' => is_array($researchForItem) ? (int) $researchForItem['generation_operation_id'] : null,
        'research_package_id' => is_array($researchForItem) ? (int) $researchForItem['id'] : null,
        'draft_operation_id' => $draftAccepted ? (int) $draft['generation_operation_id'] : null,
        'draft_version_id' => $draftAccepted ? (int) $draft['id'] : null,
        'quality_operation_id' => $qualityPassed ? (int) $quality['generation_operation_id'] : null,
        'quality_check_id' => $qualityPassed ? (int) $quality['id'] : null,
        'post_id' => is_array($draft) ? (int) $draft['post_id'] : (is_array($researchForItem) ? (int) $researchForItem['post_id'] : null)];
}

function create_topic_workflow_batch(mixed $rawTopicIds, string $action, ?string $requestKey = null, string $actor = 'admin', ?string $retryStage = null): array
{
    generation_batch_assert_api_available();
    $action = strtolower(trim($action));
    if (!in_array($action, GENERATION_WORKFLOW_ACTIONS, true)) throw new InvalidArgumentException('Nieprawidłowa akcja workflow.');
    if ($action === 'retry' && !in_array($retryStage, GENERATION_WORKFLOW_STAGES, true)) throw new InvalidArgumentException('Ponowienie wymaga konkretnego etapu.');
    $topicIds = generation_batch_validate_topic_ids($rawTopicIds);
    $dispatchMode = $actor === 'full-auto' ? 'automatic' : 'operator_manual';
    $requestKey = trim((string) $requestKey) ?: bin2hex(random_bytes(16));
    if (strlen($requestKey) > 128 || preg_match('/^[a-zA-Z0-9._:-]+$/', $requestKey) !== 1) throw new InvalidArgumentException('Nieprawidłowy klucz idempotencji batcha.');
    $database = bueno_database();
    $existing = $database->prepare('SELECT id FROM generation_batches WHERE request_key = :key');
    $existing->execute([':key' => $requestKey]);
    if (($id = $existing->fetchColumn()) !== false) return generation_batch_payload((int) $id);
    if ($action === 'generate_all' && $dispatchMode === 'operator_manual' && count($topicIds) === 1) {
        $running = $database->prepare('SELECT b.id FROM generation_batches b INNER JOIN generation_batch_items i ON i.batch_id=b.id
            WHERE i.topic_id=:topic AND b.dispatch_mode="operator_manual"
              AND i.status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled")
            ORDER BY i.id DESC LIMIT 1');
        $running->execute([':topic' => $topicIds[0]]);
        if (($runningBatchId = $running->fetchColumn()) !== false) return generation_batch_payload((int) $runningBatchId);
    }
    $database->beginTransaction();
    try {
        $database->prepare('INSERT INTO generation_batches (batch_key, request_key, action, item_count, created_by, execution_mode, dispatch_mode) VALUES (:batch_key, :request_key, :action, :count, :actor, :execution_mode, :dispatch_mode)')
            ->execute([':batch_key' => bin2hex(random_bytes(16)), ':request_key' => $requestKey, ':action' => $action, ':count' => count($topicIds), ':actor' => mb_substr($actor, 0, 100), ':execution_mode' => generation_mode(), ':dispatch_mode' => $dispatchMode]);
        $batchId = (int) $database->lastInsertId();
        foreach ($topicIds as $topicId) {
            $initial = generation_workflow_initial_item($topicId, $action, $retryStage);
            if ($action === 'generate_all' && generation_mode() === 'api'
                && in_array((string) $initial['status'], ['waiting_review', 'manual_review'], true)) {
                $initial['status'] = 'auto_repair';
                $initial['outcome'] = 'repair_router_queued';
                $initial['completed_at'] = null;
                $initial['wait_reason'] = 'Router naprawczy wznawia autonomiczne przygotowanie pakietu.';
            }
            $columns = ['batch_id', 'topic_id', ...array_keys($initial)];
            $sql = 'INSERT INTO generation_batch_items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')';
            $database->prepare($sql)->execute([':batch_id' => $batchId, ':topic_id' => $topicId, ...array_combine(array_map(static fn (string $key): string => ':' . $key, array_keys($initial)), array_values($initial))]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
    generation_batch_audit($batchId, null, 'batch_created', $actor, ['topic_ids' => $topicIds, 'action' => $action, 'retry_stage' => $retryStage]);
    generation_batch_refresh_status($batchId);
    return generation_batch_payload($batchId);
}

function generation_batch_item_rows(int $batchId): array
{
    $statement = bueno_database()->prepare(
        'SELECT items.*, topics.title AS topic_title
         FROM generation_batch_items AS items
         INNER JOIN editorial_topics AS topics ON topics.id = items.topic_id
         WHERE items.batch_id = :batch_id ORDER BY items.id'
    );
    $statement->execute([':batch_id' => $batchId]);
    return $statement->fetchAll();
}

function generation_batch_payload(int $batchId): array
{
    $statement = bueno_database()->prepare('SELECT * FROM generation_batches WHERE id = :id');
    $statement->execute([':id' => $batchId]);
    $batch = $statement->fetch();
    if (!is_array($batch)) {
        throw new RuntimeException('Nie znaleziono batcha.');
    }
    $items = generation_batch_item_rows($batchId);
    $terminal = 0;
    $ready = 0;
    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['topic_id'] = (int) $item['topic_id'];
        $item['progress_percent'] = (int) $item['progress_percent'];
        $item['draft_version_id'] = $item['draft_version_id'] === null ? null : (int) $item['draft_version_id'];
        $item['review_url'] = $item['draft_version_id'] === null
            ? null : 'admin-proposals.php?batch=' . $batchId . '&draft=' . (int) $item['draft_version_id'];
        if (in_array((string) $item['status'], GENERATION_BATCH_TERMINAL_STATUSES, true)) {
            $terminal++;
        }
        if (in_array((string) $item['status'], ['ready', 'ready_for_preview', 'ready_with_notes'], true)) {
            $ready++;
        }
    }
    unset($item);
    $usageStatement = bueno_database()->prepare('SELECT COUNT(*) FROM gemini_quota_events WHERE batch_id=:batch AND status IN ("reserved","completed","failed")');
    $usageStatement->execute([':batch'=>$batchId]);
    $usedCalls = (int) $usageStatement->fetchColumn();
    return [
        'id' => (int) $batch['id'],
        'key' => (string) $batch['batch_key'],
        'action' => (string) ($batch['action'] ?? 'generate_all'),
        'status' => (string) $batch['status'],
        'item_count' => (int) $batch['item_count'],
        'completed_count' => $terminal,
        'ready_count' => $ready,
        'estimated_gemini_calls' => 3 * (int) $batch['item_count'],
        'gemini_calls_used' => $usedCalls,
        'terminal' => $terminal === count($items),
        'created_at' => (string) $batch['created_at'],
        'updated_at' => (string) $batch['updated_at'],
        'items' => $items,
    ];
}

function list_generation_batches(int $limit = 10): array
{
    $statement = bueno_database()->prepare('SELECT id FROM generation_batches ORDER BY id DESC LIMIT :limit');
    $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
    $statement->execute();
    return array_map(static fn ($id): array => generation_batch_payload((int) $id), $statement->fetchAll(PDO::FETCH_COLUMN));
}

function list_generation_process_history(int $limit = 50): array
{
    $statement = bueno_database()->prepare(
        'SELECT items.*, topics.title AS topic_title, batches.action AS batch_action,
                batches.created_by, batches.created_at AS batch_created_at
         FROM generation_batch_items AS items
         INNER JOIN generation_batches AS batches ON batches.id = items.batch_id
         INNER JOIN editorial_topics AS topics ON topics.id = items.topic_id
         WHERE items.status IN ("queued", "research", "draft", "auto_repair", "quality_check", "images", "rate_limited", "auto_retry_scheduled", "waiting_review", "failed", "ready", "ready_for_preview", "ready_with_notes", "auto_rejected", "cancelled")
         ORDER BY CASE
            WHEN items.status IN ("queued", "research", "draft", "auto_repair", "quality_check", "images", "rate_limited", "auto_retry_scheduled") THEN 0
            WHEN items.status IN ("waiting_review", "failed") THEN 1
            ELSE 2 END,
            datetime(items.updated_at) DESC, items.id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function generation_workflow_step_status(?string $recordStatus, ?string $operationStatus, bool $passed = false, bool $waiting = false): string
{
    if ($waiting) return 'waiting_review';
    if ($passed) return 'completed';
    return match ($recordStatus ?: $operationStatus ?: '') {
        'approved', 'completed', 'frozen' => 'completed',
        'failed' => 'failed',
        'running' => 'running',
        'prepared', 'queued' => 'queued',
        default => 'not_started',
    };
}

function generation_batch_item_is_retryable(array $item, array $draftValidation = []): bool
{
    $status = (string) ($item['job_status'] ?? $item['status'] ?? '');
    $stage = (string) ($item['job_stage'] ?? $item['stage'] ?? '');
    $outcome = (string) ($item['outcome'] ?? '');
    return in_array($status, ['rate_limited', 'cancelled'], true)
        || ($status === 'failed'
            && ((string) ($draftValidation['repair_scope'] ?? '') === 'titles' || $outcome !== 'validation_contract'))
        || (in_array($status, ['manual_review', 'waiting_review'], true) && $stage === 'images');
}

/** One SQL query for a topic list; no per-topic lookups. */
function generation_workflow_statuses(mixed $rawTopicIds): array
{
    if (!is_array($rawTopicIds) || $rawTopicIds === [] || count($rawTopicIds) > 500) {
        throw new InvalidArgumentException('Odczyt statusu wymaga od 1 do 500 identyfikatorów tematów.');
    }
    $topicIds = [];
    foreach ($rawTopicIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Lista tematów zawiera nieprawidłowy identyfikator.');
        $topicIds[(int) $id] = (int) $id;
    }
    $topicIds = array_values($topicIds);
    $params = [];
    $holders = [];
    foreach ($topicIds as $index => $topicId) {
        $holders[] = ':topic_' . $index;
        $params[':topic_' . $index] = $topicId;
    }
    $sql = 'WITH
        latest_research AS (
            SELECT r.*, o.status AS operation_status, ROW_NUMBER() OVER (PARTITION BY r.topic_id ORDER BY r.id DESC) rn
            FROM research_packages r INNER JOIN generation_operations o ON o.id = r.generation_operation_id
            WHERE r.topic_id IN (' . implode(',', $holders) . ')
        ),
        latest_draft AS (
            SELECT d.*, o.status AS operation_status, ROW_NUMBER() OVER (PARTITION BY d.topic_id ORDER BY d.is_active DESC, d.id DESC) rn
            FROM article_draft_versions d INNER JOIN generation_operations o ON o.id = d.generation_operation_id
            WHERE d.topic_id IN (' . implode(',', $holders) . ')
        ),
        latest_quality AS (
            SELECT q.*, o.status AS operation_status, d.topic_id,
                   ROW_NUMBER() OVER (PARTITION BY q.draft_version_id ORDER BY q.id DESC) rn
            FROM quality_check_runs q
            INNER JOIN article_draft_versions d ON d.id = q.draft_version_id
            INNER JOIN generation_operations o ON o.id = q.generation_operation_id
            WHERE d.topic_id IN (' . implode(',', $holders) . ')
        ),
        latest_job AS (
            SELECT i.*, b.action AS batch_action,
                   ROW_NUMBER() OVER (PARTITION BY i.topic_id ORDER BY i.id DESC) rn
            FROM generation_batch_items i INNER JOIN generation_batches b ON b.id = i.batch_id
            WHERE i.topic_id IN (' . implode(',', $holders) . ')
        ),
        image_counts AS (
            SELECT post_id, COUNT(*) total,
                   SUM(CASE WHEN status = "downloaded" THEN 1 ELSE 0 END) completed,
                   SUM(CASE WHEN status = "manual_review" THEN 1 ELSE 0 END) manual,
                   SUM(CASE WHEN status IN ("missing", "planned") THEN 1 ELSE 0 END) pending
            FROM article_images GROUP BY post_id
        ),
        gemini_usage AS (
            SELECT topic_id,COUNT(*) calls FROM gemini_quota_events
            WHERE topic_id IS NOT NULL AND status IN ("reserved","completed","failed") GROUP BY topic_id
        )
        SELECT t.id topic_id, p.status topic_status, t.automatic_eligible,COALESCE(gu.calls,0) gemini_calls_used,
               r.id research_id, r.generation_operation_id research_operation_id, r.status research_status,
               r.operation_status research_operation_status, r.approved_at, r.validation_json research_validation,
               d.id draft_id, d.generation_operation_id draft_operation_id, d.version_number draft_version,
               d.status draft_status, d.operation_status draft_operation_status, d.post_id, d.validation_json draft_validation,
               q.id quality_id, q.draft_version_id quality_draft_id, q.generation_operation_id quality_operation_id, q.check_number,
               q.status quality_status, q.operation_status quality_operation_status, q.passed,
               q.hard_blocks_json, q.human_review_status,
               COALESCE(img.total, 0) image_total, COALESCE(img.completed, 0) image_completed,
               COALESCE(img.manual, 0) image_manual, COALESCE(img.pending, 0) image_pending,
               j.id job_id, j.batch_id, j.batch_action, j.status job_status, j.stage job_stage,
               j.progress_percent, j.available_at, j.next_retry_at, j.quota_dimension, j.quota_model,
               j.wait_reason, j.error_message, j.outcome
        FROM editorial_topics t
        INNER JOIN posts p ON p.id = t.primary_post_id
        LEFT JOIN latest_research r ON r.topic_id = t.id AND r.rn = 1
        LEFT JOIN latest_draft d ON d.topic_id = t.id AND d.rn = 1
        LEFT JOIN latest_quality q ON q.draft_version_id = d.id AND q.rn = 1
        LEFT JOIN image_counts img ON img.post_id = d.post_id
        LEFT JOIN gemini_usage gu ON gu.topic_id = t.id
        LEFT JOIN latest_job j ON j.topic_id = t.id AND j.rn = 1
        WHERE t.id IN (' . implode(',', $holders) . ') ORDER BY t.id';
    $statement = bueno_database()->prepare($sql);
    $statement->execute($params);
    $payload = [];
    foreach ($statement->fetchAll() as $row) {
        $hardBlocks = json_decode((string) ($row['hard_blocks_json'] ?? '[]'), true) ?: [];
        if ((string) ($row['human_review_status'] ?? '') === 'approved') {
            $hardBlocks = array_values(array_filter(
                $hardBlocks,
                static fn (array $block): bool => ($block['code'] ?? '') !== 'high_risk_without_human_approval'
            ));
        }
        $draftValidation = json_decode((string) ($row['draft_validation'] ?? '{}'), true) ?: [];
        $qualityIsCurrent = (int) ($row['quality_draft_id'] ?? 0) === (int) ($row['draft_id'] ?? 0);
        $qualityPassed = $qualityIsCurrent && (int) ($row['passed'] ?? 0) === 1 && $hardBlocks === [];
        if ($qualityPassed && !quality_check_is_production_eligible((int) ($row['quality_id'] ?? 0))) {
            $qualityPassed = false;
        }
        $coverage = !empty($row['post_id']) ? article_image_coverage_state((int) $row['post_id'], (int) $row['topic_id']) : null;
        $imageReady = is_array($coverage) && !empty($coverage['coverage_complete']);
        $imageManual = (int) $row['image_manual'] > 0 || (int) $row['image_pending'] > 0;
        $proposalReviewable = in_array((string) ($row['draft_status'] ?? ''), ['completed', 'frozen'], true)
            && $qualityIsCurrent && ($row['quality_status'] ?? '') === 'completed';
        $allStagesReady = ($row['research_status'] ?? '') === 'approved'
            && !empty($row['approved_at'])
            && in_array((string) ($row['draft_status'] ?? ''), ['completed', 'frozen'], true)
            && $qualityPassed
            && $imageReady
            && final_multimodal_qc_readiness((int) ($row['post_id'] ?? 0)) === 'ready_for_manual_publish';
        $active = in_array((string) ($row['job_status'] ?? ''), GENERATION_BATCH_ACTIVE_STATUSES, true);
        $researchWaiting = ($row['research_status'] ?? '') === 'completed' && empty($row['approved_at']);
        $qualityWaiting = $qualityIsCurrent && ($row['quality_status'] ?? '') === 'completed' && !$qualityPassed;
        $jobStatus = (string) ($row['job_status'] ?? '');
        $overall = $active ? $jobStatus
            : (in_array($jobStatus, ['ready_for_preview', 'ready_with_notes', 'manual_review', 'waiting_review'], true) ? $jobStatus
            : ($jobStatus === 'auto_rejected' ? 'auto_rejected'
            : ($jobStatus === 'failed' ? 'failed'
            : ($qualityWaiting || $researchWaiting ? 'waiting_review'
            : ($allStagesReady ? 'ready' : 'eligible')))));
        $progress = (int) ($row['progress_percent'] ?? 0);
        if ($jobStatus === 'manual_review' && (string) ($row['job_stage'] ?? '') === 'images'
            && is_array($coverage) && count($coverage['required_slots']) > 0) {
            $progress = (int) floor(100 * count($coverage['filled_slots']) / count($coverage['required_slots']));
        }
        $availableAt = !empty($row['next_retry_at'] ?? null)
            ? gmdate('c', strtotime((string) $row['next_retry_at'] . ' UTC'))
            : (!empty($row['available_at']) ? gmdate('c', strtotime((string) $row['available_at'] . ' UTC')) : null);
        $retryAfterSeconds = in_array($overall, ['rate_limited', 'auto_retry_scheduled'], true) && $availableAt !== null
            ? max(0, strtotime($availableAt) - time())
            : null;
        $payload[] = [
            'topic_id' => (int) $row['topic_id'], 'status' => $overall,
            'gemini_calls_used' => (int) ($row['gemini_calls_used'] ?? 0), 'gemini_call_budget' => 15,
            'steps' => [
                'research' => ['status' => generation_workflow_step_status($row['research_status'], $row['research_operation_status'], !empty($row['approved_at']), $researchWaiting), 'progress' => $row['research_id'] ? ($researchWaiting ? 100 : ($active && $row['job_stage'] === 'research' ? min(99, $progress * 3) : 100)) : 0, 'result_id' => $row['research_id'] ? (int) $row['research_id'] : null],
                'draft' => ['status' => generation_workflow_step_status($row['draft_status'], $row['draft_operation_status']), 'progress' => $row['draft_id'] ? ($active && $row['job_stage'] === 'draft' ? min(99, $progress) : 100) : 0, 'result_id' => $row['draft_id'] ? (int) $row['draft_id'] : null, 'version' => $row['draft_version'] ? (int) $row['draft_version'] : null],
                'quality' => ['status' => $qualityIsCurrent ? generation_workflow_step_status($row['quality_status'], $row['quality_operation_status'], $qualityPassed, $qualityWaiting) : 'not_started', 'progress' => $qualityIsCurrent && $row['quality_id'] ? ($active && $row['job_stage'] === 'quality_check' ? min(99, $progress) : 100) : 0, 'result_id' => $qualityIsCurrent && $row['quality_id'] ? (int) $row['quality_id'] : null, 'version' => $qualityIsCurrent && $row['check_number'] ? (int) $row['check_number'] : null],
                'images' => ['status' => $imageManual ? 'manual_review' : ($imageReady ? 'completed' : ($active && $row['job_stage'] === 'images' ? 'running' : 'not_started')), 'progress' => is_array($coverage) && count($coverage['required_slots']) > 0 ? (int) floor(100 * count($coverage['filled_slots']) / count($coverage['required_slots'])) : 0, 'completed' => is_array($coverage) ? count($coverage['filled_slots']) : 0, 'total' => is_array($coverage) ? count($coverage['required_slots']) : 0, 'coverage' => $coverage],
            ],
            'wait_reason' => (string) ($row['wait_reason'] ?? ''), 'error' => (string) ($row['error_message'] ?? ''),
            'available_at' => $availableAt, 'next_retry_at' => $availableAt,
            'quota_dimension' => (string) ($row['quota_dimension'] ?? ''), 'quota_model' => (string) ($row['quota_model'] ?? ''),
            'retry_after_seconds' => $retryAfterSeconds,
            'active_job_id' => $active ? (int) $row['job_id'] : null, 'active_batch_id' => $active ? (int) $row['batch_id'] : null,
            'active_action' => $active ? (string) $row['batch_action'] : null,
            'active_stage' => $active ? (string) $row['job_stage'] : null,
            'latest_job_id' => $row['job_id'] ? (int) $row['job_id'] : null,
            'latest_batch_id' => $row['batch_id'] ? (int) $row['batch_id'] : null,
            'latest_job_status' => $row['job_status'] ? (string) $row['job_status'] : null,
            'latest_action' => $row['batch_action'] ? (string) $row['batch_action'] : null,
            'latest_outcome' => $row['outcome'] ? (string) $row['outcome'] : null,
            'latest_stage' => $row['job_stage'] ? (string) $row['job_stage'] : null,
            'retryable' => generation_batch_item_is_retryable($row, $draftValidation),
            'repair_scope' => (string) ($draftValidation['repair_scope'] ?? ''),
            'repair' => $draftValidation,
            'progress' => $row['job_id'] ? $progress : ($qualityPassed && $imageReady ? 100 : 0),
            'readiness' => $allStagesReady, 'publication_readiness' => $allStagesReady,
            'proposal_url' => $proposalReviewable && $row['draft_id']
                ? 'admin-proposals.php?draft=' . (int) $row['draft_id'] . ($allStagesReady ? '' : '&queue=action')
                : null,
            'result_ids' => ['research_package_id' => $row['research_id'] ? (int) $row['research_id'] : null, 'draft_version_id' => $row['draft_id'] ? (int) $row['draft_id'] : null, 'quality_check_id' => $row['quality_id'] ? (int) $row['quality_id'] : null],
        ];
    }
    return $payload;
}

function generation_batch_claim_items(?int $limit = null): array
{
    $dispatchPaused = generation_automatic_dispatch_paused();
    if (!$dispatchPaused) {
        generation_batch_reconcile_feed_enrichment_regression();
        generation_batch_reconcile_legacy_quota_storm();
        generation_batch_reconcile_field_constraint_retries();
        generation_batch_reconcile_legacy_auto_rejected();
        generation_batch_reconcile_autonomous_items();
    }
    $configuredLimit = (int) app_config('batch_worker_concurrency');
    $requested = max(1, min($configuredLimit, $limit ?? $configuredLimit));
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $database->exec(
            'UPDATE generation_batch_items SET lease_token = NULL, lease_expires_at = NULL
             WHERE lease_expires_at IS NOT NULL AND lease_expires_at <= CURRENT_TIMESTAMP'
        );
        $leased = (int) $database->query(
            'SELECT COUNT(*) FROM generation_batch_items
             WHERE lease_token IS NOT NULL AND lease_expires_at > CURRENT_TIMESTAMP'
        )->fetchColumn();
        $available = min($requested, max(0, $configuredLimit - $leased));
        if ($available === 0) {
            $database->commit();
            return [];
        }
        $select = $database->prepare(
            'SELECT items.id FROM generation_batch_items items INNER JOIN generation_batches batches ON batches.id=items.batch_id
             WHERE items.status IN ("queued", "research", "draft", "auto_repair", "quality_check", "images", "rate_limited", "auto_retry_scheduled")
               AND items.available_at <= CURRENT_TIMESTAMP AND items.lease_token IS NULL
               AND (:paused=0 OR batches.dispatch_mode="operator_manual")
             ORDER BY items.available_at, items.id LIMIT :limit'
        );
        $select->bindValue(':paused', $dispatchPaused ? 1 : 0, PDO::PARAM_INT);
        $select->bindValue(':limit', $available, PDO::PARAM_INT);
        $select->execute();
        $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
        $claimed = [];
        foreach ($ids as $id) {
            $token = bin2hex(random_bytes(16));
            $update = $database->prepare(
                'UPDATE generation_batch_items
                 SET lease_token = :token,
                     lease_expires_at = datetime(CURRENT_TIMESTAMP, :lease), updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND lease_token IS NULL'
            );
            $update->execute([
                ':token' => $token,
                ':lease' => '+' . (int) app_config('batch_lease_seconds') . ' seconds',
                ':id' => $id,
            ]);
            if ($update->rowCount() === 1) {
                $claimed[] = ['id' => $id, 'lease_token' => $token];
            }
        }
        $database->commit();
        return $claimed;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function generation_batch_reconcile_field_constraint_retries(?array $itemIds = null): array
{
    $params = [];
    $filter = '';
    if (is_array($itemIds) && $itemIds !== []) {
        $ids = array_values(array_filter(array_unique(array_map('intval', $itemIds)), static fn (int $id): bool => $id > 0));
        if ($ids === []) return [];
        $filter = ' AND items.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }
    $statement = bueno_database()->prepare(
        'SELECT items.*, batches.action batch_action, batches.execution_mode batch_execution_mode
         FROM generation_batch_items items
         INNER JOIN generation_batches batches ON batches.id=items.batch_id
         WHERE items.status="auto_retry_scheduled"
           AND items.outcome IN ("validation_retry_scheduled","infrastructure_retry_scheduled")
           AND batches.action="generate_all" AND batches.execution_mode="api"' . $filter . ' ORDER BY items.id'
    );
    $statement->execute($params);
    $results = [];
    foreach ($statement->fetchAll() as $item) {
        $operationId = match ((string) $item['stage']) {
            'research' => (int) ($item['research_operation_id'] ?? 0),
            'draft', 'auto_repair' => (int) ($item['draft_operation_id'] ?? 0),
            'quality_check' => (int) ($item['quality_operation_id'] ?? 0),
            default => 0,
        };
        $operation = $operationId > 0 ? find_generation_operation($operationId) : null;
        if (!is_array($operation)) continue;
        if ((string) ($operation['output_json'] ?? '') === '' && (string) $item['stage'] === 'draft') {
            $draft = find_article_draft_by_operation($operationId);
            $saved = is_array($draft) ? json_decode((string) ($draft['draft_json'] ?? ''), true) : null;
            if (is_array($saved) && $saved !== []) {
                bueno_database()->prepare('UPDATE generation_operations SET output_json=:output WHERE id=:id')
                    ->execute([':output' => generation_json($saved), ':id' => $operationId]);
                $operation['output_json'] = generation_json($saved);
            }
        }
        $constraint = generation_saved_field_constraint($operation);
        if ($constraint === null) continue;
        $field = ltrim($constraint->jsonPath, '$.');
        generation_batch_update_item((int) $item['id'], [
            'status' => 'queued', 'available_at' => gmdate('Y-m-d H:i:s'), 'completed_at' => null,
            'wait_reason' => "Automatycznie dopasowuję długość pola {$field} (obecnie {$constraint->actualLength}, wymagane "
                . ($constraint->minimumLength ?? 0) . '–' . ($constraint->maximumLength ?? '∞') . ').',
            'error_message' => '', 'outcome' => 'field_constraint_repair_queued',
        ]);
        generation_batch_audit((int) $item['batch_id'], (int) $item['id'], 'legacy_field_constraint_reconciled', 'system', [
            'operation_id' => $operationId, 'json_path' => $constraint->jsonPath,
            'actual_length' => $constraint->actualLength, 'old_available_at' => (string) $item['available_at'],
        ]);
        $results[] = ['item_id' => (int) $item['id'], 'operation_id' => $operationId, 'json_path' => $constraint->jsonPath];
    }
    return $results;
}

/** Stops the legacy 429 -> field repair reconcile loop without touching real field constraints. */
function generation_batch_reconcile_legacy_quota_storm(): int
{
    $database = bueno_database();
    $retry = gmdate('Y-m-d H:i:s', gemini_next_daily_reset(time()));
    $statement = $database->prepare(
        'UPDATE generation_batch_items SET outcome="quota_retry_scheduled",status="auto_retry_scheduled",
            quota_dimension="RPD",quota_model=:model,next_retry_at=:retry,available_at=:retry,
            wait_reason=:reason,updated_at=CURRENT_TIMESTAMP
         WHERE status IN ("queued","auto_retry_scheduled","rate_limited")
           AND outcome IN ("validation_retry_scheduled","infrastructure_retry_scheduled","field_constraint_repair_queued","quota_retry_scheduled")
           AND (error_message LIKE "%limit: 500%" OR error_message LIKE "%requests per day%" OR error_message LIKE "%RPD%")'
    );
    $model = (string) app_config('gemini_model');
    $statement->execute([':model'=>$model,':retry'=>$retry,':reason'=>'Wyczerpano dzienny limit modelu '.$model.'.']);
    return $statement->rowCount();
}

function generation_batch_find_item(int $itemId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT items.*, batches.action AS batch_action, batches.created_by, batches.execution_mode AS batch_execution_mode, batches.dispatch_mode FROM generation_batch_items items
         INNER JOIN generation_batches batches ON batches.id = items.batch_id WHERE items.id = :id'
    );
    $statement->execute([':id' => $itemId]);
    $item = $statement->fetch();
    return is_array($item) ? $item : null;
}

function generation_batch_update_item(int $itemId, array $changes): void
{
    $allowed = [
        'status', 'stage', 'progress_percent', 'research_operation_id', 'research_package_id',
        'draft_operation_id', 'draft_version_id', 'quality_operation_id', 'quality_check_id',
        'post_id', 'retry_count', 'auto_repair_count', 'available_at', 'next_retry_at', 'quota_dimension', 'quota_model', 'wait_reason', 'error_message', 'completed_at',
        'lease_token', 'lease_expires_at', 'requested_stage', 'outcome', 'convergence_active',
        'paused_from_status', 'paused_at',
    ];
    $sets = ['updated_at = CURRENT_TIMESTAMP'];
    $params = [':id' => $itemId];
    foreach ($changes as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            throw new InvalidArgumentException('Niedozwolona zmiana elementu batcha.');
        }
        $sets[] = $key . ' = :' . $key;
        $params[':' . $key] = $value;
    }
    $current = bueno_database()->prepare('SELECT status FROM generation_batch_items WHERE id=:id');
    $current->execute([':id' => $itemId]);
    $currentStatus = (string) ($current->fetchColumn() ?: '');
    $leaseOnly = array_diff(array_keys($changes), ['lease_token', 'lease_expires_at']) === [];
    if ($currentStatus === 'paused_by_operator' && !$leaseOnly) {
        throw new GenerationBatchItemPausedException('Element batcha został wstrzymany przez operatora.');
    }
    bueno_database()->prepare(
        'UPDATE generation_batch_items SET ' . implode(', ', $sets) . ' WHERE id = :id'
    )->execute($params);
}

/** Pause one active item without discarding its latest pipeline checkpoint. */
function generation_batch_pause_item(int $itemId, string $actor = 'operator'): array
{
    $item = generation_batch_find_item($itemId);
    if (!is_array($item) || !in_array((string) $item['status'], GENERATION_BATCH_ACTIVE_STATUSES, true)) {
        throw new DomainException('Można wstrzymać wyłącznie aktywny element batcha.');
    }
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $update = $database->prepare('UPDATE generation_batch_items SET
            paused_from_status=status,status="paused_by_operator",outcome="manual_ready_to_resume",
            wait_reason="Wstrzymany przez operatora.",error_message="",available_at=CURRENT_TIMESTAMP,next_retry_at=NULL,
            quota_dimension="",quota_model="",lease_token=NULL,lease_expires_at=NULL,paused_at=CURRENT_TIMESTAMP,
            completed_at=NULL,updated_at=CURRENT_TIMESTAMP
            WHERE id=:id AND status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled")');
        $update->execute([':id' => $itemId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('Można wstrzymać wyłącznie aktywny element batcha.');
        }
        generation_batch_audit((int) $item['batch_id'], $itemId, 'item_paused_by_operator', $actor, [
            'previous_status' => (string) $item['status'], 'stage' => (string) $item['stage'],
            'checkpoint_preserved' => true,
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
    generation_batch_refresh_status((int) $item['batch_id']);
    return generation_batch_find_item($itemId) ?? [];
}

/** Back up generated artifacts and return one RSS-backed article to a clean
 * baseline. Real Gemini quota events stay immutable; the per-article budget is reset. */
function reset_topic_for_fresh_pipeline(int $topicId, string $actor = 'admin'): array
{
    $topic = find_editorial_topic($topicId);
    $postId = (int) ($topic['primary_post_id'] ?? 0);
    if (!is_array($topic) || $postId <= 0) throw new InvalidArgumentException('Temat nie ma artykułu do wyzerowania.');
    $post = find_post($postId, true);
    if (!is_array($post)) throw new InvalidArgumentException('Nie znaleziono artykułu do wyzerowania.');
    if (in_array((string) ($post['status'] ?? ''), ['published', 'scheduled'], true) || (int) ($post['is_published'] ?? 0) === 1) {
        throw new DomainException('Opublikowanego lub zaplanowanego artykułu nie można wyzerować tym przyciskiem.');
    }
    $database = bueno_database();
    $activeMarks = implode(',', array_fill(0, count(GENERATION_BATCH_ACTIVE_STATUSES), '?'));
    $active = $database->prepare('SELECT COUNT(*) FROM generation_batch_items WHERE topic_id=? AND status IN (' . $activeMarks . ')');
    $active->execute([$topicId, ...GENERATION_BATCH_ACTIVE_STATUSES]);
    if ((int) $active->fetchColumn() > 0) throw new DomainException('Najpierw wstrzymaj generowanie przyciskiem pauzy, a następnie użyj resetu.');

    $backupDirectory = app_path('data/backups');
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0755, true) && !is_dir($backupDirectory)) throw new RuntimeException('Nie można utworzyć katalogu backupu.');
    $fetch = static function (string $table, string $where, array $parameters) use ($database): array {
        $statement = $database->prepare('SELECT * FROM ' . $table . ' WHERE ' . $where);
        $statement->execute($parameters);
        return $statement->fetchAll();
    };
    $items = $fetch('generation_batch_items', 'topic_id=:topic OR post_id=:post', [':topic'=>$topicId, ':post'=>$postId]);
    $itemIds = array_map('intval', array_column($items, 'id'));
    $backup = ['contract'=>'fresh_pipeline_reset_v1','created_at'=>gmdate('c'),'topic_id'=>$topicId,'post_id'=>$postId,
        'posts'=>$fetch('posts','id=:id',[':id'=>$postId]),
        'research_packages'=>$fetch('research_packages','post_id=:id',[':id'=>$postId]),
        'narrative_plans'=>$fetch('narrative_plans','article_id=:id',[':id'=>$postId]),
        'article_draft_versions'=>$fetch('article_draft_versions','post_id=:id',[':id'=>$postId]),
        'quality_check_runs'=>$fetch('quality_check_runs','post_id=:id',[':id'=>$postId]),
        'final_multimodal_qc_runs'=>$fetch('final_multimodal_qc_runs','post_id=:id',[':id'=>$postId]),
        'thumbnail_versions'=>$fetch('thumbnail_versions','post_id=:id',[':id'=>$postId]),
        'article_feedback_operations'=>$fetch('article_feedback_operations','post_id=:id',[':id'=>$postId]),
        'article_proposal_audit'=>$fetch('article_proposal_audit','post_id=:id',[':id'=>$postId]),
        'article_images'=>$fetch('article_images','post_id=:id',[':id'=>$postId]),
        'article_related_context_blocks'=>$fetch('article_related_context_blocks','post_id=:id',[':id'=>$postId]),
        'article_image_vision_audit'=>$fetch('article_image_vision_audit','post_id=:id',[':id'=>$postId]),
        'generation_operations'=>$fetch('generation_operations','post_id=:id',[':id'=>$postId]),
        'article_generation_budget'=>$fetch('article_generation_budget','article_id=:id',[':id'=>$postId]),
        'generation_batch_items'=>$items,
        'gemini_quota_events'=>$fetch('gemini_quota_events','topic_id=:topic',[':topic'=>$topicId]),
        'quota_history_policy'=>'preserved_for_real_provider_accounting'];
    if ($itemIds !== []) {
        $marks = implode(',', array_fill(0, count($itemIds), '?'));
        $backup['generation_batch_audit'] = $fetch('generation_batch_audit', 'item_id IN (' . $marks . ')', $itemIds);
    } else $backup['generation_batch_audit'] = [];
    $backupPath = $backupDirectory . '/fresh-pipeline-topic-' . $topicId . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
    $encoded = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($backupPath, $encoded, LOCK_EX) === false) throw new RuntimeException('Nie udało się zapisać backupu resetu.');

    $database->beginTransaction();
    try {
        if ($itemIds !== []) {
            $marks = implode(',', array_fill(0, count($itemIds), '?'));
            $database->prepare('DELETE FROM generation_batch_audit WHERE item_id IN (' . $marks . ')')->execute($itemIds);
        }
        $database->prepare('DELETE FROM generation_batch_items WHERE topic_id=:topic OR post_id=:post')->execute([':topic'=>$topicId, ':post'=>$postId]);
        foreach (['final_multimodal_qc_runs','thumbnail_versions','quality_check_runs','article_feedback_operations','article_proposal_audit','article_related_context_blocks','article_image_vision_audit','article_draft_versions','research_packages','article_images','generation_operations'] as $table) {
            $database->prepare('DELETE FROM ' . $table . ' WHERE post_id=:post')->execute([':post'=>$postId]);
        }
        $database->prepare('DELETE FROM narrative_plans WHERE article_id=:post')->execute([':post'=>$postId]);
        $database->prepare('DELETE FROM article_generation_budget WHERE article_id=:post')->execute([':post'=>$postId]);
        $database->prepare('INSERT INTO post_status_history (post_id,previous_status,new_status,reason,actor) VALUES (:post,:previous,"idea","Reset do świeżego pipeline; backup zapisany przed usunięciem artefaktów.",:actor)')->execute([':post'=>$postId,':previous'=>(string)$post['status'],':actor'=>mb_substr($actor,0,100)]);
        $database->prepare('UPDATE posts SET title=COALESCE((SELECT title FROM discovered_feed_items WHERE post_id=:post ORDER BY id DESC LIMIT 1),title),excerpt=COALESCE((SELECT summary FROM discovered_feed_items WHERE post_id=:post ORDER BY id DESC LIMIT 1),""),content="",image_path="",content_image_path="",content_images="[]",content_blocks="[]",image_alt="",status="idea",is_published=0,published_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:post')->execute([':post'=>$postId]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
    return ['topic_id'=>$topicId,'post_id'=>$postId,'backup_path'=>$backupPath,'backup_sha256'=>hash_file('sha256',$backupPath),'gemini_budget_used'=>0];
}

/** Resume an item only when it was explicitly paused by an operator. */
function resume_generation_batch_item(int $itemId, string $actor = 'operator'): array
{
    $item = generation_batch_find_item($itemId);
    $resumeStage = (string) ($item['stage'] ?? '');
    if (!is_array($item) || (string) $item['status'] !== 'paused_by_operator'
        || !in_array((string) $item['paused_from_status'], GENERATION_BATCH_ACTIVE_STATUSES, true)
        || !in_array($resumeStage, GENERATION_WORKFLOW_STAGES, true)) {
        throw new DomainException('Wznowić można wyłącznie element wstrzymany przez operatora.');
    }
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $database->prepare('UPDATE generation_batch_items SET status=:status,paused_from_status="",paused_at=NULL,
            outcome="queued",wait_reason="",error_message="",available_at=CURRENT_TIMESTAMP,next_retry_at=NULL,
            quota_dimension="",quota_model="",lease_token=NULL,lease_expires_at=NULL,completed_at=NULL,updated_at=CURRENT_TIMESTAMP
            WHERE id=:id AND status="paused_by_operator"')
            ->execute([':id' => $itemId, ':status' => $resumeStage]);
        generation_batch_audit((int) $item['batch_id'], $itemId, 'item_resumed_by_operator', $actor, [
            'resume_stage' => $resumeStage, 'paused_from_status' => (string) $item['paused_from_status'],
            'checkpoint_preserved' => true,
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
    generation_batch_refresh_status((int) $item['batch_id']);
    return generation_batch_find_item($itemId) ?? [];
}

function generation_batch_is_autonomous(array $item): bool
{
    return (string) ($item['batch_action'] ?? '') === 'generate_all'
        && (string) ($item['batch_execution_mode'] ?? '') === 'api';
}

function generation_batch_legacy_checkpoint(int $topicId): array
{
    $drafts=bueno_database()->prepare('SELECT d.*,r.generation_operation_id research_operation_id,r.status research_status
        FROM article_draft_versions d INNER JOIN research_packages r ON r.id=d.research_package_id
        WHERE d.topic_id=:topic AND d.is_active=1 AND d.status="completed" AND r.status="approved"
        ORDER BY d.id DESC');
    $drafts->execute([':topic'=>$topicId]);
    foreach($drafts->fetchAll() as $draft){
        $validation=json_decode((string)$draft['validation_json'],true)?:[];$json=json_decode((string)$draft['draft_json'],true)?:[];
        if(($validation['valid']??false)!==true||$json===[]||article_draft_main_content_length($json)<=0)continue;
        return ['checkpoint'=>'validated_active_draft','stage'=>'quality_check','status'=>'queued','post_id'=>(int)$draft['post_id'],
            'research_package_id'=>(int)$draft['research_package_id'],'research_operation_id'=>(int)$draft['research_operation_id'],
            'draft_version_id'=>(int)$draft['id'],'draft_operation_id'=>(int)$draft['generation_operation_id']];
    }
    $research=generation_workflow_latest_approved_research($topicId);
    if(is_array($research))return ['checkpoint'=>'approved_research','stage'=>'draft','status'=>'queued','post_id'=>(int)$research['post_id'],
        'research_package_id'=>(int)$research['id'],'research_operation_id'=>(int)$research['generation_operation_id']];
    $feed=list_safe_feed_research_sources($topicId);
    if($feed!==[])return ['checkpoint'=>'safe_feed','stage'=>'research','status'=>'queued','post_id'=>(int)(find_editorial_topic($topicId)['primary_post_id']??0),
        'wait_reason'=>'Pełna strona niedostępna — kontynuuję z danymi RSS i szukam drugiego źródła.'];
    return ['checkpoint'=>'no_material','stage'=>'research','status'=>'auto_retry_scheduled','post_id'=>(int)(find_editorial_topic($topicId)['primary_post_id']??0),
        'outcome'=>'source_discovery_scheduled','available_at'=>gmdate('Y-m-d H:i:s',time()+300),
        'wait_reason'=>'Brak jakiegokolwiek materiału — zaplanowano autonomiczne pozyskanie źródeł.'];
}

function generation_batch_resume_legacy_item(int $legacyItemId,string $actor='system'): array
{
    $legacy=generation_batch_find_item($legacyItemId);
    if(!is_array($legacy)||(string)$legacy['status']!=='auto_rejected'||!generation_batch_is_autonomous($legacy))throw new DomainException('Element nie jest automatycznie odrzuconym przebiegiem generate_all/API.');
    $topic=find_editorial_topic((int)$legacy['topic_id']);
    if(!is_array($topic)||in_array((string)($topic['primary_post_status']??''),['rejected','trash','published','scheduled'],true))throw new DomainException('Temat został ręcznie odrzucony albo zakończony i nie może być wznowiony automatycznie.');
    $existing=bueno_database()->prepare('SELECT * FROM generation_batch_items WHERE migrated_from_item_id=:legacy LIMIT 1');$existing->execute([':legacy'=>$legacyItemId]);
    if(is_array($row=$existing->fetch()))return ['created'=>false,'item'=>$row,'checkpoint'=>(string)$row['chosen_checkpoint']];
    generation_batch_supersede_stale_legacy_predecessors($legacy,$actor);
    $active=bueno_database()->prepare('SELECT id FROM generation_batch_items WHERE topic_id=:topic AND id<>:legacy AND status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled") LIMIT 1');
    $active->execute([':topic'=>(int)$legacy['topic_id'],':legacy'=>$legacyItemId]);
    if($active->fetchColumn()!==false)throw new DomainException('Temat ma już aktywny autonomiczny przebieg.');
    $checkpoint=generation_batch_legacy_checkpoint((int)$legacy['topic_id']);$db=bueno_database();$db->beginTransaction();
    try{
        // A reset may remove the migrated item while retaining historical batches.
        // Keep the migration idempotent through migrated_from_item_id, but never reuse
        // a request key owned by a historical, now-empty batch.
        $requestKey='legacy-router-resume-'.$legacyItemId.'-'.bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO generation_batches(batch_key,request_key,action,item_count,created_by,execution_mode,status) VALUES(:key,:request,"generate_all",1,:actor,"api","running")')
            ->execute([':key'=>bin2hex(random_bytes(16)),':request'=>$requestKey,':actor'=>mb_substr($actor,0,100)]);
        $batchId=(int)$db->lastInsertId();
        $values=[':batch'=>$batchId,':topic'=>(int)$legacy['topic_id'],':status'=>$checkpoint['status'],':stage'=>$checkpoint['stage'],
            ':progress'=>$checkpoint['stage']==='research'?5:($checkpoint['stage']==='draft'?35:70),':research_operation'=>$checkpoint['research_operation_id']??null,
            ':research_package'=>$checkpoint['research_package_id']??null,':draft_operation'=>$checkpoint['draft_operation_id']??null,
            ':draft_version'=>$checkpoint['draft_version_id']??null,':post'=>$checkpoint['post_id']??null,':outcome'=>$checkpoint['outcome']??'legacy_checkpoint_resumed',
            ':wait_reason'=>$checkpoint['wait_reason']??'Wznowiono od ostatniego bezpiecznego checkpointu.',':available'=>$checkpoint['available_at']??gmdate('Y-m-d H:i:s'),
            ':legacy'=>$legacyItemId,':checkpoint'=>$checkpoint['checkpoint']];
        $db->prepare('INSERT INTO generation_batch_items(batch_id,topic_id,status,stage,progress_percent,research_operation_id,research_package_id,draft_operation_id,draft_version_id,post_id,outcome,wait_reason,available_at,migrated_from_item_id,chosen_checkpoint)
            VALUES(:batch,:topic,:status,:stage,:progress,:research_operation,:research_package,:draft_operation,:draft_version,:post,:outcome,:wait_reason,:available,:legacy,:checkpoint)')->execute($values);
        $itemId=(int)$db->lastInsertId();
        $db->prepare('UPDATE editorial_topics SET automatic_eligible=1,automation_status=:status,automation_reason=:reason,automation_updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute([':status'=>$checkpoint['status'],':reason'=>'Wznowienie nowym routerem od '.$checkpoint['checkpoint'],':id'=>(int)$legacy['topic_id']]);
        generation_batch_audit($batchId,$itemId,'legacy_checkpoint_resume_created',$actor,['migrated_from_item_id'=>$legacyItemId,'chosen_checkpoint'=>$checkpoint['checkpoint'],'preserved_old_item'=>true]);
        generation_batch_audit((int)$legacy['batch_id'],$legacyItemId,'legacy_migrated_to_new_router',$actor,['new_batch_id'=>$batchId,'new_item_id'=>$itemId,'chosen_checkpoint'=>$checkpoint['checkpoint']]);
        $db->commit();return ['created'=>true,'batch_id'=>$batchId,'item_id'=>$itemId,'checkpoint'=>$checkpoint['checkpoint']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function generation_batch_supersede_stale_legacy_predecessors(array $legacy,string $actor='system'): array
{
    $statement=bueno_database()->prepare('SELECT i.*,b.action batch_action,b.execution_mode batch_execution_mode
        FROM generation_batch_items i INNER JOIN generation_batches b ON b.id=i.batch_id
        WHERE i.topic_id=:topic AND i.id<:legacy AND i.status="auto_retry_scheduled"
          AND b.action="generate_all" AND b.execution_mode="api" ORDER BY i.id');
    $statement->execute([':topic'=>(int)$legacy['topic_id'],':legacy'=>(int)$legacy['id']]);$superseded=[];
    foreach($statement->fetchAll() as $item){
        $text=mb_strtolower((string)($item['error_message']??'').' '.(string)($item['wait_reason']??''));
        $transient=str_contains($text,'429')||str_contains($text,'rate limit')||str_contains($text,'limit api')
            ||str_contains($text,'timeout')||str_contains($text,'timed out')||str_contains($text,'temporarily unavailable');
        $missingCheckpoint=((string)$item['stage']==='quality_check'&&(int)($item['draft_version_id']??0)<=0)
            ||((string)$item['stage']==='draft'&&(int)($item['research_package_id']??0)<=0);
        $legacyOutcome=in_array((string)($item['outcome']??''),['validation_retry_scheduled','infrastructure_retry_scheduled','reconcile_state_retry'],true);
        if($transient||!$missingCheckpoint||!$legacyOutcome)continue;
        generation_batch_update_item((int)$item['id'],['status'=>'cancelled','outcome'=>'legacy_superseded_by_checkpoint_resume',
            'wait_reason'=>'Stary nieodtwarzalny retry został zastąpiony wznowieniem od bezpiecznego checkpointu.','completed_at'=>gmdate('Y-m-d H:i:s')]);
        generation_batch_audit((int)$item['batch_id'],(int)$item['id'],'legacy_stale_retry_superseded',$actor,
            ['superseded_by_legacy_item_id'=>(int)$legacy['id'],'preserved_old_item'=>true,'transient_failure'=>false]);
        $superseded[]=(int)$item['id'];
    }
    return $superseded;
}

function generation_batch_reconcile_legacy_auto_rejected(?array $itemIds=null): array
{
    $params=[];$filter='';if(is_array($itemIds)&&$itemIds!==[]){$ids=array_values(array_filter(array_unique(array_map('intval',$itemIds)),static fn(int $id):bool=>$id>0));if($ids===[])return[];$filter=' AND i.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$params=$ids;}
    $statement=bueno_database()->prepare('SELECT i.id FROM generation_batch_items i INNER JOIN generation_batches b ON b.id=i.batch_id INNER JOIN posts p ON p.id=(SELECT primary_post_id FROM editorial_topics WHERE id=i.topic_id)
        WHERE i.status="auto_rejected" AND b.action="generate_all" AND b.execution_mode="api" AND p.status NOT IN ("rejected","trash","published","scheduled")
          AND NOT EXISTS(SELECT 1 FROM generation_batch_items migrated WHERE migrated.migrated_from_item_id=i.id)
          AND NOT EXISTS(SELECT 1 FROM generation_batch_items newer WHERE newer.topic_id=i.topic_id AND newer.id>i.id AND newer.status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled"))'.$filter.' ORDER BY i.id DESC');
    $statement->execute($params);$results=[];$topics=[];
    foreach($statement->fetchAll(PDO::FETCH_COLUMN) as $id){$legacy=generation_batch_find_item((int)$id);if(!is_array($legacy)||isset($topics[(int)$legacy['topic_id']]))continue;$topics[(int)$legacy['topic_id']]=true;try{$results[]=generation_batch_resume_legacy_item((int)$id,'reconcile');}catch(DomainException){}}
    return $results;
}

function generation_batch_can_retry_research(array $item): bool
{
    return (int) ($item['auto_repair_count'] ?? 0) < 2
        && (list_verified_research_sources((int) $item['topic_id']) !== [] || topic_feed_items((int) $item['topic_id']) !== []);
}

function generation_batch_queue_research_retry(array $item, string $reason, array $details = []): void
{
    $attempt = (int) ($item['auto_repair_count'] ?? 0) + 1;
    generation_batch_update_item((int) $item['id'], [
        'status' => 'auto_repair', 'stage' => 'research', 'progress_percent' => 15,
        'research_operation_id' => null, 'research_package_id' => null,
        'draft_operation_id' => null, 'draft_version_id' => null,
        'quality_operation_id' => null, 'quality_check_id' => null,
        'retry_count' => max((int) $item['retry_count'], $attempt), 'auto_repair_count' => $attempt,
        'outcome' => 'research_retry', 'wait_reason' => 'Ponowny research ' . $attempt . '/2: ' . mb_substr($reason, 0, 700),
        'error_message' => '', 'completed_at' => null, 'available_at' => gmdate('Y-m-d H:i:s'),
    ]);
    generation_batch_audit((int) $item['batch_id'], (int) $item['id'], 'research_auto_retry_queued', 'worker', [
        'attempt' => $attempt, 'reason' => $reason, 'previous_research_package_id' => $item['research_package_id'] ?? null,
        ...$details,
    ]);
}

/** Reconciles legacy autonomous generate_all items without replaying approved research or completed drafts. */
function generation_batch_reconcile_autonomous_items(?array $itemIds = null): array
{
    $params = [];
    $filter = '';
    if (is_array($itemIds) && $itemIds !== []) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $filter = ' AND items.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }
    $statement = bueno_database()->prepare(
        'SELECT items.*, batches.action AS batch_action, batches.execution_mode AS batch_execution_mode, batches.created_by
         FROM generation_batch_items items
         INNER JOIN generation_batches batches ON batches.id=items.batch_id
         WHERE items.status IN ("waiting_review","auto_rejected") AND batches.action="generate_all"
           AND batches.execution_mode="api"
           AND (items.status="waiting_review" OR items.outcome IN ("auto_repair_limit","auto_repair_limit_reconciled"))'
           . ' AND NOT EXISTS (SELECT 1 FROM generation_batch_items active_item
                WHERE active_item.topic_id=items.topic_id AND active_item.id<>items.id
                  AND active_item.status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled"))'
           . $filter . ' ORDER BY items.id'
    );
    $statement->execute($params);
    $results = [];
    foreach ($statement->fetchAll() as $item) {
        $audit = bueno_database()->prepare(
            'SELECT details_json FROM generation_batch_audit
             WHERE item_id=:item AND action="auto_repair_draft_validated" ORDER BY id'
        );
        $audit->execute([':item' => (int) $item['id']]);
        $performedAttempts = [];
        foreach ($audit->fetchAll(PDO::FETCH_COLUMN) as $detailsJson) {
            $details = json_decode((string) $detailsJson, true) ?: [];
            $attempt = (int) ($details['attempt'] ?? 0);
            if ($attempt >= 1 && $attempt <= 2) $performedAttempts[$attempt] = true;
        }
        if ($performedAttempts === [] && (int) ($item['post_id'] ?? 0) > 0) {
            $legacy = bueno_database()->prepare(
                'SELECT COUNT(*) FROM article_draft_versions
                 WHERE post_id=:post AND change_source="auto_qc_repair" AND status="completed"'
            );
            $legacy->execute([':post' => (int) $item['post_id']]);
            $legacyCount = min(2, (int) $legacy->fetchColumn());
            for ($attempt = 1; $attempt <= $legacyCount; $attempt++) $performedAttempts[$attempt] = true;
        }
        $performed = count($performedAttempts);
        if ($performed >= 2) {
            generation_batch_update_item((int) $item['id'], ['status' => 'auto_repair', 'stage' => 'quality_check',
                'outcome' => 'safe_composer_queued', 'progress_percent' => 84, 'completed_at' => null,
                'wait_reason' => 'Uruchamiam deterministyczny bezpieczny kompozytor.', 'error_message' => '',
                'available_at' => gmdate('Y-m-d H:i:s')]);
            bueno_database()->prepare('UPDATE editorial_topics SET automatic_eligible=1,automation_status="auto_repair",automation_reason="Wznowienie przez safe composer",automation_updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute([':id' => (int) $item['topic_id']]);
            repair_report_append((int) $item['id'], 'final_package', 'safe_composer', ['reconciled' => true, 'performed_attempts' => $performed]);
            generation_batch_audit((int) $item['batch_id'], (int) $item['id'], 'autonomous_item_reconciled', 'system', ['decision' => 'safe_composer_queued', 'performed_attempts' => $performed]);
            $results[] = ['item_id' => (int) $item['id'], 'decision' => 'safe_composer_queued', 'performed_attempts' => $performed];
            generation_batch_refresh_status((int) $item['batch_id']);
            continue;
        }
        $draft = find_article_draft_by_id((int) ($item['draft_version_id'] ?? 0));
        $check = (int) ($item['quality_operation_id'] ?? 0) > 0
            ? find_quality_check_by_operation((int) $item['quality_operation_id'])
            : (is_array($draft) ? generation_workflow_latest_quality((int) $draft['id']) : null);
        if (!is_array($draft) || !is_array($check)) {
            generation_batch_update_item((int) $item['id'], ['status' => 'auto_retry_scheduled', 'outcome' => 'reconcile_state_retry',
                'wait_reason' => 'Automatyczne wznowienie po odtworzeniu stanu operacji.', 'available_at' => gmdate('Y-m-d H:i:s', time() + 60), 'completed_at' => null]);
            $results[] = ['item_id' => (int) $item['id'], 'decision' => 'auto_retry_scheduled', 'performed_attempts' => $performed];
            generation_batch_refresh_status((int) $item['batch_id']);
            continue;
        }
        $convergenceActive1 = (int) ($item['convergence_active'] ?? 0) === 1;
        $decision = quality_check_auto_repair_decision($check, $convergenceActive1);
        if (($decision['repairable'] ?? false) !== true || ($decision['target_stage'] ?? 'draft') !== 'draft') {
            generation_batch_update_item((int) $item['id'], ['status' => 'auto_repair', 'stage' => 'quality_check', 'outcome' => 'safe_composer_queued',
                'wait_reason' => 'Router usuwa niewspierane elementy przez safe composer.', 'available_at' => gmdate('Y-m-d H:i:s'), 'completed_at' => null]);
            repair_report_append((int) $item['id'], 'factual_source', 'safe_composer', ['reasons' => $decision['reasons'] ?? []]);
            $results[] = ['item_id' => (int) $item['id'], 'decision' => 'safe_composer_queued', 'performed_attempts' => $performed];
            generation_batch_refresh_status((int) $item['batch_id']);
            continue;
        }
        $attempt = $performed + 1;
        /* In convergence mode, always use targeted_repair; never full rewrite. */
        if ($convergenceActive1) {
            $strategy = 'targeted_repair';
        } else {
            $strategy = $attempt === 1 ? 'targeted_repair' : 'fresh_conservative_rewrite';
        }
        $operationId = prepare_article_qc_repair_operation((int) $draft['id'], $check, $decision, $attempt);
        $repairDraft = find_article_draft_by_operation($operationId);
        if (!is_array($repairDraft)) throw new RuntimeException('Reconcile nie utworzył wersji korekty.');
        generation_batch_update_item((int) $item['id'], [
            'status' => 'auto_repair', 'stage' => 'draft', 'progress_percent' => 55,
            'draft_operation_id' => $operationId, 'draft_version_id' => (int) $repairDraft['id'],
            'quality_operation_id' => null, 'quality_check_id' => null,
            'retry_count' => max((int) $item['retry_count'], $attempt), 'auto_repair_count' => $attempt,
            'outcome' => 'auto_repair', 'wait_reason' => $strategy === 'fresh_conservative_rewrite'
                ? 'Tworzę uproszczoną wersję awaryjną (próba 2/2).'
                : 'Automatyczna poprawka 1/2.',
            'error_message' => '', 'completed_at' => null, 'available_at' => gmdate('Y-m-d H:i:s'),
        ]);
        generation_batch_audit((int) $item['batch_id'], (int) $item['id'], 'autonomous_item_reconciled', 'system', [
            'decision' => 'queued', 'attempt' => $attempt, 'strategy' => $strategy,
            'source_draft_version_id' => (int) $draft['id'], 'repair_draft_version_id' => (int) $repairDraft['id'],
            'research_package_id' => (int) $draft['research_package_id'],
        ]);
        $results[] = ['item_id' => (int) $item['id'], 'decision' => 'queued', 'attempt' => $attempt, 'strategy' => $strategy];
        generation_batch_refresh_status((int) $item['batch_id']);
    }
    return $results;
}

function generation_batch_research_allows_auto_approval(array $package): bool
{
    $validation = json_decode((string) $package['validation_json'], true) ?: [];
    $research = json_decode((string) $package['package_json'], true) ?: [];
    $policy = json_decode((string) ($package['policy_json'] ?? '{}'), true) ?: [];
    return ($validation['valid'] ?? false) === true
        && ($policy['decision'] ?? '') === 'continue'
        && ($research['recommendation']['decision'] ?? '') === 'continue'
        && ($research['recommendation']['source_coverage'] ?? '') === 'sufficient'
        && (int) ($validation['cited_source_count'] ?? 0) > 0
        && (array) ($research['contradictions'] ?? []) === [];
}

function research_policy_message(array $policy, bool $recovering = false): string
{
    if ($recovering) return 'Ponawiam weryfikację źródła.';
    return match ((string)($policy['code'] ?? '')) {
        'no_complete_primary', 'no_source_material' => 'Brak jakiegokolwiek legalnego materiału wejściowego; system szuka innego źródła.',
        'safe_feed_excerpt' => 'Pełna strona niedostępna — kontynuuję z danymi RSS i szukam drugiego źródła.',
        'contradiction' => 'Źródła są sprzeczne i wymagają decyzji redakcyjnej.',
        'second_independent_source_required' => 'Polityka ryzyka wymaga drugiego niezależnego źródła.',
        'enrichment_technical_error' => 'Błąd techniczny weryfikacji źródła; system ponowi enrichment.',
        default => (string)($policy['reason'] ?? 'Research wymaga decyzji redakcyjnej.'),
    };
}

function research_sources_fingerprint(int $topicId): string
{
    return hash('sha256', generation_json(research_numbered_sources($topicId)));
}

function generation_batch_should_attempt_research_enrichment(int $itemId, string $fingerprint): bool
{
    $previous = bueno_database()->prepare(
        'SELECT COUNT(*) FROM generation_batch_audit
         WHERE item_id=:item AND action="research_enrichment_attempted"
           AND json_extract(details_json,"$.fingerprint")=:fingerprint'
    );
    $previous->execute([':item' => $itemId, ':fingerprint' => $fingerprint]);
    return (int) $previous->fetchColumn() === 0;
}

function generation_batch_reconcile_feed_enrichment_regression(?array $itemIds = null): array
{
    $params=[];$filter='';
    if(is_array($itemIds)&&$itemIds!==[]){$ids=array_values(array_filter(array_unique(array_map('intval',$itemIds)),static fn(int $id):bool=>$id>0));if($ids===[])return[];$filter=' AND items.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$params=$ids;}
    $statement=bueno_database()->prepare('SELECT items.*,batches.action batch_action,batches.execution_mode batch_execution_mode
        FROM generation_batch_items items INNER JOIN generation_batches batches ON batches.id=items.batch_id
        WHERE items.status="auto_retry_scheduled" AND items.stage="research" AND items.outcome="research_enrichment_scheduled"
          AND batches.action="generate_all" AND batches.execution_mode="api"'.$filter.' ORDER BY items.id');
    $statement->execute($params);$results=[];
    foreach($statement->fetchAll() as $item){
        $feed=list_safe_feed_research_sources((int)$item['topic_id']);if($feed===[])continue;
        $audit=bueno_database()->prepare('SELECT details_json FROM generation_batch_audit WHERE item_id=:item AND action="research_auto_retry_queued" ORDER BY id DESC LIMIT 1');
        $audit->execute([':item'=>(int)$item['id']]);$details=json_decode((string)($audit->fetchColumn()?:'{}'),true)?:[];
        $transient=false;foreach((array)($details['enrichment']['errors']??[]) as $error){$message=mb_strtolower((string)($error['error']??''));$status=(int)($error['http_status']??0);if(!empty($error['retryable'])||$status===429||$status>=500||str_contains($message,'timeout')||str_contains($message,'timed out')){$transient=true;break;}}
        if($transient)continue;
        generation_batch_update_item((int)$item['id'],['status'=>'queued','stage'=>'research','outcome'=>'safe_feed_research_queued','progress_percent'=>5,
            'available_at'=>gmdate('Y-m-d H:i:s'),'completed_at'=>null,'error_message'=>'','wait_reason'=>'Pełna strona niedostępna — kontynuuję z danymi RSS i szukam drugiego źródła.']);
        bueno_database()->prepare('UPDATE editorial_topics SET automatic_eligible=1,automation_status="queued",automation_reason="Kontynuacja z legalnych danych RSS",automation_updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id'=>(int)$item['topic_id']]);
        generation_batch_audit((int)$item['batch_id'],(int)$item['id'],'feed_enrichment_regression_reconciled','system',['chosen_strategy'=>'safe_feed_research','feed_item_ids'=>array_column($feed,'feed_item_id'),'preserved_retry_count'=>(int)$item['retry_count']]);
        $results[]=['item_id'=>(int)$item['id'],'topic_id'=>(int)$item['topic_id'],'strategy'=>'safe_feed_research'];
    }
    return $results;
}

function generation_batch_backfill_research_sources(?array $topicIds = null, ?callable $pageFetcher = null, ?callable $registry = null): array
{
    $params=[];$topicFilter='';
    if(is_array($topicIds)&&$topicIds!==[]){$ids=array_values(array_unique(array_map('intval',$topicIds)));$topicFilter=' AND i.topic_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$params=$ids;}
    $statement=bueno_database()->prepare('SELECT i.*,r.policy_json FROM generation_batch_items i INNER JOIN research_packages r ON r.id=i.research_package_id WHERE i.status="waiting_review" AND i.stage="research" AND NOT EXISTS (SELECT 1 FROM generation_batch_items newer WHERE newer.topic_id=i.topic_id AND newer.id>i.id)'.$topicFilter.' ORDER BY i.id DESC');
    $statement->execute($params);$seen=[];$results=[];
    foreach($statement->fetchAll() as $item){$topicId=(int)$item['topic_id'];if(isset($seen[$topicId]))continue;$seen[$topicId]=true;
        $oldPolicy=json_decode((string)$item['policy_json'],true)?:[];
        if(list_verified_research_sources($topicId)===[])$oldPolicy=['decision'=>'blocked','code'=>'no_complete_primary','reason'=>'Brak kompletnego, zweryfikowanego źródła pierwotnego.'];
        if(($oldPolicy['code']??'')!=='no_complete_primary')continue;
        $before=research_sources_fingerprint($topicId);generation_batch_update_item((int)$item['id'],['wait_reason'=>research_policy_message($oldPolicy,true)]);
        $enrichment=enrich_topic_sources($topicId,$pageFetcher,$registry);$after=research_sources_fingerprint($topicId);
        $topic=find_editorial_topic($topicId);$policy=research_policy_decision(list_verified_research_sources($topicId),(string)($topic['risk_level']??'low'),!empty($topic['is_controversial']));
        $changed=!hash_equals($before,$after);$queued=false;
        if($changed&&($policy['decision']??'')==='continue'){
            generation_batch_update_item((int)$item['id'],['status'=>'queued','progress_percent'=>0,'research_operation_id'=>null,'research_package_id'=>null,'wait_reason'=>'','error_message'=>'','outcome'=>'queued','completed_at'=>null,'available_at'=>gmdate('Y-m-d H:i:s')]);$queued=true;
            generation_batch_audit((int)$item['batch_id'],(int)$item['id'],'research_source_backfill_queued','system',['old_package_id'=>(int)$item['research_package_id'],'old_fingerprint'=>$before,'new_fingerprint'=>$after,'policy'=>$policy]);
        }else{
            $technical=(int)($enrichment['failed']??0)>0&&list_verified_research_sources($topicId)===[];
            if($technical)$policy=['decision'=>'blocked','code'=>'enrichment_technical_error','reason'=>'Enrichment nie pobrał źródła z powodu błędu technicznego.'];
            generation_batch_update_item((int)$item['id'],['wait_reason'=>research_policy_message($policy)]);
            generation_batch_audit((int)$item['batch_id'],(int)$item['id'],'research_source_backfill_no_retry','system',['old_package_id'=>(int)$item['research_package_id'],'fingerprint_changed'=>$changed,'policy'=>$policy,'enrichment'=>$enrichment]);
        }
        $results[]=['topic_id'=>$topicId,'item_id'=>(int)$item['id'],'old_package_id'=>(int)$item['research_package_id'],'fingerprint_changed'=>$changed,'enrichment'=>$enrichment,'policy'=>$policy,'queued'=>$queued,'gemini_called'=>false];
    }
    return $results;
}

function generation_batch_process_item(int $itemId, string $leaseToken, ?callable $transport = null): void
{
    $item = generation_batch_find_item($itemId);
    if ($item === null || !hash_equals((string) ($item['lease_token'] ?? ''), $leaseToken)) {
        return;
    }
    if (generation_automatic_dispatch_paused() && (string) ($item['dispatch_mode'] ?? 'automatic') !== 'operator_manual') {
        bueno_database()->prepare('UPDATE generation_batch_items SET paused_from_status=status,status="paused_by_operator",
            outcome="manual_ready_to_resume",wait_reason="Wstrzymany — uruchom ręcznie.",next_retry_at=NULL,
            quota_dimension="",quota_model="",lease_token=NULL,lease_expires_at=NULL,paused_at=CURRENT_TIMESTAMP,
            completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id' => $itemId]);
        bueno_database()->prepare('UPDATE generation_batches SET status="paused",updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute([':id' => (int) $item['batch_id']]);
        generation_batch_audit((int) $item['batch_id'], $itemId, 'automatic_dispatch_pause_race_blocked', 'worker', [
            'previous_status' => (string) $item['status'], 'gemini_called' => false,
        ]);
        return;
    }
    try {
        $stage = (string) $item['stage'];
        $batchAction = (string) ($item['batch_action'] ?? 'generate_all');
        if ($stage === 'research') {
            generation_batch_update_item($itemId, ['status' => 'research', 'progress_percent' => 10]);
            $operationId = (int) ($item['research_operation_id'] ?? 0);
            if ($operationId <= 0) {
                $topic = find_editorial_topic((int) $item['topic_id']);
                $policy = research_policy_for_topic((int)$item['topic_id'],(string)($topic['risk_level']??'low'),!empty($topic['is_controversial']));
                $enrichment = ['verified'=>0,'failed'=>0,'retryable_failed'=>0,'permanent_failed'=>0,'errors'=>[],'skipped'=>true];
                if (($policy['decision'] ?? '') !== 'continue') {
                    $fingerprint=research_sources_fingerprint((int)$item['topic_id']);
                    if(generation_batch_should_attempt_research_enrichment($itemId,$fingerprint)){
                        generation_batch_audit((int)$item['batch_id'],$itemId,'research_enrichment_attempted','worker',['fingerprint'=>$fingerprint,'gap'=>$policy['enrichment_gap']??$policy['code']??'unknown','strategy'=>'full_page_then_registry']);
                        $enrichment=enrich_topic_sources((int)$item['topic_id']);
                        $policy=research_policy_for_topic((int)$item['topic_id'],(string)($topic['risk_level']??'low'),!empty($topic['is_controversial']));
                    }else{
                        $enrichment=['verified'=>0,'failed'=>0,'retryable_failed'=>0,'permanent_failed'=>0,'errors'=>[],'skipped'=>true,'circuit_breaker'=>'unchanged_fingerprint'];
                    }
                }
                if (($policy['decision'] ?? '') !== 'continue') {
                    if ((int) ($enrichment['retryable_failed'] ?? 0) > 0 && list_verified_research_sources((int) $item['topic_id']) === [] && list_safe_feed_research_sources((int)$item['topic_id'])===[]) {
                        $policy = ['decision'=>'blocked','code'=>'enrichment_technical_error','reason'=>'Enrichment nie pobrał źródła z powodu błędu technicznego.'];
                    }
                    $reason = research_policy_message($policy);
                    if (generation_batch_is_autonomous($item)) {
                        if (generation_batch_can_retry_research($item)) {
                            generation_batch_queue_research_retry($item, $reason, ['policy'=>$policy,'enrichment'=>$enrichment]);
                        } else {
                            $delay = max(300, (int) app_config('batch_rate_limit_backoff_seconds'));
                            generation_batch_update_item($itemId, ['status'=>'auto_retry_scheduled','outcome'=>'research_enrichment_scheduled',
                                'available_at'=>gmdate('Y-m-d H:i:s', time()+$delay),'wait_reason'=>'Zaplanowano ponowne pozyskanie legalnych źródeł. '.$reason,'completed_at'=>null]);
                            repair_report_append($itemId, 'factual_source', 'research_enrichment', ['policy'=>$policy,'enrichment'=>$enrichment,
                                'verified_sources'=>array_values(array_filter(array_map(static fn(array $source): string => (string)($source['source_id']??$source['id']??''),list_verified_research_sources((int)$item['topic_id']))))], [$reason]);
                        }
                    } else {
                        generation_batch_update_item($itemId, ['status'=>'waiting_review','progress_percent'=>20,'wait_reason'=>$reason,'completed_at'=>gmdate('Y-m-d H:i:s')]);
                        generation_batch_audit((int) $item['batch_id'], $itemId, 'research_policy_blocked_before_generation', 'worker', ['policy'=>$policy,'enrichment'=>$enrichment]);
                    }
                    return;
                }
                $operationId = prepare_research_package_operation((int) $item['topic_id']);
                $package = find_research_package_by_operation($operationId);
                generation_batch_update_item($itemId, [
                    'research_operation_id' => $operationId,
                    'research_package_id' => (int) $package['id'],
                    'post_id' => (int) $package['post_id'],
                ]);
            }
            execute_generation_operation($operationId, $transport);
            $articleId = (int) ($item['post_id'] ?? 0);
            if ($articleId > 0) {
                $budgetState = gemini_article_budget_state($articleId);
                if ((int)($budgetState['convergence_active'] ?? 0) === 1) {
                    generation_batch_update_item($itemId, ['convergence_active' => 1]);
                }
            }
            $package = find_research_package_by_operation($operationId);
            if (!is_array($package) || !generation_batch_research_allows_auto_approval($package)) {
                $policy = is_array($package) ? (json_decode((string) ($package['policy_json'] ?? '{}'), true) ?: []) : [];
                $reason = is_array($package) ? research_policy_message($policy) : 'Research nie zwrócił kompletnego, zwalidowanego pakietu.';
                if (generation_batch_is_autonomous($item)) {
                    if (generation_batch_can_retry_research($item)) {
                        generation_batch_queue_research_retry($item, $reason, ['policy'=>$policy]);
                    } else {
                        $delay = max(300, (int) app_config('batch_rate_limit_backoff_seconds'));
                        generation_batch_update_item($itemId, ['status'=>'auto_retry_scheduled','outcome'=>'research_enrichment_scheduled',
                            'available_at'=>gmdate('Y-m-d H:i:s', time()+$delay),'wait_reason'=>'Ponowny research został zaplanowany; niewystarczające źródła nie zostaną obejściem factual gate.','completed_at'=>null]);
                        repair_report_append($itemId, 'factual_source', 'research_enrichment', ['policy'=>$policy,
                            'verified_sources'=>array_values(array_filter(array_map(static fn(array $source): string => (string)($source['source_id']??$source['id']??''),list_verified_research_sources((int)$item['topic_id']))))], [$reason]);
                    }
                } else {
                    generation_batch_update_item($itemId, [
                        'status' => 'waiting_review', 'progress_percent' => 30, 'wait_reason' => $reason,
                        'completed_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    generation_batch_audit((int) $item['batch_id'], $itemId, 'research_waiting_review');
                }
            } else {
                approve_research_package((int) $package['id']);
                $researchOnly = (string) ($item['requested_stage'] ?? '') === 'research';
                generation_batch_update_item($itemId, $researchOnly ? [
                    'status' => 'completed', 'stage' => 'research', 'progress_percent' => 100,
                    'wait_reason' => 'Research ukończony i zatwierdzony.', 'completed_at' => gmdate('Y-m-d H:i:s'),
                ] : [
                    'status' => 'draft', 'stage' => 'draft', 'progress_percent' => 35, 'wait_reason' => '',
                ]);
                generation_batch_audit((int) $item['batch_id'], $itemId, 'research_auto_approved');
            }
        } elseif ($stage === 'draft') {
            $isAutoRepair = (string) ($item['outcome'] ?? '') === 'auto_repair';
            generation_batch_update_item($itemId, ['status' => $isAutoRepair ? 'auto_repair' : 'draft', 'progress_percent' => $isAutoRepair ? 58 : 45]);
            $operationId = (int) ($item['draft_operation_id'] ?? 0);
            if ($operationId <= 0) {
                // Generate NarrativePlan
                $existingPlan = find_narrative_plan_for_post((int) $item['post_id'], (int) $item['topic_id']);
                if ($existingPlan === null) {
                    $existingPlan = generation_batch_finalize_stored_narrative_plan((int) $item['topic_id'], (int) $item['post_id']);
                    if ($existingPlan === null) {
                        $planResult = generate_narrative_plan((int) $item['topic_id'], [], $transport);
                        $planId = (int) ($planResult['plan_id'] ?? 0);
                        $existingPlan = $planId > 0 ? find_narrative_plan($planId) : null;
                    }
                    if ($existingPlan === null) {
                        throw new RuntimeException('Niespójny NarrativePlan: ukończona operacja nie dała się trwale sfinalizować; wymagane ręczne wznowienie po naprawie artefaktu.');
                    }
                }
                if (is_array($existingPlan)) {
                    accept_narrative_plan((int) $existingPlan['id']);
                }

                $operationId = prepare_article_draft_operation(
                    (int) $item['research_package_id'],
                    'informational',
                    is_array($existingPlan) ? $existingPlan : null
                );
                $draft = find_article_draft_by_operation($operationId);
                generation_batch_update_item($itemId, [
                    'draft_operation_id' => $operationId, 'draft_version_id' => (int) $draft['id'],
                ]);
            }
            $savedDraft = find_article_draft_by_operation($operationId);
            $savedValidation = is_array($savedDraft) ? (json_decode((string)$savedDraft['validation_json'], true) ?: []) : [];
            if (($savedValidation['repair_scope'] ?? '') === 'titles') {
                resume_saved_article_title_repair($operationId, $transport);
            } else {
                execute_generation_operation($operationId, $transport);
                $articleId = (int) ($item['post_id'] ?? 0);
                if ($articleId > 0) {
                    $budgetState = gemini_article_budget_state($articleId);
                    if ((int)($budgetState['convergence_active'] ?? 0) === 1) {
                        generation_batch_update_item($itemId, ['convergence_active' => 1]);
                    }
                }
            }
            $completedDraft = find_article_draft_by_operation($operationId);
            $completedValidation = is_array($completedDraft) ? (json_decode((string)$completedDraft['validation_json'], true) ?: []) : [];
            if ($isAutoRepair) {
                if (!is_array($completedDraft)) throw new RuntimeException('Nie zapisano wersji automatycznej korekty.');
                activate_completed_article_qc_repair((int) $completedDraft['id']);
                generation_batch_audit((int) $item['batch_id'], $itemId, 'auto_repair_draft_validated', 'worker', [
                    'attempt' => (int) ($item['auto_repair_count'] ?? 0), 'draft_version_id' => (int) $completedDraft['id'],
                    'parent_version_id' => (int) ($completedDraft['parent_version_id'] ?? 0),
                    'strategy' => (string) ($completedDraft['repair_strategy'] ?? ''),
                ]);
            }
            $draftOnly = (string) ($item['requested_stage'] ?? '') === 'draft';
            generation_batch_update_item($itemId, $draftOnly ? [
                'status' => 'completed', 'stage' => 'draft', 'progress_percent' => 100,
                'wait_reason' => 'Szkic ukończony.', 'completed_at' => gmdate('Y-m-d H:i:s'),
            ] : [
                'status' => 'quality_check', 'stage' => 'quality_check', 'progress_percent' => 65,
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, 'draft_completed', 'worker', ['operation_id' => $operationId, 'repair_scope'=>$completedValidation['repair_scope'] ?? null]);
        } elseif ($stage === 'quality_check') {
            generation_batch_update_item($itemId, ['status' => 'quality_check', 'progress_percent' => 72]);
            if (generation_batch_is_autonomous($item) && (string) ($item['outcome'] ?? '') === 'safe_composer_queued') {
                if (!generation_explicit_test_mode()) {
                    generation_batch_update_item($itemId, [
                        'status' => 'manual_review', 'stage' => 'quality_check', 'outcome' => 'safe_composer_blocked',
                        'progress_percent' => 84, 'completed_at' => gmdate('Y-m-d H:i:s'),
                        'wait_reason' => 'Automatyczna atrapa safe composer jest zablokowana poza trybem testowym; wymagany ręczny przegląd.',
                    ]);
                    generation_batch_audit((int) $item['batch_id'], $itemId, 'safe_composer_blocked', 'worker', ['reason' => 'fixture_not_allowed_outside_test_mode']);
                    return;
                }
                $salvaged = salvage_execute_safe_composer($item);
                generation_batch_update_item($itemId, ['status' => 'images', 'stage' => 'images', 'progress_percent' => 85,
                    'draft_operation_id' => (int) $salvaged['draft']['generation_operation_id'],
                    'draft_version_id' => (int) $salvaged['draft']['id'],
                    'quality_operation_id' => (int) $salvaged['quality']['generation_operation_id'],
                    'quality_check_id' => (int) $salvaged['quality']['id'], 'outcome' => 'safe_composer_validated',
                    'wait_reason' => 'Bezpieczny kompozytor przeszedł factual gate; przygotowuję obrazy.']);
                $safeDraftJson = json_decode((string)$salvaged['draft']['draft_json'], true) ?: [];
                repair_report_append($itemId, 'factual_source', 'safe_composer', ['draft_version_id' => (int) $salvaged['draft']['id'],
                    'factual_gate' => 'passed', 'title_selection' => ['selected' => (string)($safeDraftJson['title']??''),
                        'candidates' => array_values(array_filter(array_map(static fn(array $variant): string => (string)($variant['title']??''),(array)($safeDraftJson['title_variants']??[]))))]]);
                generation_batch_audit((int) $item['batch_id'], $itemId, 'safe_composer_validated', 'worker', ['draft_version_id' => (int) $salvaged['draft']['id'], 'quality_check_id' => (int) $salvaged['quality']['id']]);
                return;
            }
            $operationId = (int) ($item['quality_operation_id'] ?? 0);
            if ($operationId <= 0) {
                $operationId = prepare_quality_check_operation((int) $item['draft_version_id']);
                $check = find_quality_check_by_operation($operationId);
                generation_batch_update_item($itemId, [
                    'quality_operation_id' => $operationId, 'quality_check_id' => (int) $check['id'],
                ]);
            }
            execute_generation_operation($operationId, $transport);
            $articleId = (int) ($item['post_id'] ?? 0);
            if ($articleId > 0) {
                $budgetState = gemini_article_budget_state($articleId);
                if ((int)($budgetState['convergence_active'] ?? 0) === 1) {
                    generation_batch_update_item($itemId, ['convergence_active' => 1]);
                }
            }
            $check = find_quality_check_by_operation($operationId);
            if (!is_array($check) || (int) $check['passed'] !== 1 || quality_active_hard_blocks($check) !== []) {
                $convergenceActive = (int)($item['convergence_active'] ?? 0) === 1;
                $routerAssessment = repair_router_assess(is_array($check) ? $check : [], $convergenceActive);
                $decision = is_array($check)
                    ? quality_check_auto_repair_decision($check, $convergenceActive)
                    : ['repairable' => false, 'human_required' => true, 'reasons' => ['Brak kompletnego wyniku QC.']];
                $repairAttempt = (int) ($item['auto_repair_count'] ?? 0) + 1;
                if (($decision['repairable'] ?? false) === true && ($decision['target_stage'] ?? 'draft') === 'research'
                    && generation_batch_is_autonomous($item)) {
                    $reason = implode(' ', (array) ($decision['feedback'] ?? ['Źródła wymagają ponownego researchu.']));
                    if (generation_batch_can_retry_research($item)) {
                        repair_report_append($itemId, 'factual_source', 'research_enrichment', ['issues' => $routerAssessment['issues'], 'reason' => $reason]);
                        generation_batch_queue_research_retry($item, $reason, [
                            'quality_check_id' => is_array($check) ? (int) $check['id'] : null,
                            'categories' => $decision['categories'] ?? [],
                        ]);
                    } else {
                        generation_batch_update_item($itemId, ['status' => 'auto_repair', 'stage' => 'quality_check', 'outcome' => 'safe_composer_queued',
                            'progress_percent' => 84, 'wait_reason' => 'Research nie dostarczył bezpiecznego rozszerzenia; buduję pakiet wyłącznie z zatwierdzonych faktów.',
                            'quality_operation_id' => null, 'quality_check_id' => null, 'completed_at' => null]);
                        repair_report_append($itemId, 'factual_source', 'safe_composer', ['reason' => $reason, 'research_budget_exhausted' => true]);
                    }
                } elseif (($decision['repairable'] ?? false) === true && $repairAttempt <= 2 && generation_batch_is_autonomous($item)) {
                    /* In convergence mode, always use targeted_repair; never full rewrite. */
                    if ($convergenceActive) {
                        $strategy = 'targeted_repair';
                    } else {
                        $strategy = $repairAttempt === 1 ? 'targeted_repair' : 'fresh_conservative_rewrite';
                    }
                    $repairOperationId = prepare_article_qc_repair_operation(
                        (int) $item['draft_version_id'],
                        $check,
                        $decision,
                        $repairAttempt
                    );
                    $repairDraft = find_article_draft_by_operation($repairOperationId);
                    if (!is_array($repairDraft)) throw new RuntimeException('Nie utworzono wersji automatycznej korekty QC.');
                    generation_batch_update_item($itemId, [
                        'status' => 'auto_repair', 'stage' => 'draft', 'progress_percent' => 55,
                        'draft_operation_id' => $repairOperationId, 'draft_version_id' => (int) $repairDraft['id'],
                        'quality_operation_id' => null, 'quality_check_id' => null,
                        'retry_count' => max((int) $item['retry_count'], $repairAttempt), 'auto_repair_count' => $repairAttempt,
                        'outcome' => 'auto_repair',
                        'wait_reason' => $strategy === 'fresh_conservative_rewrite'
                            ? 'Tworzę uproszczoną wersję awaryjną (próba 2/2).'
                            : 'Automatyczna poprawka 1/2.',
                        'completed_at' => null,
                    ]);
                    generation_batch_audit((int) $item['batch_id'], $itemId, 'quality_auto_repair_queued', 'worker', [
                        'attempt' => $repairAttempt, 'quality_check_id' => (int) $check['id'],
                        'source_draft_version_id' => (int) $item['draft_version_id'], 'repair_draft_version_id' => (int) $repairDraft['id'],
                        'strategy' => $strategy, 'categories' => $decision['categories'] ?? [], 'feedback' => $decision['feedback'] ?? [],
                    ]);
                    repair_report_append($itemId, (string) ($routerAssessment['issues'][0]['gate'] ?? 'final_package'), $strategy, [
                        'quality_check_id' => (int) $check['id'], 'issues' => $routerAssessment['issues'],
                        'feedback' => $decision['feedback'] ?? [], 'budget' => repair_router_budget_state($itemId, (string) ($routerAssessment['issues'][0]['gate'] ?? 'final_package')),
                    ]);
                } else {
                    $limitReached = ($decision['repairable'] ?? false) === true && $repairAttempt > 2;
                    $reasons = $limitReached
                        ? ['Dwie korekty modelowe nie przeszły pełnej kontroli jakości; uruchamiam deterministyczny safe composer.']
                        : (array) ($decision['reasons'] ?? ['Kontrola jakości wymaga decyzji redakcyjnej.']);
                    if (generation_batch_is_autonomous($item)) {
                        generation_batch_update_item($itemId, ['status' => 'auto_repair', 'stage' => 'quality_check', 'outcome' => 'safe_composer_queued',
                            'progress_percent' => 84, 'wait_reason' => 'Wyczerpano strategie modelowe; usuwam ryzykowne elementy i składam wersję wyłącznie ze zwalidowanych faktów.',
                            'quality_operation_id' => null, 'quality_check_id' => null, 'completed_at' => null]);
                        $convergenceActive2 = (int)($item['convergence_active'] ?? 0) === 1;
                        $routerIssues = repair_router_assess(is_array($check) ? $check : [], $convergenceActive2)['issues'];
                        repair_report_append($itemId, 'final_package', 'safe_composer', ['quality_check_id' => is_array($check) ? (int) $check['id'] : null,
                            'issues' => $routerIssues, 'reasons' => $reasons,
                            'removed_claims_or_quotes' => array_values(array_map(static fn(array $issue): string => (string)($issue['message']??$issue['code']),
                                array_filter($routerIssues, static fn(array $issue): bool => in_array((string)($issue['code']??''),['unsupported_claim','false_quote','high_risk'],true))))]);
                        generation_batch_audit((int) $item['batch_id'], $itemId, 'safe_composer_queued', 'worker', ['limit_reached' => $limitReached, 'reasons' => $reasons]);
                    } else {
                        generation_batch_update_item($itemId, [
                            'status' => 'waiting_review', 'progress_percent' => 82,
                            'outcome' => $limitReached ? 'auto_repair_limit' : 'human_review_required',
                            'wait_reason' => implode(' ', $reasons), 'completed_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                        generation_batch_audit((int) $item['batch_id'], $itemId, 'quality_waiting_review', 'worker', [
                            'quality_check_id' => is_array($check) ? (int) $check['id'] : null,
                            'repair_attempts' => (int) ($item['auto_repair_count'] ?? 0), 'limit_reached' => $limitReached,
                            'reasons' => $reasons,
                        ]);
                    }
                }
            } else {
                /* Freeze accepted artifacts after successful QC iteration. */
                qc_freeze_accepted_artifacts(
                    (int) $item['draft_version_id'],
                    (int) ($item['convergence_active'] ?? 0) === 1
                );
                $qualityOnly = (string) ($item['requested_stage'] ?? '') === 'quality';
                generation_batch_update_item($itemId, $qualityOnly ? [
                    'status' => 'completed', 'stage' => 'quality_check', 'progress_percent' => 100,
                    'wait_reason' => 'Kontrola jakości zaliczona.', 'completed_at' => gmdate('Y-m-d H:i:s'),
                ] : [
                    'status' => 'images', 'stage' => 'images', 'progress_percent' => 85,
                    'wait_reason' => 'Kontrola jakości zaliczona; przygotowuję grafiki.',
                    'error_message' => '', 'next_retry_at' => null,
                ]);
                generation_batch_audit((int) $item['batch_id'], $itemId, 'quality_passed', 'worker', ['quality_check_id' => (int) $check['id']]);
            }
        } elseif ($stage === 'images') {
            generation_batch_update_item($itemId, [
                'status' => 'images', 'stage' => 'images', 'error_message' => '',
                'wait_reason' => 'Przygotowuję grafiki zgodnie z zatwierdzonym szkicem.',
            ]);
            if ((bool) app_config('ai_image_generation_enabled')) {
                throw new RuntimeException('Batch odmawia uruchomienia, gdy generator obrazów AI jest włączony.');
            }
            $draft = find_article_draft_by_operation((int) $item['draft_operation_id']);
            $draftJson = is_array($draft) ? json_decode((string) $draft['draft_json'], true) : null;
            if (!is_array($draftJson) || !is_array($draftJson['illustration_plan'] ?? null)) {
                throw new RuntimeException('Szkic nie zawiera zwalidowanego planu legalnych ilustracji.');
            }
            $postId = (int) $item['post_id'];
            $narrativePlan = find_narrative_plan_for_post($postId, (int) $item['topic_id']);
            if (!is_array($narrativePlan)) {
                $narrativePlan = generation_batch_finalize_stored_narrative_plan((int) $item['topic_id'], $postId);
            }
            if (!is_array($narrativePlan)) {
                throw new RuntimeException('Niespójny NarrativePlan: P06 nie może rozpocząć recovery grafik bez trwałego, zwalidowanego planu; wymagane ręczne wznowienie po naprawie artefaktu.');
            }
            $visualPlan = article_final_visual_plan_for_post($postId, (int) $item['topic_id']);
            if (!is_array($visualPlan)) {
                $finalPlanOperationId = prepare_article_final_visual_plan_operation($postId, (int) $item['topic_id']);
                execute_generation_operation($finalPlanOperationId, $transport);
                $visualPlan = article_final_visual_plan_for_post($postId, (int) $item['topic_id']);
            }
            if (!is_array($visualPlan)) throw new RuntimeException('FinalVisualPlan nie został ukończony dla locked core text.');
            $visualPlan = article_image_effective_visual_plan($postId, (int) $item['topic_id'], $narrativePlan);
            $heroSlot = (array) ($visualPlan['hero_slot'] ?? []);
            if (($heroSlot['role'] ?? '') !== 'hero' || empty($heroSlot['required'])
                || empty($heroSlot['must_be_direct']) || (array) ($heroSlot['search_queries_direct'] ?? []) === []) {
                throw new RuntimeException('Niespójny NarrativePlan: P06 wymaga hero zgodnego z kontraktem direct-coverage; wymagane ręczne wznowienie po naprawie planu.');
            }
            $existingSlots = [];
            foreach (list_article_images($postId) as $existingImage) {
                $existingSlots[(string) $existingImage['role'] . ':' . (string) $existingImage['section_id']] = true;
            }
            $plannedCount = 0;
            $finalIllustrationPlan = narrative_visual_plan_to_illustration_plan($visualPlan);
            foreach ([(array) $finalIllustrationPlan['hero'], ...(array) $finalIllustrationPlan['inline']] as $plannedImage) {
                $slot = (string) $plannedImage['role'] . ':' . (string) $plannedImage['section_id'];
                if (!isset($existingSlots[$slot])) {
                    persist_article_image($postId, $plannedImage);
                    $existingSlots[$slot] = true;
                }
                $plannedCount++;
            }
            // The completed draft is the proposal's source of truth. Materialize
            // it before marking the item ready, otherwise the proposal preview
            // keeps rendering the original RSS idea instead of the Gemini draft.
            promote_article_draft_to_post((int) $item['draft_version_id']);
            $directVisionBudget = article_image_direct_vision_budget_plan($postId, (int) $item['topic_id']);
            $imageSummary = (bool) app_config('source_image_mock')
                ? fulfill_article_source_images($postId, static fn (string $query): array => [], static fn (array $image): array => $image, null, 'direct', (int) $directVisionBudget['direct_vision_limit'])
                : fulfill_article_source_images($postId, null, null, null, 'direct', (int) $directVisionBudget['direct_vision_limit']);
            $imageSummary['direct_vision_budget'] = $directVisionBudget;
            $imageSummary['pending_related_hero_resume'] = article_image_resume_pending_related_hero(
                $postId,
                (int) $item['topic_id']
            );
            $recovery = null;
            $coverageBeforeRecovery = article_image_coverage_state($postId, (int) $item['topic_id']);
            $p06Completed = article_image_operation_completed($postId, 'image_recovery');
            if (article_image_shortage_recovery_needed($coverageBeforeRecovery, $p06Completed)
                && find_narrative_plan_for_post($postId, (int) $item['topic_id']) !== null) {
                try {
                    $recovery = article_image_execute_shortage_recovery(
                        $postId,
                        (int) $item['topic_id'],
                        (bool) app_config('source_image_mock')
                            ? static fn (string $query): array => []
                            : article_image_default_searcher(),
                        $transport
                    );
                    $recovery = article_image_apply_shortage_recovery((int) $recovery['operation_id'], null, null, $transport);
                } catch (ArticleRecoveryPreflightException $exception) {
                    $recovery = [
                        'status' => 'refused_pretransport',
                        'reason_code' => $exception->reasonCode,
                        'message' => $exception->getMessage(),
                        'provider_error' => false,
                        'transport_attempted' => false,
                    ];
                    generation_batch_audit((int) $item['batch_id'], $itemId, 'recovery_preflight_refused', 'worker', $recovery);
                }
            } elseif (!empty($coverageBeforeRecovery['missing_slots']) && $p06Completed) {
                $recovery = ['status'=>'reused_completed_p06','provider_calls'=>0];
            }
            if (generation_batch_is_autonomous($item)) {
                repair_report_append($itemId, 'image_plan', 'source_image_waterfall', ['summary' => $imageSummary,
                    'recovery' => $recovery,
                    'waterfall' => ['primary_source', 'institutional_repository', 'topic_bc_queries', 'related_recovery']]);
            }
            $coverage = article_image_coverage_state($postId, (int) $item['topic_id']);
            $replan = null;
            $replanEligibility = article_image_recovery_replan_retry_state(
                $postId, (int) $item['topic_id'], $coverage,
                gemini_article_budget_state($postId), !article_image_has_pending_recovery($postId)
            );
            if (!empty($replanEligibility['eligible'])) {
                try {
                    $replanOperationId = prepare_article_image_recovery_replan_operation($postId, (int) $item['topic_id']);
                    execute_generation_operation($replanOperationId, $transport);
                    $replan = article_image_apply_recovery_replan($replanOperationId);
                    $afterReplanBudget = gemini_article_budget_state($postId);
                    $remainingAfterReplan = max(0, (int) ($afterReplanBudget['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT) - (int) ($afterReplanBudget['used_calls'] ?? 0));
                    $visionLimit = min(
                        2 * count((array) ($coverage['missing_slots'] ?? [])),
                        max(0, $remainingAfterReplan - (int) $replanEligibility['closure_reserve'])
                    );
                    $replan['retrieval'] = $visionLimit > 0
                        ? ((bool) app_config('source_image_mock')
                            ? fulfill_article_source_images($postId, static fn (string $query): array => [], static fn (array $image): array => $image, null, 'semantic', $visionLimit, 2)
                            : fulfill_article_source_images($postId, null, null, null, 'semantic', $visionLimit, 2))
                        : ['vision_call_limit'=>0,'missing'=>count((array) ($coverage['missing_slots'] ?? []))];
                    $replan['vision_candidate_limit_per_missing_slot'] = 2;
                    $replan['closure_reserve'] = (int) $replanEligibility['closure_reserve'];
                } catch (ArticleRecoveryPreflightException $exception) {
                    $replan = ['status'=>'refused_pretransport','reason_code'=>$exception->reasonCode,'provider_calls'=>0];
                }
                $coverage = article_image_coverage_state($postId, (int) $item['topic_id']);
            }
            $wwClosure = ['revalidation'=>null,'retrieval'=>null];
            if (empty($coverage['coverage_complete'])) {
                $closureReserve = article_layout_reusable_operation_id($postId) !== null ? 1 : 2;
                $remainingForImages = max(0, (int) (gemini_article_budget_state($postId)['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT)
                    - (int) (gemini_article_budget_state($postId)['used_calls'] ?? 0) - $closureReserve);
                if ($remainingForImages > 0) {
                    $wwClosure['revalidation'] = article_image_revalidate_downloaded_contextual_candidates(
                        $postId, (int) $item['topic_id'], min(count((array) ($coverage['missing_slots'] ?? [])), $remainingForImages)
                    );
                    $coverage = article_image_coverage_state($postId, (int) $item['topic_id']);
                    $remainingForImages = max(0, (int) (gemini_article_budget_state($postId)['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT)
                        - (int) (gemini_article_budget_state($postId)['used_calls'] ?? 0) - $closureReserve);
                    if ($remainingForImages > 0 && empty($coverage['coverage_complete'])) {
                        $wwClosure['retrieval'] = fulfill_article_source_images(
                            $postId, null, null, null, 'contextual',
                            min($remainingForImages, max(1, count((array) ($coverage['missing_slots'] ?? [])))), 1
                        );
                        $coverage = article_image_coverage_state($postId, (int) $item['topic_id']);
                    }
                }
            }
            $requiresReview = empty($coverage['coverage_complete']);
            $heroRecoveryFailed = !(bool) app_config('source_image_mock')
                && empty($coverage['hero_is_allowed']);
            $layoutOperationId = null;
            $finalQcOperationId = null;
            $finalQcResult = null;
            $finalQcReadiness = 'manual_review';
            if (generation_batch_image_coverage_allows_finalization($coverage)) {
                $layoutOperationId = article_layout_reusable_operation_id($postId);
                if ($layoutOperationId === null) {
                    $layoutOperationId = prepare_article_layout_plan_operation($postId, (int) $item['topic_id']);
                    execute_generation_operation($layoutOperationId, $transport);
                } else {
                    refresh_article_image_rendering($postId);
                }
                $finalQcOperationId = prepare_final_multimodal_qc_operation($postId, (int) $item['topic_id'], (int) $item['draft_version_id']);
                execute_generation_operation($finalQcOperationId, $transport);
                $finalQcResult = complete_final_multimodal_qc_operation($finalQcOperationId);
                $finalQcReadiness = final_multimodal_qc_readiness($postId);
            }
            $finalQcPassed = is_array($finalQcResult)
                && in_array((string) ($finalQcResult['decision'] ?? ''), ['PASS', 'PASS_WITH_MINOR_NOTES'], true)
                && $finalQcReadiness === 'ready_for_manual_publish';
            $finalQcRequiredReview = !$requiresReview && !$finalQcPassed;
            $imageWaitReason = $requiresReview
                ? 'Gotowa propozycja jest dostępna. Brakujące lub niezweryfikowane ilustracje oznaczono jako wymagające uwagi; publikacja pozostaje zablokowana.'
                : 'Legalne ilustracje źródłowe zostały użyte automatycznie; gotowa propozycja jest dostępna.';
            generation_batch_update_item($itemId, [
                'status' => ($heroRecoveryFailed || $requiresReview || $finalQcRequiredReview) ? 'manual_review' : ((string) ($item['requested_stage'] ?? '') === 'images' ? 'completed'
                    : (generation_batch_is_autonomous($item) ? 'ready_for_preview' : 'ready')),
                'stage' => ($heroRecoveryFailed || $requiresReview || $finalQcRequiredReview) ? 'images' : ((string) ($item['requested_stage'] ?? '') === 'images' ? 'images' : 'ready'),
                'outcome' => $heroRecoveryFailed ? 'hero_recovery_manual_review' : ($requiresReview ? 'completed_with_warnings' : ($finalQcRequiredReview ? 'final_multimodal_qc_manual_review' : 'final_multimodal_qc_passed')), 'progress_percent' => 100,
                'wait_reason' => $heroRecoveryFailed ? 'Hero nie osiągnął poziomu direct_ok, broader_direct_ok ani controlled_related_supported po bounded recovery; wymagany ręczny przegląd.' : $imageWaitReason,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, ($requiresReview || $finalQcRequiredReview) ? 'item_manual_review' : 'item_ready', 'worker', [
                'draft_version_id' => (int) $item['draft_version_id'], 'published' => false,
                'planned_legal_images' => $plannedCount, 'source_image_summary' => $imageSummary,
                'coverage' => $coverage, 'layout_operation_id' => $layoutOperationId, 'final_multimodal_qc_operation_id' => $finalQcOperationId,
                'final_multimodal_qc' => $finalQcResult, 'final_multimodal_qc_readiness' => $finalQcReadiness,
                'recovery_replan' => $replan,
                'ww_closure' => $wwClosure,
                'ai_image_generation_calls' => 0,
            ]);
        }
    } catch (GenerationBatchItemPausedException $exception) {
        $paused = generation_batch_find_item($itemId);
        if (is_array($paused) && (string) $paused['status'] === 'paused_by_operator') {
            generation_batch_audit((int) $paused['batch_id'], $itemId, 'worker_observed_item_pause', 'worker', [
                'stage' => (string) $paused['stage'], 'checkpoint_preserved' => true,
            ]);
            return;
        }
        throw $exception;
    } catch (Throwable $exception) {
        $paused = generation_batch_find_item($itemId);
        if (is_array($paused) && (string) $paused['status'] === 'paused_by_operator') {
            generation_batch_audit((int) $paused['batch_id'], $itemId, 'worker_observed_item_pause', 'worker', [
                'stage' => (string) $paused['stage'], 'checkpoint_preserved' => true,
            ]);
            return;
        }
        $message = mb_substr($exception->getMessage(), 0, 2000);
        $classification = generation_error_classification($exception);
        $budgetNow = gemini_article_budget_state((int) $item['post_id']);
        $budgetExhausted = (bool) ($budgetNow['is_exhausted'] ?? false)
            || (int) ($budgetNow['used_calls'] ?? 0) >= (int) ($budgetNow['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT);
        if (generation_batch_is_autonomous($item)
            && ($exception instanceof GeminiArticleBudgetException || $budgetExhausted)) {
            $budgetDiagnostics = gemini_budget_exhaustion_diagnostics((int) $item['post_id']);
            generation_batch_update_item($itemId, [
                'status'=>'manual_review','stage'=>$stage,'outcome'=>'budget_exhausted','progress_percent'=>90,
                'wait_reason'=>'Budżet ' . GEMINI_ARTICLE_CALL_LIMIT . ' wywołań Gemini wyczerpany; artykuł wymaga przeglądu redakcyjnego.',
                'available_at'=>gmdate('Y-m-d H:i:s'),'next_retry_at'=>null,'quota_dimension'=>'','quota_model'=>'','completed_at'=>null,
            ]);
            repair_report_append($itemId, 'final_package', 'manual_review', [
                'live_requests_used'=>(int) ($budgetNow['used_calls'] ?? 0),'request_cap'=>(int) ($budgetNow['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT),'publication_recommended'=>false,
                'stage_at_exhaustion'=>$stage,'convergence_active'=>(bool)($item['convergence_active']??false),
                'trigger_error'=>$message,
                'budget'=>$budgetDiagnostics['budget'],
                'artifacts'=>$budgetDiagnostics['artifacts'],
                'images'=>$budgetDiagnostics['images'],
            ]);
            generation_batch_audit((int)$item['batch_id'],$itemId,'gemini_article_budget_exhausted','worker',[
                'live_requests_used'=>(int) ($budgetNow['used_calls'] ?? 0),'request_cap'=>(int) ($budgetNow['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT),'next'=>'manual_review',
                'stage_at_exhaustion'=>$stage,
                'trigger_error'=>$message,
                'convergence_active'=>(bool)($item['convergence_active']??false),
                'diagnostics'=>$budgetDiagnostics,
            ]);
            return;
        }
        if ($exception instanceof GeminiQuotaWaitException) {
            // Preserve provider retry-after while keeping the UI contract observable
            // across a whole status read (a one-second retry can otherwise expire
            // before the workflow payload is assembled).
            $retryAt = gmdate('Y-m-d H:i:s', max(strtotime($exception->nextRetryAt), time() + 2));
            generation_batch_update_item($itemId, [
                'status' => generation_batch_is_autonomous($item) ? 'auto_retry_scheduled' : 'rate_limited',
                'outcome' => 'quota_retry_scheduled',
                'available_at' => $retryAt, 'next_retry_at' => $retryAt,
                'quota_dimension' => $exception->quotaDimension, 'quota_model' => $exception->quotaModel,
                'wait_reason' => gemini_quota_wait_message($exception->quotaDimension, $exception->quotaModel),
                'error_message' => $message,
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, 'quota_retry_scheduled', 'worker', [
                'next_retry_at' => gmdate('c', strtotime($exception->nextRetryAt)),
                'quota_dimension' => $exception->quotaDimension, 'model' => $exception->quotaModel,
            ]);
            return;
        }
        $finalQcContractFailure = generation_batch_is_autonomous($item)
            && $stage === 'quality_check'
            && $classification['class'] === 'validation_contract'
            && gemini_topic_live_request_count((int) $item['topic_id']) >= 15;
        if ($finalQcContractFailure) {
            generation_batch_update_item($itemId, [
                'status' => 'auto_repair', 'stage' => 'quality_check', 'outcome' => 'safe_composer_queued',
                'progress_percent' => 94,
                'wait_reason' => 'Finalne QC modelowe zwróciło nieprawidłowy kontrakt; wykonuję deterministyczny post-QC gate bez kolejnego requestu.',
                'available_at' => gmdate('Y-m-d H:i:s'), 'next_retry_at' => null,
                'quota_dimension' => '', 'quota_model' => '', 'completed_at' => null,
            ]);
            repair_report_append($itemId, 'final_package', 'deterministic_post_qc_gate', [
                'model_qc_status' => 'invalid_contract', 'live_requests_used' => 15,
                'model_qc_passed' => false, 'next' => 'source_bounded_safe_composer',
                'publication_recommended' => false,
            ], ['Finalne modelowe QC nie dostarczyło poprawnego kontraktu; gotowość zostanie oparta na bramkach deterministycznych.']);
            generation_batch_audit((int) $item['batch_id'], $itemId, 'final_qc_contract_salvage', 'worker', [
                'live_requests_used' => 15, 'model_qc_passed' => false,
                'decision_basis' => 'deterministic_preflight_and_post_qc_gate',
            ]);
            return;
        }
        if ((bool) ($classification['retryable'] ?? false)) {
            $retry = (int) $item['retry_count'] + 1;
            preg_match('/Retry-After:\s*(\d+)/i', $message, $retryAfterMatch);
            $retryAfter = isset($retryAfterMatch[1]) ? (int) $retryAfterMatch[1] : 0;
            $delay = min(86400, max($retryAfter, (int) app_config('batch_rate_limit_backoff_seconds') * (2 ** min(5, $retry - 1))));
            generation_batch_update_item($itemId, [
                'status' => generation_batch_is_autonomous($item) ? 'auto_retry_scheduled' : 'rate_limited', 'retry_count' => $retry,
                'outcome' => 'transport_retry_scheduled',
                'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'quota_dimension' => 'transport', 'quota_model' => (string) app_config('gemini_model'),
                'wait_reason' => 'Oczekiwanie na wznowienie transportu API.', 'error_message' => $message,
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, generation_batch_is_autonomous($item) ? 'auto_retry_scheduled' : 'rate_limited', 'worker', ['delay_seconds' => $delay]);
        } else {
            $contractError = str_starts_with($message, 'Nieprawidłowa odpowiedź Gemini API:');
            $transportConfigurationError = $classification['class'] === 'transport_configuration';
            $visualPlanContractMismatch = $stage === 'draft'
                && (str_starts_with($message, 'VisualPlan ') || str_starts_with($message, 'NarrativePlan '));
            $display = $transportConfigurationError
                ? 'Konfiguracja transportu Gemini wskazuje niedostępny lokalny proxy; popraw środowisko procesu przed ręcznym wznowieniem.'
                : ($contractError
                ? 'Błąd formatu odpowiedzi Gemini wymaga ręcznego wznowienia po poprawie kontraktu.'
                : 'Nieponawialny błąd Gemini wymaga ręcznego wznowienia po usunięciu przyczyny.');
            if ($visualPlanContractMismatch) {
                $display = 'Niespójny kontrakt VisualPlan został zatrzymany przed wywołaniem Gemini; wymagana naprawa artefaktu.';
            }
            generation_batch_update_item($itemId, [
                'status' => 'failed', 'outcome' => $transportConfigurationError ? 'transport_configuration' : ($visualPlanContractMismatch ? 'visual_plan_contract_mismatch' : ($contractError ? 'validation_contract' : 'non_retryable_provider_error')),
                'available_at' => gmdate('Y-m-d H:i:s'), 'next_retry_at' => null,
                'wait_reason' => $display, 'error_message' => $message,
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, $visualPlanContractMismatch ? 'item_failed_visual_plan_contract_mismatch' : 'item_failed_non_retryable_provider_error', 'worker', [
                'error' => $message, 'classification' => $visualPlanContractMismatch ? 'visual_plan_contract_mismatch' : ($contractError ? 'validation_contract' : $classification['class']),
                'manual_resume_required' => true,
            ]);
        }
    } finally {
        generation_batch_update_item($itemId, ['lease_token' => null, 'lease_expires_at' => null]);
        $current = generation_batch_find_item($itemId);
        if (is_array($current)) {
            generation_batch_refresh_status((int) $current['batch_id']);
        }
    }
}

function generation_batch_refresh_status(int $batchId): void
{
    $items = generation_batch_item_rows($batchId);
    $terminal = array_filter($items, static fn (array $item): bool => in_array($item['status'], GENERATION_BATCH_TERMINAL_STATUSES, true));
    $status = count($terminal) === count($items) ? 'completed' : 'running';
    bueno_database()->prepare(
        'UPDATE generation_batches SET status = :status, updated_at = CURRENT_TIMESTAMP,
         completed_at = CASE WHEN :status = "completed" THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = :id'
    )->execute([':status' => $status, ':id' => $batchId]);
}

function cancel_generation_batch_item(int $itemId, string $actor = 'admin'): void
{
    $item = generation_batch_find_item($itemId);
    if (!is_array($item) || !in_array($item['status'], ['queued', 'rate_limited', 'auto_retry_scheduled'], true) || $item['lease_token'] !== null) {
        throw new RuntimeException('Można anulować tylko oczekujący, nieprzetwarzany element.');
    }
    generation_batch_update_item($itemId, [
        'status' => 'cancelled', 'completed_at' => gmdate('Y-m-d H:i:s'), 'wait_reason' => 'Anulowano przed wykonaniem.',
    ]);
    generation_batch_audit((int) $item['batch_id'], $itemId, 'item_cancelled', $actor);
    generation_batch_refresh_status((int) $item['batch_id']);
}

function retry_generation_batch_item(int $itemId, string $actor = 'admin'): void
{
    $item = generation_batch_find_item($itemId);
    $imageReviewRetry = is_array($item)
        && in_array((string) ($item['status'] ?? ''), ['manual_review', 'waiting_review'], true)
        && (string) ($item['stage'] ?? '') === 'images';
    if (!is_array($item) || (!in_array($item['status'], ['failed', 'rate_limited', 'auto_retry_scheduled', 'cancelled'], true) && !$imageReviewRetry)) {
        throw new RuntimeException('Ponowić można wyłącznie element nieudany, oczekujący na limit, anulowany albo etap grafik wymagający decyzji.');
    }
    if ((string) ($item['outcome'] ?? '') === 'validation_contract') {
        $operationId = (int) ($item['draft_operation_id'] ?? 0);
        $operation = $operationId > 0 ? find_generation_operation($operationId) : null;
        $storedOutput = is_array($operation) ? trim((string) ($operation['output_json'] ?? '')) : '';
        if ((string) ($item['stage'] ?? '') === 'draft' && is_array($operation)
            && (string) ($operation['operation_type'] ?? '') === 'article_draft' && $storedOutput !== '') {
            complete_generation_operation($operationId, $storedOutput, (string) ($operation['execution_mode'] ?? 'api'), [
                'response_id' => (string) ($operation['provider_response_id'] ?? ''),
                'usage' => ['stored_validation_contract_replay' => true, 'provider_calls' => 0],
            ]);
            $draftOnly = (string) ($item['requested_stage'] ?? '') === 'draft';
            generation_batch_update_item($itemId, $draftOnly ? [
                'status'=>'completed','stage'=>'draft','progress_percent'=>100,'outcome'=>'completed',
                'wait_reason'=>'Szkic ukończony po lokalnej rewalidacji kontraktu.','error_message'=>'','completed_at'=>gmdate('Y-m-d H:i:s'),
            ] : [
                'status'=>'quality_check','stage'=>'quality_check','progress_percent'=>65,'outcome'=>'queued',
                'wait_reason'=>'','error_message'=>'','completed_at'=>null,'available_at'=>gmdate('Y-m-d H:i:s'),
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, 'stored_validation_contract_replayed', $actor, [
                'stage'=>'draft','operation_id'=>$operationId,'provider_calls'=>0,
            ]);
            generation_batch_refresh_status((int) $item['batch_id']);
            return;
        }
        throw new RuntimeException('Błąd formatu odpowiedzi wymaga nowej operacji QC po aktualizacji kontraktu.');
    }
    $reset = (string) $item['stage'] === 'quality_check' ? ['quality_operation_id' => null, 'quality_check_id' => null] : [];
    if ((string) $item['stage'] === 'research') {
        $latest=bueno_database()->prepare('SELECT o.input_json FROM research_packages r INNER JOIN generation_operations o ON o.id=r.generation_operation_id WHERE r.topic_id=:topic ORDER BY r.id DESC LIMIT 1');
        $latest->execute([':topic'=>(int)$item['topic_id']]);$old=json_decode((string)($latest->fetchColumn()?:'{}'),true)?:[];
        enrich_topic_sources((int)$item['topic_id']);
        $before=hash('sha256',generation_json($old['numbered_sources']??[]));$after=research_sources_fingerprint((int)$item['topic_id']);
        if(hash_equals($before,$after)) throw new DomainException('Fingerprint źródeł nie zmienił się; identyczne wywołanie Gemini nie zostanie ponowione.');
        $reset=['research_operation_id'=>null,'research_package_id'=>null];
    }
    generation_batch_update_item($itemId, [
        'status' => (string) $item['stage'], 'available_at' => gmdate('Y-m-d H:i:s'),
        'wait_reason' => '', 'error_message' => '', 'outcome' => 'queued', 'completed_at' => null,
        ...$reset,
    ]);
    generation_batch_audit((int) $item['batch_id'], $itemId, 'item_retried', $actor, ['stage' => $item['stage']]);
    generation_batch_refresh_status((int) $item['batch_id']);
}

/** Start an operator retry without reusing an exhausted image-attempt budget. */
function retry_generation_batch_item_from_ui(int $itemId, string $actor = 'admin'): array
{
    $item = generation_batch_find_item($itemId);
    if (!is_array($item)) throw new RuntimeException('Nie znaleziono elementu batcha.');

    $narrativePlanFailure = (string) ($item['status'] ?? '') === 'failed'
        && (string) ($item['stage'] ?? '') === 'draft'
        && str_contains((string) (($item['error_message'] ?? '') . ' ' . ($item['wait_reason'] ?? '')), 'NarrativePlan');
    if ($narrativePlanFailure) {
        $result = create_generation_workflow_batch(
            [(int) $item['topic_id']],
            'generate_all',
            'retry-narrative-plan-' . $itemId . '-' . bin2hex(random_bytes(8)),
            $actor
        );
        if (!is_array($result['batch'] ?? null)) {
            $reason = (string) (($result['skipped'][0]['reason'] ?? '') ?: 'Nie udało się utworzyć świeżej próby po błędzie NarrativePlan.');
            throw new DomainException($reason);
        }
        generation_batch_audit((int) $item['batch_id'], $itemId, 'item_retry_superseded_by_new_batch', $actor, [
            'stage' => 'draft', 'reason' => 'invalid_narrative_plan', 'new_batch_id' => (int) $result['batch']['id'],
        ]);
        return $result;
    }

    $imageReviewRetry = in_array((string) ($item['status'] ?? ''), ['manual_review', 'waiting_review'], true)
        && (string) ($item['stage'] ?? '') === 'images';
    if ($imageReviewRetry) {
        $result = create_generation_workflow_batch(
            [(int) $item['topic_id']],
            'images',
            'retry-images-' . $itemId . '-' . bin2hex(random_bytes(8)),
            $actor
        );
        if (!is_array($result['batch'] ?? null)) {
            $reason = (string) (($result['skipped'][0]['reason'] ?? '') ?: 'Nie udało się utworzyć nowej próby etapu grafik.');
            throw new DomainException($reason);
        }
        generation_batch_audit((int) $item['batch_id'], $itemId, 'item_retry_superseded_by_new_batch', $actor, [
            'stage' => 'images', 'new_batch_id' => (int) $result['batch']['id'],
        ]);
        return $result;
    }

    retry_generation_batch_item($itemId, $actor);
    return ['batch' => null, 'skipped' => []];
}

function retry_generation_batch(int $batchId, string $actor = 'admin'): int
{
    $count = 0;
    foreach (generation_batch_item_rows($batchId) as $item) {
        if (in_array($item['status'], ['failed', 'rate_limited', 'auto_retry_scheduled', 'cancelled'], true)) {
            retry_generation_batch_item((int) $item['id'], $actor);
            $count++;
        }
    }
    generation_batch_audit($batchId, null, 'batch_retried', $actor, ['item_count' => $count]);
    return $count;
}

function generation_batch_launch_worker(): void
{
    if (getenv('CMS_BATCH_NO_SPAWN') === '1') {
        return;
    }
    if (!generation_batch_has_due_items()) return;
    $database = bueno_database();
    $token = bin2hex(random_bytes(16));
    $database->exec('BEGIN IMMEDIATE');
    try {
        $database->exec('DELETE FROM generation_worker_guard WHERE expires_at<=CURRENT_TIMESTAMP');
        $guard = $database->prepare('INSERT OR IGNORE INTO generation_worker_guard(guard_key,lease_token,expires_at) VALUES(1,:token,:expires)');
        $guard->execute([':token'=>$token,':expires'=>gmdate('Y-m-d H:i:s',time()+(int)app_config('batch_lease_seconds'))]);
        $acquired = $guard->rowCount() === 1;
        $database->exec('COMMIT');
    } catch (Throwable $exception) {
        try { $database->exec('ROLLBACK'); } catch (Throwable) {}
        throw $exception;
    }
    if (!$acquired) return;
    $php = function_exists('content_studio_php_cli') ? content_studio_php_cli() : PHP_BINARY;
    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'generation-batch-worker.php';
    $logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
        throw new RuntimeException('Nie można utworzyć katalogu logów workera batch.');
    }
    $log = $logDirectory . DIRECTORY_SEPARATOR . 'generation-batch-worker.log';
    for ($slot = 0; $slot < (int) app_config('batch_worker_concurrency'); $slot++) {
        if (PHP_OS_FAMILY === 'Windows') {
            $handle = popen('start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker)
                . ' --drain --guard=' . escapeshellarg($token) . ' >> ' . escapeshellarg($log) . ' 2>&1', 'r');
            if ($handle === false) throw new RuntimeException('Nie udało się uruchomić workera batch.');
            pclose($handle);
            continue;
        }
        exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' --drain --guard=' . escapeshellarg($token) . ' >> ' . escapeshellarg($log) . ' 2>&1 &', $output, $exitCode);
        if ($exitCode !== 0) throw new RuntimeException('Nie udało się uruchomić workera batch.');
    }
}

function generation_batch_has_due_items(): bool
{
    $statement = bueno_database()->prepare(
        'SELECT EXISTS(SELECT 1 FROM generation_batch_items items INNER JOIN generation_batches batches ON batches.id=items.batch_id
         WHERE items.status IN ("queued", "research", "draft", "auto_repair", "quality_check", "images", "rate_limited", "auto_retry_scheduled")
           AND items.available_at <= CURRENT_TIMESTAMP AND items.lease_token IS NULL
           AND (:paused=0 OR batches.dispatch_mode="operator_manual") LIMIT 1)'
    );
    $statement->execute([':paused' => generation_automatic_dispatch_paused() ? 1 : 0]);
    return (bool) $statement->fetchColumn();
}
