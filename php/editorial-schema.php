<?php

declare(strict_types=1);

const EDITORIAL_SCHEMA_MIGRATION = '20260724_001_editorial_foundation';
const PUBLICATION_STATUS_MIGRATION = '20260724_002_publication_status_source';
const EDITORIAL_EDITOR_MIGRATION = '20260724_003_editorial_editor_fields';
const TECHNICAL_SOURCES_MIGRATION = '20260724_004_technical_sources';
const FEED_INGESTION_MIGRATION = '20260724_005_feed_ingestion';
const TOPIC_GROUPING_MIGRATION = '20260724_006_topic_grouping';
const TOPIC_SCORING_MIGRATION = '20260724_007_topic_scoring';
const GENERATION_BRIDGE_MIGRATION = '20260724_008_generation_bridge';
const POPULAR_SCIENCE_PROFILE_MIGRATION = '20260724_009_popular_science_profile';
const RESEARCH_PACKAGES_MIGRATION = '20260724_010_research_packages';
const ARTICLE_DRAFT_VERSIONS_MIGRATION = '20260724_011_article_draft_versions';
const QUALITY_CHECKS_MIGRATION = '20260724_012_quality_checks';
const THUMBNAIL_VERSIONS_MIGRATION = '20260724_013_thumbnail_versions';
const ARTICLE_IMAGES_MIGRATION = '20260725_014_article_images';
const ARTICLE_IMAGE_INDEX_MIGRATION = '20260725_015_article_image_index';

function database_table_columns(PDO $database, string $table): array
{
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowa nazwa tabeli.');
    }

    return array_column($database->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
}

function database_add_column_if_missing(PDO $database, string $table, string $column, string $definition): void
{
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowa nazwa kolumny.');
    }

    if (!in_array($column, database_table_columns($database, $table), true)) {
        $database->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

function editorial_author_slug(string $name): string
{
    $name = trim(mb_strtolower($name));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(is_string($ascii) ? $ascii : $name));
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'redakcja';
}

