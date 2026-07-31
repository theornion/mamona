<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
start_admin_session();

function content_studio_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_is_logged_in()) {
    content_studio_json(['ok' => false, 'error' => 'Wymagane jest logowanie administratora.'], 401);
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    if ((string) ($_GET['action'] ?? 'status') !== 'status') {
        content_studio_json(['ok' => false, 'error' => 'Nieprawidłowa akcja.'], 400);
    }
    $lastSuccess = bueno_database()->query(
        'SELECT finished_at FROM editorial_ingestion_jobs
         WHERE status IN ("success", "partial_success") ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    content_studio_json([
        'ok' => true,
        'job' => content_studio_job_payload(content_studio_latest_job()),
        'active_sources' => content_studio_active_rss_count(),
        'last_success_at' => $lastSuccess ?: null,
        'topics' => content_studio_topics(),
        'batch_limit' => CONTENT_STUDIO_BATCH_LIMIT,
        'batches' => list_generation_batches(5),
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    content_studio_json(['ok' => false, 'error' => 'Niedozwolona metoda.'], 405);
}
if (!admin_valid_csrf()) {
    content_studio_json(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.'], 403);
}

try {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'start') {
        $job = content_studio_create_job('admin');
        try {
            content_studio_launch_worker((int) $job['id']);
        } catch (Throwable $exception) {
            content_studio_update_job((int) $job['id'], [
                'status' => 'failed',
                'stage' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
            throw $exception;
        }
        content_studio_json(['ok' => true, 'job' => content_studio_job_payload($job)], 202);
    }
    if ($action === 'prepare_generation') {
        $batch = create_generation_batch(
            $_POST['topic_ids'] ?? null,
            trim((string) ($_POST['request_key'] ?? '')) ?: null,
            'admin'
        );
        generation_batch_launch_worker();
        content_studio_json(['ok' => true, 'batch' => $batch], 202);
    }
    if ($action === 'cancel_batch_item') {
        cancel_generation_batch_item(filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?: 0);
        content_studio_json(['ok' => true, 'batches' => list_generation_batches(5)]);
    }
    if ($action === 'retry_batch_item') {
        retry_generation_batch_item(filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?: 0);
        generation_batch_launch_worker();
        content_studio_json(['ok' => true, 'batches' => list_generation_batches(5)]);
    }
    if ($action === 'retry_batch') {
        retry_generation_batch(filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT) ?: 0);
        generation_batch_launch_worker();
        content_studio_json(['ok' => true, 'batches' => list_generation_batches(5)]);
    }
    throw new InvalidArgumentException('Nieprawidłowa akcja.');
} catch (DomainException $exception) {
    content_studio_json(['ok' => false, 'error' => $exception->getMessage(), 'job' => content_studio_job_payload(content_studio_latest_job())], 409);
} catch (InvalidArgumentException $exception) {
    content_studio_json(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    content_studio_json(['ok' => false, 'error' => $exception->getMessage()], 500);
}
