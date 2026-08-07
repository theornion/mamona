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
const ARTICLE_IMAGE_EXPECTED_CONTENT_MIGRATION = '20260727_016_article_image_expected_content';
const GENERATION_BATCHES_MIGRATION = '20260731_017_generation_batches';
const CONTENT_STUDIO_MIGRATION = '20260731_017_content_studio';
const PROPOSAL_REVIEW_MIGRATION = '20260731_018_proposal_review';
const TOPIC_WORKFLOWS_MIGRATION = '20260731_019_topic_workflows';
const TOPIC_TRASH_MIGRATION = '20260731_020_topic_trash';
const TOPIC_TRASH_SNAPSHOTS_MIGRATION = '20260731_021_topic_trash_snapshots';
const SOURCE_ENRICHMENT_MIGRATION = '20260731_022_source_enrichment';
const ARTICLE_IMAGE_SEMANTIC_CASCADE_MIGRATION = '20260731_023_article_image_semantic_cascade';
const FEED_RELIABILITY_MIGRATION = '20260731_024_feed_reliability';
const QC_AUTO_REPAIR_MIGRATION = '20260801_025_qc_auto_repair_status';
const INACCESSIBLE_OFFICIAL_FEEDS_MIGRATION = '20260801_027_inaccessible_official_feeds';
const QC_AUTO_REPAIR_COUNTER_MIGRATION = '20260801_026_qc_auto_repair_counter';
const FULL_AUTO_TERMINAL_MIGRATION = '20260801_028_full_auto_terminal';
const AUTONOMOUS_GENERATE_ALL_MIGRATION = '20260801_029_autonomous_generate_all';
const QUALITY_SALVAGE_ROUTER_MIGRATION = '20260801_030_quality_salvage_router';
const IMAGE_RIGHTS_MANIFEST_MIGRATION = '20260801_033_image_rights_manifest';
const IMAGE_PROVIDER_RATE_LIMIT_MIGRATION = '20260801_034_image_provider_rate_limit';
const LEGACY_CHECKPOINT_RESUME_MIGRATION = '20260801_031_legacy_checkpoint_resume';
const TEST_SOURCE_ARTIFACT_CLEANUP_MIGRATION = '20260801_035_test_source_artifact_cleanup';
const LEAKED_BATCH_FIXTURE_CLEANUP_MIGRATION = '20260801_036_leaked_batch_fixture_cleanup';
const GEMINI_GLOBAL_QUOTA_MIGRATION = '20260801_037_gemini_global_quota';
const IMAGE_INTEGRITY_MIGRATION = '20260801_038_image_integrity';
const GEMINI_LEDGER_EXTENSION_MIGRATION = '20260801_039_gemini_ledger_extension';
const AUTOMATIC_DISPATCH_PAUSE_MIGRATION = '20260801_040_automatic_dispatch_pause';
const ARTICLE_GENERATION_BUDGET_MIGRATION = '20260807_041_article_generation_budget';
const NARRATIVE_PLANS_MIGRATION = '20260807_042_narrative_plans';

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
        SOURCE_ENRICHMENT_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'editorial_topics', 'risk_level', "TEXT NOT NULL DEFAULT 'low'");
            database_add_column_if_missing($database, 'editorial_topics', 'is_controversial', 'INTEGER NOT NULL DEFAULT 0');
            database_add_column_if_missing($database, 'research_packages', 'policy_json', "TEXT NOT NULL DEFAULT '{}'");
            database_add_column_if_missing($database, 'research_packages', 'approval_actor', "TEXT NOT NULL DEFAULT ''");
            database_add_column_if_missing($database, 'research_packages', 'approval_reason', "TEXT NOT NULL DEFAULT ''");
            database_add_column_if_missing($database, 'technical_sources', 'request_timeout_seconds', 'INTEGER NOT NULL DEFAULT 20');
            database_add_column_if_missing($database, 'technical_sources', 'response_max_bytes', 'INTEGER NOT NULL DEFAULT 3145728');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS verified_research_sources (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic_id INTEGER NOT NULL,
                    discovery_feed_item_id INTEGER,
                    source_kind TEXT NOT NULL,
                    is_primary INTEGER NOT NULL DEFAULT 0,
                    is_peer_reviewed INTEGER NOT NULL DEFAULT 0,
                    publisher TEXT NOT NULL DEFAULT "",
                    title TEXT NOT NULL,
                    published_at TEXT,
                    identifier_type TEXT NOT NULL DEFAULT "",
                    identifier_value TEXT NOT NULL DEFAULT "",
                    canonical_url TEXT NOT NULL,
                    verification_method TEXT NOT NULL,
                    verification_status TEXT NOT NULL DEFAULT "verified",
                    completeness TEXT NOT NULL DEFAULT "metadata_only",
                    evidence_json TEXT NOT NULL DEFAULT "[]",
                    content_excerpt TEXT NOT NULL DEFAULT "",
                    content_fingerprint TEXT NOT NULL,
                    verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE,
                    FOREIGN KEY (discovery_feed_item_id) REFERENCES discovered_feed_items(id) ON DELETE SET NULL,
                    UNIQUE(topic_id, canonical_url)
                );
                CREATE INDEX IF NOT EXISTS verified_sources_topic_idx
                    ON verified_research_sources(topic_id, verification_status, is_primary);
                CREATE TABLE IF NOT EXISTS research_policy_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic_id INTEGER NOT NULL,
                    research_package_id INTEGER,
                    decision TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    policy_json TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT "system",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE,
                    FOREIGN KEY (research_package_id) REFERENCES research_packages(id) ON DELETE SET NULL
                );'
            );

            /* example.com is a documentation fixture, never a production feed. */
            $database->exec("UPDATE technical_sources SET is_active = 0, last_error = 'Deactivated fixture endpoint' WHERE is_active = 1 AND (feed_url LIKE '%://example.com/%' OR website_url LIKE '%://example.com/%')");
            $sources = [
                ['NSF News', 'https://www.nsf.gov/news/', 'https://www.nsf.gov/rss/rss_www_news.xml', 'new-technologies', 1, 1],
                ['NIH News Releases', 'https://www.nih.gov/news-releases', 'https://www.nih.gov/news-releases/feed.xml', 'human-technology', 1, 0],
                ['NIEHS News', 'https://www.niehs.nih.gov/news/', 'https://www.niehs.nih.gov/news/newsroom/rssfeed/rss_news.xml', 'human-technology', 1, 1],
                ['NIEHS Recently Published Research', 'https://www.niehs.nih.gov/news/', 'https://www.niehs.nih.gov/news/newsroom/rssfeed/rss_recently_published_research.xml', 'human-technology', 1, 1],
                ['ESO News', 'https://www.eso.org/public/news/', 'https://www.eso.org/public/news/feed/', 'space', 1, 1],
            ];
            $insert = $database->prepare('INSERT INTO technical_sources (name, website_url, feed_url, source_type, topic_category, language, credibility_level, is_primary, is_active, profile_key) VALUES (:name,:website,:feed,"rss",:category,"en",5,:primary,:active,"popular_science") ON CONFLICT(name) DO UPDATE SET website_url=excluded.website_url, feed_url=excluded.feed_url, is_active=excluded.is_active, updated_at=CURRENT_TIMESTAMP');
            foreach ($sources as [$name, $website, $feed, $category, $primary, $active]) {
                $insert->execute([':name'=>$name, ':website'=>$website, ':feed'=>$feed, ':category'=>$category, ':primary'=>$primary, ':active'=>$active]);
            }
            $database->exec("UPDATE technical_sources SET request_timeout_seconds=30 WHERE name='ESA Space Science'");
            $database->exec("UPDATE technical_sources SET request_timeout_seconds=30, response_max_bytes=6291456 WHERE name='MIT Research News'");
            $database->exec("UPDATE technical_sources SET last_error='HTTP 403 during endpoint validation; source remains configurable but inactive' WHERE name='NIH News Releases'");
            $database->exec("UPDATE technical_sources SET last_error='Endpoint returns HTTP 403; TLS verification remains enabled' WHERE name='NASA Jet Propulsion Laboratory'");
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
                    expected_content TEXT NOT NULL DEFAULT "",
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
    apply_schema_migration(
        $database,
        ARTICLE_IMAGE_EXPECTED_CONTENT_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing(
                $database,
                'article_images',
                'expected_content',
                'TEXT NOT NULL DEFAULT ""'
            );
        }
    );
    apply_schema_migration(
        $database,
        ARTICLE_IMAGE_SEMANTIC_CASCADE_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'article_images', 'relationship', 'TEXT NOT NULL DEFAULT "exact_subject"');
            database_add_column_if_missing($database, 'article_images', 'search_audit_json', 'TEXT NOT NULL DEFAULT "[]"');
        }
    );
    apply_schema_migration(
        $database,
        IMAGE_RIGHTS_MANIFEST_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'article_images', 'rights_manifest_json', 'TEXT NOT NULL DEFAULT "{}"');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS image_provider_cache (
                    provider TEXT NOT NULL,
                    query_hash TEXT NOT NULL,
                    response_json TEXT NOT NULL DEFAULT "{}",
                    expires_at TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(provider, query_hash)
                );
                CREATE INDEX IF NOT EXISTS image_provider_cache_expiry_idx
                    ON image_provider_cache(expires_at);'
            );
        }
    );
    apply_schema_migration(
        $database,
        IMAGE_PROVIDER_RATE_LIMIT_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS image_provider_rate_windows (
                    provider TEXT NOT NULL,
                    window_started_at TEXT NOT NULL,
                    request_count INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY(provider, window_started_at)
                );'
            );
        }
    );
    apply_schema_migration(
        $database,
        CONTENT_STUDIO_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS editorial_ingestion_jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    status TEXT NOT NULL DEFAULT "queued",
                    stage TEXT NOT NULL DEFAULT "queued",
                    current_source TEXT NOT NULL DEFAULT "",
                    processed_units INTEGER NOT NULL DEFAULT 0,
                    total_units INTEGER NOT NULL DEFAULT 0,
                    active_source_count INTEGER NOT NULL DEFAULT 0,
                    created_count INTEGER NOT NULL DEFAULT 0,
                    duplicate_count INTEGER NOT NULL DEFAULT 0,
                    failed_source_count INTEGER NOT NULL DEFAULT 0,
                    source_results_json TEXT NOT NULL DEFAULT "[]",
                    grouping_result_json TEXT NOT NULL DEFAULT "{}",
                    scoring_result_json TEXT NOT NULL DEFAULT "{}",
                    error_message TEXT NOT NULL DEFAULT "",
                    requested_by TEXT NOT NULL DEFAULT "admin",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    started_at TEXT,
                    heartbeat_at TEXT,
                    finished_at TEXT
                );
                CREATE INDEX IF NOT EXISTS editorial_ingestion_jobs_created_idx
                    ON editorial_ingestion_jobs(created_at DESC);
                CREATE UNIQUE INDEX IF NOT EXISTS editorial_ingestion_one_active_idx
                    ON editorial_ingestion_jobs((1))
                    WHERE status IN ("queued", "running");'
            );
        }
    );
    apply_schema_migration(
        $database,
        FEED_RELIABILITY_MIGRATION,
        static function (PDO $database): void {
            foreach ([
                'feed_connect_timeout_seconds' => 'INTEGER',
                'feed_transfer_timeout_seconds' => 'INTEGER',
                'feed_low_speed_limit' => 'INTEGER',
                'feed_low_speed_time_seconds' => 'INTEGER',
                'feed_max_attempts' => 'INTEGER',
                'feed_job_budget_seconds' => 'INTEGER',
                'feed_etag' => 'TEXT NOT NULL DEFAULT ""',
                'feed_last_modified' => 'TEXT NOT NULL DEFAULT ""',
                'consecutive_failures' => 'INTEGER NOT NULL DEFAULT 0',
                'health_status' => 'TEXT NOT NULL DEFAULT "healthy"',
                'muted_until' => 'TEXT',
                'last_http_status' => 'INTEGER',
                'last_transport_diagnostics' => 'TEXT NOT NULL DEFAULT "{}"',
            ] as $column => $definition) {
                database_add_column_if_missing($database, 'technical_sources', $column, $definition);
            }
            foreach ([
                'succeeded_source_count' => 'INTEGER NOT NULL DEFAULT 0',
                'not_modified_source_count' => 'INTEGER NOT NULL DEFAULT 0',
                'retried_source_count' => 'INTEGER NOT NULL DEFAULT 0',
            ] as $column => $definition) {
                database_add_column_if_missing($database, 'editorial_ingestion_jobs', $column, $definition);
            }
            $database->exec("UPDATE technical_sources SET feed_url='https://science.nasa.gov/feed/?science_org=19791%2C22453', last_error='' WHERE name='NASA Earth Observatory'");
            $database->exec("UPDATE technical_sources SET health_status='unavailable', last_error='Oficjalny endpoint zwraca HTTP 403 dla automatycznego klienta; zabezpieczenia nie są obchodzone', muted_until=datetime('now', '+30 minutes') WHERE name='NASA Jet Propulsion Laboratory'");
            $database->exec("UPDATE technical_sources SET health_status='healthy', last_error='' WHERE name IN ('NIEHS News','NIH News Releases','NSF News','Quanta Magazine')");
        }
    );
    apply_schema_migration(
        $database,
        INACCESSIBLE_OFFICIAL_FEEDS_MIGRATION,
        static function (PDO $database): void {
            $deactivate = $database->prepare(
                'UPDATE technical_sources
                 SET is_active = 0, health_status = "unavailable", muted_until = NULL,
                     last_error = :reason, updated_at = CURRENT_TIMESTAMP
                 WHERE name = :name OR feed_url = :feed_url'
            );
            $deactivate->execute([
                ':name' => 'NASA Jet Propulsion Laboratory',
                ':feed_url' => 'https://www.jpl.nasa.gov/feeds/news/',
                ':reason' => 'Wyłączone: oficjalny endpoint stale odmawia bezpiecznemu klientowi RSS (HTTP 403). Treści JPL są pokrywane przez aktywne kanały NASA Science i NASA Technology; ochrona nie jest obchodzona.',
            ]);
            $deactivate->execute([
                ':name' => 'NIH News Releases',
                ':feed_url' => 'https://www.nih.gov/news-releases/feed.xml',
                ':reason' => 'Wyłączone: oficjalny endpoint stale odmawia bezpiecznemu klientowi RSS (HTTP 403). Profil biomedyczny pozostaje pokryty przez oficjalne kanały NIEHS i NIBIB; ochrona nie jest obchodzona.',
            ]);

            $database->exec(
                'INSERT INTO technical_sources (
                    name, website_url, feed_url, source_type, topic_category,
                    language, credibility_level, is_primary, is_active, profile_key,
                    health_status, last_error
                 ) VALUES (
                    "NIBIB News", "https://www.nibib.nih.gov/news-events/newsroom",
                    "https://www.nibib.nih.gov/rss", "rss", "human-technology",
                    "en", 5, 1, 1, "popular_science", "healthy", ""
                 )
                 ON CONFLICT(name) DO UPDATE SET
                    website_url = excluded.website_url, feed_url = excluded.feed_url,
                    source_type = excluded.source_type, topic_category = excluded.topic_category,
                    language = excluded.language, credibility_level = excluded.credibility_level,
                    is_primary = excluded.is_primary, is_active = excluded.is_active,
                    profile_key = excluded.profile_key, health_status = excluded.health_status,
                    muted_until = NULL, last_error = excluded.last_error,
                    updated_at = CURRENT_TIMESTAMP'
            );
        }
    );
    apply_schema_migration(
        $database,
        GENERATION_BATCHES_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS generation_batches (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    batch_key TEXT NOT NULL UNIQUE,
                    request_key TEXT NOT NULL UNIQUE,
                    status TEXT NOT NULL DEFAULT "queued",
                    item_count INTEGER NOT NULL,
                    created_by TEXT NOT NULL DEFAULT "admin",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT
                );
                CREATE TABLE IF NOT EXISTS generation_batch_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    batch_id INTEGER NOT NULL,
                    topic_id INTEGER NOT NULL,
                    status TEXT NOT NULL DEFAULT "queued",
                    stage TEXT NOT NULL DEFAULT "research",
                    progress_percent INTEGER NOT NULL DEFAULT 0,
                    research_operation_id INTEGER,
                    research_package_id INTEGER,
                    draft_operation_id INTEGER,
                    draft_version_id INTEGER,
                    quality_operation_id INTEGER,
                    quality_check_id INTEGER,
                    post_id INTEGER,
                    retry_count INTEGER NOT NULL DEFAULT 0,
                    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    lease_token TEXT,
                    lease_expires_at TEXT,
                    wait_reason TEXT NOT NULL DEFAULT "",
                    error_message TEXT NOT NULL DEFAULT "",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT,
                    FOREIGN KEY (batch_id) REFERENCES generation_batches(id) ON DELETE CASCADE,
                    FOREIGN KEY (topic_id) REFERENCES editorial_topics(id) ON DELETE CASCADE,
                    UNIQUE(batch_id, topic_id)
                );
                CREATE TABLE IF NOT EXISTS generation_batch_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    batch_id INTEGER NOT NULL,
                    item_id INTEGER,
                    action TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT "admin",
                    details_json TEXT NOT NULL DEFAULT "{}",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (batch_id) REFERENCES generation_batches(id) ON DELETE CASCADE,
                    FOREIGN KEY (item_id) REFERENCES generation_batch_items(id) ON DELETE SET NULL
                );
                CREATE INDEX IF NOT EXISTS generation_batch_items_work_idx
                    ON generation_batch_items(status, available_at, lease_expires_at, id);
                CREATE INDEX IF NOT EXISTS generation_batch_items_topic_idx
                    ON generation_batch_items(topic_id, status);
                CREATE INDEX IF NOT EXISTS generation_batch_audit_batch_idx
                    ON generation_batch_audit(batch_id, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        PROPOSAL_REVIEW_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'article_draft_versions', 'parent_version_id', 'INTEGER');
            database_add_column_if_missing($database, 'article_draft_versions', 'change_source', 'TEXT NOT NULL DEFAULT "gemini"');
            database_add_column_if_missing($database, 'article_draft_versions', 'is_active', 'INTEGER NOT NULL DEFAULT 0');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS article_feedback_operations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER NOT NULL,
                    source_draft_version_id INTEGER NOT NULL,
                    result_draft_version_id INTEGER,
                    generation_operation_id INTEGER,
                    scope TEXT NOT NULL,
                    section_id TEXT NOT NULL DEFAULT "",
                    notes TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT "prepared",
                    actor TEXT NOT NULL DEFAULT "admin",
                    immutable_rules_json TEXT NOT NULL DEFAULT "[]",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (source_draft_version_id) REFERENCES article_draft_versions(id) ON DELETE RESTRICT,
                    FOREIGN KEY (result_draft_version_id) REFERENCES article_draft_versions(id) ON DELETE SET NULL,
                    FOREIGN KEY (generation_operation_id) REFERENCES generation_operations(id) ON DELETE SET NULL
                );
                CREATE TABLE IF NOT EXISTS article_proposal_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER NOT NULL,
                    draft_version_id INTEGER,
                    action TEXT NOT NULL,
                    actor TEXT NOT NULL DEFAULT "admin",
                    details_json TEXT NOT NULL DEFAULT "{}",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                    FOREIGN KEY (draft_version_id) REFERENCES article_draft_versions(id) ON DELETE SET NULL
                );
                CREATE INDEX IF NOT EXISTS feedback_post_idx ON article_feedback_operations(post_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS proposal_audit_post_idx ON article_proposal_audit(post_id, created_at DESC);'
            );
            $database->exec(
                'UPDATE article_draft_versions SET is_active = 1
                 WHERE status = "completed" AND id IN (
                    SELECT MAX(id) FROM article_draft_versions
                    WHERE status = "completed" GROUP BY post_id
                 )'
            );
        }
    );
    apply_schema_migration(
        $database,
        TOPIC_WORKFLOWS_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'generation_batches', 'action', 'TEXT NOT NULL DEFAULT "generate_all"');
            database_add_column_if_missing($database, 'generation_batch_items', 'requested_stage', 'TEXT NOT NULL DEFAULT ""');
            database_add_column_if_missing($database, 'generation_batch_items', 'outcome', 'TEXT NOT NULL DEFAULT "queued"');
            $database->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS generation_batch_one_active_topic_idx
                    ON generation_batch_items(topic_id)
                    WHERE status IN ("queued", "research", "draft", "quality_check", "images", "rate_limited");
                 CREATE INDEX IF NOT EXISTS generation_batches_action_idx
                    ON generation_batches(action, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        TOPIC_TRASH_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'editorial_topics', 'trashed_at', 'TEXT');
            database_add_column_if_missing($database, 'editorial_topics', 'trashed_by', 'TEXT');
            database_add_column_if_missing($database, 'editorial_topics', 'trash_reason', 'TEXT NOT NULL DEFAULT ""');
            database_add_column_if_missing($database, 'editorial_topics', 'trash_origin', 'TEXT NOT NULL DEFAULT "admin"');
            database_add_column_if_missing($database, 'editorial_topics', 'purged_at', 'TEXT');
            database_add_column_if_missing($database, 'editorial_topics', 'purged_by', 'TEXT');
            database_add_column_if_missing($database, 'editorial_topics', 'pre_trash_automatic_eligible', 'INTEGER');
            database_add_column_if_missing($database, 'editorial_topics', 'pre_trash_post_status', 'TEXT');
            database_add_column_if_missing($database, 'editorial_topics', 'pre_trash_score', 'INTEGER');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS topic_trash_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic_id INTEGER NOT NULL,
                    topic_title TEXT NOT NULL,
                    action TEXT NOT NULL,
                    actor TEXT NOT NULL,
                    reason TEXT NOT NULL DEFAULT "",
                    origin TEXT NOT NULL DEFAULT "admin",
                    details_json TEXT NOT NULL DEFAULT "{}",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS topic_trash_cleanup_runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    cutoff_at TEXT NOT NULL,
                    deleted_count INTEGER NOT NULL DEFAULT 0,
                    skipped_count INTEGER NOT NULL DEFAULT 0,
                    error_count INTEGER NOT NULL DEFAULT 0,
                    error_json TEXT NOT NULL DEFAULT "[]",
                    started_at TEXT NOT NULL,
                    finished_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS editorial_topics_trash_idx
                    ON editorial_topics(trashed_at, purged_at);
                CREATE INDEX IF NOT EXISTS topic_trash_audit_topic_idx
                    ON topic_trash_audit(topic_id, created_at DESC);'
            );
        }
    );
    apply_schema_migration(
        $database,
        TOPIC_TRASH_SNAPSHOTS_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'editorial_topics', 'pre_trash_post_status', 'TEXT');
            database_add_column_if_missing($database, 'editorial_topics', 'pre_trash_score', 'INTEGER');
        }
    );
    apply_schema_migration(
        $database,
        QC_AUTO_REPAIR_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'DROP INDEX IF EXISTS generation_batch_one_active_topic_idx;
                 CREATE UNIQUE INDEX generation_batch_one_active_topic_idx
                    ON generation_batch_items(topic_id)
                    WHERE status IN ("queued", "research", "draft", "auto_repair", "quality_check", "images", "rate_limited");'
            );
        }
    );
    apply_schema_migration(
        $database,
        QC_AUTO_REPAIR_COUNTER_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'generation_batch_items', 'auto_repair_count', 'INTEGER NOT NULL DEFAULT 0');
        }
    );
    apply_schema_migration(
        $database,
        FULL_AUTO_TERMINAL_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'editorial_topics', 'automation_status', 'TEXT NOT NULL DEFAULT ""');
            database_add_column_if_missing($database, 'editorial_topics', 'automation_reason', 'TEXT NOT NULL DEFAULT ""');
            database_add_column_if_missing($database, 'editorial_topics', 'automation_updated_at', 'TEXT');
        }
    );
    apply_schema_migration(
        $database,
        AUTONOMOUS_GENERATE_ALL_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'generation_batches', 'execution_mode', 'TEXT NOT NULL DEFAULT "api"');
            database_add_column_if_missing($database, 'article_draft_versions', 'repair_strategy', 'TEXT NOT NULL DEFAULT ""');
            $database->exec(
                'UPDATE generation_batch_items
                 SET status="auto_repair", stage="quality_check", outcome="safe_composer_queued", progress_percent=84,
                     wait_reason="Wznawiam przez bezpieczny kompozytor.", completed_at=NULL,
                     available_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP
                 WHERE status="waiting_review" AND id IN (
                    SELECT items.id FROM generation_batch_items items
                    INNER JOIN generation_batches batches ON batches.id=items.batch_id
                    WHERE batches.action="generate_all" AND batches.execution_mode="api"
                      AND (
                        SELECT COUNT(DISTINCT audit.id) FROM generation_batch_audit audit
                        WHERE audit.item_id=items.id AND audit.action="auto_repair_draft_validated"
                      ) >= 2
                 );
                 UPDATE editorial_topics
                 SET automatic_eligible=1, automation_status="auto_repair",
                     automation_reason="Wznawianie przez bezpieczny kompozytor po wyczerpaniu korekt modelowych.",
                     automation_updated_at=CURRENT_TIMESTAMP
                 WHERE id IN (
                    SELECT topic_id FROM generation_batch_items
                    WHERE status="auto_repair" AND outcome="safe_composer_queued"
                 );'
            );
            $audit = $database->prepare(
                'INSERT INTO generation_batch_audit (batch_id,item_id,action,actor,details_json)
                 SELECT items.batch_id,items.id,"autonomous_item_reconciled","migration",:details
                 FROM generation_batch_items items
                 WHERE items.status="auto_repair" AND items.outcome="safe_composer_queued"
                   AND NOT EXISTS (
                    SELECT 1 FROM generation_batch_audit existing
                    WHERE existing.item_id=items.id AND existing.action="autonomous_item_reconciled"
                   )'
            );
            $audit->execute([':details' => json_encode([
                'decision' => 'safe_composer_queued', 'reason' => 'two_completed_repairs',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    );
    apply_schema_migration(
        $database,
        QUALITY_SALVAGE_ROUTER_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS generation_repair_reports (
                    item_id INTEGER PRIMARY KEY,
                    report_json TEXT NOT NULL DEFAULT "[]",
                    unresolved_json TEXT NOT NULL DEFAULT "[]",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (item_id) REFERENCES generation_batch_items(id) ON DELETE CASCADE
                 );
                 CREATE INDEX IF NOT EXISTS generation_repair_reports_updated_idx
                    ON generation_repair_reports(updated_at DESC);
                 DROP INDEX IF EXISTS generation_batch_one_active_topic_idx;
                 CREATE UNIQUE INDEX generation_batch_one_active_topic_idx
                    ON generation_batch_items(topic_id)
                    WHERE status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled");
                 UPDATE generation_batch_items
                 SET status="auto_repair",stage="quality_check",outcome="safe_composer_queued",progress_percent=84,
                     wait_reason="Wznawiam przez bezpieczny kompozytor.",completed_at=NULL,available_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP
                 WHERE status IN ("waiting_review","auto_rejected")
                   AND outcome IN ("auto_repair_limit","auto_repair_limit_reconciled")
                   AND id IN (SELECT items.id FROM generation_batch_items items
                     INNER JOIN generation_batches batches ON batches.id=items.batch_id
                     WHERE batches.action="generate_all" AND batches.execution_mode="api"
                       AND NOT EXISTS (SELECT 1 FROM generation_batch_items active_item
                         WHERE active_item.topic_id=items.topic_id AND active_item.id<>items.id
                           AND active_item.status IN ("queued","research","draft","auto_repair","quality_check","images","rate_limited","auto_retry_scheduled")));
                 UPDATE editorial_topics SET automatic_eligible=1,automation_status="auto_repair",
                    automation_reason="Wznowienie przez router naprawczy.",automation_updated_at=CURRENT_TIMESTAMP
                 WHERE id IN (SELECT topic_id FROM generation_batch_items WHERE status="auto_repair" AND outcome="safe_composer_queued");'
            );
        }
    );
    apply_schema_migration(
        $database,
        LEGACY_CHECKPOINT_RESUME_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'generation_batch_items', 'migrated_from_item_id', 'INTEGER');
            database_add_column_if_missing($database, 'generation_batch_items', 'chosen_checkpoint', 'TEXT NOT NULL DEFAULT ""');
            $database->exec('CREATE UNIQUE INDEX IF NOT EXISTS generation_batch_migrated_item_idx
                ON generation_batch_items(migrated_from_item_id) WHERE migrated_from_item_id IS NOT NULL;');
        }
    );
    apply_schema_migration(
        $database,
        TEST_SOURCE_ARTIFACT_CLEANUP_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS test_artifact_cleanup_audit (
                    migration_key TEXT PRIMARY KEY,
                    removed_count INTEGER NOT NULL,
                    marker TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                 )'
            );
            $predicate = 'name GLOB "Batch smoke [0-9]*"
                AND name NOT GLOB "Batch smoke *[^0-9]"
                AND website_url = "https://example.com/" || substr(name, 13)
                AND feed_url = "https://example.com/" || substr(name, 13) || ".xml"
                AND NOT EXISTS (SELECT 1 FROM discovered_feed_items WHERE technical_source_id = technical_sources.id)';
            $count = (int) $database->query('SELECT COUNT(*) FROM technical_sources WHERE ' . $predicate)->fetchColumn();
            $database->exec('DELETE FROM technical_sources WHERE ' . $predicate);
            $audit = $database->prepare(
                'INSERT OR REPLACE INTO test_artifact_cleanup_audit (migration_key, removed_count, marker)
                 VALUES (:migration, :count, :marker)'
            );
            $audit->execute([
                ':migration' => TEST_SOURCE_ARTIFACT_CLEANUP_MIGRATION,
                ':count' => $count,
                ':marker' => 'exact Batch smoke <digits> + matching example.com fixture URLs + no discovered items',
            ]);
        }
    );
    apply_schema_migration(
        $database,
        LEAKED_BATCH_FIXTURE_CLEANUP_MIGRATION,
        static function (PDO $database): void {
            $database->exec('CREATE TEMP TABLE leaked_batch_fixture_sources (id INTEGER PRIMARY KEY)');
            $database->exec(
                'INSERT INTO leaked_batch_fixture_sources (id)
                 SELECT sources.id FROM technical_sources sources
                 WHERE sources.name GLOB "Batch smoke [0-9]*"
                   AND sources.name NOT GLOB "Batch smoke *[^0-9]"
                   AND sources.website_url = "https://example.com/" || substr(sources.name, 13)
                   AND sources.feed_url = "https://example.com/" || substr(sources.name, 13) || ".xml"
                   AND NOT EXISTS (
                       SELECT 1 FROM discovered_feed_items items
                       INNER JOIN posts ON posts.id=items.post_id
                       WHERE items.technical_source_id=sources.id
                         AND (items.source_name<>"Batch smoke"
                           OR items.source_url NOT LIKE sources.website_url || "/article-%"
                           OR posts.editorial_origin<>"automatic" OR posts.is_published<>0)
                   )'
            );
            $removed = (int) $database->query('SELECT COUNT(*) FROM leaked_batch_fixture_sources')->fetchColumn();
            $database->exec(
                'DELETE FROM posts WHERE id IN (
                    SELECT post_id FROM discovered_feed_items
                    WHERE technical_source_id IN (SELECT id FROM leaked_batch_fixture_sources)
                 );
                 DELETE FROM technical_sources WHERE id IN (SELECT id FROM leaked_batch_fixture_sources);'
            );
            $audit = $database->prepare(
                'INSERT OR REPLACE INTO test_artifact_cleanup_audit (migration_key, removed_count, marker)
                 VALUES (:migration, :count, :marker)'
            );
            $audit->execute([
                ':migration' => LEAKED_BATCH_FIXTURE_CLEANUP_MIGRATION,
                ':count' => $removed,
                ':marker' => 'numeric Batch smoke source + exact example.com fixture URLs + automatic unpublished fixture items only',
            ]);
            $database->exec('DROP TABLE leaked_batch_fixture_sources');
        }
    );
    apply_schema_migration(
        $database,
        GEMINI_GLOBAL_QUOTA_MIGRATION,
        static function (PDO $database): void {
            foreach ([
                'model_used' => 'TEXT NOT NULL DEFAULT ""',
                'call_reason' => 'TEXT NOT NULL DEFAULT ""',
                'call_fingerprint' => 'TEXT NOT NULL DEFAULT ""',
                'live_request_count' => 'INTEGER NOT NULL DEFAULT 0',
                'next_retry_at' => 'TEXT',
                'quota_dimension' => 'TEXT NOT NULL DEFAULT ""',
            ] as $column => $definition) {
                database_add_column_if_missing($database, 'generation_operations', $column, $definition);
            }
            foreach ([
                'next_retry_at' => 'TEXT',
                'quota_dimension' => 'TEXT NOT NULL DEFAULT ""',
                'quota_model' => 'TEXT NOT NULL DEFAULT ""',
            ] as $column => $definition) {
                database_add_column_if_missing($database, 'generation_batch_items', $column, $definition);
            }
            $database->exec(
                'CREATE TABLE IF NOT EXISTS gemini_quota_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    project_key TEXT NOT NULL,
                    model TEXT NOT NULL,
                    operation_id INTEGER,
                    topic_id INTEGER,
                    batch_id INTEGER,
                    item_id INTEGER,
                    stage TEXT NOT NULL DEFAULT "",
                    attempt INTEGER NOT NULL DEFAULT 1,
                    call_reason TEXT NOT NULL DEFAULT "",
                    fingerprint TEXT NOT NULL,
                    estimated_tokens INTEGER NOT NULL DEFAULT 0,
                    actual_tokens INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT "reserved",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    completed_at TEXT
                );
                CREATE INDEX IF NOT EXISTS gemini_quota_events_window_idx
                    ON gemini_quota_events(project_key, model, created_at);
                CREATE TABLE IF NOT EXISTS gemini_quota_state (
                    project_key TEXT NOT NULL,
                    model TEXT NOT NULL,
                    quota_dimension TEXT NOT NULL DEFAULT "",
                    next_retry_at TEXT,
                    last_http_status INTEGER NOT NULL DEFAULT 0,
                    details_json TEXT NOT NULL DEFAULT "{}",
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(project_key, model)
                );
                CREATE TABLE IF NOT EXISTS gemini_model_leases (
                    project_key TEXT NOT NULL,
                    model TEXT NOT NULL,
                    lease_token TEXT NOT NULL,
                    operation_id INTEGER,
                    expires_at TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(project_key, model)
                );
                CREATE TABLE IF NOT EXISTS gemini_call_cache (
                    project_key TEXT NOT NULL,
                    model TEXT NOT NULL,
                    fingerprint TEXT NOT NULL,
                    output_json TEXT NOT NULL,
                    provider_response_id TEXT NOT NULL DEFAULT "",
                    usage_json TEXT NOT NULL DEFAULT "{}",
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(project_key, model, fingerprint)
                );
                CREATE TABLE IF NOT EXISTS generation_worker_guard (
                    guard_key INTEGER PRIMARY KEY CHECK(guard_key=1),
                    lease_token TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );'
            );
        }
    );
    apply_schema_migration(
        $database,
        IMAGE_INTEGRITY_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'article_images', 'has_transparency', 'INTEGER NOT NULL DEFAULT 0');
            database_add_column_if_missing($database, 'article_images', 'watermark_status', 'TEXT NOT NULL DEFAULT ""');
        }
    );
    apply_schema_migration(
        $database,
        GEMINI_LEDGER_EXTENSION_MIGRATION,
        static function (PDO $database): void {
            foreach ([
                'topic_id' => 'INTEGER', 'batch_id' => 'INTEGER', 'item_id' => 'INTEGER',
                'stage' => 'TEXT NOT NULL DEFAULT ""', 'attempt' => 'INTEGER NOT NULL DEFAULT 1',
            ] as $column => $definition) {
                database_add_column_if_missing($database, 'gemini_quota_events', $column, $definition);
            }
            $database->exec('CREATE TABLE IF NOT EXISTS generation_worker_guard (
                guard_key INTEGER PRIMARY KEY CHECK(guard_key=1), lease_token TEXT NOT NULL,
                expires_at TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );');
        }
    );
    apply_schema_migration(
        $database,
        AUTOMATIC_DISPATCH_PAUSE_MIGRATION,
        static function (PDO $database): void {
            database_add_column_if_missing($database, 'generation_settings', 'automatic_dispatch_paused', 'INTEGER NOT NULL DEFAULT 0');
            database_add_column_if_missing($database, 'generation_settings', 'automatic_dispatch_paused_at', 'TEXT');
            database_add_column_if_missing($database, 'generation_batches', 'dispatch_mode', 'TEXT NOT NULL DEFAULT "automatic"');
            database_add_column_if_missing($database, 'generation_batch_items', 'paused_from_status', 'TEXT NOT NULL DEFAULT ""');
            database_add_column_if_missing($database, 'generation_batch_items', 'paused_at', 'TEXT');
            $database->exec('CREATE INDEX IF NOT EXISTS generation_batches_dispatch_mode_idx ON generation_batches(dispatch_mode, status);');
        }
    );
    apply_schema_migration(
        $database,
        ARTICLE_GENERATION_BUDGET_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS article_generation_budget (' .
                    'article_id INTEGER PRIMARY KEY,' .
                    'max_calls INTEGER NOT NULL DEFAULT 20,' .
                    'used_calls INTEGER NOT NULL DEFAULT 0,' .
                    'convergence_threshold INTEGER NOT NULL DEFAULT 16,' .
                    'calls_log_json TEXT DEFAULT "[]",' .
                    'is_exhausted INTEGER NOT NULL DEFAULT 0,' .
                    'convergence_active INTEGER NOT NULL DEFAULT 0,' .
                    'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,' .
                    'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP' .
                ')'
            );
            database_add_column_if_missing($database, 'article_images', 'is_fallback', 'INTEGER NOT NULL DEFAULT 0');
            database_add_column_if_missing($database, 'article_images', 'semantic_score', 'INTEGER');
            database_add_column_if_missing($database, 'article_images', 'editorial_rejected', 'INTEGER NOT NULL DEFAULT 0');
            database_add_column_if_missing($database, 'generation_batch_items', 'convergence_active', 'INTEGER NOT NULL DEFAULT 0');
            $database->prepare(
                'UPDATE article_images SET is_fallback = 1 WHERE local_path LIKE "%editorial-fallback/%" OR search_audit_json LIKE "%local_fallback%"'
            )->execute();
        }
    );
    apply_schema_migration(
        $database,
        NARRATIVE_PLANS_MIGRATION,
        static function (PDO $database): void {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS narrative_plans (' .
                    'id INTEGER PRIMARY KEY AUTOINCREMENT,' .
                    'article_id INTEGER NOT NULL,' .
                    'promise_to_reader TEXT NOT NULL DEFAULT "",' .
                    'main_thesis TEXT NOT NULL DEFAULT "",' .
                    'narrative_arc TEXT NOT NULL DEFAULT "",' .
                    'arc_justification TEXT NOT NULL DEFAULT "",' .
                    'sections_json TEXT NOT NULL DEFAULT "[]",' .
                    'transitions_json TEXT NOT NULL DEFAULT "[]",' .
                    'rhythm_notes TEXT NOT NULL DEFAULT "",' .
                    'visual_slots_planned INTEGER NOT NULL DEFAULT 1,' .
                    'hero_topic_ref TEXT NOT NULL DEFAULT "A",' .
                    'ending_type TEXT NOT NULL DEFAULT "",' .
                    'supplemental_topics_json TEXT NOT NULL DEFAULT "[]",' .
                    'target_length INTEGER NOT NULL DEFAULT 4000,' .
                    'status TEXT NOT NULL DEFAULT "planned",' .
                    'batch_stage_ref TEXT,' .
                    'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,' .
                    'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP' .
                ')'
            );
        }
    );
}
