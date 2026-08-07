<?php

declare(strict_types=1);

require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/editorial-schema.php';
require_once __DIR__ . '/editorial-repository.php';
require_once __DIR__ . '/publication-service.php';
require_once __DIR__ . '/discovery-service.php';
require_once __DIR__ . '/editorial-editor-service.php';
require_once __DIR__ . '/scheduled-publication-service.php';
require_once __DIR__ . '/technical-source-repository.php';
require_once __DIR__ . '/editorial-profile-service.php';
require_once __DIR__ . '/feed-ingestion-service.php';
require_once __DIR__ . '/source-enrichment-service.php';
require_once __DIR__ . '/topic-grouping-service.php';
require_once __DIR__ . '/topic-scoring-service.php';
require_once __DIR__ . '/topic-trash-service.php';
require_once __DIR__ . '/content-studio-service.php';
require_once __DIR__ . '/generation-service.php';
require_once __DIR__ . '/article-image-service.php';
require_once __DIR__ . '/advertising.php';
require_once __DIR__ . '/research-package-service.php';
require_once __DIR__ . '/narrative-plan-service.php';
require_once __DIR__ . '/article-draft-service.php';
require_once __DIR__ . '/quality-check-service.php';
require_once __DIR__ . '/proposal-review-service.php';
require_once __DIR__ . '/thumbnail-service.php';
require_once __DIR__ . '/repair-router-service.php';
require_once __DIR__ . '/salvage-service.php';
require_once __DIR__ . '/generation-batch-service.php';
require_once __DIR__ . '/trust-pages-service.php';

if (!defined('CMS_DATABASE_FILE')) {
    $testDatabase = PHP_SAPI === 'cli' ? trim((string) getenv('CMS_TEST_DATABASE_FILE')) : '';
    define('CMS_DATABASE_FILE', $testDatabase !== '' ? $testDatabase : dirname(__DIR__) . '/data/cms.sqlite');
}

function bueno_database(): PDO
{
    static $database = null;

    if ($database instanceof PDO) {
        return $database;
    }

    $directory = dirname(CMS_DATABASE_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu danych.');
    }

    $database = new PDO('sqlite:' . CMS_DATABASE_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('PRAGMA journal_mode = WAL');
    $database->exec('PRAGMA foreign_keys = ON');

    $database->exec(
        'CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            subject TEXT NOT NULL DEFAULT "",
            message TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "new",
            is_read INTEGER NOT NULL DEFAULT 0,
            is_important INTEGER NOT NULL DEFAULT 0,
            email_sent INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reply_body TEXT,
            replied_at TEXT
        );
        CREATE INDEX IF NOT EXISTS messages_created_at_idx ON messages(created_at DESC);
        CREATE TABLE IF NOT EXISTS cats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            image_path TEXT NOT NULL,
            image_crop TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT
        );
        CREATE INDEX IF NOT EXISTS cats_sort_order_idx ON cats(sort_order, id);
        CREATE TABLE IF NOT EXISTS galleries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            slug TEXT NOT NULL UNIQUE,
            mobile_two_up INTEGER NOT NULL DEFAULT 0,
            tile_view INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT
        );
        CREATE INDEX IF NOT EXISTS galleries_created_at_idx ON galleries(created_at DESC);
        CREATE TABLE IF NOT EXISTS gallery_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gallery_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            image_path TEXT NOT NULL,
            image_crop TEXT NOT NULL DEFAULT "",
            image_crop_mobile TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT,
            FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS gallery_items_sort_order_idx ON gallery_items(gallery_id, sort_order, id);'
    );

    $database->exec(
        'CREATE TABLE IF NOT EXISTS post_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            slug TEXT NOT NULL UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT
        );
        CREATE INDEX IF NOT EXISTS post_categories_created_at_idx ON post_categories(created_at DESC);
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            excerpt TEXT NOT NULL DEFAULT "",
            content TEXT NOT NULL DEFAULT "",
            image_path TEXT NOT NULL DEFAULT "",
            slug TEXT NOT NULL DEFAULT "",
            is_published INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT,
            FOREIGN KEY (category_id) REFERENCES post_categories(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS posts_category_created_at_idx ON posts(category_id, created_at DESC);'
    );

    ensure_message_trash_column($database);
    ensure_message_read_column($database);
    ensure_message_important_column($database);
    ensure_message_subject_column($database);
    purge_expired_trashed_messages($database);
    seed_initial_cats($database);
    seed_initial_posts($database);
    ensure_gallery_sort_order_column($database);
    ensure_gallery_mobile_layout_column($database);
    ensure_gallery_tile_view_column($database);
    ensure_gallery_item_crop_column($database);
    ensure_post_category_sort_order_column($database);
    ensure_post_slug_column($database);
    ensure_post_extra_columns($database);
    ensure_content_trash_columns($database);
    run_schema_migrations($database);
    purge_expired_trashed_content($database);
    seed_contact_settings($database);
    seed_social_media($database);
    ensure_site_style_settings($database);

    return $database;
}

function ensure_post_extra_columns(PDO $database): void
{
    $columns = array_column($database->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
    if (!in_array('content_image_path', $columns, true)) {
        $database->exec("ALTER TABLE posts ADD COLUMN content_image_path TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('gallery_id', $columns, true)) {
        $database->exec("ALTER TABLE posts ADD COLUMN gallery_id INTEGER");
    }
    if (!in_array('content_images', $columns, true)) {
        $database->exec("ALTER TABLE posts ADD COLUMN content_images TEXT NOT NULL DEFAULT '[]'");
    }
    if (!in_array('image_fit', $columns, true)) {
        $database->exec("ALTER TABLE posts ADD COLUMN image_fit TEXT NOT NULL DEFAULT 'cover'");
    }
    if (!in_array('image_crop', $columns, true)) {
        $database->exec("ALTER TABLE posts ADD COLUMN image_crop TEXT NOT NULL DEFAULT ''");
    }

    // Migrate the original single inline image into the new ordered list.
    $legacyRows = $database->query(
        "SELECT id, content_image_path FROM posts
         WHERE content_image_path <> '' AND (content_images IS NULL OR content_images = '' OR content_images = '[]')"
    )->fetchAll();
    if ($legacyRows !== []) {
        $statement = $database->prepare('UPDATE posts SET content_images = :content_images WHERE id = :id');
        foreach ($legacyRows as $legacyRow) {
            $statement->execute([
                ':id' => (int) $legacyRow['id'],
                ':content_images' => json_encode([(string) $legacyRow['content_image_path']], JSON_UNESCAPED_SLASHES),
            ]);
        }
    }
}

function normalize_post_crop(mixed $crop): array
{
    if (is_string($crop)) {
        $decoded = json_decode($crop, true);
        $crop = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($crop)) {
        $crop = [];
    }

    $x = max(0.0, min(0.95, (float) ($crop['x'] ?? 0)));
    $y = max(0.0, min(0.95, (float) ($crop['y'] ?? 0)));
    $width = max(0.05, min(1.0 - $x, (float) ($crop['width'] ?? 1)));
    $height = max(0.05, min(1.0 - $y, (float) ($crop['height'] ?? 1)));
    $imageWidth = max(0, (int) ($crop['imageWidth'] ?? 0));
    $imageHeight = max(0, (int) ($crop['imageHeight'] ?? 0));

    return [
        'x' => round($x, 6),
        'y' => round($y, 6),
        'width' => round($width, 6),
        'height' => round($height, 6),
        'imageWidth' => $imageWidth,
        'imageHeight' => $imageHeight,
    ];
}

function post_content_image_items(array $post): array
{
    $decoded = json_decode((string) ($post['content_images'] ?? ''), true);
    $items = [];

    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = [
                    'path' => ltrim(str_replace('\\', '/', trim($item)), '/'),
                    'crop' => normalize_post_crop([]),
                ];
            } elseif (is_array($item) && is_string($item['path'] ?? null) && trim($item['path']) !== '') {
                $items[] = [
                    'path' => ltrim(str_replace('\\', '/', trim($item['path'])), '/'),
                    'crop' => normalize_post_crop($item['crop'] ?? []),
                ];
            }
        }
    }

    if ($items === [] && trim((string) ($post['content_image_path'] ?? '')) !== '') {
        $items[] = [
            'path' => ltrim(str_replace('\\', '/', trim((string) $post['content_image_path'])), '/'),
            'crop' => normalize_post_crop([]),
        ];
    }

    $unique = [];
    foreach ($items as $item) {
        $unique[$item['path']] = $item;
    }

    return array_values($unique);
}

function post_content_images(array $post): array
{
    return array_column(post_content_image_items($post), 'path');
}

function post_main_image_crop(array $post): array
{
    return normalize_post_crop((string) ($post['image_crop'] ?? ''));
}

function post_image_fit(array $post): string
{
    return (string) ($post['image_fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover';
}

function seed_contact_settings(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS contact_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            address TEXT NOT NULL,
            phone TEXT NOT NULL,
            email TEXT NOT NULL,
            mail_password TEXT
        )'
    );

    $count = (int) $database->query('SELECT COUNT(*) FROM contact_settings WHERE id = 1')->fetchColumn();

    if ($count > 0) {
        return;
    }

    $statement = $database->prepare(
        'INSERT INTO contact_settings (id, address, phone, email) VALUES (1, :address, :phone, :email)'
    );
    $statement->execute([
        ':address' => '',
        ':phone' => '',
        ':email' => '',
    ]);
}

function get_contact_settings(): array
{
    $statement = bueno_database()->query(
        'SELECT address, phone, email, mail_password FROM contact_settings WHERE id = 1'
    );
    $settings = $statement->fetch();

    if (!is_array($settings)) {
        throw new RuntimeException('Nie można odczytać danych kontaktowych.');
    }

    return $settings;
}

function update_contact_settings(array $changes): void
{
    $allowedColumns = ['address', 'phone', 'email', 'mail_password'];
    $updates = [];
    $parameters = [];

    foreach ($changes as $column => $value) {
        if (!in_array($column, $allowedColumns, true) || $value === null) {
            continue;
        }

        $updates[] = $column . ' = :' . $column;
        $parameters[':' . $column] = $value;
    }

    if ($updates === []) {
        return;
    }

    $statement = bueno_database()->prepare(
        'UPDATE contact_settings SET ' . implode(', ', $updates) . ' WHERE id = 1'
    );
    $statement->execute($parameters);
}