function ensure_schema_migrations_table(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration_key TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function schema_migration_applied(PDO $database, string $migrationKey): bool
{
    $statement = $database->prepare(
        'SELECT COUNT(*) FROM schema_migrations WHERE migration_key = :migration_key'
    );
    $statement->execute([':migration_key' => $migrationKey]);

    return (int) $statement->fetchColumn() > 0;
}

function apply_schema_migration(PDO $database, string $migrationKey, callable $migration): void
{
    if (schema_migration_applied($database, $migrationKey)) {
        return;
    }

    $database->beginTransaction();

    try {
        $migration($database);
        $statement = $database->prepare(
            'INSERT INTO schema_migrations (migration_key) VALUES (:migration_key)'
        );
        $statement->execute([':migration_key' => $migrationKey]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        throw new RuntimeException(
            'Migracja bazy ' . $migrationKey . ' nie powiodła się: ' . $exception->getMessage(),
            0,
            $exception
        );
    }
}

function migrate_editorial_foundation(PDO $database): void
{
    database_add_column_if_missing($database, 'posts', 'status', "TEXT NOT NULL DEFAULT 'draft'");
    database_add_column_if_missing($database, 'posts', 'published_at', 'TEXT');
    database_add_column_if_missing($database, 'posts', 'content_updated_at', 'TEXT');
    database_add_column_if_missing($database, 'posts', 'scheduled_at', 'TEXT');
    database_add_column_if_missing($database, 'posts', 'author_id', 'INTEGER');
    database_add_column_if_missing($database, 'posts', 'seo_description', "TEXT NOT NULL DEFAULT ''");
    database_add_column_if_missing($database, 'posts', 'image_alt', "TEXT NOT NULL DEFAULT ''");
    database_add_column_if_missing($database, 'posts', 'canonical_url', "TEXT NOT NULL DEFAULT ''");
    database_add_column_if_missing($database, 'posts', 'ai_assisted', 'INTEGER NOT NULL DEFAULT 0');
    database_add_column_if_missing($database, 'posts', 'ai_disclosure', "TEXT NOT NULL DEFAULT ''");
    database_add_column_if_missing($database, 'posts', 'quality_score', 'INTEGER');
    database_add_column_if_missing($database, 'posts', 'rejection_reason', "TEXT NOT NULL DEFAULT ''");
    database_add_column_if_missing($database, 'posts', 'editorial_origin', "TEXT NOT NULL DEFAULT 'manual'");

    $database->exec(
        'CREATE TABLE IF NOT EXISTS authors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            bio TEXT NOT NULL DEFAULT "",
            profile_url TEXT NOT NULL DEFAULT "",
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS post_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            source_url TEXT NOT NULL,
            source_title TEXT NOT NULL DEFAULT "",
            publisher_name TEXT NOT NULL DEFAULT "",
            source_type TEXT NOT NULL DEFAULT "secondary",
            is_primary INTEGER NOT NULL DEFAULT 0,
            source_published_at TEXT,
            accessed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            UNIQUE(post_id, source_url)
        );
        CREATE TABLE IF NOT EXISTS post_status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            previous_status TEXT,
            new_status TEXT NOT NULL,
            reason TEXT NOT NULL DEFAULT "",
            actor TEXT NOT NULL DEFAULT "system",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS post_generation_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER,
            generation_type TEXT NOT NULL,
            provider TEXT NOT NULL DEFAULT "",
            model TEXT NOT NULL DEFAULT "",
            status TEXT NOT NULL DEFAULT "started",
            provider_response_id TEXT NOT NULL DEFAULT "",
            input_hash TEXT NOT NULL DEFAULT "",
            result_reference TEXT NOT NULL DEFAULT "",
            usage_json TEXT NOT NULL DEFAULT "{}",
            metadata_json TEXT NOT NULL DEFAULT "{}",
            error_message TEXT NOT NULL DEFAULT "",
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at TEXT,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS posts_status_scheduled_idx
            ON posts(status, scheduled_at);
        CREATE INDEX IF NOT EXISTS posts_published_at_idx
            ON posts(published_at DESC);
        CREATE INDEX IF NOT EXISTS posts_author_id_idx
            ON posts(author_id);
        CREATE INDEX IF NOT EXISTS post_sources_post_id_idx
            ON post_sources(post_id, is_primary DESC);
        CREATE INDEX IF NOT EXISTS post_sources_url_idx
            ON post_sources(source_url);
        CREATE INDEX IF NOT EXISTS post_status_history_post_id_idx
            ON post_status_history(post_id, created_at DESC);
        CREATE INDEX IF NOT EXISTS post_generation_runs_post_id_idx
            ON post_generation_runs(post_id, started_at DESC);
        CREATE INDEX IF NOT EXISTS post_generation_runs_status_idx
            ON post_generation_runs(status, started_at);'
    );

    $defaultAuthorName = trim((string) app_config('default_author'));
    $defaultAuthorName = $defaultAuthorName !== '' ? $defaultAuthorName : 'Redakcja';
    $defaultAuthorSlug = editorial_author_slug($defaultAuthorName);
    $authorStatement = $database->prepare(
        'INSERT OR IGNORE INTO authors (name, slug) VALUES (:name, :slug)'
    );
    $authorStatement->execute([
        ':name' => $defaultAuthorName,
        ':slug' => $defaultAuthorSlug,
    ]);

    $authorIdStatement = $database->prepare('SELECT id FROM authors WHERE slug = :slug');
    $authorIdStatement->execute([':slug' => $defaultAuthorSlug]);
    $defaultAuthorId = (int) $authorIdStatement->fetchColumn();

    $backfillStatement = $database->prepare(
        "UPDATE posts
         SET status = CASE
                WHEN deleted_at IS NULL AND is_published = 1 THEN 'published'
                ELSE 'draft'
             END,
             published_at = CASE
                WHEN deleted_at IS NULL AND is_published = 1 THEN COALESCE(published_at, created_at)
                ELSE published_at
             END,
             content_updated_at = COALESCE(content_updated_at, updated_at),
             author_id = COALESCE(author_id, :author_id),
             editorial_origin = CASE
                WHEN editorial_origin = '' THEN 'manual'
                ELSE editorial_origin
             END"
    );
    $backfillStatement->execute([':author_id' => $defaultAuthorId]);

    $database->exec(
        "INSERT INTO post_status_history (post_id, previous_status, new_status, reason, actor, created_at)
         SELECT posts.id, NULL, posts.status, 'Stan początkowy po migracji', 'migration', CURRENT_TIMESTAMP
         FROM posts
         WHERE NOT EXISTS (
             SELECT 1
             FROM post_status_history
             WHERE post_status_history.post_id = posts.id
               AND post_status_history.actor = 'migration'
         )"
    );
}

function run_schema_migrations(PDO $database): void
{
    ensure_schema_migrations_table($database);
    apply_schema_migration($database, EDITORIAL_SCHEMA_MIGRATION, 'migrate_editorial_foundation');
    apply_schema_migration(
        $database,
        PUBLICATION_STATUS_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                "UPDATE posts
                 SET is_published = CASE
                    WHEN status = 'published' AND deleted_at IS NULL THEN 1
                    ELSE 0
                 END"
            );
        }
    );
    apply_schema_migration(
        $database,
        EDITORIAL_EDITOR_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'posts', 'ai_components', "TEXT NOT NULL DEFAULT '[]'");
        }
    );
    apply_schema_migration(
        $database,
        TECHNICAL_SOURCES_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS technical_sources (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    website_url TEXT NOT NULL,
                    feed_url TEXT NOT NULL UNIQUE,
                    source_type TEXT NOT NULL DEFAULT "rss",
                    topic_category TEXT NOT NULL DEFAULT "technology",
                    language TEXT NOT NULL DEFAULT "en",
                    credibility_level INTEGER NOT NULL DEFAULT 5,
                    is_primary INTEGER NOT NULL DEFAULT 1,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    last_success_at TEXT,
                    last_checked_at TEXT,
                    last_error TEXT NOT NULL DEFAULT "",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS technical_sources_active_idx
                    ON technical_sources(is_active, source_type, credibility_level DESC);'
            );

            $sources = [
                ['Google Developers Blog', 'https://developers.googleblog.com/', 'https://developers.googleblog.com/feeds/posts/default/?alt=rss', 'development'],
                ['GitHub Changelog', 'https://github.blog/changelog/', 'https://github.blog/changelog/feed/', 'development'],
                ['AWS What’s New', 'https://aws.amazon.com/new/', 'https://aws.amazon.com/about-aws/whats-new/recent/feed/', 'cloud'],
                ['Cloudflare Blog', 'https://blog.cloudflare.com/', 'https://blog.cloudflare.com/rss/', 'infrastructure'],
                ['Microsoft DevBlogs', 'https://devblogs.microsoft.com/', 'https://devblogs.microsoft.com/feed/', 'development'],
            ];
            $insert = $database->prepare(
                'INSERT OR IGNORE INTO technical_sources (
                    name, website_url, feed_url, source_type, topic_category,
                    language, credibility_level, is_primary, is_active
                 ) VALUES (
                    :name, :website_url, :feed_url, "rss", :topic_category,
                    "en", 5, 1, 1
                 )'
            );
            foreach ($sources as [$name, $websiteUrl, $feedUrl, $category]) {
                $insert->execute([
                    ':name' => $name,
                    ':website_url' => $websiteUrl,
                    ':feed_url' => $feedUrl,
                    ':topic_category' => $category,
                ]);
            }
        }
    );
    apply_schema_migration(
        $database,
        FEED_INGESTION_MIGRATION,
        static function (PDO $database): void {
            if (in_array('post_categories', array_column($database->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(), 'name'), true)) {
                database_add_column_if_missing($database, 'post_categories', 'is_editorial_only', 'INTEGER NOT NULL DEFAULT 0');
            }
            $database->exec(
                'CREATE TABLE IF NOT EXISTS discovered_feed_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    technical_source_id INTEGER NOT NULL,
                    post_id INTEGER NOT NULL,
                    external_id TEXT NOT NULL,
                    source_url TEXT NOT NULL,
                    title TEXT NOT NULL,
                    source_name TEXT NOT NULL,
                    published_at TEXT,
                    summary TEXT NOT NULL DEFAULT "",
                    category TEXT NOT NULL DEFAULT "",
                    content_hash TEXT NOT NULL,
                    first_detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (technical_source_id) REFERENCES technical_sources(id) ON DELETE CASCADE,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    UNIQUE(technical_source_id, external_id),
                    UNIQUE(technical_source_id, source_url)
                );
                CREATE INDEX IF NOT EXISTS discovered_feed_items_detected_idx
                    ON discovered_feed_items(first_detected_at DESC);
                CREATE INDEX IF NOT EXISTS discovered_feed_items_hash_idx
                    ON discovered_feed_items(content_hash);
                CREATE INDEX IF NOT EXISTS discovered_feed_items_post_idx
                    ON discovered_feed_items(post_id);'
            );
        }
    );
    apply_schema_migration(
        $database,
        TOPIC_GROUPING_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS editorial_topics (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    primary_post_id INTEGER NOT NULL UNIQUE,
                    title TEXT NOT NULL,
                    normalized_title TEXT NOT NULL DEFAULT "",
                    event_at TEXT NOT NULL,
                    grouping_locked INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (primary_post_id) REFERENCES posts(id) ON DELETE CASCADE
                );
                CREATE TABLE IF NOT EXISTS feed_topic_memberships (
                    feed_item_id INTEGER PRIMARY KEY,
                    topic_id INTEGER NOT NULL,
                    confidence REAL NOT NULL DEFAULT 1,
                    match_method TEXT NOT NULL DEFAULT "single",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (feed_item_id) REFERENCES discovered_feed_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE
                );
                CREATE TABLE IF NOT EXISTS topic_grouping_candidates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    feed_item_id INTEGER NOT NULL,
                    candidate_topic_id INTEGER NOT NULL,
                    confidence REAL NOT NULL,
                    explanation TEXT NOT NULL DEFAULT "",
                    status TEXT NOT NULL DEFAULT "suggested",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    decided_at TEXT,
                    FOREIGN KEY (feed_item_id) REFERENCES discovered_feed_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (candidate_topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE,
                    UNIQUE(feed_item_id, candidate_topic_id)
                );
                CREATE TABLE IF NOT EXISTS topic_grouping_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    feed_item_id INTEGER NOT NULL,
                    from_topic_id INTEGER,
                    to_topic_id INTEGER,
                    action TEXT NOT NULL,
                    confidence REAL,
                    actor TEXT NOT NULL DEFAULT "system",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (feed_item_id) REFERENCES discovered_feed_items(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS editorial_topics_event_idx
                    ON editorial_topics(event_at DESC);
                CREATE INDEX IF NOT EXISTS feed_topic_memberships_topic_idx
                    ON feed_topic_memberships(topic_id);
                CREATE INDEX IF NOT EXISTS topic_grouping_candidates_status_idx
                    ON topic_grouping_candidates(status, confidence DESC);'
            );
            $database->exec(
                'INSERT OR IGNORE INTO editorial_topics (
                    primary_post_id, title, normalized_title, event_at
                 )
                 SELECT post_id, title, "", COALESCE(published_at, first_detected_at)
                 FROM discovered_feed_items;
                 INSERT OR IGNORE INTO feed_topic_memberships (
                    feed_item_id, topic_id, confidence, match_method
                 )
                 SELECT discovered_feed_items.id, editorial_topics.id, 1, "single"
                 FROM discovered_feed_items
                 INNER JOIN editorial_topics
                    ON editorial_topics.primary_post_id = discovered_feed_items.post_id;'
            );
        }
    );
    apply_schema_migration(
        $database,
        TOPIC_SCORING_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'editorial_topics', 'score', 'INTEGER');
            database_add_column_if_missing(
                $database,
                'editorial_topics',
                'scoring_breakdown_json',
                "TEXT NOT NULL DEFAULT '{}'"
            );
            database_add_column_if_missing(
                $database,
                'editorial_topics',
                'risk_level',
                "TEXT NOT NULL DEFAULT 'low'"
            );
            database_add_column_if_missing(
                $database,
                'editorial_topics',
                'automatic_eligible',
                'INTEGER NOT NULL DEFAULT 0'
            );
            database_add_column_if_missing($database, 'editorial_topics', 'scored_at', 'TEXT');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS topic_score_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic_id INTEGER NOT NULL,
                    score INTEGER NOT NULL,
                    risk_level TEXT NOT NULL,
                    automatic_eligible INTEGER NOT NULL DEFAULT 0,
                    breakdown_json TEXT NOT NULL,
                    scored_at TEXT NOT NULL,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS editorial_topics_score_idx
                    ON editorial_topics(score DESC, event_at DESC);
                CREATE INDEX IF NOT EXISTS topic_score_history_topic_idx
                    ON topic_score_history(topic_id, scored_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        GENERATION_BRIDGE_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS generation_settings (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    generation_mode TEXT NOT NULL DEFAULT "manual",
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS generation_operations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    operation_key TEXT NOT NULL UNIQUE,
                    post_id INTEGER,
                    topic_id INTEGER,
                    operation_type TEXT NOT NULL,
                    execution_mode TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT "prepared",
                    prompt_text TEXT NOT NULL,
                    input_json TEXT NOT NULL,
                    output_schema_json TEXT NOT NULL,
                    output_json TEXT,
                    input_hash TEXT NOT NULL,
                    provider TEXT NOT NULL DEFAULT "",
                    model TEXT NOT NULL DEFAULT "",
                    provider_response_id TEXT NOT NULL DEFAULT "",
                    attempt_count INTEGER NOT NULL DEFAULT 0,
                    usage_json TEXT NOT NULL DEFAULT "{}",
                    error_message TEXT NOT NULL DEFAULT "",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE SET NULL
                );
                CREATE INDEX IF NOT EXISTS generation_operations_status_idx
                    ON generation_operations(status, created_at DESC);
                CREATE INDEX IF NOT EXISTS generation_operations_topic_idx
                    ON generation_operations(topic_id, created_at DESC);'
            );
            $statement = $database->prepare(
                'INSERT OR IGNORE INTO generation_settings (id, generation_mode)
                 VALUES (1, :generation_mode)'
            );
            $statement->execute([':generation_mode' => (string) app_config('generation_mode')]);
        }
    );
    apply_schema_migration(
        $database,
        POPULAR_SCIENCE_PROFILE_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing(
                $database,
                'technical_sources',
                'profile_key',
                "TEXT NOT NULL DEFAULT 'legacy'"
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS editorial_profile_categories (
                    slug TEXT PRIMARY KEY,
                    label TEXT NOT NULL,
                    sort_order INTEGER NOT NULL DEFAULT 0,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS editorial_profile_cleanup_runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    profile_key TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    status TEXT NOT NULL,
                    preview_count INTEGER NOT NULL DEFAULT 0,
                    affected_count INTEGER NOT NULL DEFAULT 0,
                    affected_post_ids_json TEXT NOT NULL DEFAULT "[]",
                    actor TEXT NOT NULL DEFAULT "admin",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT
                );
                CREATE INDEX IF NOT EXISTS technical_sources_profile_idx
                    ON technical_sources(profile_key, is_active);
                CREATE INDEX IF NOT EXISTS editorial_profile_cleanup_runs_created_idx
                    ON editorial_profile_cleanup_runs(created_at DESC);'
            );

            $categories = [
                ['new-technologies', 'Nowe technologie', 10],
                ['how-it-works', 'Jak to działa', 20],
                ['space', 'Kosmos', 30],
                ['earth-nature', 'Ziemia i natura', 40],
                ['energy-climate', 'Energia i klimat', 50],
                ['robotics-transport', 'Robotyka i transport', 60],
                ['materials-inventions', 'Materiały i wynalazki', 70],
                ['human-technology', 'Człowiek i technologia', 80],
            ];
            $categoryStatement = $database->prepare(
                'INSERT INTO editorial_profile_categories (slug, label, sort_order)
                 VALUES (:slug, :label, :sort_order)
                 ON CONFLICT(slug) DO UPDATE SET
                    label = excluded.label,
                    sort_order = excluded.sort_order,
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP'
            );
            foreach ($categories as [$slug, $label, $sortOrder]) {
                $categoryStatement->execute([
                    ':slug' => $slug,
                    ':label' => $label,
                    ':sort_order' => $sortOrder,
                ]);
            }

            $developerSources = [
                'Google Developers Blog',
                'GitHub Changelog',
                'AWS What’s New',
                'Cloudflare Blog',
                'Microsoft DevBlogs',
            ];
            $placeholders = implode(',', array_fill(0, count($developerSources), '?'));
            $statement = $database->prepare(
                'UPDATE technical_sources
                 SET is_active = 0, profile_key = "developer",
                     updated_at = CURRENT_TIMESTAMP
                 WHERE name IN (' . $placeholders . ')'
            );
            $statement->execute($developerSources);

            $sources = [
                ['NASA Technology', 'https://www.nasa.gov/technology/', 'https://www.nasa.gov/technology/feed/', 'new-technologies', 5, 1],
                ['NASA Science', 'https://science.nasa.gov/', 'https://science.nasa.gov/feed/', 'space', 5, 1],
                ['NASA Jet Propulsion Laboratory', 'https://www.jpl.nasa.gov/news/', 'https://www.jpl.nasa.gov/feeds/news/', 'space', 5, 1],
                ['ESA Space Science', 'https://www.esa.int/Science_Exploration/Space_Science', 'https://www.esa.int/rssfeed/Our_Activities/Space_Science', 'space', 5, 1],
                ['NASA Earth Observatory', 'https://earthobservatory.nasa.gov/', 'https://earthobservatory.nasa.gov/feeds/earth-observatory.rss', 'earth-nature', 5, 1],
                ['USGS Volcano Hazards', 'https://volcanoes.usgs.gov/hans-public/', 'https://volcanoes.usgs.gov/hans-public/rss/cap/', 'earth-nature', 5, 1],
                ['CERN News', 'https://home.cern/news', 'https://home.cern/feed/', 'how-it-works', 5, 1],
                ['MIT Research News', 'https://news.mit.edu/topic/research', 'https://news.mit.edu/topic/mitresearch-rss.xml', 'new-technologies', 5, 1],
                ['Caltech Research News', 'https://www.caltech.edu/about/news', 'https://www.caltech.edu/about/news/rss', 'materials-inventions', 5, 1],
                ['Quanta Magazine', 'https://www.quantamagazine.org/', 'https://www.quantamagazine.org/feed/', 'how-it-works', 5, 0],
                ['Science News', 'https://www.sciencenews.org/', 'https://www.sciencenews.org/feed/', 'human-technology', 4, 0],
            ];
            $sourceStatement = $database->prepare(
                'INSERT INTO technical_sources (
                    name, website_url, feed_url, source_type, topic_category,
                    language, credibility_level, is_primary, is_active, profile_key
                 ) VALUES (
                    :name, :website_url, :feed_url, "rss", :topic_category,
                    "en", :credibility_level, :is_primary, 1, "popular_science"
                 )
                 ON CONFLICT(name) DO UPDATE SET
                    website_url = excluded.website_url,
                    feed_url = excluded.feed_url,
                    source_type = "rss",
                    topic_category = excluded.topic_category,
                    language = "en",
                    credibility_level = excluded.credibility_level,
                    is_primary = excluded.is_primary,
                    is_active = 1,
                    profile_key = "popular_science",
                    updated_at = CURRENT_TIMESTAMP'
            );
            foreach ($sources as [$name, $websiteUrl, $feedUrl, $category, $credibility, $primary]) {
                $sourceStatement->execute([
                    ':name' => $name,
                    ':website_url' => $websiteUrl,
                    ':feed_url' => $feedUrl,
                    ':topic_category' => $category,
                    ':credibility_level' => $credibility,
                    ':is_primary' => $primary,
                ]);
            }
        }
    );
    apply_schema_migration(
        $database,
        RESEARCH_PACKAGES_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS research_packages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic_id INTEGER NOT NULL,
                    post_id INTEGER NOT NULL,
                    generation_operation_id INTEGER NOT NULL UNIQUE,
                    status TEXT NOT NULL DEFAULT "prepared",
                    execution_mode TEXT NOT NULL,
                    package_json TEXT,
                    validation_json TEXT NOT NULL DEFAULT "{}",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (generation_operation_id) REFERENCES generation_operations(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS research_packages_topic_idx
                    ON research_packages(topic_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS research_packages_status_idx
                    ON research_packages(status, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        ARTICLE_DRAFT_VERSIONS_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'research_packages', 'approved_at', 'TEXT');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS article_draft_versions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    research_package_id INTEGER NOT NULL,
                    topic_id INTEGER NOT NULL,
                    post_id INTEGER NOT NULL,
                    generation_operation_id INTEGER NOT NULL UNIQUE,
                    version_number INTEGER NOT NULL,
                    composition_mode TEXT NOT NULL,
                    execution_mode TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT "prepared",
                    draft_json TEXT,
                    validation_json TEXT NOT NULL DEFAULT "{}",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT,
                    FOREIGN KEY (research_package_id) REFERENCES research_packages(id) ON DELETE CASCADE,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (generation_operation_id) REFERENCES generation_operations(id) ON DELETE CASCADE,
                    UNIQUE (research_package_id, version_number)
                );
                CREATE INDEX IF NOT EXISTS article_drafts_topic_idx
                    ON article_draft_versions(topic_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS article_drafts_status_idx
                    ON article_draft_versions(status, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        QUALITY_CHECKS_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS quality_check_runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    draft_version_id INTEGER NOT NULL,
                    post_id INTEGER NOT NULL,
                    generation_operation_id INTEGER NOT NULL UNIQUE,
                    check_number INTEGER NOT NULL,
                    execution_mode TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT "prepared",
                    model_score INTEGER,
                    final_score INTEGER,
                    passed INTEGER NOT NULL DEFAULT 0,
                    model_result_json TEXT,
                    deterministic_json TEXT NOT NULL DEFAULT "{}",
                    hard_blocks_json TEXT NOT NULL DEFAULT "[]",
                    validation_json TEXT NOT NULL DEFAULT "{}",
                    human_review_status TEXT NOT NULL DEFAULT "pending",
                    human_review_reason TEXT NOT NULL DEFAULT "",
                    human_reviewed_at TEXT,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT,
                    FOREIGN KEY (draft_version_id) REFERENCES article_draft_versions(id) ON DELETE CASCADE,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (generation_operation_id) REFERENCES generation_operations(id) ON DELETE CASCADE,
                    UNIQUE (draft_version_id, check_number)
                );
                CREATE INDEX IF NOT EXISTS quality_checks_draft_idx
                    ON quality_check_runs(draft_version_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS quality_checks_post_idx
                    ON quality_check_runs(post_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS quality_checks_status_idx
                    ON quality_check_runs(status, passed, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        THUMBNAIL_VERSIONS_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS thumbnail_versions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    draft_version_id INTEGER NOT NULL,
                    quality_check_id INTEGER NOT NULL,
                    post_id INTEGER NOT NULL,
                    version_number INTEGER NOT NULL,
                    execution_mode TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT "prepared",
                    is_active INTEGER NOT NULL DEFAULT 0,
                    prompt_text TEXT NOT NULL,
                    model TEXT NOT NULL DEFAULT "",
                    alt_text TEXT NOT NULL,
                    previous_image_path TEXT NOT NULL DEFAULT "",
                    previous_alt_text TEXT NOT NULL DEFAULT "",
                    original_path TEXT,
                    public_path TEXT,
                    original_mime TEXT NOT NULL DEFAULT "",
                    original_width INTEGER,
                    original_height INTEGER,
                    public_width INTEGER,
                    public_height INTEGER,
                    provider_response_id TEXT NOT NULL DEFAULT "",
                    usage_json TEXT NOT NULL DEFAULT "{}",
                    error_message TEXT NOT NULL DEFAULT "",
                    generated_at TEXT,
                    rejected_at TEXT,
                    rejection_reason TEXT NOT NULL DEFAULT "",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (draft_version_id) REFERENCES article_draft_versions(id) ON DELETE CASCADE,
                    FOREIGN KEY (quality_check_id) REFERENCES quality_check_runs(id) ON DELETE RESTRICT,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    UNIQUE (draft_version_id, version_number)
                );
                CREATE INDEX IF NOT EXISTS thumbnails_post_idx
                    ON thumbnail_versions(post_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS thumbnails_draft_idx
                    ON thumbnail_versions(draft_version_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS thumbnails_status_idx
                    ON thumbnail_versions(status, is_active, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        ARTICLE_IMAGES_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'posts', 'content_blocks', 'TEXT NOT NULL DEFAULT "[]"');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS article_images (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER NOT NULL,
                    role TEXT NOT NULL,
                    section_id TEXT NOT NULL,
                    visual_intent TEXT NOT NULL,
                    search_queries_json TEXT NOT NULL DEFAULT "[]",
                    source_page_url TEXT NOT NULL DEFAULT "",
                    source_file_url TEXT NOT NULL DEFAULT "",
                    local_path TEXT NOT NULL DEFAULT "",
                    author TEXT NOT NULL DEFAULT "",
                    license TEXT NOT NULL DEFAULT "",
                    license_url TEXT NOT NULL DEFAULT "",
                    attribution TEXT NOT NULL DEFAULT "",
                    alt TEXT NOT NULL,
                    caption TEXT NOT NULL DEFAULT "",
                    layout TEXT NOT NULL DEFAULT "full",
                    status TEXT NOT NULL DEFAULT "planned",
                    width INTEGER,
                    height INTEGER,
                    downloaded_at TEXT,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS article_images_post_idx
                    ON article_images(post_id, role, section_id);
                CREATE INDEX IF NOT EXISTS article_images_status_idx
                    ON article_images(status, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        ARTICLE_IMAGE_INDEX_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'DROP INDEX IF EXISTS article_images_local_path_idx;
                 CREATE INDEX IF NOT EXISTS article_images_local_path_idx
                    ON article_images(local_path) WHERE local_path <> "";
                 CREATE UNIQUE INDEX IF NOT EXISTS article_images_slot_idx
                    ON article_images(post_id, role, section_id);'
            );
        }
    );
}
