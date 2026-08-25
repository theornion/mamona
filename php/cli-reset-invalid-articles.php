<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin-database.php';

/**
 * P3-D — Deterministyczny audyt i reset wadliwych artykułów.
 *
 * Nie używa Gemini ani providerów grafik.
 * Tryby: --dry-run (manifest) lub --apply (reset z backupem).
 * Fail-closed: --apply możliwy TYLKO gdy CMS_TEST_DATABASE_FILE jest ustawiona.
 */

const RESET_INVALID_ARTICLES_BACKUP_DIR = __DIR__ . '/../data/backups';

function main(): void
{
    // $argv may be unregistered on some Windows PHP builds; fall back to $_SERVER['argv'].
    $rawArgs = isset($argv) ? $argv : ($_SERVER['argv'] ?? []);
    $arguments = array_slice($rawArgs, 1);

    if (count($arguments) !== 1 || !in_array($arguments[0], ['--dry-run', '--apply'], true)) {
        fwrite(STDERR, "Użycie: php php/cli-reset-invalid-articles.php [--dry-run|--apply]\n");
        exit(2);
    }

    $mode = $arguments[0];
    $db = bueno_database();

    $candidates = audit_invalid_articles($db);

    if ($candidates === []) {
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'mode' => $mode,
            'candidates' => 0,
            'message' => 'Brak wadliwych artykułów do resetu.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($mode === '--dry-run') {
        $manifest = build_manifest($db, $candidates);
        fwrite(STDOUT, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    // --apply mode — fail-closed guard: tylko na testowej bazie
    $testDb = trim((string) getenv('CMS_TEST_DATABASE_FILE'));
    if ($testDb === '') {
        fwrite(STDERR, "BŁĄD P3-D: --apply wymaga CMS_TEST_DATABASE_FILE (fail-closed guard).\n");
        exit(3);
    }

    $articleIds = array_map('intval', array_column($candidates, 'id'));
    $backupPath = backup_affected_records($db, $articleIds);
    $sha256 = hash_file('sha256', $backupPath);
    file_put_contents(
        $backupPath . '.sha256',
        $sha256 . '  ' . basename($backupPath) . PHP_EOL,
        LOCK_EX
    );

    $result = apply_reset($db, $articleIds);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'mode' => 'apply',
        'articles_reset' => count($result),
        'backup_path' => $backupPath,
        'backup_sha256' => $sha256,
        'details' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
}

/**
 * Audyt wadliwych artykułów.
 *
 * Kryteria kwalifikacji (co najmniej jedno):
 * 1. Artykuł ma grafikę z is_fallback=1
 * 2. Artykuł ma grafikę odrzuconą przez bramkę semantyczną (editorial_rejected=1)
 * 3. Artykuł ma grafikę z brakującym plikiem assetu na dysku
 * 4. Artykuł ma za mało prawidłowych grafik vs wymagane sloty
 * 5. Brakuje multimodal ACCEPT albo finalny rekord jest niespójny
 */
function audit_invalid_articles(PDO $db): array
{
    $invalidMap = [];

    // Criterion 1: fallback images (is_fallback=1)
    $stmt = $db->prepare(
        'SELECT post_id, id, role, local_path, status FROM article_images WHERE is_fallback = 1'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $img) {
        $pid = (int) $img['post_id'];
        if (!isset($invalidMap[$pid])) {
            $invalidMap[$pid] = ['reasons' => [], 'images' => []];
        }
        $invalidMap[$pid]['reasons'][] = 'fallback_image';
        $invalidMap[$pid]['images'][] = image_summary($img);
    }

    // Criterion 3b: placeholder/missing image state.
    $stmt = $db->query(
        'SELECT post_id, id, role, local_path, status FROM article_images' .
        ' WHERE status IN ("missing","manual_review")'
    );
    foreach ($stmt->fetchAll() as $img) {
        $pid = (int) $img['post_id'];
        $invalidMap[$pid] ??= ['reasons' => [], 'images' => []];
        if (!in_array('placeholder_or_pending_image', $invalidMap[$pid]['reasons'], true)) {
            $invalidMap[$pid]['reasons'][] = 'placeholder_or_pending_image';
        }
        $invalidMap[$pid]['images'][] = image_summary($img);
    }

    // Criterion 3c: downloaded image without persisted multimodal ACCEPT or coherent final metadata.
    $stmt = $db->query(
        'SELECT post_id, id, role, local_path, status FROM article_images' .
        ' WHERE status="downloaded" AND (' .
        'multimodal_accepted<>1 OR source_page_url="" OR source_file_url="" OR license="" OR local_path="")'
    );
    foreach ($stmt->fetchAll() as $img) {
        $pid = (int) $img['post_id'];
        $invalidMap[$pid] ??= ['reasons' => [], 'images' => []];
        if (!in_array('missing_multimodal_accept_or_inconsistent_final_record', $invalidMap[$pid]['reasons'], true)) {
            $invalidMap[$pid]['reasons'][] = 'missing_multimodal_accept_or_inconsistent_final_record';
        }
        $invalidMap[$pid]['images'][] = image_summary($img);
    }

    // Criterion 2: editorial_rejected images (semantic gate rejection)
    $stmt = $db->prepare(
        'SELECT post_id, id, role, local_path, status FROM article_images WHERE editorial_rejected = 1'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $img) {
        $pid = (int) $img['post_id'];
        if (!isset($invalidMap[$pid])) {
            $invalidMap[$pid] = ['reasons' => [], 'images' => []];
        }
        if (!in_array('semantic_rejected', $invalidMap[$pid]['reasons'], true)) {
            $invalidMap[$pid]['reasons'][] = 'semantic_rejected';
        }
        $invalidMap[$pid]['images'][] = image_summary($img);
    }

    // Criterion 3: missing asset files on disk
    $stmt = $db->prepare(
        'SELECT post_id, id, role, local_path, status FROM article_images WHERE local_path <> "" AND status = "downloaded"'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $img) {
        $localPath = (string) $img['local_path'];
        $absolutePath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $localPath), '/');
        if (!is_file($absolutePath)) {
            $pid = (int) $img['post_id'];
            if (!isset($invalidMap[$pid])) {
                $invalidMap[$pid] = ['reasons' => [], 'images' => []];
            }
            if (!in_array('missing_asset_file', $invalidMap[$pid]['reasons'], true)) {
                $invalidMap[$pid]['reasons'][] = 'missing_asset_file';
            }
            $invalidMap[$pid]['images'][] = image_summary($img);
        }
    }

    // Criterion 4: too few valid images vs required slots
    $postIdsStmt = $db->query(
        'SELECT post_id FROM article_images UNION SELECT id AS post_id FROM posts WHERE status="published" OR is_published=1'
    );
    $postIdsWithImages = array_map('intval', array_column($postIdsStmt->fetchAll(), 'post_id'));

    foreach ($postIdsWithImages as $pid) {
        $validStmt = $db->prepare(
            'SELECT COUNT(*) AS cnt FROM article_images' .
            ' WHERE post_id = :post_id AND status = "downloaded" AND is_fallback = 0' .
            ' AND editorial_rejected = 0 AND multimodal_accepted = 1'
        );
        $validStmt->execute([':post_id' => $pid]);
        $validCount = (int) $validStmt->fetchColumn();

        // Check if any valid image files actually exist on disk
        $validFilesStmt = $db->prepare(
            'SELECT local_path FROM article_images' .
            ' WHERE post_id = :post_id AND status = "downloaded" AND is_fallback = 0' .
            ' AND editorial_rejected = 0 AND multimodal_accepted = 1 AND local_path <> ""'
        );
        $validFilesStmt->execute([':post_id' => $pid]);
        $filesExist = 0;
        foreach ($validFilesStmt->fetchAll(PDO::FETCH_COLUMN) as $lp) {
            $abs = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', (string) $lp), '/');
            if (is_file($abs)) {
                $filesExist++;
            }
        }

        // Get required slots from narrative plan
        $planStmt = $db->prepare(
            'SELECT visual_slots_planned FROM narrative_plans WHERE article_id = :article_id ORDER BY id DESC LIMIT 1'
        );
        $planStmt->execute([':article_id' => $pid]);
        $planRow = $planStmt->fetch();
        $requiredSlots = is_array($planRow) ? max(1, (int) ($planRow['visual_slots_planned'] ?? 1)) : 1;

        if ($filesExist < $requiredSlots) {
            if (!isset($invalidMap[$pid])) {
                $invalidMap[$pid] = ['reasons' => [], 'images' => []];
            }
            if (!in_array('too_few_valid_images', $invalidMap[$pid]['reasons'], true)) {
                $invalidMap[$pid]['reasons'][] = 'too_few_valid_images';
            }
        }

        $heroStmt = $db->prepare(
            'SELECT COUNT(*) FROM article_images WHERE post_id=:post_id AND role="hero"' .
            ' AND status="downloaded" AND is_fallback=0 AND editorial_rejected=0' .
            ' AND multimodal_accepted=1 AND local_path<>""'
        );
        $heroStmt->execute([':post_id' => $pid]);
        if ((int) $heroStmt->fetchColumn() === 0) {
            $invalidMap[$pid] ??= ['reasons' => [], 'images' => []];
            if (!in_array('missing_valid_hero', $invalidMap[$pid]['reasons'], true)) {
                $invalidMap[$pid]['reasons'][] = 'missing_valid_hero';
            }
        }
    }

    // Enrich with post details and return
    return enrich_audit_results($db, $invalidMap);
}