function site_style_definitions(): array
{
    return [
        'site_name' => [
            'group' => 'Tożsamość',
            'label' => 'Nazwa strony',
            'type' => 'text',
            'default' => (string) app_config('site_name'),
            'max' => 80,
            'help' => 'Wyświetlana w intro, nagłówku i stopce.',
        ],
        'site_tagline' => [
            'group' => 'Tożsamość',
            'label' => 'Krótki opis',
            'type' => 'text',
            'default' => 'Miejsce na krótki opis strony',
            'max' => 180,
        ],
        'copyright_text' => [
            'group' => 'Tożsamość',
            'label' => 'Tekst w stopce',
            'type' => 'text',
            'default' => 'Wszelkie prawa zastrzeżone.',
            'max' => 220,
        ],
        'page_background' => ['group' => 'Kolory', 'label' => 'Tło strony', 'type' => 'color', 'default' => '#e9edf1'],
        'surface' => ['group' => 'Kolory', 'label' => 'Tło treści', 'type' => 'color', 'default' => '#ffffff'],
        'surface_alt' => ['group' => 'Kolory', 'label' => 'Tło drugoplanowe', 'type' => 'color', 'default' => '#f5f7f9'],
        'text_color' => ['group' => 'Kolory', 'label' => 'Tekst główny', 'type' => 'color', 'default' => '#26313a'],
        'heading_color' => ['group' => 'Kolory', 'label' => 'Nagłówki', 'type' => 'color', 'default' => '#1f2933'],
        'muted_color' => ['group' => 'Kolory', 'label' => 'Tekst pomocniczy', 'type' => 'color', 'default' => '#66737f'],
        'accent_color' => ['group' => 'Kolory', 'label' => 'Kolor akcentu', 'type' => 'color', 'default' => '#526576'],
        'accent_hover' => ['group' => 'Kolory', 'label' => 'Akcent po najechaniu', 'type' => 'color', 'default' => '#34495a'],
        'accent_contrast' => ['group' => 'Kolory', 'label' => 'Tekst na akcencie', 'type' => 'color', 'default' => '#ffffff'],
        'border_color' => ['group' => 'Kolory', 'label' => 'Obramowania', 'type' => 'color', 'default' => '#d5dce2'],
        'nav_background' => ['group' => 'Kolory', 'label' => 'Tło nawigacji', 'type' => 'color', 'default' => '#25313b'],
        'nav_text' => ['group' => 'Kolory', 'label' => 'Tekst nawigacji', 'type' => 'color', 'default' => '#ffffff'],
        'footer_background' => ['group' => 'Kolory', 'label' => 'Tło stopki', 'type' => 'color', 'default' => '#eef2f5'],
        'footer_text' => ['group' => 'Kolory', 'label' => 'Tekst stopki', 'type' => 'color', 'default' => '#34424e'],
        'input_background' => ['group' => 'Kolory', 'label' => 'Tło pól formularza', 'type' => 'color', 'default' => '#ffffff'],
        'hero_background' => ['group' => 'Kolory', 'label' => 'Tło intro', 'type' => 'color', 'default' => '#dfe5ea'],
        'hero_text' => ['group' => 'Kolory', 'label' => 'Tekst intro', 'type' => 'color', 'default' => '#1f2933'],
        'body_font' => [
            'group' => 'Typografia',
            'label' => 'Krój tekstu',
            'type' => 'select',
            'default' => 'system',
            'options' => [
                'system' => 'Systemowy bezszeryfowy',
                'serif' => 'Klasyczny szeryfowy',
                'humanist' => 'Humanistyczny bezszeryfowy',
                'mono' => 'Monospace',
            ],
        ],
        'heading_font' => [
            'group' => 'Typografia',
            'label' => 'Krój nagłówków',
            'type' => 'select',
            'default' => 'system',
            'options' => [
                'system' => 'Systemowy bezszeryfowy',
                'serif' => 'Klasyczny szeryfowy',
                'display' => 'Wyrazisty szeryfowy',
                'mono' => 'Monospace',
            ],
        ],
        'base_font_size' => ['group' => 'Typografia', 'label' => 'Bazowy rozmiar tekstu', 'type' => 'number', 'default' => '16', 'min' => 12, 'max' => 22, 'step' => 1, 'unit' => 'px'],
        'line_height' => ['group' => 'Typografia', 'label' => 'Interlinia', 'type' => 'number', 'default' => '1.7', 'min' => 1.2, 'max' => 2.2, 'step' => 0.05, 'unit' => ''],
        'heading_weight' => [
            'group' => 'Typografia',
            'label' => 'Grubość nagłówków',
            'type' => 'select',
            'default' => '700',
            'options' => ['500' => 'Średnia', '600' => 'Półgruba', '700' => 'Gruba', '800' => 'Bardzo gruba'],
        ],
        'content_width' => ['group' => 'Układ', 'label' => 'Maksymalna szerokość treści', 'type' => 'number', 'default' => '1180', 'min' => 720, 'max' => 1800, 'step' => 10, 'unit' => 'px'],
        'section_spacing' => ['group' => 'Układ', 'label' => 'Odstęp sekcji', 'type' => 'number', 'default' => '64', 'min' => 24, 'max' => 140, 'step' => 4, 'unit' => 'px'],
        'border_radius' => ['group' => 'Układ', 'label' => 'Zaokrąglenie elementów', 'type' => 'number', 'default' => '6', 'min' => 0, 'max' => 36, 'step' => 1, 'unit' => 'px'],
        'nav_height' => ['group' => 'Układ', 'label' => 'Wysokość nawigacji', 'type' => 'number', 'default' => '64', 'min' => 48, 'max' => 96, 'step' => 2, 'unit' => 'px'],
        'hero_height' => ['group' => 'Układ', 'label' => 'Wysokość intro', 'type' => 'number', 'default' => '46', 'min' => 24, 'max' => 100, 'step' => 1, 'unit' => 'vh'],
        'card_shadow' => [
            'group' => 'Układ',
            'label' => 'Cień kart',
            'type' => 'select',
            'default' => 'subtle',
            'options' => ['none' => 'Brak', 'subtle' => 'Subtelny', 'medium' => 'Średni', 'strong' => 'Wyraźny'],
        ],
        'background_image' => [
            'group' => 'Tło i ruch',
            'label' => 'Adres obrazu tła',
            'type' => 'url',
            'default' => '',
            'max' => 500,
            'help' => 'Pełny adres https:// albo ścieżka /images/nazwa-pliku.webp.',
        ],
        'background_position' => [
            'group' => 'Tło i ruch',
            'label' => 'Pozycja obrazu tła',
            'type' => 'select',
            'default' => 'center',
            'options' => ['center' => 'Środek', 'top' => 'Góra', 'bottom' => 'Dół', 'left' => 'Lewa strona', 'right' => 'Prawa strona'],
        ],
        'background_size' => [
            'group' => 'Tło i ruch',
            'label' => 'Dopasowanie obrazu tła',
            'type' => 'select',
            'default' => 'cover',
            'options' => ['cover' => 'Wypełnij', 'contain' => 'Pokaż w całości', 'auto' => 'Rozmiar naturalny'],
        ],
        'animations_enabled' => [
            'group' => 'Tło i ruch',
            'label' => 'Animacje i przejścia',
            'type' => 'checkbox',
            'default' => '1',
        ],
        'transition_duration' => ['group' => 'Tło i ruch', 'label' => 'Czas przejść', 'type' => 'number', 'default' => '180', 'min' => 0, 'max' => 1000, 'step' => 10, 'unit' => 'ms'],
        'custom_css' => [
            'group' => 'Zaawansowane',
            'label' => 'Własny CSS',
            'type' => 'textarea',
            'default' => '',
            'max' => 20000,
            'help' => 'Ładowany na końcu motywu. Pozwala dostosować każdy selektor strony publicznej.',
        ],
    ];
}

function ensure_site_style_settings(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS site_style_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL DEFAULT "",
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $statement = $database->prepare(
        'INSERT OR IGNORE INTO site_style_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)'
    );
    foreach (site_style_definitions() as $key => $definition) {
        $statement->execute([
            ':setting_key' => $key,
            ':setting_value' => (string) $definition['default'],
        ]);
    }
}

function get_site_style_settings(): array
{
    $settings = [];
    foreach (site_style_definitions() as $key => $definition) {
        $settings[$key] = (string) $definition['default'];
    }

    $rows = bueno_database()->query('SELECT setting_key, setting_value FROM site_style_settings')->fetchAll();
    foreach ($rows as $row) {
        $key = (string) ($row['setting_key'] ?? '');
        if (array_key_exists($key, $settings)) {
            $settings[$key] = (string) ($row['setting_value'] ?? '');
        }
    }

    return $settings;
}

function validate_site_style_value(string $key, mixed $rawValue): string
{
    $definitions = site_style_definitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('Nieznane ustawienie wyglądu.');
    }

    $definition = $definitions[$key];
    $type = (string) $definition['type'];
    $value = is_string($rawValue) ? $rawValue : '';

    if ($type === 'checkbox') {
        return $value === '1' ? '1' : '0';
    }
    if ($type === 'color') {
        $value = strtolower(trim($value));
        if (!preg_match('/^#[0-9a-f]{6}$/', $value)) {
            throw new InvalidArgumentException('Kolory podaj w formacie #RRGGBB.');
        }
        return $value;
    }
    if ($type === 'number') {
        $value = str_replace(',', '.', trim($value));
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Jedna z wartości liczbowych jest nieprawidłowa.');
        }
        $number = max((float) $definition['min'], min((float) $definition['max'], (float) $value));
        return abs($number - round($number)) < 0.00001 ? (string) (int) round($number) : rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
    if ($type === 'select') {
        if (!array_key_exists($value, (array) $definition['options'])) {
            throw new InvalidArgumentException('Wybrano nieprawidłową opcję wyglądu.');
        }
        return $value;
    }
    if ($type === 'url') {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $isWebUrl = filter_var($value, FILTER_VALIDATE_URL)
            && in_array((string) parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
        $isLocalPath = preg_match('#^(?:/|images/|assets/)[a-zA-Z0-9_./% -]+$#', $value) === 1;
        if (!$isWebUrl && !$isLocalPath) {
            throw new InvalidArgumentException('Podaj poprawny adres obrazu tła.');
        }
    }

    $maximum = (int) ($definition['max'] ?? 500);
    if (strlen($value) > $maximum) {
        throw new InvalidArgumentException('Jedna z wartości jest zbyt długa.');
    }

    return $type === 'textarea' ? $value : trim($value);
}

function update_site_style_settings(array $changes): void
{
    $database = bueno_database();
    $statement = $database->prepare(
        'INSERT INTO site_style_settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, CURRENT_TIMESTAMP)
         ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP'
    );

    $database->beginTransaction();
    try {
        foreach ($changes as $key => $rawValue) {
            if (!is_string($key) || !array_key_exists($key, site_style_definitions())) {
                continue;
            }
            $statement->execute([
                ':setting_key' => $key,
                ':setting_value' => validate_site_style_value($key, $rawValue),
            ]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }
}

function reset_site_style_settings(): void
{
    $database = bueno_database();
    $database->exec('DELETE FROM site_style_settings');
    ensure_site_style_settings($database);
}

function seed_social_media(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS social_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            url TEXT NOT NULL DEFAULT "",
            icon_path TEXT NOT NULL DEFAULT "",
            icon_class TEXT NOT NULL DEFAULT "",
            is_visible INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS cms_meta (
            meta_key TEXT PRIMARY KEY,
            meta_value TEXT NOT NULL DEFAULT ""
        )'
    );

    $seeded = $database->query(
        "SELECT COUNT(*) FROM cms_meta WHERE meta_key = 'social_media_seeded'"
    )->fetchColumn();
    if ((int) $seeded > 0) {
        return;
    }

    $defaults = [];
    $statement = $database->prepare(
        'INSERT OR IGNORE INTO social_media (name, slug, icon_class, sort_order)
         VALUES (:name, :slug, :icon_class, :sort_order)'
    );

    foreach ($defaults as [$name, $slug, $iconClass, $sortOrder]) {
        $statement->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':icon_class' => $iconClass,
            ':sort_order' => $sortOrder,
        ]);
    }

    $database->exec(
        "INSERT OR REPLACE INTO cms_meta (meta_key, meta_value)
         VALUES ('social_media_seeded', '1')"
    );
}

function list_social_media(bool $visibleOnly = false): array
{
    $sql = 'SELECT id, name, slug, url, icon_path, icon_class, is_visible, sort_order
            FROM social_media';

    if ($visibleOnly) {
        $sql .= ' WHERE is_visible = 1';
    }

    $sql .= ' ORDER BY sort_order ASC, id ASC';

    return bueno_database()->query($sql)->fetchAll();
}

function find_social_medium(int $socialId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT id, name, slug, url, icon_path, icon_class, is_visible, sort_order
         FROM social_media WHERE id = :id'
    );
    $statement->execute([':id' => $socialId]);
    $social = $statement->fetch();

    return is_array($social) ? $social : null;
}

function unique_social_slug(PDO $database, string $name): string
{
    $baseSlug = gallery_slug_from_title($name);

    if ($baseSlug === 'nowa-galeria') {
        $baseSlug = 'social-media';
    }

    $slug = $baseSlug;
    $suffix = 2;

    do {
        $statement = $database->prepare('SELECT COUNT(*) FROM social_media WHERE slug = :slug');
        $statement->execute([':slug' => $slug]);

        if ((int) $statement->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    } while (true);
}

function create_social_medium(string $name, string $url, bool $isVisible, string $iconPath): int
{
    $database = bueno_database();
    $sortOrder = (int) $database->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM social_media')->fetchColumn();
    $statement = $database->prepare(
        'INSERT INTO social_media (name, slug, url, icon_path, icon_class, is_visible, sort_order, updated_at)
         VALUES (:name, :slug, :url, :icon_path, "", :is_visible, :sort_order, CURRENT_TIMESTAMP)'
    );
    $statement->execute([
        ':name' => $name,
        ':slug' => unique_social_slug($database, $name),
        ':url' => $url,
        ':icon_path' => $iconPath,
        ':is_visible' => $isVisible ? 1 : 0,
        ':sort_order' => $sortOrder,
    ]);

    return (int) $database->lastInsertId();
}

function update_social_medium(int $socialId, string $name, string $url, bool $isVisible, ?string $iconPath = null): void
{
    $updates = ['name = :name', 'url = :url', 'is_visible = :is_visible', 'updated_at = CURRENT_TIMESTAMP'];
    $parameters = [
        ':id' => $socialId,
        ':name' => $name,
        ':url' => $url,
        ':is_visible' => $isVisible ? 1 : 0,
    ];

    if ($iconPath !== null) {
        $updates[] = 'icon_path = :icon_path';
        $parameters[':icon_path'] = $iconPath;
    }

    $statement = bueno_database()->prepare(
        'UPDATE social_media SET ' . implode(', ', $updates) . ' WHERE id = :id'
    );
    $statement->execute($parameters);
}

function delete_social_medium(int $socialId): ?array
{
    $database = bueno_database();
    $social = find_social_medium($socialId);
    if ($social === null) {
        return null;
    }

    $statement = $database->prepare('DELETE FROM social_media WHERE id = :id');
    $statement->execute([':id' => $socialId]);

    return $social;
}

function ensure_message_trash_column(PDO $database): void
{
    $columns = $database->query('PRAGMA table_info(messages)')->fetchAll();
    $hasDeletedAt = false;

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'deleted_at') {
            $hasDeletedAt = true;
            break;
        }
    }

    if (!$hasDeletedAt) {
        $database->exec('ALTER TABLE messages ADD COLUMN deleted_at TEXT');
    }
}

function ensure_message_read_column(PDO $database): void
{
    $columns = $database->query('PRAGMA table_info(messages)')->fetchAll();

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'is_read') {
            return;
        }
    }

    $database->exec('ALTER TABLE messages ADD COLUMN is_read INTEGER NOT NULL DEFAULT 0');
}

function ensure_message_important_column(PDO $database): void
{
    $columns = $database->query('PRAGMA table_info(messages)')->fetchAll();

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'is_important') {
            return;
        }
    }

    $database->exec('ALTER TABLE messages ADD COLUMN is_important INTEGER NOT NULL DEFAULT 0');
}

function ensure_message_subject_column(PDO $database): void
{
    $columns = $database->query('PRAGMA table_info(messages)')->fetchAll();

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'subject') {
            return;
        }
    }

    $database->exec('ALTER TABLE messages ADD COLUMN subject TEXT NOT NULL DEFAULT ""');
}

function ensure_content_trash_columns(PDO $database): void
{
    foreach (['cats', 'galleries', 'gallery_items', 'post_categories', 'posts'] as $table) {
        $columns = $database->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        $hasDeletedAt = false;

        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'deleted_at') {
                $hasDeletedAt = true;
                break;
            }
        }

        if (!$hasDeletedAt) {
            $database->exec('ALTER TABLE ' . $table . ' ADD COLUMN deleted_at TEXT');
        }
    }
}

function ensure_gallery_sort_order_column(PDO $database): void
{
    $columns = $database->query('PRAGMA table_info(galleries)')->fetchAll();

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'sort_order') {
            return;
        }
    }

    $database->exec('ALTER TABLE galleries ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
}

function ensure_gallery_mobile_layout_column(PDO $database): void
{
    $columns = array_column($database->query('PRAGMA table_info(galleries)')->fetchAll(), 'name');

    if (!in_array('mobile_two_up', $columns, true)) {
        $database->exec('ALTER TABLE galleries ADD COLUMN mobile_two_up INTEGER NOT NULL DEFAULT 0');
    }
}

