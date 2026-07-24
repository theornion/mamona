<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin-database.php';

if (!in_array('--confirm-permanent-delete', $argv, true)) {
    fwrite(STDERR, "Użycie: php php/purge-developer-feeds.php --confirm-permanent-delete\n");
    exit(2);
}

$archivePath = dirname(__DIR__) . '/data/archives/developer-feeds-before-purge-'
    . gmdate('Ymd-His') . '.json';

try {
    $result = purge_developer_feed_records($archivePath, true);
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