/**
 * Helper: buduje krótki podsumowanie rekordu obrazu.
 */
function image_summary(array $img): array
{
    return [
        'id' => (int) $img['id'],
        'role' => (string) $img['role'],
        'local_path' => (string) $img['local_path'],
        'status' => (string) $img['status'],
    ];
}

/**
 * Wzbogaca wyniki audytu o dane z tabeli posts i seed/topic.
 */
function enrich_audit_results(PDO $db, array $invalidMap): array
{
    $result = [];

    foreach ($invalidMap as $pid => $info) {
        $postStmt = $db->prepare(
            'SELECT id, title, slug, status, is_published, category_id,' .
            ' created_at, updated_at, editorial_origin FROM posts WHERE id = :id'
        );
        $postStmt->execute([':id' => $pid]);
        $post = $postStmt->fetch();

        if (!is_array($post)) {
            continue;
        }

        // Seed/topic title from generation_batch_items → editorial_topics
        $topicStmt = $db->prepare(
            'SELECT topics.title AS topic_title FROM generation_batch_items items' .
            ' INNER JOIN editorial_topics topics ON topics.id = items.topic_id' .
            ' WHERE items.post_id = :post_id ORDER BY items.id DESC LIMIT 1'
        );
        $topicStmt->execute([':post_id' => $pid]);
        $topicTitle = (string) $topicStmt->fetchColumn();

        $result[] = [
            'id' => (int) $post['id'],
            'title' => (string) $post['title'],
            'seed_title' => $topicTitle,
            'slug' => (string) $post['slug'],
            'status' => (string) $post['status'],
            'is_published' => (int) $post['is_published'],
            'category_id' => (int) $post['category_id'],
            'editorial_origin' => (string) ($post['editorial_origin'] ?? ''),
            'created_at' => (string) $post['created_at'],
            'updated_at' => (string) $post['updated_at'],
            'reasons' => array_unique($info['reasons']),
            'images' => $info['images'],
        ];
    }

    return $result;
}

