<?php

declare(strict_types=1);

/**
 * Reset the local MVP content baseline without touching global CMS settings or RSS sources.
 * Default mode is a non-mutating dry run; use --apply only after reviewing its output.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app-config.php';

const MVP_RESET_DYNAMIC_TABLES = [
    'article_feedback_operations', 'article_proposal_audit', 'thumbnail_versions',
    'quality_check_runs', 'article_draft_versions', 'research_policy_audit',
    'research_packages', 'generation_repair_reports', 'generation_batch_audit',
    'generation_batch_items', 'generation_batches', 'generation_operations',
    'post_generation_runs', 'article_images', 'post_sources', 'post_status_history',
    'verified_research_sources', 'topic_grouping_candidates', 'topic_grouping_history',
    'feed_topic_memberships', 'topic_score_history', 'narrative_plans',
    'article_generation_budget', 'editorial_ingestion_jobs', 'full_auto_reservations',
    'full_auto_runs', 'generation_worker_guard',
    'topic_trash_audit', 'topic_trash_cleanup_runs', 'editorial_profile_cleanup_runs',
    'gemini_call_cache', 'gemini_quota_events', 'gemini_quota_state',
    'gemini_model_leases', 'image_provider_cache', 'image_provider_rate_windows',
    'discovered_feed_items', 'editorial_topics', 'posts',
];

const MVP_RESET_EXPECTED_ZERO_TABLES = [
    'posts', 'discovered_feed_items', 'editorial_topics', 'generation_operations',
    'generation_batches', 'generation_batch_items', 'article_draft_versions',
    'quality_check_runs', 'article_images', 'research_packages',
];

const MVP_RESET_PRESERVED_TABLES = [
    'schema_migrations', 'technical_sources', 'editorial_profile_categories',
    'generation_settings', 'post_categories', 'authors', 'cms_meta',
    'contact_settings', 'site_style_settings', 'social_media',
];

function mvp_reset_main(array $arguments): void
{
    $apply = in_array('--apply', $arguments, true);
    if (array_diff($arguments, ['--apply', '--dry-run']) !== []) {
        throw new InvalidArgumentException('Użycie: php php/cli-reset-mvp-content.php [--dry-run|--apply]');
    }
    if (strtolower((string) app_config('environment')) === 'production') {
        throw new RuntimeException('Reset MVP jest zablokowany dla CMS_ENV=production.');
    }

    $databasePath = mvp_reset_database_path();
    $database = mvp_reset_open_database($databasePath);
    $before = mvp_reset_counts($database, array_merge(MVP_RESET_EXPECTED_ZERO_TABLES, MVP_RESET_PRESERVED_TABLES));
    $result = [
        'ok' => true,
        'mode' => $apply ? 'apply' : 'dry-run',
        'database_path' => $databasePath,
        'environment' => (string) app_config('environment'),
        'counts_before' => $before,
        'preservation_assertions' => mvp_reset_preservation_assertions($before),
    ];

    if (!$apply) {
        $result['planned_tables'] = mvp_reset_existing_tables($database, MVP_RESET_DYNAMIC_TABLES);
        $result['planned_filesystem_cleanup'] = ['images/posts/**', 'pages/post-*.html', 'generated news pages'];
        mvp_reset_write_result($result);
        return;
    }

    if (app_public_url('') === '') {
        throw new RuntimeException('Przed resetem ustaw CMS_PUBLIC_URL, aby można było zsynchronizować pusty publiczny feed i sitemapę.');
    }

    $backup = mvp_reset_create_backup($database, $databasePath);
    $result['backup'] = $backup;
    $tables = mvp_reset_existing_tables($database, MVP_RESET_DYNAMIC_TABLES);
    mvp_reset_purge($database, $tables);
    mvp_reset_cleanup_filesystem();
    mvp_reset_sync_public_files();
    $integrity = (string) $database->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') {
        throw new RuntimeException('PRAGMA integrity_check po purge zwrócił: ' . $integrity);
    }
    $database->exec('VACUUM');
    $after = mvp_reset_counts($database, array_merge(MVP_RESET_EXPECTED_ZERO_TABLES, MVP_RESET_PRESERVED_TABLES));
    foreach (MVP_RESET_EXPECTED_ZERO_TABLES as $table) {
        if (($after[$table] ?? 0) !== 0) {
            throw new RuntimeException('Tabela po resecie nie jest pusta: ' . $table);
        }
    }
    if (!mvp_reset_preservation_assertions($after)['ok']) {
        throw new RuntimeException('Nie przeszły asercje zachowania konfiguracji/RSS.');
    }
    clearstatcache(true, $databasePath);
    $result += [
        'integrity_check' => $integrity,
        'vacuum' => 'ok',
        'counts_after' => $after,
        'database_size_after' => filesize($databasePath),
    ];
    mvp_reset_write_result($result);
}

function mvp_reset_database_path(): string
{
    $expected = realpath(app_path('data/cms.sqlite'));
    if ($expected === false || !is_file($expected)) {
        throw new RuntimeException('Nie znaleziono lokalnej bazy danych MVP.');
    }
    $configured = trim((string) getenv('CMS_TEST_DATABASE_FILE'));
    if ($configured !== '') {
        throw new RuntimeException('Reset MVP nie akceptuje CMS_TEST_DATABASE_FILE. Uruchom go wyłącznie na lokalnej bazie developerskiej.');
    }
    return $expected;
}

function mvp_reset_open_database(string $databasePath): PDO
{
    $database = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA busy_timeout = 10000');
    $database->exec('PRAGMA foreign_keys = ON');
    return $database;
}

function mvp_reset_existing_tables(PDO $database, array $candidates): array
{
    $available = array_flip($database->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($candidates, static fn (string $table): bool => isset($available[$table])));
}

function mvp_reset_counts(PDO $database, array $tables): array
{
    $counts = [];
    foreach (mvp_reset_existing_tables($database, $tables) as $table) {
        $counts[$table] = (int) $database->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
    }
    return $counts;
}

function mvp_reset_preservation_assertions(array $counts): array
{
    $required = ['schema_migrations', 'technical_sources', 'editorial_profile_categories'];
    $missing = array_values(array_filter($required, static fn (string $table): bool => ($counts[$table] ?? 0) < 1));
    return ['ok' => $missing === [], 'missing_or_empty' => $missing];
}

function mvp_reset_create_backup(PDO $database, string $databasePath): array
{
    $backupDirectory = dirname(app_project_root()) . '/mamona-backups';
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Nie można utworzyć katalogu backupu poza repozytorium: ' . $backupDirectory);
    }
    $backupPath = $backupDirectory . '/mamona-pre-mvp-cleanup-' . gmdate('Ymd-His') . '.sqlite';
    $quotedPath = str_replace("'", "''", str_replace('\\', '/', $backupPath));
    $database->exec("VACUUM INTO '" . $quotedPath . "'");
    if (!is_file($backupPath) || filesize($backupPath) < 1) {
        throw new RuntimeException('Nie utworzono poprawnego backupu SQLite.');
    }
    $backupDatabase = mvp_reset_open_database($backupPath);
    $integrity = (string) $backupDatabase->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') {
        throw new RuntimeException('Backup SQLite nie przeszedł integrity_check: ' . $integrity);
    }
    return [
        'path' => $backupPath,
        'bytes' => filesize($backupPath),
        'sha256' => hash_file('sha256', $backupPath),
        'integrity_check' => $integrity,
        'source_path' => $databasePath,
    ];
}

function mvp_reset_purge(PDO $database, array $tables): void
{
    $database->beginTransaction();
    try {
        foreach ($tables as $table) {
            $database->exec('DELETE FROM "' . $table . '"');
        }
        if (in_array('sqlite_sequence', $database->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true)) {
            $statement = $database->prepare('DELETE FROM sqlite_sequence WHERE name = :name');
            foreach ($tables as $table) {
                $statement->execute([':name' => $table]);
            }
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function mvp_reset_cleanup_filesystem(): void
{
    mvp_reset_empty_directory(app_post_image_path());
    $pagesDirectory = app_path('pages');
    foreach (glob($pagesDirectory . '/post-*.html') ?: [] as $path) {
        if (!unlink($path)) {
            throw new RuntimeException('Nie można usunąć wygenerowanej strony: ' . $path);
        }
    }
    $manifestPath = app_path('data/generated-news-pages.json');
    $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
    foreach (is_array($manifest) ? $manifest : [] as $filename) {
        if (is_string($filename) && preg_match('/^(?:aktualnosci-[0-9]+|kategoria-[a-z0-9-]+(?:-[0-9]+)?)\.html$/', $filename) === 1) {
            $path = $pagesDirectory . '/' . $filename;
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Nie można usunąć wygenerowanej strony: ' . $path);
            }
        }
    }
}

function mvp_reset_empty_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $root = realpath($directory);
    if ($root === false || $root !== realpath(app_post_image_path())) {
        throw new RuntimeException('Odmowa czyszczenia nieoczekiwanego katalogu mediów.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir()) {
            if (!rmdir($path)) {
                throw new RuntimeException('Nie można usunąć katalogu mediów: ' . $path);
            }
        } elseif (!unlink($path)) {
            throw new RuntimeException('Nie można usunąć pliku mediów: ' . $path);
        }
    }
}

function mvp_reset_sync_public_files(): void
{
    require_once __DIR__ . '/admin-database.php';
    sync_public_navigation();
}

function mvp_reset_write_result(array $result): void
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
}

try {
    mvp_reset_main(array_slice($argv, 1));
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
