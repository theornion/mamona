<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-artifact-cleanup-' . bin2hex(random_bytes(5));
mkdir($directory, 0700, true);
$databaseFile = $directory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';
$database = bueno_database();

function artifact_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$batchSmoke = (string) file_get_contents(__DIR__ . '/generation-batch-smoke.php');
artifact_assert(
    strpos($batchSmoke, "putenv('CMS_TEST_DATABASE_FILE='") < strpos($batchSmoke, "require_once dirname(__DIR__) . '/php/admin-database.php'"),
    'Batch smoke nie wymusza bazy tymczasowej przed połączeniem z bazą.'
);
artifact_assert(str_contains($batchSmoke, 'register_shutdown_function') && str_contains($batchSmoke, "'-wal'") && str_contains($batchSmoke, "'-shm'"), 'Awaria batch smoke nie ma cleanup bazy tymczasowej.');

// Re-run only the narrow cleanup body against controlled post-migration fixtures.
$insert = $database->prepare('INSERT INTO technical_sources (name,website_url,feed_url,source_type,topic_category,language,credibility_level,is_primary,is_active,profile_key) VALUES (?,?,?,"rss","science","en",5,1,1,"test")');
$insert->execute(['Batch smoke 317703239823', 'https://example.com/317703239823', 'https://example.com/317703239823.xml']);
$artifactId = (int) $database->lastInsertId();
$insert->execute(['Batch smoke user', 'https://example.com/user', 'https://example.com/user.xml']);
$userId = (int) $database->lastInsertId();
$insert->execute(['Batch smoke 123', 'https://publisher.example/123', 'https://publisher.example/123.xml']);
$publisherId = (int) $database->lastInsertId();
$database->exec("DELETE FROM schema_migrations WHERE migration_key='" . LEAKED_BATCH_FIXTURE_CLEANUP_MIGRATION . "'");
run_schema_migrations($database);
artifact_assert(find_technical_source($artifactId) === null, 'Jednoznaczny artefakt nie został usunięty.');
artifact_assert(find_technical_source($userId) !== null && find_technical_source($publisherId) !== null, 'Migracja usunęła źródło bez pełnego markera fixture.');
$audit = $database->query("SELECT removed_count FROM test_artifact_cleanup_audit WHERE migration_key='" . LEAKED_BATCH_FIXTURE_CLEANUP_MIGRATION . "'")->fetchColumn();
artifact_assert((int) $audit === 1, 'Audyt nie podaje dokładnie jednego usuniętego artefaktu.');

echo "TEST_ARTIFACT_CLEANUP_SMOKE_OK\n";
