<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin-database.php';

$argument = (string) ($argv[1] ?? '');
if ($argument === '--next') {
    content_studio_expire_stale_jobs();
    $jobId = bueno_database()->query(
        'SELECT id FROM editorial_ingestion_jobs WHERE status = "queued" ORDER BY id LIMIT 1'
    )->fetchColumn();
    if ($jobId === false) {
        exit(0);
    }
} else {
    $jobId = filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($jobId === false) {
        fwrite(STDERR, "Podaj poprawne ID zadania albo --next.\n");
        exit(2);
    }
}

try {
    content_studio_run_job((int) $jobId);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