/**
 * Buduje manifest kandydatów do resetu (--dry-run).
 *
 * Wzbogaca o seed/topic/brief/type/category/language/input/settings/status_history/batch_context.
 */
function build_manifest(PDO $db, array $candidates): array
{
    $articles = [];

    foreach ($candidates as $candidate) {
        $articleId = $candidate['id'];

        $draftCount = count_table_where($db, 'article_draft_versions', 'post_id', $articleId);
        $qcCount = count_table_where($db, 'quality_check_runs', 'post_id', $articleId);
        $genCount = count_table_where($db, 'generation_operations', 'post_id', $articleId);
        $planCount = count_table_where($db, 'narrative_plans', 'article_id', $articleId);
        $thumbCount = count_table_where($db, 'thumbnail_versions', 'post_id', $articleId);

        $imgStmt = $db->prepare(
            'SELECT id, role, status, is_fallback, editorial_rejected, local_path' .
            ' FROM article_images WHERE post_id = :post_id'
        );
        $imgStmt->execute([':post_id' => $articleId]);
        $allImages = $imgStmt->fetchAll();

        // Seed/topic context from generation_batch_items → editorial_topics
        $batchStmt = $db->prepare(
            'SELECT items.input, items.settings, items.batch_id, items.stage AS batch_stage,' .
            ' items.status AS batch_status, topics.brief, topics.type, topics.language' .
            ' FROM generation_batch_items items' .
            ' INNER JOIN editorial_topics topics ON topics.id = items.topic_id' .
            ' WHERE items.post_id = :post_id ORDER BY items.id DESC LIMIT 1'
        );
        $batchStmt->execute([':post_id' => $articleId]);
        $batchRow = $batchStmt->fetch();

        // Category title
        $catStmt = $db->prepare('SELECT title FROM post_categories WHERE id = :id');
        $catStmt->execute([':id' => $candidate['category_id']]);
        $categoryTitle = (string) $catStmt->fetchColumn();

        // Status history
        $histStmt = $db->prepare(
            'SELECT previous_status, new_status, reason, created_at FROM post_status_history' .
            ' WHERE post_id = :post_id ORDER BY id DESC'
        );
        $histStmt->execute([':post_id' => $articleId]);
        $statusHistory = $histStmt->fetchAll();

        // Batch context — execution_mode i dispatch_mode z generation_batches
        $batchCtx = [];
        if (is_array($batchRow) && (int) $batchRow['batch_id'] > 0) {
            $bCtxStmt = $db->prepare(
                'SELECT execution_mode, dispatch_mode, action FROM generation_batches WHERE id = :id'
            );
            $bCtxStmt->execute([':id' => (int) $batchRow['batch_id']]);
            $bCtxRow = $bCtxStmt->fetch();
            if (is_array($bCtxRow)) {
                $batchCtx = $bCtxRow;
            }
        }

        $articles[] = [
            'article_id' => $candidate['id'],
            'title' => $candidate['title'],
            'seed_title' => $candidate['seed_title'],
            'slug' => $candidate['slug'],
            'current_status' => $candidate['status'],
            'is_published' => $candidate['is_published'],
            'category_id' => $candidate['category_id'],
            'category_title' => $categoryTitle,
            'editorial_origin' => $candidate['editorial_origin'],
            'qualification_reasons' => $candidate['reasons'],
            'assets' => [
                'article_images_count' => count($allImages),
                'article_images' => $allImages,
                'draft_versions_count' => $draftCount,
                'qc_runs_count' => $qcCount,
                'generation_operations_count' => $genCount,
                'narrative_plans_count' => $planCount,
                'thumbnail_versions_count' => $thumbCount,
            ],
            'seed_topic_context' => [
                'brief' => is_array($batchRow) ? (string) ($batchRow['brief'] ?? '') : '',
                'type' => is_array($batchRow) ? (string) ($batchRow['type'] ?? '') : '',
                'language' => is_array($batchRow) ? (string) ($batchRow['language'] ?? '') : '',
            ],
            'input_settings' => [
                'input' => is_array($batchRow) ? (string) ($batchRow['input'] ?? '') : '',
                'settings' => is_array($batchRow) ? (string) ($batchRow['settings'] ?? '') : '',
            ],
            'status_history' => $statusHistory,
            'batch_context' => $batchCtx,
            'fields_to_clear' => [
                'posts.title',
                'posts.excerpt',
                'posts.content',
                'posts.image_path',
                'posts.content_image_path',
                'posts.content_images',
                'posts.content_blocks',
                'posts.image_alt',
                'posts.slug',
                'posts.status -> draft',
                'article_draft_versions (DELETE)',
                'quality_check_runs (DELETE)',
                'generation_operations (DELETE)',
                'article_images (DELETE)',
                'narrative_plans (DELETE)',
                'thumbnail_versions (DELETE)',
            ],
            'fields_to_preserve' => [
                'posts.id',
                'posts.category_id',
                'posts.created_at',
                'posts.updated_at',
                'posts.editorial_origin',
                'seed_title/topic context',
                'generation_batch_items input/settings context',
                'post_status_history',
                'gemini_quota_events',
            ],
        ];
    }

    return [
        'manifest_timestamp' => date('c'),
        'total_candidates' => count($articles),
        'candidates' => $articles,
    ];
}

