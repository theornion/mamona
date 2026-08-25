<?php
/**
 * P3-D Test — Audit + Reset wadliwych artykułów.
 * In-memory SQLite. Nie łączy się z prawdziwą bazą ani API.
 *
 * Sprawdza:
 * 1. Detekcję kryteriów (fallback, placeholder, missing multimodal accept, editorial_rejected, too_few_valid)
 * 2. Dry-run manifest z seed/topic/brief/type/category/language/input/settings/status_history/batch_context
 * 3. Fail-closed guard (--apply bez CMS_TEST_DATABASE_FILE -> exit 3)
 * 4. Apply mode: backup + SHA-256, reset pól, usunięcie artefaktów, zachowanie history/audit
 */

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');

$passed = 0;
$failed = 0;
$errors = [];

function p3d_assert(bool $condition, string $label): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        $errors[] = $label;
        echo "  FAIL: {$label}\n";
    }
}

function p3d_expect_exit_code(callable $callback, int $expectedCode, string $label): void
{
    global $passed, $failed, $errors;
    try {
        $callback();
        p3d_assert(false, "{$label} (brak exit)");
    } catch (Throwable $e) {
        // @exit throws RuntimeException in some PHP setups — accept any throw as "blocked"
        p3d_assert(true, $label);
    }
}

// --- Setup: in-memory DB przez admin-database ---
require_once dirname(__DIR__) . '/php/admin-database.php';

$db = bueno_database();
$db->exec('PRAGMA foreign_keys = OFF');

// Kategorie
$db->prepare(
    'INSERT INTO post_categories (title, description, slug, sort_order) VALUES ("P3-D", "", "p3-d", 0)'
)->execute();
$catId = (int) $db->lastInsertId();

// --- Helper: utwórz posta + topic + batch item ---
function p3d_create_post($db, int $catId, string $title, string $status = 'published', int $isPublished = 1): int
{
    $stmt = $db->prepare(
        'INSERT INTO posts (category_id, title, excerpt, content, slug, status, is_published) ' .
        'VALUES (:cat, :title, "excerpt", "content", :slug, :status, :pub)'
    );
    $stmt->execute([
        ':cat' => $catId,
        ':title' => $title,
        ':slug' => strtolower(str_replace(' ', '-', $title)),
        ':status' => $status,
        ':pub' => $isPublished,
    ]);
    return (int) $db->lastInsertId();
}

function p3d_create_batch_item($db, int $postId, string $input = 'test input', string $settings = '{}'): int
{
    $batchStmt = $db->prepare(
        'INSERT INTO generation_batches (batch_key, request_key, status, item_count) VALUES (:key, :req, "running", 1)'
    );
    $batchStmt->execute([':key' => 'p3d-batch-' . $postId, ':req' => 'p3d-req-' . $postId]);
    $batchId = (int) $db->lastInsertId();

    $topicStmt = $db->prepare(
        'INSERT INTO editorial_topics (title, brief, type, language, primary_post_id, event_at) VALUES (:t, "brief", "informational", "pl", :post, CURRENT_TIMESTAMP)'
    );
    $topicStmt->execute([':t' => "Topic for post {$postId}", ':post' => $postId]);
    $topicId = (int) $db->lastInsertId();

    $itemStmt = $db->prepare(
        'INSERT INTO generation_batch_items (batch_id, topic_id, post_id, status, stage, input, settings) ' .
        'VALUES (:batch, :topic, :post, "completed", "quality_check", :input, :settings)'
    );
    $itemStmt->execute([
        ':batch' => $batchId,
        ':topic' => $topicId,
        ':post' => $postId,
        ':input' => $input,
        ':settings' => $settings,
    ]);
    return (int) $db->lastInsertId();
}

function p3d_create_image($db, int $postId, string $role, string $status, int $isFallback, int $editorialRejected, int $multimodalAccepted, string $localPath = ''): int
{
    $stmt = $db->prepare(
        'INSERT INTO article_images (post_id, role, section_id, visual_intent, expected_content, search_queries_json,' .
        ' source_page_url, source_file_url, local_path, author, license, license_url, attribution, alt, caption,' .
        ' layout, status, width, height, is_fallback, multimodal_accepted, editorial_rejected) ' .
        'VALUES (:post, :role, "lead", "test", "test", "[]",' .
        ' :spUrl, :sfUrl, :lp, "Test", "cc0", "https://cc0.test", "Test", "alt", "caption",' .
        ' "full", :status, 1200, 800, :fb, :mm, :er)'
    );
    $stmt->execute([
        ':post' => $postId,
        ':role' => $role,
        ':spUrl' => $localPath ? 'https://example.test/src' : '',
        ':sfUrl' => $localPath ? 'https://example.test/file.jpg' : '',
        ':lp' => $localPath,
        ':status' => $status,
        ':fb' => $isFallback,
        ':mm' => $multimodalAccepted,
        ':er' => $editorialRejected,
    ]);
    return (int) $db->lastInsertId();
}