function ensure_gallery_tile_view_column(PDO $database): void
{
    $columns = array_column($database->query('PRAGMA table_info(galleries)')->fetchAll(), 'name');

    if (!in_array('tile_view', $columns, true)) {
        $database->exec('ALTER TABLE galleries ADD COLUMN tile_view INTEGER NOT NULL DEFAULT 0');
    }
}

function ensure_gallery_item_crop_column(PDO $database): void
{
    $columns = array_column($database->query('PRAGMA table_info(gallery_items)')->fetchAll(), 'name');

    if (!in_array('image_crop', $columns, true)) {
        $database->exec("ALTER TABLE gallery_items ADD COLUMN image_crop TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('image_crop_mobile', $columns, true)) {
        $database->exec("ALTER TABLE gallery_items ADD COLUMN image_crop_mobile TEXT NOT NULL DEFAULT ''");
    }
}

function ensure_post_category_sort_order_column(PDO $database): void
{
    $columns = $database->query('PRAGMA table_info(post_categories)')->fetchAll();

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'sort_order') {
            return;
        }
    }

    $database->exec('ALTER TABLE post_categories ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
}

function ensure_post_slug_column(PDO $database): void
{
    $columns = $database->query('PRAGMA table_info(posts)')->fetchAll();
    $hasSlug = false;

    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'slug') {
            $hasSlug = true;
            break;
        }
    }

    if (!$hasSlug) {
        $database->exec('ALTER TABLE posts ADD COLUMN slug TEXT NOT NULL DEFAULT ""');
    }

    $posts = $database->query('SELECT id, title, slug FROM posts ORDER BY id ASC')->fetchAll();

    foreach ($posts as $post) {
        if ((string) $post['slug'] !== '') {
            continue;
        }

        $statement = $database->prepare('UPDATE posts SET slug = :slug WHERE id = :id');
        $statement->execute([
            ':id' => (int) $post['id'],
            ':slug' => unique_post_slug($database, (string) $post['title'], (int) $post['id']),
        ]);
    }

    $database->exec('CREATE UNIQUE INDEX IF NOT EXISTS posts_slug_idx ON posts(slug)');
}

function purge_expired_trashed_messages(PDO $database): void
{
    $statement = $database->prepare(
        'DELETE FROM messages
         WHERE status = "trash"
           AND deleted_at IS NOT NULL
           AND datetime(deleted_at) <= datetime("now", "-15 days")'
    );
    $statement->execute();
}

function purge_expired_trashed_content(PDO $database): void
{
    $cutoff = 'datetime("now", "-15 days")';

    foreach ($database->query('SELECT id FROM posts WHERE deleted_at IS NOT NULL AND datetime(deleted_at) <= ' . $cutoff)->fetchAll() as $row) {
        permanently_delete_post((int) $row['id']);
    }

    foreach ($database->query('SELECT id FROM post_categories WHERE deleted_at IS NOT NULL AND datetime(deleted_at) <= ' . $cutoff)->fetchAll() as $row) {
        permanently_delete_post_category((int) $row['id']);
    }

    foreach ($database->query('SELECT id FROM gallery_items WHERE deleted_at IS NOT NULL AND datetime(deleted_at) <= ' . $cutoff)->fetchAll() as $row) {
        permanently_delete_gallery_item((int) $row['id']);
    }

    foreach ($database->query('SELECT id FROM galleries WHERE deleted_at IS NOT NULL AND datetime(deleted_at) <= ' . $cutoff)->fetchAll() as $row) {
        permanently_delete_gallery((int) $row['id']);
    }

    foreach ($database->query('SELECT id FROM cats WHERE deleted_at IS NOT NULL AND datetime(deleted_at) <= ' . $cutoff)->fetchAll() as $row) {
        permanently_delete_cat((int) $row['id']);
    }
}

function seed_initial_cats(PDO $database): void
{
    $count = (int) $database->query('SELECT COUNT(*) FROM cats')->fetchColumn();

    if ($count > 0) {
        return;
    }

    // Koty są dodawane przez panel administratora. Nie wstawiamy danych
    // przykładowych odziedziczonych po poprzedniej stronie.
    $initialCats = [];

    $statement = $database->prepare(
        'INSERT INTO cats (name, description, image_path, sort_order) VALUES (:name, :description, :image_path, :sort_order)'
    );

    foreach ($initialCats as $index => [$name, $description, $imagePath]) {
        $statement->execute([
            ':name' => $name,
            ':description' => $description,
            ':image_path' => $imagePath,
            ':sort_order' => $index + 1,
        ]);
    }
}

function save_contact_message(string $name, string $email, string $subject, string $message): int
{
    $statement = bueno_database()->prepare(
        'INSERT INTO messages (name, email, subject, message) VALUES (:name, :email, :subject, :message)'
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':subject' => $subject,
        ':message' => $message,
    ]);

    return (int) bueno_database()->lastInsertId();
}

function mark_contact_email_sent(int $messageId): void
{
    $statement = bueno_database()->prepare('UPDATE messages SET email_sent = 1 WHERE id = :id');
    $statement->execute([':id' => $messageId]);
}

function find_message(int $messageId): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM messages WHERE id = :id');
    $statement->execute([':id' => $messageId]);
    $message = $statement->fetch();

    return is_array($message) ? $message : null;
}

function list_messages(string $folder = 'new', bool $showUnread = true, bool $showRead = true, bool $importantOnly = false): array
{
    $folders = ['new', 'replied', 'trash'];

    if (!in_array($folder, $folders, true)) {
        $folder = 'new';
    }

    $orderColumn = $folder === 'trash' ? 'deleted_at' : 'created_at';
    $conditions = 'status = :status';
    $parameters = [':status' => $folder];

    if ($folder === 'new') {
        $readStates = [];

        if ($showUnread) {
            $readStates[] = '0';
        }

        if ($showRead) {
            $readStates[] = '1';
        }

        $conditions = $readStates === []
            ? 'status = "new"'
            : 'status = "new" AND is_read IN (' . implode(', ', $readStates) . ')';
        $parameters = [];
    }

    if ($importantOnly) {
        $conditions .= ' AND is_important = 1';
    }

    $statement = bueno_database()->prepare(
        'SELECT * FROM messages WHERE ' . $conditions . ' ORDER BY datetime(' . $orderColumn . ') DESC, id DESC'
    );
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function mark_message_as_read(int $messageId): void
{
    $statement = bueno_database()->prepare(
        'UPDATE messages SET is_read = 1 WHERE id = :id AND status = "new"'
    );
    $statement->execute([':id' => $messageId]);
}

function toggle_message_important(int $messageId): void
{
    $statement = bueno_database()->prepare(
        'UPDATE messages
         SET is_important = CASE WHEN is_important = 1 THEN 0 ELSE 1 END
         WHERE id = :id'
    );
    $statement->execute([':id' => $messageId]);
}

function save_message_reply(int $messageId, string $reply): void
{
    $statement = bueno_database()->prepare(
        'UPDATE messages
         SET status = "replied", is_read = 1, reply_body = :reply, replied_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':id' => $messageId,
        ':reply' => $reply,
    ]);
}

function move_message_to_trash(int $messageId): void
{
    $statement = bueno_database()->prepare(
        'UPDATE messages SET status = "trash", deleted_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $statement->execute([':id' => $messageId]);
}

function move_message_to_folder(int $messageId, string $folder): void
{
    if (!in_array($folder, ['unread', 'read', 'replied', 'trash'], true)) {
        return;
    }

    if ($folder === 'unread' || $folder === 'read') {
        $statement = bueno_database()->prepare(
            'UPDATE messages SET status = "new", is_read = :is_read, deleted_at = NULL WHERE id = :id'
        );
        $statement->execute([':is_read' => $folder === 'read' ? 1 : 0, ':id' => $messageId]);
        return;
    }

    if ($folder === 'trash') {
        move_message_to_trash($messageId);
        return;
    }

    $statement = bueno_database()->prepare(
        'UPDATE messages SET status = :status, deleted_at = NULL WHERE id = :id'
    );
    $statement->execute([':status' => $folder, ':id' => $messageId]);
}

function restore_message_from_trash(int $messageId): void
{
    $message = find_message($messageId);

    if ($message === null || $message['status'] !== 'trash') {
        return;
    }

    $status = !empty($message['reply_body']) ? 'replied' : 'new';
    $statement = bueno_database()->prepare(
        'UPDATE messages SET status = :status, deleted_at = NULL WHERE id = :id'
    );
    $statement->execute([':status' => $status, ':id' => $messageId]);
}

function permanently_delete_message(int $messageId): void
{
    $statement = bueno_database()->prepare('DELETE FROM messages WHERE id = :id AND status = "trash"');
    $statement->execute([':id' => $messageId]);
}

function list_cats(bool $includeDeleted = false): array
{
    $where = $includeDeleted ? '' : ' WHERE deleted_at IS NULL';
    return bueno_database()
        ->query('SELECT * FROM cats' . $where . ' ORDER BY sort_order ASC, id ASC')
        ->fetchAll();
}

function find_cat(int $catId, bool $includeDeleted = false): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM cats WHERE id = :id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'));
    $statement->execute([':id' => $catId]);
    $cat = $statement->fetch();

    return is_array($cat) ? $cat : null;
}

function create_cat(string $name, string $description, string $imagePath): void
{
    $nextOrder = (int) bueno_database()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cats')->fetchColumn();
    $statement = bueno_database()->prepare(
        'INSERT INTO cats (name, description, image_path, sort_order) VALUES (:name, :description, :image_path, :sort_order)'
    );
    $statement->execute([
        ':name' => $name,
        ':description' => $description,
        ':image_path' => $imagePath,
        ':sort_order' => $nextOrder,
    ]);
}

function update_cat(int $catId, string $name, string $description, ?string $imagePath = null): void
{
    $sql = 'UPDATE cats SET name = :name, description = :description';
    $parameters = [':id' => $catId, ':name' => $name, ':description' => $description];

    if ($imagePath !== null) {
        $sql .= ', image_path = :image_path';
        $parameters[':image_path'] = $imagePath;
    }

    $sql .= ' WHERE id = :id';
    $statement = bueno_database()->prepare($sql);
    $statement->execute($parameters);
}

function delete_cat(int $catId): ?array
{
    $cat = find_cat($catId);

    if ($cat === null) {
        return null;
    }

    $statement = bueno_database()->prepare('UPDATE cats SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id');
    $statement->execute([':id' => $catId]);

    return $cat;
}

function trash_all_cats(): int
{
    $statement = bueno_database()->prepare('UPDATE cats SET deleted_at = CURRENT_TIMESTAMP WHERE deleted_at IS NULL');
    $statement->execute();
    return $statement->rowCount();
}

function restore_cat(int $catId): void
{
    $statement = bueno_database()->prepare('UPDATE cats SET deleted_at = NULL WHERE id = :id');
    $statement->execute([':id' => $catId]);
}

function permanently_delete_cat(int $catId): void
{
    $cat = find_cat($catId, true);
    if ($cat === null) return;
    $statement = bueno_database()->prepare('DELETE FROM cats WHERE id = :id AND deleted_at IS NOT NULL');
    $statement->execute([':id' => $catId]);
    $imagePath = (string) ($cat['image_path'] ?? '');
    if (str_starts_with($imagePath, 'images/cats/')) {
        $filePath = dirname(__DIR__) . '/' . $imagePath;
        if (is_file($filePath)) unlink($filePath);
    }
}

function seed_initial_posts(PDO $database): void
{
    $count = (int) $database->query('SELECT COUNT(*) FROM post_categories')->fetchColumn();

    if ($count > 0) {
        return;
    }

    // Kategorie i wpisy tworzy administrator. Czysta instalacja nie zawiera
    // przykładowych treści odziedziczonych po stronie źródłowej.
}

function post_slug_from_title(string $title): string
{
    return gallery_slug_from_title($title);
}

function unique_post_category_slug(PDO $database, string $title, int $excludeCategoryId = 0): string
{
    $baseSlug = post_slug_from_title($title);
    $slug = $baseSlug;
    $suffix = 2;

    do {
        $statement = $database->prepare('SELECT COUNT(*) FROM post_categories WHERE slug = :slug AND id != :id');
        $statement->execute([':slug' => $slug, ':id' => $excludeCategoryId]);

        if ((int) $statement->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    } while (true);
}

function unique_post_slug(PDO $database, string $title, int $excludePostId = 0): string
{
    $baseSlug = post_slug_from_title($title);
    $slug = $baseSlug;
    $suffix = 2;

    do {
        $statement = $database->prepare('SELECT COUNT(*) FROM posts WHERE slug = :slug AND id != :id');
        $statement->execute([':slug' => $slug, ':id' => $excludePostId]);

        if ((int) $statement->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    } while (true);
}

function post_page_filename(string $slug): string
{
    return 'post-' . $slug . '.html';
}

function post_page_path(string $slug): string
{
    return dirname(__DIR__) . '/pages/' . post_page_filename($slug);
}

function list_post_categories(bool $includeDeleted = false, bool $includeEditorialOnly = false): array
{
    $conditions = [];
    if (!$includeDeleted) {
        $conditions[] = 'post_categories.deleted_at IS NULL';
    }
    if (!$includeEditorialOnly && in_array('is_editorial_only', database_table_columns(bueno_database(), 'post_categories'), true)) {
        $conditions[] = 'post_categories.is_editorial_only = 0';
    }
    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    return bueno_database()->query(
        'SELECT post_categories.*, COUNT(posts.id) AS post_count
         FROM post_categories
         LEFT JOIN posts ON posts.category_id = post_categories.id AND posts.deleted_at IS NULL' .
         $where . '
         GROUP BY post_categories.id
         ORDER BY post_categories.sort_order ASC, post_categories.id ASC'
    )->fetchAll();
}

function find_post_category(int $categoryId, bool $includeDeleted = false): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM post_categories WHERE id = :id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'));
    $statement->execute([':id' => $categoryId]);
    $category = $statement->fetch();

    return is_array($category) ? $category : null;
}

function find_post_category_by_slug(string $slug, bool $includeDeleted = false): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM post_categories WHERE slug = :slug' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'));
    $statement->execute([':slug' => $slug]);
    $category = $statement->fetch();

    return is_array($category) ? $category : null;
}