/**
 * Helper: liczy wiersze w tabeli po podanej kolumnie.
 */
function count_table_where(PDO $db, string $table, string $column, int $value): int
{
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowa nazwa tabeli.');
    }
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowa nazwa kolumny.');
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = :val'
    );
    $stmt->execute([':val' => $value]);

    return (int) $stmt->fetchColumn();
}

/**
 * Backup dotkniętych rekordów z SHA-256 checksum.
 * Plik backupu nie jest dodawany do Git (data/backups/ w .gitignore).
 */
function backup_affected_records(PDO $db, array $articleIds): string
{
    if (!is_dir(RESET_INVALID_ARTICLES_BACKUP_DIR)) {
        mkdir(RESET_INVALID_ARTICLES_BACKUP_DIR, 0755, true);
    }

    $timestamp = date('Ymd_His');
    $backupFile = RESET_INVALID_ARTICLES_BACKUP_DIR . '/reset_invalid_articles_' . $timestamp . '.json';

    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $params = array_map('intval', $articleIds);

    $posts = fetch_where_in($db, 'posts', 'id', $params, $placeholders);
    $images = fetch_where_in($db, 'article_images', 'post_id', $params, $placeholders);
    $drafts = fetch_where_in($db, 'article_draft_versions', 'post_id', $params, $placeholders);
    $qcRuns = fetch_where_in($db, 'quality_check_runs', 'post_id', $params, $placeholders);
    $genOps = fetch_where_in($db, 'generation_operations', 'post_id', $params, $placeholders);
    $plans = fetch_where_in($db, 'narrative_plans', 'article_id', $params, $placeholders);

    $thumbnails = fetch_where_in($db, 'thumbnail_versions', 'post_id', $params, $placeholders);
    $backup = [
        'backup_timestamp' => date('c'),
        'article_ids' => $articleIds,
        'posts' => $posts,
        'article_images' => $images,
        'article_draft_versions' => $drafts,
        'quality_check_runs' => $qcRuns,
        'generation_operations' => $genOps,
        'narrative_plans' => $plans,
        'thumbnail_versions' => $thumbnails,
    ];

    file_put_contents(
        $backupFile,
        json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        LOCK_EX
    );

    return $backupFile;
}