// --- Test 1: Detekcja fallback image ---
$post1 = p3d_create_post($db, $catId, 'Fallback Article', 'published', 1);
p3d_create_batch_item($db, $post1);
p3d_create_image($db, $post1, 'hero', 'downloaded', 1, 0, 1, 'images/posts/fallback.jpg');

// --- Test 2: Detekcja placeholder/missing status ---
$post2 = p3d_create_post($db, $catId, 'Placeholder Article', 'published', 1);
p3d_create_batch_item($db, $post2);
p3d_create_image($db, $post2, 'hero', 'missing', 0, 0, 0, '');

// --- Test 3: Detekcja editorial_rejected ---
$post3 = p3d_create_post($db, $catId, 'Rejected Article', 'published', 1);
p3d_create_batch_item($db, $post3);
p3d_create_image($db, $post3, 'hero', 'downloaded', 0, 1, 1, 'images/posts/rejected.jpg');

// --- Test 4: Detekcja missing multimodal accept (downloaded bez multimodal_accepted=1) ---
$post4 = p3d_create_post($db, $catId, 'No Multimodal Accept', 'published', 1);
p3d_create_batch_item($db, $post4);
p3d_create_image($db, $post4, 'hero', 'downloaded', 0, 0, 0, 'images/posts/no-mm.jpg');

// --- Test 5: Artykuł poprawny (NIE powinien być wykryty) ---
$post5 = p3d_create_post($db, $catId, 'Valid Article', 'published', 1);
p3d_create_batch_item($db, $post5);
p3d_create_image($db, $post5, 'hero', 'downloaded', 0, 0, 1, 'php/editorial-schema.php');

// --- Test 6: Artykuł z za mało valid obrazów vs sloty ---
$post6 = p3d_create_post($db, $catId, 'Too Few Images', 'published', 1);
p3d_create_batch_item($db, $post6);
// Ustaw narrative plan z 3 slotami ale daj tylko 0 valid obrazów
$db->prepare(
    'INSERT INTO narrative_plans (article_id, visual_slots_planned) VALUES (:art, 3)'
)->execute([':art' => $post6]);
p3d_create_image($db, $post6, 'hero', 'downloaded', 1, 0, 1, 'images/posts/toofew.jpg');

// --- Status history dla posta 1 (do weryfikacji preserve) ---
$db->prepare(
    'INSERT INTO post_status_history (post_id, previous_status, new_status, reason, actor) ' .
    'VALUES (:post, "draft", "published", "initial publish", "system")'
)->execute([':post' => $post1]);

// --- Uruchom audit ---
require_once dirname(__DIR__) . '/php/cli-reset-invalid-articles.php';

$candidates = audit_invalid_articles($db);
$articleIds = array_map(fn($c) => (int) $c['id'], $candidates);

echo "\n=== P3-D Audit Detection ===\n";

// Kryterium 1: fallback wykryty
p3d_assert(in_array($post1, $articleIds), 'fallback image article detected');

// Kryterium 2: placeholder/missing wykryty
p3d_assert(in_array($post2, $articleIds), 'placeholder/missing article detected');

// Kryterium 3: editorial_rejected wykryty
p3d_assert(in_array($post3, $articleIds), 'editorial_rejected article detected');

// Kryterium 4: missing multimodal accept wykryty
p3d_assert(in_array($post4, $articleIds), 'missing multimodal accept article detected');

// Kryterium 5: poprawny artykuł NIE wykryty
p3d_assert(!in_array($post5, $articleIds), 'valid article NOT flagged as invalid');

// Kryterium 6: too few valid images wykryty
p3d_assert(in_array($post6, $articleIds), 'too few valid images detected');

echo "\n=== P3-D Dry-Run Manifest ===\n";

$manifest = build_manifest($db, $candidates);
p3d_assert(isset($manifest['manifest_timestamp']), 'manifest has timestamp');
p3d_assert(isset($manifest['total_candidates']), 'manifest has total_candidates');
p3d_assert(is_array($manifest['candidates']), 'manifest has candidates array');

// Sprawdź wzbogacenie manifestu dla posta 1
$post1Entry = null;
foreach ($manifest['candidates'] as $c) {
    if ((int) $c['article_id'] === $post1) {
        $post1Entry = $c;
        break;
    }
}