function post_category_is_public(?array $category): bool
{
    return is_array($category)
        && empty($category['deleted_at'])
        && (int) ($category['is_editorial_only'] ?? 0) !== 1;
}

function create_post_category(string $title = 'Nowa kategoria', string $description = ''): int
{
    $database = bueno_database();
    $slug = unique_post_category_slug($database, $title);
    $sortOrder = (int) $database->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM post_categories')->fetchColumn();
    $statement = $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order) VALUES (:title, :description, :slug, :sort_order)'
    );
    $statement->execute([':title' => $title, ':description' => $description, ':slug' => $slug, ':sort_order' => $sortOrder]);
    sync_public_navigation();

    return (int) $database->lastInsertId();
}

function update_post_category(int $categoryId, string $title, string $description): void
{
    $database = bueno_database();
    $category = find_post_category($categoryId);

    if ($category === null) {
        throw new RuntimeException('Nie znaleziono kategorii postów.');
    }

    $slug = $title === $category['title'] ? $category['slug'] : unique_post_category_slug($database, $title, $categoryId);
    $statement = $database->prepare(
        'UPDATE post_categories
         SET title = :title, description = :description, slug = :slug, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([':id' => $categoryId, ':title' => $title, ':description' => $description, ':slug' => $slug]);
    sync_public_navigation();
}

function reorder_post_categories(array $categoryIds): void
{
    $database = bueno_database();
    $knownIds = array_map(static fn (array $category): int => (int) $category['id'], list_post_categories());
    $orderedIds = array_values(array_unique(array_map('intval', $categoryIds)));

    if (count($orderedIds) !== count($knownIds) || array_diff($knownIds, $orderedIds) !== []) {
        throw new RuntimeException('Nieprawidłowa kolejność kategorii.');
    }

    $statement = $database->prepare('UPDATE post_categories SET sort_order = :sort_order WHERE id = :id');
    $database->beginTransaction();

    try {
        foreach ($orderedIds as $index => $id) {
            $statement->execute([':id' => $id, ':sort_order' => $index + 1]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }

    sync_public_navigation();
}

function list_posts(?int $categoryId = null, bool $publishedOnly = false, bool $includeDeleted = false): array
{
    $query = 'SELECT posts.*, post_categories.title AS category_title, post_categories.slug AS category_slug,
                     post_categories.is_editorial_only AS category_is_editorial_only
              FROM posts
              INNER JOIN post_categories ON post_categories.id = posts.category_id';
    $conditions = [];
    $parameters = [];

    if ($categoryId !== null) {
        $conditions[] = 'posts.category_id = :category_id';
        $parameters[':category_id'] = $categoryId;
    }

    if ($publishedOnly) {
        $conditions[] = "posts.status = 'published'";
    }

    if (!$includeDeleted) {
        $conditions[] = 'posts.deleted_at IS NULL';
        $conditions[] = 'post_categories.deleted_at IS NULL';
    }

    if ($conditions !== []) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $query .= $publishedOnly
        ? ' ORDER BY datetime(COALESCE(posts.published_at, posts.created_at)) DESC, posts.id DESC'
        : ' ORDER BY datetime(posts.created_at) DESC, posts.id DESC';
    $statement = bueno_database()->prepare($query);
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function find_post(int $postId, bool $includeDeleted = false): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM posts WHERE id = :id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'));
    $statement->execute([':id' => $postId]);
    $post = $statement->fetch();

    return is_array($post) ? $post : null;
}

function post_meta_description(array $post): string
{
    $description = trim((string) ($post['seo_description'] ?? ''));
    if ($description === '') {
        $description = trim((string) ($post['excerpt'] ?? ''));
    }
    $description = preg_replace('/\s+/u', ' ', strip_tags($description)) ?? '';

    return mb_strlen($description) > 160 ? rtrim(mb_substr($description, 0, 157)) . '...' : $description;
}

function post_canonical_url(array $post): string
{
    $url = trim((string) ($post['canonical_url'] ?? ''));
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
        return $url;
    }

    return app_public_url('pages/' . post_page_filename((string) $post['slug']));
}

function post_absolute_image_url(array $post): string
{
    $path = ltrim(str_replace('\\', '/', (string) ($post['image_path'] ?? '')), '/');

    return $path !== '' && is_file(dirname(__DIR__) . '/' . $path) ? app_public_url($path) : '';
}

function post_display_datetime(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return ['', ''];
    }
    try {
        $date = (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) app_config('timezone')));
    } catch (Throwable) {
        return ['', ''];
    }

    return [$date->format(DateTimeInterface::ATOM), $date->format('d.m.Y, H:i')];
}