/**
 * Helper: SELECT * FROM table WHERE column IN (...).
 */
function fetch_where_in(PDO $db, string $table, string $column, array $params, string $placeholders): array
{
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowa nazwa tabeli.');
    }
    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowa nazwa kolumny.');
    }

    $stmt = $db->prepare(
        'SELECT * FROM ' . $table . ' WHERE ' . $column . ' IN (' . $placeholders . ')'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Wykonuje reset wadliwych artykułów.
 *
 * W ramach jednej transakcji:
 * 1. Zmienia status na 'draft' i cofa publikację (atomowo)
 * 2. Rejestruje zmianę statusu w post_status_history
 * 3. Czyści wygenerowane pola w posts
 * 4. Usuwa artefakty pochodne
 *
 * Zachowuje: posts.id, category_id, created_at, updated_at,
 *            post_status_history, gemini_quota_events.
 */
function apply_reset(PDO $db, array $articleIds): array
{
    $details = [];

    $db->beginTransaction();

    try {
        foreach ($articleIds as $articleId) {
            // Get current status for history recording
            $statusStmt = $db->prepare('SELECT status, is_published FROM posts WHERE id = :id');
            $statusStmt->execute([':id' => $articleId]);
            $current = $statusStmt->fetch();

            if (!is_array($current)) {
                continue; // post no longer exists — skip silently (idempotent)
            }

            $previousStatus = (string) $current['status'];
            $wasPublished = (int) $current['is_published'] === 1;

            // Record status change in history (preserved, never deleted)
            if ($previousStatus !== 'draft') {
                $historyStmt = $db->prepare(
                    'INSERT INTO post_status_history' .
                    ' (post_id, previous_status, new_status, reason, actor)' .
                    ' VALUES (:post_id, :prev, :new, :reason, :actor)'
                );
                $historyStmt->execute([
                    ':post_id' => $articleId,
                    ':prev' => $previousStatus,
                    ':new' => 'draft',
                    ':reason' => 'Reset wadliwego artykułu — P3-D audit reset tool',
                    ':actor' => 'reset-tool',
                ]);
            }

            // Reset post fields: clear generated content, revert to draft
            $updateStmt = $db->prepare(
                'UPDATE posts SET' .
                " title = ''," .
                " excerpt = ''," .
                " content = ''," .
                " image_path = ''," .
                " content_image_path = ''," .
                " content_images = '[]'," .
                " content_blocks = '[]'," .
                " image_alt = ''," .
                " slug = :reset_slug," .
                " status = 'draft'," .
                " is_published = 0," .
                " published_at = NULL," .
                " updated_at = CURRENT_TIMESTAMP" .
                ' WHERE id = :id'
            );
            $updateStmt->execute([
                ':id' => $articleId,
                ':reset_slug' => 'reset-' . $articleId,
            ]);

            // Delete derived artifacts — order respects FK constraints
            // narrative_plans: no FK to other derived tables
            $db->prepare('DELETE FROM narrative_plans WHERE article_id = :id')
                ->execute([':id' => $articleId]);

            // thumbnail_versions: FK RESTRICT to quality_check_runs — must delete first
            $db->prepare('DELETE FROM thumbnail_versions WHERE post_id = :id')
                ->execute([':id' => $articleId]);

            // quality_check_runs: FK to article_draft_versions (CASCADE) and generation_operations (CASCADE)
            // Delete explicitly before parents to avoid cascade side-effects
            $db->prepare('DELETE FROM quality_check_runs WHERE post_id = :id')
                ->execute([':id' => $articleId]);

            // article_draft_versions: FK to generation_operations (CASCADE on gen_ops delete)
            $db->prepare('DELETE FROM article_draft_versions WHERE post_id = :id')
                ->execute([':id' => $articleId]);

            // generation_operations: FK to posts (SET NULL) — safe after clearing content
            $db->prepare('DELETE FROM generation_operations WHERE post_id = :id')
                ->execute([':id' => $articleId]);

            // article_images: FK to posts (CASCADE) — safe since we keep the post
            $db->prepare('DELETE FROM article_images WHERE post_id = :id')
                ->execute([':id' => $articleId]);

            // Preserve seed/input linkage but stop automatic execution until an operator resumes it.
            $db->prepare(
                'UPDATE generation_batch_items SET status="paused_by_operator", stage="research",' .
                ' progress_percent=0, research_operation_id=NULL, research_package_id=NULL,' .
                ' draft_operation_id=NULL, draft_version_id=NULL, quality_operation_id=NULL,' .
                ' quality_check_id=NULL, retry_count=0, lease_token=NULL, lease_expires_at=NULL,' .
                ' wait_reason="reset_pending_generation_manual_resume", error_message="",' .
                ' completed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE post_id=:id'
            )->execute([':id' => $articleId]);

            $details[] = [
                'article_id' => $articleId,
                'previous_status' => $previousStatus,
                'was_published' => $wasPublished,
                'new_status' => 'draft',
            ];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $details;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    main();
}
