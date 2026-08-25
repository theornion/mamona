<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app-config.php';

if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Użycie: php php/cli-remove-legacy-cats.php --apply\n");
    exit(2);
}
if (strtolower((string) app_config('environment')) === 'production') {
    throw new RuntimeException('Usuwanie legacy cats jest zablokowane dla CMS_ENV=production.');
}

$databasePath = realpath(app_path('data/cms.sqlite'));
if ($databasePath === false) {
    throw new RuntimeException('Nie znaleziono lokalnej bazy danych.');
}
$database = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = (int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'cats'")->fetchColumn() > 0;
if ($exists) {
    $database->exec('DROP TABLE cats');
    $database->exec('VACUUM');
}
echo json_encode(['ok' => true, 'database_path' => $databasePath, 'cats_table_removed' => $exists], JSON_UNESCAPED_SLASHES) . PHP_EOL;
