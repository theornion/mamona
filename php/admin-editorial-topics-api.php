<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
start_admin_session();

function topics_api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_is_logged_in()) topics_api_json(['ok' => false, 'error' => 'Wymagane jest logowanie administratora.'], 401);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $filter = trim((string) ($_GET['filter'] ?? 'active'));
    if (!in_array($filter, ['active', 'profile-rejected', 'all'], true)) $filter = 'active';
    if (generation_batch_has_due_items()) generation_batch_launch_worker();
    topics_api_json([
        'ok' => true,
        'server_time' => gmdate('c'),
        'automatic_dispatch_paused' => generation_automatic_dispatch_paused(),
        'topics' => generation_topics_workflow_payload(list_editorial_topics(1000, $filter)),
        'batch_limit' => (int) app_config('batch_max_topics'),
        'recommended_batch_size' => 10,
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    topics_api_json(['ok' => false, 'error' => 'Niedozwolona metoda.'], 405);
}
if (!admin_valid_csrf()) topics_api_json(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.'], 403);

try {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'toggle_automatic_dispatch') {
        $pause = trim((string) ($_POST['dispatcher_state'] ?? 'paused')) === 'paused';
        topics_api_json(['ok' => true, 'dispatch' => generation_set_automatic_dispatch_paused($pause, 'admin-api', $pause)]);
    }
    if ($action === 'run_workflow') {
        $workflowAction = trim((string) ($_POST['workflow_action'] ?? ''));
        $rawTopicIds=$_POST['topic_ids']??null;
        if($workflowAction==='generate_all'&&is_array($rawTopicIds)&&count($rawTopicIds)===1){
            $topicId=(int)$rawTopicIds[0];$status=generation_workflow_statuses([$topicId])[0]??[];
            if(($status['latest_job_status']??'')==='auto_rejected'&&($status['latest_action']??'')==='generate_all'){
                $resume=generation_batch_resume_legacy_item((int)$status['latest_job_id'],'admin');generation_batch_launch_worker();topics_api_json(['ok'=>true,'resume'=>$resume],202);
            }
        }
        $result = create_generation_workflow_batch(
            $rawTopicIds,
            $workflowAction,
            trim((string) ($_POST['request_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''))) ?: null,
            'admin',
            trim((string) ($_POST['retry_stage'] ?? '')) ?: null
        );
        if (is_array($result['batch'])) generation_batch_launch_worker();
        topics_api_json(['ok' => true, ...$result], is_array($result['batch']) ? 202 : 200);
    }
    if ($action === 'retry_item') {
        retry_generation_batch_item(filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?: 0, 'admin');
        generation_batch_launch_worker();
        topics_api_json(['ok' => true]);
    }
    if ($action === 'resume_legacy') {
        $result=generation_batch_resume_legacy_item(filter_input(INPUT_POST,'item_id',FILTER_VALIDATE_INT)?:0,'admin');
        generation_batch_launch_worker();
        topics_api_json(['ok'=>true,'resume'=>$result],202);
    }
    if ($action === 'trash_topic') {
        trash_editorial_topic(filter_input(INPUT_POST, 'topic_id', FILTER_VALIDATE_INT) ?: 0, 'admin', trim((string) ($_POST['reason'] ?? '')), 'topics_api');
        topics_api_json(['ok' => true]);
    }
    throw new InvalidArgumentException('Nieprawidłowa akcja.');
} catch (DomainException $exception) {
    topics_api_json(['ok' => false, 'error' => $exception->getMessage()], 409);
} catch (InvalidArgumentException $exception) {
    topics_api_json(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    topics_api_json(['ok' => false, 'error' => $exception->getMessage()], 500);
}
