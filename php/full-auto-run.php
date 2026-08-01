<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/full-auto-service.php';

$arguments = array_slice($argv, 1);
if (count(array_diff($arguments, ['--dry-run'])) > 0 || count($arguments) > 1) {
    fwrite(STDERR, "Użycie: php php/full-auto-run.php [--dry-run]\n");
    exit(2);
}
try {
    $result = full_auto_execute(in_array('--dry-run', $arguments, true));
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($result['errors'] === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(str_contains($exception->getMessage(), 'FULL_AUTO_ENABLED=false') ? 4 : 1);
}
