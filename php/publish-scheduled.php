<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin-database.php';

$arguments = array_slice($argv ?? [], 1);
$dryRun = in_array('--dry-run', $arguments, true);
$unknown = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--dry-run'));
if ($unknown !== []) {
    fwrite(STDERR, "Użycie: php php/publish-scheduled.php [--dry-run]\n");
    exit(2);
}

try {
    $result = run_scheduled_publications($dryRun);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['status'] === 'locked' ? 3 : ($result['failed'] === [] ? 0 : 1));
} catch (Throwable $exception) {
    try {
        scheduled_publication_log('run_failed', ['message' => $exception->getMessage()]);
    } catch (Throwable) {
        // The original scheduler error remains the actionable one.
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
