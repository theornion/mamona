<?php

declare(strict_types=1);

const GENERATION_BATCH_ACTIVE_STATUSES = ['queued', 'research', 'draft', 'quality_check', 'images', 'rate_limited'];
const GENERATION_BATCH_TERMINAL_STATUSES = ['ready', 'completed', 'already_complete', 'waiting_review', 'manual_review', 'skipped_prerequisite', 'invalid', 'failed', 'cancelled'];
const GENERATION_WORKFLOW_ACTIONS = ['research', 'draft', 'quality', 'images', 'generate_all', 'retry'];
const GENERATION_WORKFLOW_STAGES = ['research', 'draft', 'quality_check', 'images'];

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
            'INSERT INTO generation_batches (batch_key, request_key, item_count, created_by)
             VALUES (:batch_key, :request_key, :item_count, :actor)'
        )->execute([
            ':batch_key' => bin2hex(random_bytes(16)),
            ':request_key' => $requestKey,
            ':item_count' => count($topicIds),
            ':actor' => mb_substr($actor, 0, 100),
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
        $researchDone = ($status['steps']['research']['status'] ?? '') === 'completed';
        $draftDone = ($status['steps']['draft']['status'] ?? '') === 'completed';
        $qualityDone = ($status['steps']['quality']['status'] ?? '') === 'completed';
        $reason = $selectable ? '' : ((string) ($status['wait_reason'] ?: $status['error']) ?: 'Automatyzacja jest zablokowana przez stan, scoring albo poziom ryzyka tematu.');
        return [
            'id' => $topicId, 'post_id' => (int) $topic['primary_post_id'], 'title' => (string) $topic['title'],
            'score' => $topic['score'] === null ? null : (int) $topic['score'], 'risk' => (string) $topic['risk_level'],
            'automatic_eligible' => (int) $topic['automatic_eligible'] === 1, 'status' => (string) $topic['status'],
            'item_count' => (int) $topic['item_count'], 'source_count' => (int) $topic['source_count'],
            'source_names' => (string) $topic['source_names'], 'selectable' => $selectable, 'unavailable_reason' => $reason,
            'actions' => [
                'research' => ['enabled' => $selectable, 'reason' => $reason],
                'draft' => ['enabled' => $selectable && $researchDone, 'reason' => $researchDone ? $reason : 'Najpierw ukończ i zatwierdź research.'],
                'quality' => ['enabled' => $selectable && $draftDone, 'reason' => $draftDone ? $reason : 'Najpierw wygeneruj szkic.'],
                'images' => ['enabled' => $selectable && $qualityDone, 'reason' => $qualityDone ? $reason : 'Najpierw zalicz kontrolę jakości.'],
                'generate_all' => ['enabled' => $selectable, 'reason' => $reason],
            ],
            'workflow' => ['research' => ['done' => $researchDone, 'version' => $status['steps']['research']['result_id'] ?? null],
                'draft' => ['done' => $draftDone, 'version' => $status['steps']['draft']['version'] ?? null],
                'quality' => ['done' => $qualityDone, 'version' => $status['steps']['quality']['version'] ?? null],
                'images' => ['done' => ($status['steps']['images']['status'] ?? '') === 'completed'], 'ready' => (bool) $status['readiness']],
            'workflow_status' => $status, 'job' => $status['latest_job_id'] === null ? null : [
                'id' => $status['latest_job_id'], 'batch_id' => $status['latest_batch_id'], 'status' => $status['latest_job_status'],
                'stage' => $status['latest_stage'], 'progress' => (int) $status['progress'], 'reason' => (string) ($status['wait_reason'] ?: $status['error']),
                'technical_error' => (string) $status['error'],
                'retryable' => (bool) $status['retryable'], 'review_url' => $status['proposal_url'],
                'available_at' => $status['available_at'] ?? null,
                'retry_after_seconds' => $status['retry_after_seconds'] ?? null],
            'proposal_url' => $status['proposal_url'],
        ];
    }, $topics);
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
    $statement = bueno_database()->prepare(
        'SELECT * FROM ' . $table . ' WHERE topic_id = :topic_id ORDER BY id DESC LIMIT 1'
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

function generation_workflow_initial_item(int $topicId, string $action, ?string $retryStage = null): array
{
    $requestedStage = $action === 'quality' ? 'quality' : ($action === 'generate_all' ? '' : ($retryStage ?: $action));
    $topic = find_editorial_topic($topicId);
    if (!is_array($topic) || (int) ($topic['automatic_eligible'] ?? 0) !== 1 || ($topic['primary_post_status'] ?? '') === 'rejected') {
        return ['status' => 'invalid', 'stage' => $retryStage ?: 'research', 'requested_stage' => $requestedStage,
            'outcome' => 'invalid', 'progress_percent' => 0,
            'wait_reason' => 'Automatyzacja jest zablokowana przez stan, scoring albo poziom ryzyka tematu.',
            'completed_at' => gmdate('Y-m-d H:i:s')];
    }
    $active = bueno_database()->prepare(
        'SELECT id, batch_id FROM generation_batch_items
         WHERE topic_id = :topic_id AND status IN ("queued", "research", "draft", "quality_check", "images", "rate_limited")
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
                'wait_reason' => 'Ponowienie jest dozwolone tylko po bĹ‚Ä™dzie technicznym albo zmianie danych ĹşrĂłdĹ‚owych.',
                'completed_at' => gmdate('Y-m-d H:i:s')];
        }
    }

    $research = generation_workflow_latest('research_packages', $topicId);
    $approved = generation_workflow_latest_approved_research($topicId);
    $draft = generation_workflow_latest('article_draft_versions', $topicId);
    $quality = is_array($draft) ? generation_workflow_latest_quality((int) $draft['id']) : null;
    $qualityPassed = is_array($quality) && $quality['status'] === 'completed'
        && (int) $quality['passed'] === 1 && quality_active_hard_blocks($quality) === [];
    $images = is_array($draft) ? generation_workflow_images_state((int) $draft['post_id']) : ['total' => 0, 'completed' => 0, 'manual' => 0, 'missing' => 0];
    $imageReady = $images['total'] > 0 && $images['completed'] === $images['total'];
    $stage = $action === 'quality' ? 'quality_check' : $action;
    if ($action === 'retry') $stage = (string) $retryStage;
    if ($action === 'generate_all') {
        $stage = !is_array($approved) ? 'research'
            : (!is_array($draft) || $draft['status'] !== 'completed' ? 'draft'
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
        'quality_check' => is_array($draft) && $draft['status'] === 'completed',
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
        || ($stage === 'draft' && is_array($draft) && $draft['status'] === 'completed')
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
        'draft_operation_id' => is_array($draft) && $draft['status'] === 'completed' ? (int) $draft['generation_operation_id'] : null,
        'draft_version_id' => is_array($draft) && $draft['status'] === 'completed' ? (int) $draft['id'] : null,
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
    $requestKey = trim((string) $requestKey) ?: bin2hex(random_bytes(16));
    if (strlen($requestKey) > 128 || preg_match('/^[a-zA-Z0-9._:-]+$/', $requestKey) !== 1) throw new InvalidArgumentException('Nieprawidłowy klucz idempotencji batcha.');
    $database = bueno_database();
    $existing = $database->prepare('SELECT id FROM generation_batches WHERE request_key = :key');
    $existing->execute([':key' => $requestKey]);
    if (($id = $existing->fetchColumn()) !== false) return generation_batch_payload((int) $id);
    $database->beginTransaction();
    try {
        $database->prepare('INSERT INTO generation_batches (batch_key, request_key, action, item_count, created_by) VALUES (:batch_key, :request_key, :action, :count, :actor)')
            ->execute([':batch_key' => bin2hex(random_bytes(16)), ':request_key' => $requestKey, ':action' => $action, ':count' => count($topicIds), ':actor' => mb_substr($actor, 0, 100)]);
        $batchId = (int) $database->lastInsertId();
        foreach ($topicIds as $topicId) {
            $initial = generation_workflow_initial_item($topicId, $action, $retryStage);
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
        if ($item['status'] === 'ready') {
            $ready++;
        }
    }
    unset($item);
    return [
        'id' => (int) $batch['id'],
        'key' => (string) $batch['batch_key'],
        'action' => (string) ($batch['action'] ?? 'generate_all'),
        'status' => (string) $batch['status'],
        'item_count' => (int) $batch['item_count'],
        'completed_count' => $terminal,
        'ready_count' => $ready,
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
         WHERE items.status IN ("queued", "research", "draft", "quality_check", "images", "rate_limited", "waiting_review", "failed", "ready", "cancelled")
         ORDER BY CASE
            WHEN items.status IN ("queued", "research", "draft", "quality_check", "images", "rate_limited") THEN 0
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
        'approved', 'completed' => 'completed',
        'failed' => 'failed',
        'running' => 'running',
        'prepared', 'queued' => 'queued',
        default => 'not_started',
    };
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
            SELECT d.*, o.status AS operation_status, ROW_NUMBER() OVER (PARTITION BY d.topic_id ORDER BY d.id DESC) rn
            FROM article_draft_versions d INNER JOIN generation_operations o ON o.id = d.generation_operation_id
            WHERE d.topic_id IN (' . implode(',', $holders) . ')
        ),
        latest_quality AS (
            SELECT q.*, o.status AS operation_status, d.topic_id,
                   ROW_NUMBER() OVER (PARTITION BY d.topic_id ORDER BY q.id DESC) rn
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
        )
        SELECT t.id topic_id, p.status topic_status, t.automatic_eligible,
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
               j.progress_percent, j.available_at, j.wait_reason, j.error_message, j.outcome
        FROM editorial_topics t
        INNER JOIN posts p ON p.id = t.primary_post_id
        LEFT JOIN latest_research r ON r.topic_id = t.id AND r.rn = 1
        LEFT JOIN latest_draft d ON d.topic_id = t.id AND d.rn = 1
        LEFT JOIN latest_quality q ON q.topic_id = t.id AND q.rn = 1
        LEFT JOIN image_counts img ON img.post_id = d.post_id
        LEFT JOIN latest_job j ON j.topic_id = t.id AND j.rn = 1
        WHERE t.id IN (' . implode(',', $holders) . ') ORDER BY t.id';
    $statement = bueno_database()->prepare($sql);
    $statement->execute($params);
    $payload = [];
    foreach ($statement->fetchAll() as $row) {
        $hardBlocks = json_decode((string) ($row['hard_blocks_json'] ?? '[]'), true) ?: [];
        $draftValidation = json_decode((string) ($row['draft_validation'] ?? '{}'), true) ?: [];
        $qualityIsCurrent = (int) ($row['quality_draft_id'] ?? 0) === (int) ($row['draft_id'] ?? 0);
        $qualityPassed = $qualityIsCurrent && (int) ($row['passed'] ?? 0) === 1 && $hardBlocks === [];
        $imageReady = (int) $row['image_total'] > 0 && (int) $row['image_total'] === (int) $row['image_completed'];
        $imageManual = (int) $row['image_manual'] > 0 || (int) $row['image_pending'] > 0;
        $proposalReady = $qualityPassed && (int) $row['image_total'] > 0;
        $active = in_array((string) ($row['job_status'] ?? ''), GENERATION_BATCH_ACTIVE_STATUSES, true);
        $researchWaiting = ($row['research_status'] ?? '') === 'completed' && empty($row['approved_at']);
        $qualityWaiting = $qualityIsCurrent && ($row['quality_status'] ?? '') === 'completed' && !$qualityPassed;
        $overall = $active ? (string) $row['job_status']
            : ($qualityWaiting || $researchWaiting ? 'waiting_review'
            : ($proposalReady ? 'ready'
            : (($row['job_status'] ?? '') === 'failed' ? 'failed' : 'eligible')));
        $progress = (int) ($row['progress_percent'] ?? 0);
        $availableAt = !empty($row['available_at'])
            ? gmdate('c', strtotime((string) $row['available_at'] . ' UTC'))
            : null;
        $retryAfterSeconds = $overall === 'rate_limited' && $availableAt !== null
            ? max(0, strtotime($availableAt) - time())
            : null;
        $payload[] = [
            'topic_id' => (int) $row['topic_id'], 'status' => $overall,
            'steps' => [
                'research' => ['status' => generation_workflow_step_status($row['research_status'], $row['research_operation_status'], !empty($row['approved_at']), $researchWaiting), 'progress' => $row['research_id'] ? ($researchWaiting ? 100 : ($active && $row['job_stage'] === 'research' ? min(99, $progress * 3) : 100)) : 0, 'result_id' => $row['research_id'] ? (int) $row['research_id'] : null],
                'draft' => ['status' => generation_workflow_step_status($row['draft_status'], $row['draft_operation_status']), 'progress' => $row['draft_id'] ? ($active && $row['job_stage'] === 'draft' ? min(99, $progress) : 100) : 0, 'result_id' => $row['draft_id'] ? (int) $row['draft_id'] : null, 'version' => $row['draft_version'] ? (int) $row['draft_version'] : null],
                'quality' => ['status' => $qualityIsCurrent ? generation_workflow_step_status($row['quality_status'], $row['quality_operation_status'], $qualityPassed, $qualityWaiting) : 'not_started', 'progress' => $qualityIsCurrent && $row['quality_id'] ? ($active && $row['job_stage'] === 'quality_check' ? min(99, $progress) : 100) : 0, 'result_id' => $qualityIsCurrent && $row['quality_id'] ? (int) $row['quality_id'] : null, 'version' => $qualityIsCurrent && $row['check_number'] ? (int) $row['check_number'] : null],
                'images' => ['status' => $imageManual ? 'manual_review' : ($imageReady ? 'completed' : ($active && $row['job_stage'] === 'images' ? 'running' : 'not_started')), 'progress' => (int) $row['image_total'] > 0 ? (int) floor(100 * (int) $row['image_completed'] / (int) $row['image_total']) : 0, 'completed' => (int) $row['image_completed'], 'total' => (int) $row['image_total']],
            ],
            'wait_reason' => (string) ($row['wait_reason'] ?? ''), 'error' => (string) ($row['error_message'] ?? ''),
            'available_at' => $availableAt, 'retry_after_seconds' => $retryAfterSeconds,
            'active_job_id' => $active ? (int) $row['job_id'] : null, 'active_batch_id' => $active ? (int) $row['batch_id'] : null,
            'active_action' => $active ? (string) $row['batch_action'] : null,
            'active_stage' => $active ? (string) $row['job_stage'] : null,
            'latest_job_id' => $row['job_id'] ? (int) $row['job_id'] : null,
            'latest_batch_id' => $row['batch_id'] ? (int) $row['batch_id'] : null,
            'latest_job_status' => $row['job_status'] ? (string) $row['job_status'] : null,
            'latest_stage' => $row['job_stage'] ? (string) $row['job_stage'] : null,
            'retryable' => in_array((string) ($row['job_status'] ?? ''), ['rate_limited', 'cancelled'], true)
                || ((string) ($row['job_status'] ?? '') === 'failed' && ((string) ($draftValidation['repair_scope'] ?? '') === 'titles' || (string) ($row['outcome'] ?? '') !== 'validation_contract')),
            'repair_scope' => (string) ($draftValidation['repair_scope'] ?? ''),
            'repair' => $draftValidation,
            'progress' => $row['job_id'] ? $progress : ($qualityPassed && $imageReady ? 100 : 0),
            'readiness' => $proposalReady, 'publication_readiness' => $qualityPassed && $imageReady,
            'proposal_url' => $proposalReady && $row['draft_id'] ? 'admin-proposals.php?draft=' . (int) $row['draft_id'] : null,
            'result_ids' => ['research_package_id' => $row['research_id'] ? (int) $row['research_id'] : null, 'draft_version_id' => $row['draft_id'] ? (int) $row['draft_id'] : null, 'quality_check_id' => $row['quality_id'] ? (int) $row['quality_id'] : null],
        ];
    }
    return $payload;
}

function generation_batch_claim_items(?int $limit = null): array
{
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
            'SELECT id FROM generation_batch_items
             WHERE status IN ("queued", "research", "draft", "quality_check", "images", "rate_limited")
               AND available_at <= CURRENT_TIMESTAMP AND lease_token IS NULL
             ORDER BY available_at, id LIMIT :limit'
        );
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

function generation_batch_find_item(int $itemId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT items.*, batches.action AS batch_action FROM generation_batch_items items
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
        'post_id', 'retry_count', 'available_at', 'wait_reason', 'error_message', 'completed_at',
        'lease_token', 'lease_expires_at', 'requested_stage', 'outcome',
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
    bueno_database()->prepare(
        'UPDATE generation_batch_items SET ' . implode(', ', $sets) . ' WHERE id = :id'
    )->execute($params);
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
        'no_complete_primary' => 'Brak kompletnego, zweryfikowanego źródła pierwotnego.',
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
    try {
        $stage = (string) $item['stage'];
        $batchAction = (string) ($item['batch_action'] ?? 'generate_all');
        if ($stage === 'research') {
            generation_batch_update_item($itemId, ['status' => 'research', 'progress_percent' => 10]);
            $operationId = (int) ($item['research_operation_id'] ?? 0);
            if ($operationId <= 0) {
                $enrichment = enrich_topic_sources((int) $item['topic_id']);
                $topic = find_editorial_topic((int) $item['topic_id']);
                $policy = research_policy_decision(list_verified_research_sources((int) $item['topic_id']), (string) ($topic['risk_level'] ?? 'low'), !empty($topic['is_controversial']));
                if (($policy['decision'] ?? '') !== 'continue') {
                    if ((int) ($enrichment['failed'] ?? 0) > 0 && list_verified_research_sources((int) $item['topic_id']) === []) {
                        $policy = ['decision'=>'blocked','code'=>'enrichment_technical_error','reason'=>'Enrichment nie pobrał źródła z powodu błędu technicznego.'];
                    }
                    generation_batch_update_item($itemId, ['status'=>'waiting_review','progress_percent'=>20,'wait_reason'=>research_policy_message($policy),'completed_at'=>gmdate('Y-m-d H:i:s')]);
                    generation_batch_audit((int) $item['batch_id'], $itemId, 'research_policy_blocked_before_generation', 'worker', ['policy'=>$policy,'enrichment'=>$enrichment]);
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
            $package = find_research_package_by_operation($operationId);
            if (!is_array($package) || !generation_batch_research_allows_auto_approval($package)) {
                generation_batch_update_item($itemId, [
                    'status' => 'waiting_review', 'progress_percent' => 30,
                    'wait_reason' => research_policy_message(json_decode((string) ($package['policy_json'] ?? '{}'), true) ?: []),
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                ]);
                generation_batch_audit((int) $item['batch_id'], $itemId, 'research_waiting_review');
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
            generation_batch_update_item($itemId, ['status' => 'draft', 'progress_percent' => 45]);
            $operationId = (int) ($item['draft_operation_id'] ?? 0);
            if ($operationId <= 0) {
                $operationId = prepare_article_draft_operation((int) $item['research_package_id'], 'informational');
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
            }
            $completedDraft = find_article_draft_by_operation($operationId);
            $completedValidation = is_array($completedDraft) ? (json_decode((string)$completedDraft['validation_json'], true) ?: []) : [];
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
            $operationId = (int) ($item['quality_operation_id'] ?? 0);
            if ($operationId <= 0) {
                $operationId = prepare_quality_check_operation((int) $item['draft_version_id']);
                $check = find_quality_check_by_operation($operationId);
                generation_batch_update_item($itemId, [
                    'quality_operation_id' => $operationId, 'quality_check_id' => (int) $check['id'],
                ]);
            }
            execute_generation_operation($operationId, $transport);
            $check = find_quality_check_by_operation($operationId);
            if (!is_array($check) || (int) $check['passed'] !== 1 || quality_active_hard_blocks($check) !== []) {
                generation_batch_update_item($itemId, [
                    'status' => 'waiting_review', 'progress_percent' => 82,
                    'wait_reason' => 'Kontrola jakości wymaga decyzji redakcyjnej.',
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                ]);
                generation_batch_audit((int) $item['batch_id'], $itemId, 'quality_waiting_review', 'worker', ['quality_check_id' => is_array($check) ? (int) $check['id'] : null]);
            } else {
                $qualityOnly = (string) ($item['requested_stage'] ?? '') === 'quality';
                generation_batch_update_item($itemId, $qualityOnly ? [
                    'status' => 'completed', 'stage' => 'quality_check', 'progress_percent' => 100,
                    'wait_reason' => 'Kontrola jakości zaliczona.', 'completed_at' => gmdate('Y-m-d H:i:s'),
                ] : [
                    'status' => 'images', 'stage' => 'images', 'progress_percent' => 85,
                ]);
                generation_batch_audit((int) $item['batch_id'], $itemId, 'quality_passed', 'worker', ['quality_check_id' => (int) $check['id']]);
            }
        } elseif ($stage === 'images') {
            if ((bool) app_config('ai_image_generation_enabled')) {
                throw new RuntimeException('Batch odmawia uruchomienia, gdy generator obrazów AI jest włączony.');
            }
            $draft = find_article_draft_by_operation((int) $item['draft_operation_id']);
            $draftJson = is_array($draft) ? json_decode((string) $draft['draft_json'], true) : null;
            if (!is_array($draftJson) || !is_array($draftJson['illustration_plan'] ?? null)) {
                throw new RuntimeException('Szkic nie zawiera zwalidowanego planu legalnych ilustracji.');
            }
            $postId = (int) $item['post_id'];
            $existingSlots = [];
            foreach (list_article_images($postId) as $existingImage) {
                $existingSlots[(string) $existingImage['role'] . ':' . (string) $existingImage['section_id']] = true;
            }
            $plannedCount = 0;
            foreach ([(array) $draftJson['illustration_plan']['hero'], ...(array) $draftJson['illustration_plan']['inline']] as $plannedImage) {
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
            $imageSummary = (bool) app_config('source_image_mock')
                ? fulfill_article_source_images($postId, static fn (string $query): array => [], static fn (array $image): array => $image)
                : fulfill_article_source_images($postId);
            $requiresReview = ((int) $imageSummary['missing'] + (int) $imageSummary['manual_review']) > 0;
            $imageWaitReason = $requiresReview
                ? 'Gotowa propozycja jest dostępna. Brakujące lub niezweryfikowane ilustracje oznaczono jako wymagające uwagi; publikacja pozostaje zablokowana.'
                : 'Legalne ilustracje źródłowe zostały użyte automatycznie; gotowa propozycja jest dostępna.';
            generation_batch_update_item($itemId, [
                'status' => (string) ($item['requested_stage'] ?? '') === 'images' ? 'completed' : 'ready',
                'stage' => (string) ($item['requested_stage'] ?? '') === 'images' ? 'images' : 'ready',
                'outcome' => $requiresReview ? 'completed_with_warnings' : 'completed', 'progress_percent' => 100,
                'wait_reason' => $imageWaitReason,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, $requiresReview ? 'item_ready_with_image_warnings' : 'item_ready', 'worker', [
                'draft_version_id' => (int) $item['draft_version_id'], 'published' => false,
                'planned_legal_images' => $plannedCount, 'source_image_summary' => $imageSummary,
                'ai_image_generation_calls' => 0,
            ]);
        }
    } catch (Throwable $exception) {
        $message = mb_substr($exception->getMessage(), 0, 2000);
        $classification = generation_error_classification($exception);
        $rateLimited = str_contains(mb_strtolower($message), 'limit free tier')
            || str_contains($message, '429') || str_contains(mb_strtolower($message), 'rate limit')
            || str_contains(mb_strtolower($message), 'timeout') || str_contains(mb_strtolower($message), 'timed out')
            || str_contains(mb_strtolower($message), 'temporarily unavailable');
        if ($rateLimited) {
            $retry = (int) $item['retry_count'] + 1;
            preg_match('/Retry-After:\s*(\d+)/i', $message, $retryAfterMatch);
            $retryAfter = isset($retryAfterMatch[1]) ? (int) $retryAfterMatch[1] : 0;
            $delay = min(86400, max($retryAfter, (int) app_config('batch_rate_limit_backoff_seconds') * (2 ** min(5, $retry - 1))));
            generation_batch_update_item($itemId, [
                'status' => 'rate_limited', 'retry_count' => $retry,
                'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'wait_reason' => 'Oczekiwanie na limit API (' . $delay . ' s).', 'error_message' => $message,
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, 'rate_limited', 'worker', ['delay_seconds' => $delay]);
        } else {
            $contractError = (string) $item['stage'] === 'quality_check'
                && str_starts_with($message, 'Nieprawidłowa odpowiedź Gemini API:');
            $display = $contractError
                ? 'Błąd formatu odpowiedzi / wymaga poprawy kontraktu lub nowej operacji po aktualizacji.'
                : $message;
            generation_batch_update_item($itemId, [
                'status' => 'failed', 'outcome' => $contractError ? 'validation_contract' : 'failed',
                'wait_reason' => $display, 'error_message' => $message,
            ]);
            generation_batch_audit((int) $item['batch_id'], $itemId, 'item_failed', 'worker', [
                'error' => $message, 'classification' => $contractError ? 'validation_contract' : $classification['class'],
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
    if (!is_array($item) || !in_array($item['status'], ['queued', 'rate_limited'], true) || $item['lease_token'] !== null) {
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
    if (!is_array($item) || !in_array($item['status'], ['failed', 'rate_limited', 'cancelled'], true)) {
        throw new RuntimeException('Ponowić można wyłącznie element nieudany, oczekujący na limit albo anulowany.');
    }
    if ((string) ($item['outcome'] ?? '') === 'validation_contract') {
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

function retry_generation_batch(int $batchId, string $actor = 'admin'): int
{
    $count = 0;
    foreach (generation_batch_item_rows($batchId) as $item) {
        if (in_array($item['status'], ['failed', 'rate_limited', 'cancelled'], true)) {
            retry_generation_batch_item((int) $item['id'], $actor);
            $count++;
        }
    }
    generation_batch_audit($batchId, null, 'batch_retried', $actor, ['item_count' => $count]);
    return $count;
}

function generation_batch_launch_worker(): void
{
    if (getenv('CMS_BATCH_NO_SPAWN') === '1') return;
    $php = function_exists('content_studio_php_cli') ? content_studio_php_cli() : PHP_BINARY;
    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'generation-batch-worker.php';
    for ($slot = 0; $slot < (int) app_config('batch_worker_concurrency'); $slot++) {
        if (PHP_OS_FAMILY === 'Windows') {
            $handle = popen('start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' --drain > NUL 2>&1', 'r');
            if ($handle === false) throw new RuntimeException('Nie udało się uruchomić workera batch.');
            pclose($handle);
            continue;
        }
        exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' --drain > /dev/null 2>&1 &', $output, $exitCode);
        if ($exitCode !== 0) throw new RuntimeException('Nie udało się uruchomić workera batch.');
    }
}

function generation_batch_has_due_items(): bool
{
    return (bool) bueno_database()->query(
        'SELECT EXISTS(SELECT 1 FROM generation_batch_items
         WHERE status IN ("queued", "rate_limited") AND available_at <= CURRENT_TIMESTAMP
           AND lease_token IS NULL LIMIT 1)'
    )->fetchColumn();
}