function list_related_published_posts(array $post, int $limit = 3): array
{
    $statement = bueno_database()->prepare(
        "SELECT id, title, slug FROM posts
         WHERE category_id = :category_id AND id != :post_id
           AND status = 'published' AND deleted_at IS NULL
         ORDER BY datetime(COALESCE(published_at, created_at)) DESC, id DESC LIMIT :limit"
    );
    $statement->bindValue(':category_id', (int) $post['category_id'], PDO::PARAM_INT);
    $statement->bindValue(':post_id', (int) $post['id'], PDO::PARAM_INT);
    $statement->bindValue(':limit', max(1, min(10, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function render_news_article_json_ld(array $post, ?array $category): string
{
    $canonical = post_canonical_url($post);
    $image = post_absolute_image_url($post);
    [$publishedIso] = post_display_datetime((string) ($post['published_at'] ?? $post['created_at'] ?? ''));
    [$modifiedIso] = post_display_datetime((string) ($post['content_updated_at'] ?? ''));
    $author = !empty($post['author_id']) ? find_author((int) $post['author_id']) : null;

    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => (string) $post['title'],
        'description' => post_meta_description($post),
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $canonical,
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => (string) app_config('publisher_name'),
        ],
        'inLanguage' => (string) app_config('language'),
    ];

    if (post_category_is_public($category)) {
        $data['articleSection'] = (string) $category['title'];
    }

    if ($publishedIso !== '') {
        $data['datePublished'] = $publishedIso;
        $data['dateModified'] = $modifiedIso !== '' ? $modifiedIso : $publishedIso;
    }
    if (is_array($author) && trim((string) ($author['name'] ?? '')) !== '') {
        $data['author'] = [
            '@type' => 'Person',
            'name' => (string) $author['name'],
            'url' => app_public_url('pages/' . trust_author_filename($author)),
        ];
        $profileUrl = trim((string) ($author['profile_url'] ?? ''));
        if ($profileUrl !== '' && filter_var($profileUrl, FILTER_VALIDATE_URL)) {
            $data['author']['sameAs'] = $profileUrl;
        }
    }
    if ($image !== '') {
        $data['image'] = [$image];
    }

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if (!is_string($json)) {
        throw new RuntimeException('Nie można zakodować danych strukturalnych artykułu.');
    }

    return '<script type="application/ld+json">' . $json . '</script>';
}

function render_cropped_post_image(string $imagePath, array $crop, string $className, bool $lazy = true, string $alt = ''): string
{
    $normalizedPath = ltrim(str_replace('\\', '/', $imagePath), '/');
    $absolutePath = dirname(__DIR__) . '/' . $normalizedPath;

    if ($normalizedPath === '' || !is_file($absolutePath)) {
        return '';
    }

    $crop = normalize_post_crop($crop);
    $imageWidth = (int) $crop['imageWidth'];
    $imageHeight = (int) $crop['imageHeight'];
    if ($imageWidth <= 0 || $imageHeight <= 0) {
        $imageInfo = @getimagesize($absolutePath);
        $imageWidth = max(1, (int) ($imageInfo[0] ?? 1));
        $imageHeight = max(1, (int) ($imageInfo[1] ?? 1));
    }

    $ratio = max(0.1, min(10.0, ($crop['width'] * $imageWidth) / ($crop['height'] * $imageHeight)));
    $renderWidth = 100 / $crop['width'];
    $renderLeft = -100 * $crop['x'] / $crop['width'];
    $renderTop = -100 * $crop['y'] / $crop['height'];
    $style = sprintf(
        '--crop-ratio:%.6f;--crop-image-width:%.6f%%;--crop-image-left:%.6f%%;--crop-image-top:%.6f%%',
        $ratio,
        $renderWidth,
        $renderLeft,
        $renderTop
    );
    $src = '../' . htmlspecialchars($normalizedPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $class = htmlspecialchars($className, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $alt = htmlspecialchars(trim($alt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $loading = $lazy ? ' loading="lazy"' : ' loading="eager" fetchpriority="high"';

    return '<figure class="' . $class . ' post-cropped-image" style="' . $style . '"><div class="post-cropped-image-frame"><img src="' . $src . '" alt="' . $alt
        . '" width="' . $imageWidth . '" height="' . $imageHeight . '" decoding="async"' . $loading . '></div></figure>';
}

function render_post_page_html(array $post, bool $preview = false): string
{
    $category = find_post_category((int) $post['category_id']);

    if ($category === null) {
        throw new RuntimeException('Nie można utworzyć strony postu bez kategorii.');
    }

    $template = file_get_contents(dirname(__DIR__) . '/pages/index.html');

    if ($template === false) {
        throw new RuntimeException('Nie można odczytać szablonu aktualności.');
    }
    $template = str_replace('<body class="is-preload menu-page">', '<body class="is-preload menu-page page-no-intro static-header-start">', $template);

    $title = htmlspecialchars((string) $post['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $excerpt = htmlspecialchars((string) $post['excerpt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $siteName = htmlspecialchars((string) app_config('site_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $description = htmlspecialchars(post_meta_description($post), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $canonical = htmlspecialchars(post_canonical_url($post), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $absoluteImage = htmlspecialchars(post_absolute_image_url($post), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $template = preg_replace('/\s*<meta name="description" content="[^"]*"\s*\/?>/i', '', $template, 1) ?? $template;
    $template = preg_replace('/\s*<meta name="robots" content="[^"]*"\s*\/?>/i', '', $template, 1) ?? $template;
    $head = '<title>' . $title . ' | ' . $siteName . '</title>'
        . '<meta name="description" content="' . $description . '">'
        . '<meta name="robots" content="' . ($preview ? 'noindex,nofollow,noarchive' : 'index,follow,max-image-preview:large') . '">';
    if (!$preview) {
        $head .= '<link rel="canonical" href="' . $canonical . '">'
            . '<meta property="og:type" content="article">'
            . '<meta property="og:title" content="' . $title . '">'
            . '<meta property="og:description" content="' . $description . '">'
            . '<meta property="og:url" content="' . $canonical . '">'
            . ($absoluteImage !== '' ? '<meta property="og:image" content="' . $absoluteImage . '">' : '')
            . '<meta name="twitter:card" content="' . ($absoluteImage !== '' ? 'summary_large_image' : 'summary') . '">'
            . '<meta name="twitter:title" content="' . $title . '">'
            . '<meta name="twitter:description" content="' . $description . '">'
            . ($absoluteImage !== '' ? '<meta name="twitter:image" content="' . $absoluteImage . '">' : '')
            . render_news_article_json_ld($post, post_category_is_public($category) ? $category : null);
    }
    $template = preg_replace('/<title>.*?<\/title>/s', $head, $template, 1) ?? $template;
    $contentBlocks = json_decode((string) ($post['content_blocks'] ?? '[]'), true);
    $articleImages = list_article_images((int) $post['id']);
    $adConfig = advertising_config();
    if ($preview && !empty($adConfig['enabled'])) {
        $adConfig['preview'] = true;
    }
    $adBudget = max(0, (int) ($adConfig['max_slots_per_page'] ?? 0));
    $allowedAdPlacements = (array) ($adConfig['allowed_placements'] ?? []);
    $renderPageTopAd = $adBudget > 0 && in_array('page-top', $allowedAdPlacements, true);
    $adBudget -= $renderPageTopAd ? 1 : 0;
    $renderPostArticleAd = $adBudget > 0 && in_array('post-article', $allowedAdPlacements, true);
    $adBudget -= $renderPostArticleAd ? 1 : 0;
    $adConfig['max_inline_slots'] = min((int) ($adConfig['max_inline_slots'] ?? 0), $adBudget);
    $content = is_array($contentBlocks) && $contentBlocks !== []
        ? render_article_blocks_with_advertising($contentBlocks, $articleImages, $adConfig)
        : nl2br(htmlspecialchars((string) $post['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $contentImages = post_content_image_items($post);
    $hasMainImage = trim((string) ($post['image_path'] ?? '')) !== ''
        && is_file(app_path((string) $post['image_path']));
    foreach ($contentImages as $index => $contentImageItem) {
        $contentImage = render_cropped_post_image(
            (string) $contentImageItem['path'],
            (array) $contentImageItem['crop'],
            'post-content-image',
            $hasMainImage || $index > 0
        );
        if ($contentImage === '') {
            continue;
        }
        $content = str_replace('[[Z' . ($index + 1) . ']]', $contentImage, $content);
        if ($index === 0) {
            $content = str_replace('[[ZDJECIE]]', $contentImage, $content);
        }
    }
    if (!$hasMainImage) {
        $priorityImageSeen = false;
        $content = preg_replace_callback(
            '/<img\b[^>]*\bfetchpriority="high"[^>]*>/i',
            static function (array $match) use (&$priorityImageSeen): string {
                if (!$priorityImageSeen) {
                    $priorityImageSeen = true;
                    return $match[0];
                }

                return str_replace(' loading="eager" fetchpriority="high"', ' loading="lazy"', $match[0]);
            },
            $content
        ) ?? $content;
    }
    $galleryLink = '';
    if (!empty($post['gallery_id'])) {
        $linkedGallery = find_gallery((int) $post['gallery_id']);
        if ($linkedGallery !== null) {
            $linkedGalleryTitle = htmlspecialchars((string) $linkedGallery['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $linkedGallerySlug = rawurlencode((string) $linkedGallery['slug']);
            $galleryLink = '<section class="post-linked-gallery cat-gallery" data-gallery-embedded="true" data-gallery-source="../php/gallery-items.php?gallery=' . $linkedGallerySlug . '" data-gallery-title="' . $linkedGalleryTitle . '"><header><span>Galeria</span><h2>' . $linkedGalleryTitle . '</h2><p>Ładowanie zdjęć galerii…</p></header></section>';
        }
    }
    $publicCategory = post_category_is_public($category);
    $categoryTitle = $publicCategory
        ? htmlspecialchars((string) $category['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : '';
    $categoryKicker = $categoryTitle !== ''
        ? '<p class="news-feed-kicker">' . $categoryTitle . '</p>'
        : '';
    $author = !empty($post['author_id']) ? find_author((int) $post['author_id']) : null;
    $authorName = is_array($author)
        ? htmlspecialchars((string) $author['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : '';
    [$publishedIso, $publishedLabel] = post_display_datetime((string) ($post['published_at'] ?? $post['created_at'] ?? ''));
    [$updatedIso, $updatedLabel] = post_display_datetime((string) ($post['content_updated_at'] ?? ''));
    $byline = '<div class="post-byline">';
    if ($authorName !== '') {
        $byline .= '<span>Autor: <a href="' . trust_escape(trust_author_filename($author)) . '">' . $authorName . '</a></span>';
    }
    if ($publishedIso !== '') {
        $byline .= '<span>Opublikowano: <time datetime="' . htmlspecialchars($publishedIso, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($publishedLabel, ENT_QUOTES, 'UTF-8') . '</time></span>';
    }
    if ($updatedIso !== '' && $updatedIso !== $publishedIso) {
        $byline .= '<span>Aktualizacja: <time datetime="' . htmlspecialchars($updatedIso, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($updatedLabel, ENT_QUOTES, 'UTF-8') . '</time></span>';
    }
    $byline .= '<span><a href="polityka-redakcyjna.html">Polityka redakcyjna</a></span>';
    $byline .= '</div>';
    $sourceItems = [];
    foreach (list_post_sources((int) $post['id']) as $source) {
        $sourceUrl = htmlspecialchars((string) $source['source_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sourceLabel = trim((string) ($source['source_title'] ?? ''))
            ?: trim((string) ($source['publisher_name'] ?? ''))
            ?: (string) (parse_url((string) $source['source_url'], PHP_URL_HOST) ?: $source['source_url']);
        $sourceItems[] = '<li><a href="' . $sourceUrl . '" target="_blank" rel="noopener noreferrer">'
            . htmlspecialchars($sourceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
    }
    $sourcesHtml = $sourceItems === [] ? '' : '<section class="post-sources" aria-labelledby="post-sources-title"><h2 id="post-sources-title">Źródła</h2><ul>' . implode('', $sourceItems) . '</ul></section>';
    $relatedItems = [];
    foreach (list_related_published_posts($post) as $related) {
        $relatedItems[] = '<li><a href="' . htmlspecialchars(post_page_filename((string) $related['slug']), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string) $related['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
    }
    $relatedHtml = $relatedItems === [] ? '' : '<aside class="post-related" aria-labelledby="post-related-title"><h2 id="post-related-title">Powiązane artykuły</h2><ul>' . implode('', $relatedItems) . '</ul></aside>';
    $aiHtml = '';
    if (!empty($post['ai_assisted'])) {
        $disclosure = trim((string) ($post['ai_disclosure'] ?? ''));
        $aiHtml = '<aside class="post-ai-disclosure"><strong>Jak powstał ten materiał?</strong> '
            . htmlspecialchars($disclosure !== '' ? $disclosure : 'Materiał przygotowano z pomocą narzędzi automatycznych i zweryfikowano redakcyjnie.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</aside>';
    }
    $imageBlock = '';
    foreach ($articleImages as $articleImage) {
        if ((string) ($articleImage['role'] ?? '') === 'hero'
            && (string) ($articleImage['status'] ?? '') === 'downloaded') {
            $imageBlock = render_article_image_record($articleImage, true);
            break;
        }
    }
    if ($imageBlock === '') {
        $imageBlock = render_cropped_post_image(
            (string) ($post['image_path'] ?? ''),
            post_main_image_crop($post),
            'post-page-image',
            false,
            trim((string) ($post['image_alt'] ?? '')) ?: (string) $post['title']
        );
    }
    if ($imageBlock !== '') {
        $imageBlock = "\t\t\t\t\t\t\t{$imageBlock}\n";
    }
    $categoryUrl = $publicCategory
        ? '../index.html?category=' . rawurlencode((string) $category['slug'])
        : '../index.html';
    $pageTopAd = $renderPageTopAd ? render_ad_slot('page-top', 1, false, $adConfig) : '';
    $postArticleAd = $renderPostArticleAd ? render_ad_slot('post-article', 1, true, $adConfig) : '';
    $postSection = <<<HTML
						{$pageTopAd}
						<article class="post featured bueno-post-page">
{$imageBlock}							<header class="major news-feed-heading">
								{$categoryKicker}
								<h1>{$title}</h1>
								<p>{$excerpt}</p>
								{$byline}
							</header>
							<div class="post-page-body">{$content}</div>
							{$galleryLink}
							{$sourcesHtml}
							{$relatedHtml}
							{$aiHtml}
							<ul class="actions special"><li><a class="button" href="{$categoryUrl}">Wróć do aktualności</a></li></ul>
						</article>
HTML;
    $postSection .= "\n\t\t\t\t\t\t" . $postArticleAd;

    $template = preg_replace(
        '/<section class="post featured bueno-newsfeed" data-news-source="\.\.\/php\/posts\.php">.*?<\/section>/s',
        $postSection,
        $template,
        1
    ) ?? $template;

    if ($galleryLink !== '') {
        $template = preg_replace(
            '/<script defer src="\.\.\/assets\/js\/news-feed\.js\?v=[^"]+"><\/script>/',
            '<script defer src="../assets/js/cat-gallery.js?v=cms-core-20260721"></script>',
            $template,
            1
        ) ?? $template;
    } else {
        $template = preg_replace(
            '/\s*<script defer src="\.\.\/assets\/js\/news-feed\.js\?v=[^"]+"><\/script>/',
            '',
            $template,
            1
        ) ?? $template;
    }

    return $template;
}

function write_post_page(array $post): void
{
    $pagePath = post_page_path((string) $post['slug']);

    if (!post_is_public($post)) {
        remove_public_file($pagePath);
        return;
    }

    write_public_file_atomically($pagePath, render_post_page_html($post));
}

function create_post(int $categoryId, string $title, string $excerpt, string $content, string $imagePath = '', string $contentImagePath = '', ?int $galleryId = null, array $contentImages = [], string $imageFit = 'cover', array $imageCrop = [], bool $isPublished = false): int
{
    $database = bueno_database();
    $slug = unique_post_slug($database, $title);
    $status = post_publication_status($isPublished);
    $statement = $database->prepare(
        'INSERT INTO posts (
            category_id, title, excerpt, content, image_path, content_image_path,
            content_images, image_fit, image_crop, gallery_id, slug,
            status, is_published, published_at, content_updated_at, author_id
         ) VALUES (
            :category_id, :title, :excerpt, :content, :image_path, :content_image_path,
            :content_images, :image_fit, :image_crop, :gallery_id, :slug,
            :status, :is_published, :published_at, CURRENT_TIMESTAMP, :author_id
         )'
    );
    $database->beginTransaction();
    try {
        $statement->execute([
            ':category_id' => $categoryId,
            ':title' => $title,
            ':excerpt' => $excerpt,
            ':content' => $content,
            ':image_path' => $imagePath,
            ':content_image_path' => $contentImagePath,
            ':content_images' => json_encode(array_values($contentImages), JSON_UNESCAPED_SLASHES),
            ':image_fit' => $imageFit === 'contain' ? 'contain' : 'cover',
            ':image_crop' => json_encode(normalize_post_crop($imageCrop), JSON_UNESCAPED_SLASHES),
            ':gallery_id' => $galleryId,
            ':slug' => $slug,
            ':status' => $status,
            ':is_published' => post_legacy_publication_flag($status),
            ':published_at' => $isPublished ? gmdate('Y-m-d H:i:s') : null,
            ':author_id' => default_author_id(),
        ]);
        $postId = (int) $database->lastInsertId();
        record_post_status_change($postId, null, $status, 'Utworzenie artykułu', 'admin');
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    $post = find_post($postId);

    if ($post === null) {
        throw new RuntimeException('Nie można utworzyć postu.');
    }

    write_post_page($post);
    sync_public_navigation();

    return $postId;
}

function update_post(int $postId, string $title, string $excerpt, string $content, string $imagePath, bool $isPublished, string $contentImagePath = '', ?int $galleryId = null, array $contentImages = [], string $imageFit = 'cover', array $imageCrop = [], ?string $editorialStatus = null): void
{
    $database = bueno_database();
    $currentPost = find_post($postId);

    if ($currentPost === null) {
        throw new RuntimeException('Nie znaleziono postu.');
    }

    $slug = $title === $currentPost['title'] ? $currentPost['slug'] : unique_post_slug($database, $title, $postId);
    $currentStatus = normalize_editorial_status((string) $currentPost['status']);
    $newStatus = $editorialStatus !== null
        ? normalize_editorial_status($editorialStatus)
        : post_publication_status($isPublished);
    if ($currentStatus !== $newStatus && in_array($newStatus, ['scheduled', 'published'], true)) {
        assert_post_quality_allows_publication($postId);
    }
    $statement = $database->prepare(
        'UPDATE posts
         SET title = :title, excerpt = :excerpt, content = :content, image_path = :image_path, content_image_path = :content_image_path, content_images = :content_images, image_fit = :image_fit, image_crop = :image_crop, gallery_id = :gallery_id, slug = :slug,
             status = :status,
             is_published = :is_published,
             published_at = CASE
                WHEN :status = "published" THEN COALESCE(published_at, CURRENT_TIMESTAMP)
                ELSE published_at
             END,
             content_updated_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $database->beginTransaction();
    try {
        $statement->execute([
            ':id' => $postId,
            ':title' => $title,
            ':excerpt' => $excerpt,
            ':content' => $content,
            ':image_path' => $imagePath,
            ':content_image_path' => $contentImagePath,
            ':content_images' => json_encode(array_values($contentImages), JSON_UNESCAPED_SLASHES),
            ':image_fit' => $imageFit === 'contain' ? 'contain' : 'cover',
            ':image_crop' => json_encode(normalize_post_crop($imageCrop), JSON_UNESCAPED_SLASHES),
            ':gallery_id' => $galleryId,
            ':slug' => $slug,
            ':status' => $newStatus,
            ':is_published' => post_legacy_publication_flag($newStatus),
        ]);
        if ($currentStatus !== $newStatus) {
            record_post_status_change($postId, $currentStatus, $newStatus, 'Zmiana publikacji w edytorze', 'admin');
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    $updatedPost = find_post($postId);

    if ($currentPost['slug'] !== $slug) {
        remove_public_file(post_page_path((string) $currentPost['slug']));
    }

    if ($updatedPost !== null) {
        write_post_page($updatedPost);
    }

    // Private drafts have no public artifacts. The CLI image worker may update
    // their preview without CMS_PUBLIC_URL; publication still synchronizes.
    if (post_is_public($currentPost) || ($updatedPost !== null && post_is_public($updatedPost))) {
        sync_public_navigation();
    }
}

function change_post_editorial_status(int $postId, string $newStatus, string $reason = '', string $actor = 'admin', ?string $publicationTimestamp = null): void
{
    $newStatus = normalize_editorial_status($newStatus);
    $reason = trim($reason);
    $post = find_post($postId);
    if ($post === null) {
        throw new RuntimeException('Nie znaleziono materiału.');
    }

    $currentStatus = normalize_editorial_status((string) $post['status']);
    if ($currentStatus === $newStatus) {
        throw new InvalidArgumentException(
            $newStatus === 'published'
                ? 'Materiał jest już opublikowany i nie może zostać opublikowany ponownie.'
                : 'Materiał ma już wybrany status.'
        );
    }
    if ($currentStatus === 'published' && $newStatus === 'scheduled') {
        throw new InvalidArgumentException('Opublikowanego materiału nie można ponownie zaplanować.');
    }
    if ($newStatus === 'rejected' && $reason === '') {
        throw new InvalidArgumentException('Odrzucenie materiału wymaga podania przyczyny.');
    }
    if (mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Przyczyna zmiany statusu jest zbyt długa.');
    }
    if (in_array($newStatus, ['scheduled', 'published'], true)) {
        assert_post_quality_allows_publication($postId);
        assert_trust_configuration_allows_publication();
    }

    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            "UPDATE posts
             SET status = :status,
                 is_published = :is_published,
                 published_at = CASE
                    WHEN :status = 'published' THEN COALESCE(published_at, :publication_timestamp, CURRENT_TIMESTAMP)
                    ELSE published_at
                 END,
                 rejection_reason = CASE
                    WHEN :status = 'rejected' THEN :rejection_reason
                    ELSE ''
                 END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND deleted_at IS NULL"
        );
        $statement->execute([
            ':status' => $newStatus,
            ':is_published' => post_legacy_publication_flag($newStatus),
            ':publication_timestamp' => $publicationTimestamp,
            ':rejection_reason' => $reason,
            ':id' => $postId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Nie udało się zmienić statusu materiału.');
        }
        record_post_status_change(
            $postId,
            $currentStatus,
            $newStatus,
            $reason !== '' ? $reason : 'Zmiana statusu w kolejce redakcyjnej',
            $actor
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    $updated = find_post($postId);
    if ($updated !== null) {
        write_post_page($updated);
    }
    sync_public_navigation();
}

function delete_post(int $postId): ?array
{
    $database = bueno_database();
    $post = find_post($postId);

    if ($post === null) {
        return null;
    }

    $statement = $database->prepare('UPDATE posts SET deleted_at = CURRENT_TIMESTAMP, is_published = 0 WHERE id = :id');
    $statement->execute([':id' => $postId]);

    remove_public_file(post_page_path((string) $post['slug']));

    sync_public_navigation();

    return $post;
}

function delete_post_category(int $categoryId): ?array
{
    $database = bueno_database();
    $category = find_post_category($categoryId);

    if ($category === null) {
        return null;
    }

    $posts = list_posts($categoryId);
    $database->beginTransaction();

    try {
        $statement = $database->prepare('UPDATE posts SET deleted_at = CURRENT_TIMESTAMP, is_published = 0 WHERE category_id = :category_id AND deleted_at IS NULL');
        $statement->execute([':category_id' => $categoryId]);
        $statement = $database->prepare('UPDATE post_categories SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([':id' => $categoryId]);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }

    foreach ($posts as $post) {
        remove_public_file(post_page_path((string) $post['slug']));
    }

    sync_public_navigation();

    return $category;
}

function restore_post(int $postId): void
{
    $post = find_post($postId, true);
    if ($post === null || $post['deleted_at'] === null) return;
    $status = normalize_editorial_status((string) $post['status']);
    $statement = bueno_database()->prepare(
        'UPDATE posts
         SET deleted_at = NULL, is_published = :is_published
         WHERE id = :id'
    );
    $statement->execute([
        ':id' => $postId,
        ':is_published' => post_legacy_publication_flag($status),
    ]);
    $restored = find_post($postId, true);
    if ($restored !== null) write_post_page($restored);
    sync_public_navigation();
}

function restore_post_category(int $categoryId): void
{
    $category = find_post_category($categoryId, true);
    if ($category === null || $category['deleted_at'] === null) return;
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare('UPDATE post_categories SET deleted_at = NULL WHERE id = :id');
        $statement->execute([':id' => $categoryId]);
        $statement = $database->prepare(
            'UPDATE posts
             SET deleted_at = NULL,
                 is_published = CASE WHEN status = "published" THEN 1 ELSE 0 END
             WHERE category_id = :id'
        );
        $statement->execute([':id' => $categoryId]);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }
    foreach (list_posts($categoryId, false, true) as $post) {
        if ($post['deleted_at'] === null) write_post_page($post);
    }
    sync_public_navigation();
}

function permanently_delete_post(int $postId): void
{
    $post = find_post($postId, true);
    if ($post === null || $post['deleted_at'] === null) return;
    bueno_database()->prepare('DELETE FROM posts WHERE id = :id AND deleted_at IS NOT NULL')->execute([':id' => $postId]);
    remove_public_file(post_page_path((string) $post['slug']));
    $imagePath = (string) ($post['image_path'] ?? '');
    if (str_starts_with($imagePath, app_post_image_directory() . '/')) {
        $filePath = app_path($imagePath);
        if (is_file($filePath)) unlink($filePath);
    }
    foreach (post_content_images($post) as $contentImagePath) {
        if (str_starts_with($contentImagePath, app_post_image_directory() . '/')) {
            $contentFilePath = app_path($contentImagePath);
            if (is_file($contentFilePath)) unlink($contentFilePath);
        }
    }
}

function permanently_delete_post_category(int $categoryId): void
{
    $category = find_post_category($categoryId, true);
    if ($category === null || $category['deleted_at'] === null) return;
    foreach (list_posts($categoryId, false, true) as $post) {
        permanently_delete_post((int) $post['id']);
    }
    bueno_database()->prepare('DELETE FROM post_categories WHERE id = :id AND deleted_at IS NOT NULL')->execute([':id' => $categoryId]);
}

function gallery_slug_from_title(string $title): string
{
    $polishCharacters = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];
    $lowercaseTitle = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    $slug = strtr($lowercaseTitle, $polishCharacters);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'nowa-galeria';
}

function unique_gallery_slug(PDO $database, string $title, int $excludeGalleryId = 0): string
{
    $baseSlug = gallery_slug_from_title($title);
    $slug = $baseSlug;
    $suffix = 2;

    do {
        $statement = $database->prepare('SELECT COUNT(*) FROM galleries WHERE slug = :slug AND id != :id');
        $statement->execute([':slug' => $slug, ':id' => $excludeGalleryId]);

        if ((int) $statement->fetchColumn() === 0 && !is_file(dirname(__DIR__) . '/pages/' . $slug . '.html')) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    } while (true);
}

function gallery_page_path(string $slug): string
{
    return dirname(__DIR__) . '/pages/' . $slug . '.html';
}

function write_gallery_overview_page(): void
{
    $template = file_get_contents(dirname(__DIR__) . '/pages/index.html');

    if ($template === false) {
        throw new RuntimeException('Nie można odczytać szablonu strony aktualności.');
    }

    $template = str_replace('<body class="is-preload menu-page">', '<body class="is-preload menu-page page-no-intro static-header-start">', $template);

    $gallerySection = <<<'HTML'
						<section class="post featured bueno-newsfeed gallery-overview" data-gallery-overview-source="../php/galleries.php">
							<p class="news-feed-empty">Ładowanie galerii…</p>
						</section>
HTML;

    $template = preg_replace(
        '/<section class="post featured bueno-newsfeed" data-news-source="\.\.\/php\/posts\.php">.*?<\/section>/s',
        $gallerySection,
        $template,
        1
    ) ?? $template;
    $template = preg_replace('/<meta name="description" content=".*?" \/>/s', '<meta name="description" content="Wybierz galerię, którą chcesz zobaczyć." />', $template, 1) ?? $template;
    $template = preg_replace(
        '/assets\/js\/news-feed\.js\?v=[^"\']+/',
        'assets/js/gallery-overview.js?v=cms-core-20260721',
        $template,
        1
    ) ?? $template;
    if (file_put_contents(dirname(__DIR__) . '/pages/galerie.html', $template, LOCK_EX) === false) {
        throw new RuntimeException('Nie można utworzyć strony galerii.');
    }
}

function sync_public_navigation(): void
{
    if (getenv('CMS_SKIP_PUBLIC_SYNC') === '1') {
        return;
    }

    write_gallery_overview_page();
    write_trust_pages();
    $galleries = list_galleries();
    $postCategories = list_post_categories();
    $posts = list_posts(null, true);
    $filenames = array_merge(['index.html', 'galerie.html'], trust_public_page_filenames());

    foreach (list_posts(null, false, true) as $post) {
        if (!post_is_public($post)) {
            remove_public_file(post_page_path((string) $post['slug']));
        }
    }

    foreach ($posts as $post) {
        write_post_page($post);
        $filenames[] = post_page_filename((string) $post['slug']);
    }

    foreach ($galleries as $gallery) {
        write_gallery_page($gallery);
        $filenames[] = $gallery['slug'] . '.html';
    }

    foreach (array_unique($filenames) as $filename) {
        $path = dirname(__DIR__) . '/pages/' . $filename;

        if (!is_file($path)) {
            continue;
        }

        $page = file_get_contents($path);

        if ($page === false) {
            throw new RuntimeException('Nie można odczytać strony do aktualizacji nawigacji.');
        }

        $currentPage = pathinfo($filename, PATHINFO_FILENAME);
        $links = [];
        $newsLinks = [];

        foreach ($postCategories as $category) {
            $title = htmlspecialchars((string) $category['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $newsLinks[] = '<li><a href="kategoria-' . rawurlencode((string) $category['slug']) . '.html">' . $title . '</a></li>';
        }

        $isNewsActive = $currentPage === 'index' || str_starts_with($currentPage, 'post-');
        $links[] = '<li class="nav-dropdown' . ($isNewsActive ? ' active' : '') . '"><a href="index.html">Aktualności</a><ul class="nav-dropdown-menu">' . implode('', $newsLinks) . '</ul></li>';
        $galleryLinks = [];

        foreach ($galleries as $gallery) {
            $slug = (string) $gallery['slug'];
            $title = htmlspecialchars((string) $gallery['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $galleryLinks[] = '<li><a href="' . rawurlencode($slug) . '.html">' . $title . '</a></li>';
        }

        $isGalleryActive = in_array($currentPage, ['galerie', 'kotki'], true);

        foreach ($galleries as $gallery) {
            if ($currentPage === (string) $gallery['slug']) {
                $isGalleryActive = true;
                break;
            }
        }

        $links[] = '<li class="nav-dropdown' . ($isGalleryActive ? ' active' : '') . '"><a href="galerie.html">Galerie</a><ul class="nav-dropdown-menu">' . implode('', $galleryLinks) . '</ul></li>';

        $links[] = '<li' . ($currentPage === 'kontakt' ? ' class="active"' : '') . '><a href="kontakt.html">Kontakt</a></li>';
        $navigation = "<ul class=\"links\">\n\t\t\t\t\t\t\t\t\t" . implode("\n\t\t\t\t\t\t\t\t\t", $links) . "\n\t\t\t\t\t\t\t\t</ul>";
        $navigation = str_replace('href="index.html"', 'href="../index.html"', $navigation);
        $updatedPage = preg_replace('/<ul class="links">.*?<\/ul>(?=\s*<ul class="icons"[^>]*>)/s', $navigation, $page, 1);

        if ($updatedPage !== null) {
            $updatedPage = preg_replace(
                '/assets\/css\/main\.css\?v=[^"\']+/',
                'assets/css/main.css?v=cms-core-20260727-articles',
                $updatedPage,
                1
            ) ?? $updatedPage;
            $updatedPage = preg_replace('/\s*<article class="post featured legacy-index-content">.*?<\/article>/s', '', $updatedPage, 1) ?? $updatedPage;
        }

        if ($updatedPage === null || file_put_contents($path, $updatedPage, LOCK_EX) === false) {
            throw new RuntimeException('Nie można zapisać zaktualizowanej nawigacji galerii.');
        }
    }

    write_root_index_page();
    write_discovery_files();
}

function news_feed_plain_text(string $value): string
{
    $value = preg_replace('/\[\[\s*(?:Z\d+|ZDJECIE)\s*\]\]/iu', ' ', $value) ?? $value;

    return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
}

function render_server_news_feed(array $posts, ?array $category, int $page, string $pagePattern): string
{
    $perPage = 5;
    $pageCount = max(1, (int) ceil(count($posts) / $perPage));
    $page = max(1, min($pageCount, $page));
    $visible = array_slice($posts, ($page - 1) * $perPage, $perPage);
    $heading = htmlspecialchars((string) ($category['title'] ?? 'Aktualności'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $description = htmlspecialchars(
        trim((string) ($category['description'] ?? '')) ?: 'Najnowsze informacje opublikowane na stronie.',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $categorySlug = htmlspecialchars((string) ($category['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
    $adConfig = advertising_config();
    $pageTopAd = (int) ($adConfig['max_slots_per_page'] ?? 0) > 0
        ? render_ad_slot('page-top', 1, false, $adConfig)
        : '';
    $feedInlineAd = (int) ($adConfig['max_slots_per_page'] ?? 0) > ($pageTopAd === '' ? 0 : 1)
        ? render_ad_slot('feed-inline', 1, true, $adConfig)
        : '';
    $html = $pageTopAd
        . '<section class="post featured bueno-newsfeed" data-news-source="../php/posts.php" data-news-rendered="server"'
        . ($categorySlug !== '' ? ' data-news-category="' . $categorySlug . '"' : '')
        . ' data-news-page="' . $page . '">';
    $html .= '<header class="major news-feed-heading"><p class="news-feed-kicker">Najnowsze</p><h1>'
        . $heading . '</h1><p>' . $description . '</p></header><div class="news-feed-list">';

    if ($visible === []) {
        $html .= '<p class="news-feed-empty">W tej kategorii nie ma jeszcze opublikowanych aktualności.</p>';
    }
    foreach ($visible as $index => $post) {
        $title = htmlspecialchars((string) $post['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $excerpt = htmlspecialchars(
            news_feed_plain_text((string) $post['excerpt']) ?: news_feed_plain_text((string) $post['content']),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        [$dateIso, $dateLabel] = post_display_datetime((string) ($post['published_at'] ?? $post['created_at'] ?? ''));
        $imagePath = ltrim(str_replace('\\', '/', (string) ($post['image_path'] ?? '')), '/');
        $absoluteImagePath = app_path($imagePath);
        $visual = '';
        $hasImage = $imagePath !== '' && is_file($absoluteImagePath);
        if ($hasImage) {
            $info = @getimagesize($absoluteImagePath);
            $width = max(1, (int) ($info[0] ?? 1));
            $height = max(1, (int) ($info[1] ?? 1));
            $alt = htmlspecialchars(trim((string) ($post['image_alt'] ?? '')) ?: (string) $post['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $loading = $index === 0 && $page === 1
                ? ' loading="eager" fetchpriority="high"'
                : ' loading="lazy"';
            $visual = '<div class="news-feed-visual"><img src="../' . htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8')
                . '" alt="' . $alt . '" width="' . $width . '" height="' . $height . '" decoding="async"' . $loading . '></div>';
        }
        $categoryLabel = (int) ($post['category_is_editorial_only'] ?? 0) === 1
            ? ''
            : trim((string) ($post['category_title'] ?? ''));
        $categoryMarkup = $categoryLabel === ''
            ? ''
            : '<p class="news-feed-category">'
                . htmlspecialchars($categoryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p>';
        $html .= '<article class="news-feed-item"><a class="news-feed-card news-feed-post-link'
            . ($hasImage ? ' has-news-feed-visual' : '') . '" href="'
            . htmlspecialchars(post_page_filename((string) $post['slug']), ENT_QUOTES, 'UTF-8') . '">'
            . $visual . '<div class="news-feed-content">' . $categoryMarkup
            . '<h2>' . $title . '</h2><p class="news-feed-excerpt">' . $excerpt . '</p>'
            . ($dateIso !== '' ? '<time datetime="' . htmlspecialchars($dateIso, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') . '</time>' : '')
            . '</div></a></article>';
        if ($index === 2 && count($visible) >= 4) {
            $html .= $feedInlineAd;
        }
    }
    $html .= '</div>';
    if ($pageCount > 1) {
        $html .= '<nav class="news-feed-pagination" aria-label="Strony aktualności">';
        for ($number = 1; $number <= $pageCount; $number++) {
            $href = sprintf($pagePattern, $number);
            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                . ($number === $page ? ' class="is-active" aria-current="page"' : '')
                . ' aria-label="Strona ' . $number . '">' . $number . '</a>';
        }
        $html .= '</nav>';
    }

    return $html . '</section>';
}

function replace_news_feed_section(string $template, string $section): string
{
    return preg_replace(
        '/<section class="post featured bueno-newsfeed"[^>]*>.*?<\/section>/s',
        $section,
        $template,
        1
    ) ?? $template;
}

function write_server_rendered_news_pages(string $template): string
{
    $allPosts = list_posts(null, true);
    $generatedFiles = [];
    foreach (list_post_categories() as $category) {
        $slug = rawurlencode((string) $category['slug']);
        $template = str_replace(
            ['href="../index.html?category=' . $slug . '"', 'href="index.html?category=' . $slug . '"'],
            'href="kategoria-' . $slug . '.html"',
            $template
        );
    }
    $rootSection = render_server_news_feed($allPosts, null, 1, 'aktualnosci-%d.html');
    $rootSection = str_replace('href="aktualnosci-1.html"', 'href="../index.html"', $rootSection);
    $rootTemplate = replace_news_feed_section($template, $rootSection);

    $writePages = static function (array $posts, ?array $category, string $baseName) use ($template, &$generatedFiles): void {
        $pageCount = max(1, (int) ceil(count($posts) / 5));
        for ($page = 1; $page <= $pageCount; $page++) {
            if ($category === null && $page === 1) {
                continue;
            }
            $pattern = $category === null ? 'aktualnosci-%d.html' : $baseName . '-%d.html';
            $section = render_server_news_feed($posts, $category, $page, $pattern);
            if ($category !== null) {
                $section = str_replace('href="' . $baseName . '-1.html"', 'href="' . $baseName . '.html"', $section);
            }
            $pageHtml = replace_news_feed_section($template, $section);
            $filename = $category !== null && $page === 1 ? $baseName . '.html' : sprintf($pattern, $page);
            write_public_file_atomically(app_path('pages/' . $filename), $pageHtml);
            $generatedFiles[] = $filename;
        }
    };
    $writePages($allPosts, null, 'aktualnosci');
    foreach (list_post_categories() as $category) {
        $writePages(list_posts((int) $category['id'], true), $category, 'kategoria-' . $category['slug']);
    }

    $manifestPath = app_path('data/generated-news-pages.json');
    $previousFiles = [];
    if (is_file($manifestPath)) {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        $previousFiles = is_array($decoded) ? $decoded : [];
    }
    foreach (array_diff($previousFiles, $generatedFiles) as $obsoleteFile) {
        if (is_string($obsoleteFile)
            && preg_match('/^(?:aktualnosci-[0-9]+|kategoria-[a-z0-9-]+(?:-[0-9]+)?)\.html$/', $obsoleteFile) === 1) {
            remove_public_file(app_path('pages/' . $obsoleteFile));
        }
    }
    sort($generatedFiles, SORT_NATURAL);
    write_public_file_atomically(
        $manifestPath,
        json_encode($generatedFiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
    );

    return $rootTemplate;
}

/**
 * Keep the hosting entry point at the project root while pages/ remains the
 * generated-page directory and template source.
 */
function write_root_index_page(): void
{
    $templatePath = dirname(__DIR__) . '/pages/index.html';
    $rootPath = dirname(__DIR__) . '/index.html';
    $template = file_get_contents($templatePath);

    if ($template === false) {
        throw new RuntimeException('Nie można odczytać szablonu strony głównej.');
    }

    $template = write_server_rendered_news_pages($template);
    $root = str_replace(
        ['../assets/', '../images/', '../php/'],
        ['assets/', 'images/', 'php/'],
        $template
    );
    $root = str_replace('href="../index.html', 'href="index.html', $root);
    $root = str_replace('href="post-', 'href="pages/post-', $root);
    $root = str_replace('data-news-source="php/posts.php"', 'data-news-source="php/posts.php" data-news-base="pages/"', $root);
    $root = str_replace('href="galerie.html"', 'href="pages/galerie.html"', $root);
    foreach (array_keys(TRUST_PUBLIC_PAGES) as $trustFilename) {
        $root = str_replace('href="' . $trustFilename . '"', 'href="pages/' . $trustFilename . '"', $root);
    }

    foreach (list_galleries() as $gallery) {
        $slug = rawurlencode((string) $gallery['slug']);
        $root = str_replace('href="' . $slug . '.html"', 'href="pages/' . $slug . '.html"', $root);
    }
    foreach (list_post_categories() as $category) {
        $slug = rawurlencode((string) $category['slug']);
        $root = str_replace('href="kategoria-' . $slug, 'href="pages/kategoria-' . $slug, $root);
    }
    $root = str_replace('href="aktualnosci-', 'href="pages/aktualnosci-', $root);

    if (file_put_contents($rootPath, $root, LOCK_EX) === false) {
        throw new RuntimeException('Nie można zapisać głównej strony wejściowej.');
    }
}

function sync_public_gallery_navigation(): void
{
    sync_public_navigation();
}

function write_gallery_page(array $gallery): void
{
    $template = file_get_contents(dirname(__DIR__) . '/pages/index.html');

    if ($template === false) {
        throw new RuntimeException('Nie można odczytać szablonu strony galerii.');
    }

    $template = str_replace('<body class="is-preload menu-page">', '<body class="is-preload menu-page page-no-intro static-header-start">', $template);

    $title = htmlspecialchars((string) $gallery['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $description = htmlspecialchars(
        (string) $gallery['description'] !== '' ? (string) $gallery['description'] : 'Galeria jest przygotowana do uzupełnienia.',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $slug = (string) $gallery['slug'];
    $filename = $slug . '.html';
    $gallerySection = <<<HTML
							<section class="post menu-snap cat-gallery gallery-page-placeholder" data-gallery-source="../php/gallery-items.php?gallery={$slug}" data-gallery-title="{$title}">
								<section class="burger-slide cat-slide" data-section-title="{$title}" data-section-label="Galeria" data-section-index="1">
									<div class="burger-inner cat-inner">
										<span class="burger-section">GALERIA</span>
										<h1>{$title}</h1>
										<div class="burger-meta"><p>{$description}</p></div>
									</div>
								</section>
							</section>
HTML;

    $template = str_replace('<div id="wrapper" class="fade-in">', '<div id="wrapper">', $template);
    $template = preg_replace(
        '/<div id="main">.*?<!-- Footer -->/s',
        "<div id=\"main\">\n{$gallerySection}\n\t\t\t\t\t</div>\n\n\t\t\t\t<!-- Footer -->",
        $template,
        1
    ) ?? $template;
    $template = preg_replace('/<title>.*?<\/title>/s', '<title>' . $title . ' | Twoja marka</title>', $template, 1) ?? $template;
    $template = preg_replace('/<meta name="description" content=".*?" \/>/s', '<meta name="description" content="' . $description . '" />', $template, 1) ?? $template;
    $template = preg_replace('/\s*<link rel="canonical" href=".*?" \/>/s', '', $template, 1) ?? $template;
    $template = preg_replace(
        '/<script defer src="\.\.\/assets\/js\/news-feed\.js\?v=[^"]+"><\/script>/',
        '<script defer src="../assets/js/cat-gallery.js?v=cms-core-20260721"></script>',
        $template,
        1
    ) ?? $template;
    if (file_put_contents(gallery_page_path($slug), $template, LOCK_EX) === false) {
        throw new RuntimeException('Nie można utworzyć pliku strony galerii.');
    }
}

function create_gallery(string $title = 'Nowa galeria', string $description = ''): int
{
    $database = bueno_database();
    $slug = unique_gallery_slug($database, $title);
    $sortOrder = (int) $database->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM galleries')->fetchColumn();

    $statement = $database->prepare(
        'INSERT INTO galleries (title, description, slug, sort_order) VALUES (:title, :description, :slug, :sort_order)'
    );
    $statement->execute([
        ':title' => $title,
        ':description' => $description,
        ':slug' => $slug,
        ':sort_order' => $sortOrder,
    ]);

    $galleryId = (int) $database->lastInsertId();
    $gallery = find_gallery($galleryId);

    try {
        if ($gallery === null) {
            throw new RuntimeException('Nie można utworzyć galerii.');
        }

        write_gallery_page($gallery);
        sync_public_gallery_navigation();
    } catch (Throwable $exception) {
        $statement = $database->prepare('DELETE FROM galleries WHERE id = :id');
        $statement->execute([':id' => $galleryId]);
        throw $exception;
    }

    return $galleryId;
}

function list_galleries(bool $includeDeleted = false): array
{
    $where = $includeDeleted ? '' : ' WHERE deleted_at IS NULL';
    return bueno_database()
        ->query('SELECT * FROM galleries' . $where . ' ORDER BY sort_order ASC, id ASC')
        ->fetchAll();
}

function reorder_galleries(array $galleryIds): void
{
    $database = bueno_database();
    $knownIds = array_map(static fn (array $gallery): int => (int) $gallery['id'], list_galleries());
    $orderedIds = array_values(array_unique(array_map('intval', $galleryIds)));

    if (count($orderedIds) !== count($knownIds) || array_diff($knownIds, $orderedIds) !== []) {
        throw new RuntimeException('Nieprawidłowa kolejność galerii.');
    }

    $statement = $database->prepare('UPDATE galleries SET sort_order = :sort_order WHERE id = :id');
    $database->beginTransaction();

    try {
        foreach ($orderedIds as $index => $id) {
            $statement->execute([':id' => $id, ':sort_order' => $index + 1]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }

    sync_public_navigation();
}

function find_gallery(int $galleryId, bool $includeDeleted = false): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM galleries WHERE id = :id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'));
    $statement->execute([':id' => $galleryId]);
    $gallery = $statement->fetch();

    return is_array($gallery) ? $gallery : null;
}

function find_gallery_by_slug(string $slug, bool $includeDeleted = false): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM galleries WHERE slug = :slug' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'));
    $statement->execute([':slug' => $slug]);
    $gallery = $statement->fetch();

    return is_array($gallery) ? $gallery : null;
}

function update_gallery(
    int $galleryId,
    string $title,
    string $description,
    bool $mobileTwoUp = false,
    bool $tileView = false
): void
{
    $database = bueno_database();
    $currentGallery = find_gallery($galleryId);

    if ($currentGallery === null) {
        throw new RuntimeException('Nie znaleziono galerii.');
    }

    if ($tileView) {
        $mobileTwoUp = false;
    }

    $proposedSlug = gallery_slug_from_title($title);
    $slug = $proposedSlug === $currentGallery['slug']
        ? $proposedSlug
        : unique_gallery_slug($database, $title, $galleryId);
    $updatedGallery = [
        'id' => $galleryId,
        'title' => $title,
        'description' => $description,
        'slug' => $slug,
        'mobile_two_up' => $mobileTwoUp ? 1 : 0,
        'tile_view' => $tileView ? 1 : 0,
    ];

    write_gallery_page($updatedGallery);

    $statement = bueno_database()->prepare(
        'UPDATE galleries
         SET title = :title, description = :description, slug = :slug,
             mobile_two_up = :mobile_two_up, tile_view = :tile_view,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':id' => $galleryId,
        ':title' => $title,
        ':description' => $description,
        ':slug' => $slug,
        ':mobile_two_up' => $mobileTwoUp ? 1 : 0,
        ':tile_view' => $tileView ? 1 : 0,
    ]);

    if ($currentGallery['slug'] !== $slug) {
        $oldPath = gallery_page_path($currentGallery['slug']);

        if (is_file($oldPath)) {
            unlink($oldPath);
        }
    }

    sync_public_gallery_navigation();
}

function delete_gallery(int $galleryId): ?array
{
    $database = bueno_database();
    $gallery = find_gallery($galleryId);

    if ($gallery === null) {
        return null;
    }

    $items = list_gallery_items($galleryId, true);
    $database->beginTransaction();

    try {
        $statement = $database->prepare('UPDATE gallery_items SET deleted_at = CURRENT_TIMESTAMP WHERE gallery_id = :gallery_id AND deleted_at IS NULL');
        $statement->execute([':gallery_id' => $galleryId]);
        $statement = $database->prepare('UPDATE galleries SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([':id' => $galleryId]);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }

    $pagePath = gallery_page_path((string) $gallery['slug']);
    if (is_file($pagePath)) {
        unlink($pagePath);
    }

    sync_public_gallery_navigation();

    return $gallery;
}

function list_gallery_items(int $galleryId, bool $includeDeleted = false): array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM gallery_items WHERE gallery_id = :gallery_id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL') . ' ORDER BY sort_order ASC, id ASC'
    );
    $statement->execute([':gallery_id' => $galleryId]);

    return $statement->fetchAll();
}

function reorder_gallery_items(int $galleryId, array $itemIds): void
{
    $database = bueno_database();
    $knownIds = array_map(
        static fn (array $item): int => (int) $item['id'],
        list_gallery_items($galleryId)
    );
    $orderedIds = array_values(array_unique(array_map('intval', $itemIds)));

    if (count($orderedIds) !== count($knownIds) || array_diff($knownIds, $orderedIds) !== []) {
        throw new RuntimeException('Nieprawidłowa kolejność zdjęć.');
    }

    $statement = $database->prepare(
        'UPDATE gallery_items SET sort_order = :sort_order WHERE id = :id AND gallery_id = :gallery_id'
    );
    $database->beginTransaction();

    try {
        foreach ($orderedIds as $index => $itemId) {
            $statement->execute([
                ':sort_order' => $index + 1,
                ':id' => $itemId,
                ':gallery_id' => $galleryId,
            ]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }

    $gallery = find_gallery($galleryId);
    if ($gallery !== null) {
        write_gallery_page($gallery);
    }
}

function create_gallery_item(
    int $galleryId,
    string $name,
    string $description,
    string $imagePath,
    array $imageCrop = [],
    array $imageCropMobile = []
): void
{
    $database = bueno_database();
    $statement = $database->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gallery_items WHERE gallery_id = :gallery_id'
    );
    $statement->execute([':gallery_id' => $galleryId]);
    $nextOrder = (int) $statement->fetchColumn();

    $statement = $database->prepare(
        'INSERT INTO gallery_items (gallery_id, name, description, image_path, image_crop, image_crop_mobile, sort_order)
         VALUES (:gallery_id, :name, :description, :image_path, :image_crop, :image_crop_mobile, :sort_order)'
    );
    $statement->execute([
        ':gallery_id' => $galleryId,
        ':name' => $name,
        ':description' => $description,
        ':image_path' => $imagePath,
        ':image_crop' => json_encode(normalize_post_crop($imageCrop), JSON_UNESCAPED_SLASHES),
        ':image_crop_mobile' => json_encode(normalize_post_crop($imageCropMobile), JSON_UNESCAPED_SLASHES),
        ':sort_order' => $nextOrder,
    ]);
}

function find_gallery_item(int $itemId, int $galleryId, bool $includeDeleted = false): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM gallery_items WHERE id = :id AND gallery_id = :gallery_id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL')
    );
    $statement->execute([':id' => $itemId, ':gallery_id' => $galleryId]);
    $item = $statement->fetch();

    return is_array($item) ? $item : null;
}

function update_gallery_item(
    int $itemId,
    int $galleryId,
    string $name,
    string $description,
    ?string $imagePath = null,
    array $imageCrop = [],
    array $imageCropMobile = []
): void {
    $sql = 'UPDATE gallery_items SET name = :name, description = :description, image_crop = :image_crop, image_crop_mobile = :image_crop_mobile';
    $parameters = [
        ':id' => $itemId,
        ':gallery_id' => $galleryId,
        ':name' => $name,
        ':description' => $description,
        ':image_crop' => json_encode(normalize_post_crop($imageCrop), JSON_UNESCAPED_SLASHES),
        ':image_crop_mobile' => json_encode(normalize_post_crop($imageCropMobile), JSON_UNESCAPED_SLASHES),
    ];

    if ($imagePath !== null) {
        $sql .= ', image_path = :image_path';
        $parameters[':image_path'] = $imagePath;
    }

    $sql .= ' WHERE id = :id AND gallery_id = :gallery_id';

    $statement = bueno_database()->prepare($sql);
    $statement->execute($parameters);
}

function delete_gallery_item(int $itemId, int $galleryId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM gallery_items WHERE id = :id AND gallery_id = :gallery_id AND deleted_at IS NULL'
    );
    $statement->execute([':id' => $itemId, ':gallery_id' => $galleryId]);
    $item = $statement->fetch();

    if (!is_array($item)) {
        return null;
    }

    $statement = bueno_database()->prepare('UPDATE gallery_items SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id');
    $statement->execute([':id' => $itemId]);

    return $item;
}

function restore_gallery(int $galleryId): void
{
    $gallery = find_gallery($galleryId, true);
    if ($gallery === null || $gallery['deleted_at'] === null) return;
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $database->prepare('UPDATE galleries SET deleted_at = NULL WHERE id = :id')->execute([':id' => $galleryId]);
        $database->prepare('UPDATE gallery_items SET deleted_at = NULL WHERE gallery_id = :id')->execute([':id' => $galleryId]);
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }
    $restored = find_gallery($galleryId, true);
    if ($restored !== null) write_gallery_page($restored);
    sync_public_gallery_navigation();
}

function restore_gallery_item(int $itemId): void
{
    $item = null;
    $statement = bueno_database()->prepare('SELECT * FROM gallery_items WHERE id = :id');
    $statement->execute([':id' => $itemId]);
    $item = $statement->fetch();
    if (!is_array($item) || $item['deleted_at'] === null) return;
    bueno_database()->prepare('UPDATE gallery_items SET deleted_at = NULL WHERE id = :id')->execute([':id' => $itemId]);
    $gallery = find_gallery((int) $item['gallery_id']);
    if ($gallery !== null) {
        write_gallery_page($gallery);
        sync_public_gallery_navigation();
    }
}

function permanently_delete_gallery_item(int $itemId): void
{
    $statement = bueno_database()->prepare('SELECT * FROM gallery_items WHERE id = :id');
    $statement->execute([':id' => $itemId]);
    $item = $statement->fetch();
    if (!is_array($item) || $item['deleted_at'] === null) return;
    bueno_database()->prepare('DELETE FROM gallery_items WHERE id = :id AND deleted_at IS NOT NULL')->execute([':id' => $itemId]);
    $imagePath = (string) ($item['image_path'] ?? '');
    if (str_starts_with($imagePath, 'images/galleries/')) {
        $filePath = dirname(__DIR__) . '/' . $imagePath;
        if (is_file($filePath)) unlink($filePath);
    }
}

function permanently_delete_gallery(int $galleryId): void
{
    $gallery = find_gallery($galleryId, true);
    if ($gallery === null || $gallery['deleted_at'] === null) return;
    foreach (list_gallery_items($galleryId, true) as $item) {
        permanently_delete_gallery_item((int) $item['id']);
    }
    bueno_database()->prepare('DELETE FROM galleries WHERE id = :id AND deleted_at IS NOT NULL')->execute([':id' => $galleryId]);
    $pagePath = gallery_page_path((string) $gallery['slug']);
    if (is_file($pagePath)) unlink($pagePath);
}

function list_trash_items_flat_legacy(): array
{
    $database = bueno_database();
    $items = [];
    $append = static function (array &$items, string $type, int $id, string $title, string $deletedAt, string $meta = ''): void {
        $items[] = [
            'key' => $type . ':' . $id,
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'meta' => $meta,
            'deleted_at' => $deletedAt,
            'preview_url' => 'admin-trash-preview.php?type=' . rawurlencode($type) . '&id=' . $id,
        ];
    };

    foreach ($database->query('SELECT id, title, deleted_at FROM post_categories WHERE deleted_at IS NOT NULL')->fetchAll() as $row) {
        $append($items, 'category', (int) $row['id'], (string) $row['title'], (string) $row['deleted_at'], 'Kategoria postów');
    }
    foreach ($database->query('SELECT id, title, deleted_at FROM posts WHERE deleted_at IS NOT NULL')->fetchAll() as $row) {
        $append($items, 'post', (int) $row['id'], (string) $row['title'], (string) $row['deleted_at'], 'Post');
    }
    foreach ($database->query('SELECT id, title, deleted_at FROM galleries WHERE deleted_at IS NOT NULL')->fetchAll() as $row) {
        $append($items, 'gallery', (int) $row['id'], (string) $row['title'], (string) $row['deleted_at'], 'Galeria');
    }
    foreach ($database->query('SELECT gallery_items.id, gallery_items.name, gallery_items.deleted_at, galleries.title AS gallery_title FROM gallery_items INNER JOIN galleries ON galleries.id = gallery_items.gallery_id WHERE gallery_items.deleted_at IS NOT NULL')->fetchAll() as $row) {
        $append($items, 'gallery_item', (int) $row['id'], (string) $row['name'], (string) $row['deleted_at'], 'Zdjęcie: ' . (string) $row['gallery_title']);
    }
    foreach ($database->query('SELECT id, name, deleted_at FROM cats WHERE deleted_at IS NOT NULL')->fetchAll() as $row) {
        $append($items, 'cat', (int) $row['id'], (string) $row['name'], (string) $row['deleted_at'], 'Nasze koty');
    }
    foreach (list_messages('trash') as $row) {
        $append($items, 'message', (int) $row['id'], (string) ($row['subject'] !== '' ? $row['subject'] : $row['name']), (string) $row['deleted_at'], 'Wiadomość od ' . (string) $row['name']);
    }

    usort($items, static fn (array $a, array $b): int => strcmp((string) $b['deleted_at'], (string) $a['deleted_at']));
    return $items;
}

function list_trash_items(): array
{
    $database = bueno_database();
    $make = static function (string $type, int $id, string $title, string $deletedAt, string $meta = '', bool $selectable = true): array {
        return [
            'key' => $selectable ? $type . ':' . $id : '',
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'meta' => $meta,
            'deleted_at' => $deletedAt,
            'preview_url' => 'admin-trash-preview.php?type=' . rawurlencode($type) . '&id=' . $id,
            'selectable' => $selectable,
            'children' => [],
        ];
    };

    $items = [];
    $categoryNodes = [];
    foreach ($database->query('SELECT id, title, deleted_at FROM post_categories WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll() as $row) {
        $categoryNodes[(int) $row['id']] = count($items);
        $items[] = $make('category', (int) $row['id'], (string) $row['title'], (string) $row['deleted_at'], 'Kategoria postów');
    }
    $activeCategoryNodes = [];
    foreach ($database->query('SELECT posts.id, posts.category_id, posts.title, posts.deleted_at, post_categories.title AS category_title FROM posts INNER JOIN post_categories ON post_categories.id = posts.category_id WHERE posts.deleted_at IS NOT NULL ORDER BY posts.deleted_at DESC')->fetchAll() as $row) {
        $categoryId = (int) $row['category_id'];
        $child = $make('post', (int) $row['id'], (string) $row['title'], (string) $row['deleted_at'], 'Post');
        if (isset($categoryNodes[$categoryId])) {
            $items[$categoryNodes[$categoryId]]['children'][] = $child;
        } elseif (isset($activeCategoryNodes[$categoryId])) {
            $items[$activeCategoryNodes[$categoryId]]['children'][] = $child;
        } else {
            $wrapper = $make('category', $categoryId, (string) $row['category_title'], (string) $row['deleted_at'], 'Kategoria postów', false);
            $wrapper['children'][] = $child;
            $activeCategoryNodes[$categoryId] = count($items);
            $items[] = $wrapper;
        }
    }

    $galleryNodes = [];
    foreach ($database->query('SELECT id, title, deleted_at FROM galleries WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll() as $row) {
        $galleryNodes[(int) $row['id']] = count($items);
        $items[] = $make('gallery', (int) $row['id'], (string) $row['title'], (string) $row['deleted_at'], 'Galeria');
    }
    $activeGalleryNodes = [];
    foreach ($database->query('SELECT gallery_items.id, gallery_items.gallery_id, gallery_items.name, gallery_items.deleted_at, galleries.title AS gallery_title FROM gallery_items INNER JOIN galleries ON galleries.id = gallery_items.gallery_id WHERE gallery_items.deleted_at IS NOT NULL ORDER BY gallery_items.deleted_at DESC')->fetchAll() as $row) {
        $galleryId = (int) $row['gallery_id'];
        $child = $make('gallery_item', (int) $row['id'], (string) $row['name'], (string) $row['deleted_at'], 'Zdjęcie');
        if (isset($galleryNodes[$galleryId])) {
            $items[$galleryNodes[$galleryId]]['children'][] = $child;
        } elseif (isset($activeGalleryNodes[$galleryId])) {
            $items[$activeGalleryNodes[$galleryId]]['children'][] = $child;
        } else {
            $wrapper = $make('gallery', $galleryId, (string) $row['gallery_title'], (string) $row['deleted_at'], 'Galeria', false);
            $wrapper['children'][] = $child;
            $activeGalleryNodes[$galleryId] = count($items);
            $items[] = $wrapper;
        }
    }

    $catRows = $database->query('SELECT id, name, deleted_at FROM cats WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll();
    if ($catRows !== []) {
        $catNode = $make('cats_gallery', 0, 'Nasze koty', (string) $catRows[0]['deleted_at'], 'Galeria', false);
        foreach ($catRows as $row) {
            $catNode['children'][] = $make('cat', (int) $row['id'], (string) $row['name'], (string) $row['deleted_at'], 'Nasze koty');
        }
        $items[] = $catNode;
    }
    foreach (list_messages('trash') as $row) {
        $items[] = $make('message', (int) $row['id'], (string) ($row['subject'] !== '' ? $row['subject'] : $row['name']), (string) $row['deleted_at'], 'Wiadomość od ' . (string) $row['name']);
    }

    usort($items, static fn (array $a, array $b): int => strcmp((string) $b['deleted_at'], (string) $a['deleted_at']));
    return $items;
}

function trash_selectable_items(?array $items = null): array
{
    $selected = [];
    foreach ($items ?? list_trash_items() as $item) {
        if (($item['selectable'] ?? true) && (string) ($item['key'] ?? '') !== '') {
            $selected[] = $item;
        }
        if (!empty($item['children'])) {
            $selected = array_merge($selected, trash_selectable_items($item['children']));
        }
    }
    return $selected;
}

function restore_trash_item(string $type, int $id): void
{
    switch ($type) {
        case 'category': restore_post_category($id); break;
        case 'post': restore_post($id); break;
        case 'gallery': restore_gallery($id); break;
        case 'gallery_item': restore_gallery_item($id); break;
        case 'cat': restore_cat($id); break;
        case 'message': restore_message_from_trash($id); break;
    }
}

function permanently_delete_trash_item(string $type, int $id): void
{
    switch ($type) {
        case 'category': permanently_delete_post_category($id); break;
        case 'post': permanently_delete_post($id); break;
        case 'gallery': permanently_delete_gallery($id); break;
        case 'gallery_item': permanently_delete_gallery_item($id); break;
        case 'cat': permanently_delete_cat($id); break;
        case 'message': permanently_delete_message($id); break;
    }
    sync_public_navigation();
}
