<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/app-config.php';
require_once dirname(__DIR__) . '/php/editorial-schema.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' Oczekiwano: ' . var_export($expected, true)
            . ', otrzymano: ' . var_export($actual, true)
        );
    }
}

$database = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database->exec(
    'CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        is_published INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        deleted_at TEXT
    );
    INSERT INTO posts (is_published, created_at, updated_at, deleted_at)
    VALUES
        (1, "2026-07-01 10:00:00", "2026-07-02 11:00:00", NULL),
        (0, "2026-07-03 12:00:00", "2026-07-04 13:00:00", NULL);'
);

run_schema_migrations($database);
run_schema_migrations($database);

$rows = $database->query('SELECT * FROM posts ORDER BY id ASC')->fetchAll();

assert_same(2, count($rows), 'Migracja zmieniła liczbę artykułów.');
assert_same('published', $rows[0]['status'], 'Opublikowany wpis otrzymał zły status.');
assert_same('2026-07-01 10:00:00', $rows[0]['created_at'], 'Data utworzenia została zmieniona.');
assert_same('2026-07-01 10:00:00', $rows[0]['published_at'], 'Data publikacji nie została uzupełniona.');
assert_same('2026-07-02 11:00:00', $rows[0]['content_updated_at'], 'Data aktualizacji treści jest błędna.');
assert_same('draft', $rows[1]['status'], 'Szkic otrzymał zły status.');
assert_same(null, $rows[1]['published_at'], 'Szkic otrzymał datę publikacji.');
assert_same($rows[0]['author_id'], $rows[1]['author_id'], 'Wpisy nie otrzymały domyślnego autora.');
assert_same(25, (int) $database->query('SELECT COUNT(migration_key) FROM schema_migrations')->fetchColumn(), 'Migracje uruchomiły się więcej niż raz.');
assert_same('healthy', (string) $database->query('SELECT health_status FROM technical_sources LIMIT 1')->fetchColumn(), 'Źródła nie otrzymały początkowego stanu zdrowia.');
assert_same('[]', $rows[0]['content_blocks'], 'Kontrolowane bloki nie mają bezpiecznej wartości domyślnej.');
assert_same('[]', $rows[0]['ai_components'], 'Nowe pole komponentów AI ma nieprawidłową wartość domyślną.');
assert_same(21, (int) $database->query('SELECT COUNT(id) FROM technical_sources')->fetchColumn(), 'Nie dodano źródeł obu profili.');
assert_same(15, (int) $database->query('SELECT COUNT(id) FROM technical_sources WHERE profile_key = "popular_science" AND is_active = 1')->fetchColumn(), 'Nie aktywowano źródeł popularnonaukowych.');
assert_same(0, (int) $database->query('SELECT COUNT(id) FROM technical_sources WHERE profile_key = "developer" AND is_active = 1')->fetchColumn(), 'Źródła deweloperskie pozostały aktywne.');
assert_same(8, (int) $database->query('SELECT COUNT(slug) FROM editorial_profile_categories WHERE is_active = 1')->fetchColumn(), 'Nie dodano kategorii profilu popularnonaukowego.');
assert_same(0, (int) $database->query('SELECT COUNT(id) FROM discovered_feed_items')->fetchColumn(), 'Migracja utworzyła fikcyjne wpisy feedu.');
assert_same(1, (int) $database->query('SELECT COUNT(id) FROM authors')->fetchColumn(), 'Domyślny autor został powielony.');
assert_same(2, (int) $database->query('SELECT COUNT(id) FROM post_status_history')->fetchColumn(), 'Nie zapisano początkowej historii statusów.');

echo "EDITORIAL_SCHEMA_SMOKE_OK\n";