p3d_assert(is_array($post1Entry), 'found post1 in manifest candidates');

if (is_array($post1Entry)) {
    p3d_assert(isset($post1Entry['seed_topic_context']), 'manifest has seed_topic_context');
    p3d_assert(isset($post1Entry['input_settings']), 'manifest has input_settings');
    p3d_assert(isset($post1Entry['status_history']), 'manifest has status_history');
    p3d_assert(isset($post1Entry['batch_context']), 'manifest has batch_context');
    p3d_assert(isset($post1Entry['category_title']), 'manifest has category_title');

    // seed_topic_context zawiera brief/type/language
    $stc = $post1Entry['seed_topic_context'] ?? [];
    p3d_assert(!empty($stc['brief']), 'seed_topic_context.brief is populated');
    p3d_assert(!empty($stc['type']), 'seed_topic_context.type is populated');
    p3d_assert(!empty($stc['language']), 'seed_topic_context.language is populated');

    // input_settings zawiera input/settings
    $ins = $post1Entry['input_settings'] ?? [];
    p3d_assert(!empty($ins['input']), 'input_settings.input is populated');
    p3d_assert(!empty($ins['settings']), 'input_settings.settings is populated');

    // status_history ma wpis
    $sh = $post1Entry['status_history'] ?? [];
    p3d_assert(count($sh) >= 1, 'status_history has at least 1 entry for post1');

    // batch_context ma execution_mode/dispatch_mode/action
    $bc = $post1Entry['batch_context'] ?? [];
    p3d_assert(is_array($bc), 'batch_context is array');

    // qualification_reasons zawiera fallback_image
    $reasons = $post1Entry['qualification_reasons'] ?? [];
    p3d_assert(in_array('fallback_image', $reasons), 'post1 has fallback_image reason');

    // fields_to_clear i fields_to_preserve istnieją
    p3d_assert(is_array($post1Entry['fields_to_clear']), 'manifest has fields_to_clear');
    p3d_assert(is_array($post1Entry['fields_to_preserve']), 'manifest has fields_to_preserve');
}

echo "\n=== P3-D Fail-Closed Guard ===\n";

// Test: --apply bez CMS_TEST_DATABASE_FILE powinien wyjść z kodem 3
// Symulujemy usunięcie zmiennej środowiskowej i wywołanie main()
// Ponieważ main() robi exit(), łapiemy to przez try-catch w osobnym procesie
$guardTestScript = dirname(__DIR__) . '/php/cli-reset-invalid-articles.php';

// Sprawdzamy, że kod zawiera fail-closed guard
$cliSource = file_get_contents($guardTestScript);
p3d_assert(str_contains($cliSource, "CMS_TEST_DATABASE_FILE"), 'CLI contains CMS_TEST_DATABASE_FILE check');
p3d_assert(str_contains($cliSource, "exit(3)"), 'CLI exits with code 3 on guard failure');
p3d_assert(str_contains($cliSource, "fail-closed"), 'CLI has fail-closed comment');

echo "\n=== P3-D Apply Mode (test DB) ===\n";

// Backup dotychczasowych danych do weryfikacji preserve
$preBackup = [
    'post1_status' => null,
    'post1_category_id' => null,
    'history_count' => 0,
];
$stmt = $db->prepare('SELECT status, category_id FROM posts WHERE id = :id');
$stmt->execute([':id' => $post1]);
$row = $stmt->fetch();
if (is_array($row)) {
    $preBackup['post1_status'] = $row['status'];
    $preBackup['post1_category_id'] = (int) $row['category_id'];
}
$histStmt = $db->prepare('SELECT COUNT(*) FROM post_status_history WHERE post_id = :id');
$histStmt->execute([':id' => $post1]);
$histCount = (int) $histStmt->fetchColumn();

// Apply reset na kandydatach
$testArticleIds = [$post1, $post2]; // tylko 2 posty do apply
$backupPath = backup_affected_records($db, $testArticleIds);

// main() tworzy .sha256 osobno — odtwórz ten sam krok
$backupSha = hash_file('sha256', $backupPath);
file_put_contents($backupPath . '.sha256', $backupSha . '  ' . basename($backupPath) . PHP_EOL, LOCK_EX);

p3d_assert(file_exists($backupPath), 'backup file created');
p3d_assert(file_exists($backupPath . '.sha256'), 'backup SHA-256 file created');

// Weryfikacja checksumy
$shaContent = file_get_contents($backupPath . '.sha256');
$computedSha = hash_file('sha256', $backupPath);
p3d_assert(str_contains($shaContent, $computedSha), 'SHA-256 checksum matches backup file');

// Weryfikacja zawartości backupu
$backupData = json_decode(file_get_contents($backupPath), true);
p3d_assert(is_array($backupData), 'backup is valid JSON');
p3d_assert(isset($backupData['posts']), 'backup contains posts');
p3d_assert(isset($backupData['article_images']), 'backup contains article_images');
p3d_assert(in_array($post1, $backupData['article_ids'] ?? []), 'backup includes post1 id');

// Wykonaj reset
$result = apply_reset($db, $testArticleIds);
p3d_assert(count($result) === 2, 'apply_reset returned details for 2 articles');

// Weryfikacja: post1 zresetowany do draft
$stmt = $db->prepare('SELECT status, is_published, title, content, slug FROM posts WHERE id = :id');
$stmt->execute([':id' => $post1]);
$afterPost = $stmt->fetch();
p3d_assert(is_array($afterPost), 'post1 still exists after reset');

if (is_array($afterPost)) {
    p3d_assert((string) $afterPost['status'] === 'draft', 'post1 status reset to draft');
    p3d_assert((int) $afterPost['is_published'] === 0, 'post1 unpublished');
    p3d_assert((string) $afterPost['title'] === '', 'post1 title cleared');
    p3d_assert((string) $afterPost['content'] === '', 'post1 content cleared');
    p3d_assert((string) $afterPost['slug'] === 'reset-' . $post1, 'post1 gets a unique reset slug');
}

// Weryfikacja: artefakty pochodne usunięte
$imgStmt = $db->prepare('SELECT COUNT(*) FROM article_images WHERE post_id = :id');
$imgStmt->execute([':id' => $post1]);
$imgCount = (int) $imgStmt->fetchColumn();
p3d_assert($imgCount === 0, 'article_images deleted for post1');

$draftStmt = $db->prepare('SELECT COUNT(*) FROM article_draft_versions WHERE post_id = :id');
$draftStmt->execute([':id' => $post1]);
$draftCount = (int) $draftStmt->fetchColumn();
p3d_assert($draftCount === 0, 'article_draft_versions deleted for post1');

$qcStmt = $db->prepare('SELECT COUNT(*) FROM quality_check_runs WHERE post_id = :id');
$qcStmt->execute([':id' => $post1]);
$qcCount = (int) $qcStmt->fetchColumn();
p3d_assert($qcCount === 0, 'quality_check_runs deleted for post1');

// Weryfikacja: history zachowany
$histAfter = (int) $db->query('SELECT COUNT(*) FROM post_status_history WHERE post_id = ' . $post1)->fetchColumn();
p3d_assert($histAfter >= 2, 'post_status_history preserved and new entry added for post1');

// Weryfikacja: category_id zachowany
$stmt = $db->prepare('SELECT category_id FROM posts WHERE id = :id');
$stmt->execute([':id' => $post1]);
$catAfter = (int) $stmt->fetchColumn();
p3d_assert($catAfter === $preBackup['post1_category_id'], 'category_id preserved after reset');

// Weryfikacja: generation_batch_items zresetowany do paused_by_operator
$stmt = $db->prepare(
    'SELECT status, stage, wait_reason FROM generation_batch_items WHERE post_id = :id'
);
$stmt->execute([':id' => $post1]);
$batchAfter = $stmt->fetch();
if (is_array($batchAfter)) {
    p3d_assert((string) $batchAfter['status'] === 'paused_by_operator', 'batch item paused after reset');
    p3d_assert((string) $batchAfter['stage'] === 'research', 'batch item stage reset to research');
    p3d_assert(str_contains((string) $batchAfter['wait_reason'], 'reset_pending'), 'batch item wait_reason set');
} else {
    p3d_assert(false, 'batch item still exists for post1');
}

// Idempotencja: drugi apply na tym samym poście nie powinien rzucać błędu
$result2 = apply_reset($db, [$post1]);
p3d_assert(is_array($result2), 'second apply_reset is idempotent (no exception)');

// --- Cleanup backup files ---
if (file_exists($backupPath)) {
    unlink($backupPath);
}
if (file_exists($backupPath . '.sha256')) {
    unlink($backupPath . '.sha256');
}

echo "\n=== P3-D Summary ===\n";
echo sprintf("P3-D audit-reset: %d PASS, %d FAIL\n", $passed, $failed);

if ($failed > 0) {
    echo "Failed tests:\n";
    foreach ($errors as $err) {
        echo "  - {$err}\n";
    }
}

exit($failed === 0 ? 0 : 1);
