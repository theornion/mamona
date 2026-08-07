<?php
/**
 * P2-A Test — centralny GeminiBudget, limit 20, convergence mode.
 * Używa SQLite w pamięci. Nie łączy się z prawdziwą bazą ani API.
 */

declare(strict_types=1);

$passed = 0;
$failed = 0;
$errors = [];

function assert_test(bool $condition, string $label): void {
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failed++;
        $errors[] = $label;
        echo "  FAIL: $label\n";
    }
}

// --- Setup: in-memory SQLite + required functions ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Include only the files we need for the test scope
require_once __DIR__ . '/../php/gemini-quota-service.php';
require_once __DIR__ . '/../php/repair-router-service.php';

// We need app_config and generation_json — mock them if not defined
if (!function_exists('app_config')) {
    function app_config(string $key, mixed $default = null) {
        static $cfg;
        if (!isset($cfg)) {
            $cfg = [
                'gemini_mock_budget_bypass' => false,
                'gemini_model' => 'gemini-2.5-flash',
                'gemini_model_fallbacks' => [],
                'gemini_rpm_target' => 60,
                'gemini_tpm_target' => 1000000,
                'gemini_rpd_target' => 10000,
                'gemini_quota_lease_seconds' => 30,
                'gemini_quota_project' => 'test',
                'gemini_quota_reset_timezone' => 'UTC',
            ];
        }
        return $cfg[$key] ?? $default;
    }
}

if (!function_exists('generation_json')) {
    function generation_json(mixed $data): string {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

// Helper: query and fetch assoc
function q1(PDO $pdo, string $sql, array $params = []): ?array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function qCol(PDO $pdo, string $sql, array $params = []): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// --- TEST 1: Migration — CREATE TABLE article_generation_budget ---
echo "\n=== TEST 1: Migration — CREATE TABLE article_generation_budget ===\n";

$pdo->exec(
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

// Verify table exists
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='article_generation_budget'")->fetchAll(PDO::FETCH_COLUMN);
assert_test(count($tables) === 1, 'Tabela article_generation_budget istnieje');

// Verify columns
$cols = array_column($pdo->query('PRAGMA table_info(article_generation_budget)')->fetchAll(), 'name');
foreach (['article_id', 'max_calls', 'used_calls', 'convergence_threshold', 'calls_log_json', 'is_exhausted', 'convergence_active', 'created_at', 'updated_at'] as $col) {
    assert_test(in_array($col, $cols, true), sprintf('Kolumna %s istnieje w article_generation_budget', $col));
}

// Verify defaults: max_calls=20, convergence_threshold=16
$pdo->exec('INSERT INTO article_generation_budget (article_id) VALUES (999)');
$row = q1($pdo, 'SELECT * FROM article_generation_budget WHERE article_id=?', [999]);
assert_test((int)$row['max_calls'] === 20, 'Domyślny max_calls = 20');
assert_test((int)$row['convergence_threshold'] === 16, 'Domyślny convergence_threshold = 16');
assert_test((int)$row['used_calls'] === 0, 'Domyślny used_calls = 0');
assert_test((int)$row['is_exhausted'] === 0, 'Domyślny is_exhausted = 0');
assert_test((int)$row['convergence_active'] === 0, 'Domyślny convergence_active = 0');
$pdo->exec('DELETE FROM article_generation_budget WHERE article_id=999');

// --- TEST 2: Migration — ALTER TABLE article_images columns ---
echo "\n=== TEST 2: Migration — ALTER TABLE article_images columns ===\n";

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS article_images (' .
        'id INTEGER PRIMARY KEY,' .
        'article_id INTEGER,' .
        'local_path TEXT DEFAULT "",' .
        'search_audit_json TEXT DEFAULT "{}"' .
    ')'
);

// Add columns if missing (simulating migration)
$aiCols = array_column($pdo->query('PRAGMA table_info(article_images)')->fetchAll(), 'name');
if (!in_array('is_fallback', $aiCols)) $pdo->exec("ALTER TABLE article_images ADD COLUMN is_fallback INTEGER NOT NULL DEFAULT 0");
if (!in_array('semantic_score', $aiCols)) $pdo->exec("ALTER TABLE article_images ADD COLUMN semantic_score INTEGER");
if (!in_array('editorial_rejected', $aiCols)) $pdo->exec("ALTER TABLE article_images ADD COLUMN editorial_rejected INTEGER NOT NULL DEFAULT 0");

$aiCols = array_column($pdo->query('PRAGMA table_info(article_images)')->fetchAll(), 'name');
assert_test(in_array('is_fallback', $aiCols), 'Kolumna is_fallback w article_images');
assert_test(in_array('semantic_score', $aiCols), 'Kolumna semantic_score w article_images');
assert_test(in_array('editorial_rejected', $aiCols), 'Kolumna editorial_rejected w article_images');

// Backfill test: insert a fallback image and verify backfill works
$pdo->exec("INSERT INTO article_images (id, article_id, local_path, search_audit_json) VALUES (1, 100, '/uploads/editorial-fallback/test.jpg', '{}')");
$pdo->exec("INSERT INTO article_images (id, article_id, local_path, search_audit_json) VALUES (2, 100, '/uploads/real/image.png', '{\"provider\":\"dall_e_3\"}')");
$pdo->exec("UPDATE article_images SET is_fallback = 1 WHERE local_path LIKE '%editorial-fallback/%' OR search_audit_json LIKE '%local_fallback%'");

$fallbackRow = qCol($pdo, 'SELECT is_fallback FROM article_images WHERE id=1');
assert_test((int)$fallbackRow === 1, 'Backfill: fallback image ma is_fallback=1');

$realRow = qCol($pdo, 'SELECT is_fallback FROM article_images WHERE id=2');
assert_test((int)$realRow === 0, 'Backfill: real image ma is_fallback=0');

// --- TEST 3: Migration — ALTER TABLE generation_batch_items convergence_active ---
echo "\n=== TEST 3: Migration — convergence_active w generation_batch_items ===\n";

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS generation_batch_items (' .
        'id INTEGER PRIMARY KEY,' .
        'topic_id INTEGER,' .
        'status TEXT DEFAULT "queued"' .
    ')'
);

$gbCols = array_column($pdo->query('PRAGMA table_info(generation_batch_items)')->fetchAll(), 'name');
if (!in_array('convergence_active', $gbCols)) $pdo->exec("ALTER TABLE generation_batch_items ADD COLUMN convergence_active INTEGER NOT NULL DEFAULT 0");

$gbCols = array_column($pdo->query('PRAGMA table_info(generation_batch_items)')->fetchAll(), 'name');
assert_test(in_array('convergence_active', $gbCols), 'Kolumna convergence_active w generation_batch_items');

// --- TEST 4: gemini_article_budget_ensure() tworzy rekord ---
echo "\n=== TEST 4: gemini_article_budget_ensure() ===\n";

$state = gemini_article_budget_ensure($pdo, 42);
assert_test(is_array($state), 'Zwraca tablicę');
assert_test((int)($state['article_id'] ?? 0) === 42, 'article_id = 42');
assert_test((int)($state['max_calls'] ?? 0) === 20, 'max_calls = 20');
assert_test((int)($state['used_calls'] ?? -1) === 0, 'used_calls = 0');
assert_test((int)($state['convergence_threshold'] ?? 0) === 16, 'convergence_threshold = 16');
assert_test((int)($state['is_exhausted'] ?? -1) === 0, 'is_exhausted = 0');
assert_test((int)($state['convergence_active'] ?? -1) === 0, 'convergence_active = 0');

// Drugie wywołanie nie duplikuje rekordu
$count = (int)qCol($pdo, 'SELECT COUNT(*) FROM article_generation_budget WHERE article_id=42');
assert_test($count === 1, 'Rekord nie jest duplikowany przy ponownym ensure');

// --- TEST 5: gemini_article_budget_increment() inkrementuje used_calls ---
echo "\n=== TEST 5: gemini_article_budget_increment() inkrementacja ===\n";

$pdo->exec('DELETE FROM article_generation_budget WHERE article_id=100');

for ($i = 1; $i <= 20; $i++) {
    try {
        gemini_article_budget_increment($pdo, 100, 'article_draft', 'draft', $i, 'success');

        $row = q1($pdo, 'SELECT used_calls, convergence_active, is_exhausted FROM article_generation_budget WHERE article_id=100');
        $used = (int)$row['used_calls'];

        assert_test($used === $i, sprintf('Po %d. wywołaniu used_calls=%d', $i, $i));

        // Convergence test: calls 1-15 -> convergence_active=0
        if ($i <= 15) {
            assert_test((int)$row['convergence_active'] === 0, sprintf('Call %d: convergence_active=0 (normal mode)', $i));
        }
        // Call 16+ -> convergence_active=1
        if ($i >= 16 && $i < 20) {
            assert_test((int)$row['convergence_active'] === 1, sprintf('Call %d: convergence_active=1 (convergence mode)', $i));
        }

        // is_exhausted test
        if ($i < 20) {
            assert_test((int)$row['is_exhausted'] === 0, sprintf('Call %d: is_exhausted=0', $i));
        }
    } catch (GeminiArticleBudgetException $e) {
        // Expected on call 21 — handled below
        if ($i <= 20) {
            assert_test(false, sprintf('Call %d NIE powinien rzucać wyjątku', $i));
        }
    }
}

// Call 20 should set is_exhausted=1 (used >= max)
$row = q1($pdo, 'SELECT is_exhausted FROM article_generation_budget WHERE article_id=100');
assert_test((int)$row['is_exhausted'] === 1, 'Po call 20: is_exhausted=1');

// Call 21 should throw GeminiArticleBudgetException
echo "\n=== TEST 6: Call 21 rzuca GeminiArticleBudgetException ===\n";
try {
    gemini_article_budget_increment($pdo, 100, 'article_draft', 'draft', 21, 'success');
    assert_test(false, 'Call 21 powinien rzucić GeminiArticleBudgetException');
} catch (GeminiArticleBudgetException $e) {
    assert_test(true, 'Call 21 rzuca GeminiArticleBudgetException');
    assert_test($e->articleId === 100, sprintf('Exception articleId=%d', $e->articleId));
    assert_test($e->usedCalls === 21, sprintf('Exception usedCalls=%d', $e->usedCalls));
    assert_test($e->maxCalls === 20, sprintf('Exception maxCalls=%d', $e->maxCalls));
} catch (Throwable $e) {
    assert_test(false, sprintf('Call 21 rzucił niewłaściwy wyjątek: %s', get_class($e)));
}

// --- TEST 7: calls_log_json zawiera wszystkie wywołania ---
echo "\n=== TEST 7: calls_log_json ===\n";

$row = q1($pdo, 'SELECT calls_log_json FROM article_generation_budget WHERE article_id=100');
$log = json_decode($row['calls_log_json'], true);
assert_test(is_array($log), 'calls_log_json jest tablicą');
assert_test(count($log) === 20, sprintf('calls_log_json ma %d wpisów (oczekiwano 20)', count($log)));

if (count($log) >= 1) {
    $first = $log[0];
    assert_test((int)$first['call_number'] === 1, 'Pierwszy wpis call_number=1');
    assert_test($first['operation_type'] === 'article_draft', 'Pierwszy wpis operation_type=article_draft');
}

// --- TEST 8: Stary limit 15 nie zatrzymuje przebiegu ---
echo "\n=== TEST 8: Stary limit 15 nie zatrzymuje ===\n";

$pdo->exec('DELETE FROM article_generation_budget WHERE article_id=200');

// Start with used_calls=14, max_calls=20
$pdo->exec('INSERT INTO article_generation_budget (article_id, max_calls, used_calls, convergence_threshold, calls_log_json, is_exhausted, convergence_active) VALUES (200, 20, 14, 16, "[]", 0, 0)');

// Call 15 should succeed
try {
    gemini_article_budget_increment($pdo, 200, 'article_draft', 'draft', 15, 'success');
    $row = q1($pdo, 'SELECT used_calls FROM article_generation_budget WHERE article_id=200');
    assert_test((int)$row['used_calls'] === 15, 'Call 15 przechodzi (stary limit nie blokuje)');
} catch (GeminiArticleBudgetException $e) {
    assert_test(false, 'Call 15 NIE powinien rzucać wyjątku');
}

// Call 16 should succeed and activate convergence
try {
    gemini_article_budget_increment($pdo, 200, 'article_draft', 'draft', 16, 'success');
    $row = q1($pdo, 'SELECT used_calls, convergence_active FROM article_generation_budget WHERE article_id=200');
    assert_test((int)$row['used_calls'] === 16, 'Call 16 przechodzi');
    assert_test((int)$row['convergence_active'] === 1, 'Call 16 aktywuje convergence');
} catch (GeminiArticleBudgetException $e) {
    assert_test(false, 'Call 16 NIE powinien rzucać wyjątku');
}

// --- TEST 9: repair_router_assess z convergenceActive=true ---
echo "\n=== TEST 9: repair_router_assess convergence mode ===\n";

// Test with risk_flags -> safe_composer, which should become targeted_repair in convergence mode
$checkWithRewrite = [
    'result_json' => json_encode([
        'unsupported_claims' => [],
        'false_quotes' => [],
        'title_supported' => true,
        'clickbait_phrases' => [],
        'missing_elements' => [],
        'language_issues' => [['message' => 'Błędy składniowe']],
        'risk_flags' => [['message' => 'Ryzykowne twierdzenie']],
    ]),
    'passed' => 0,
];

$result = repair_router_assess($checkWithRewrite, true);
assert_test(isset($result['convergence_mode']), 'Return zawiera klucz convergence_mode');
assert_test($result['convergence_mode'] === true, 'convergence_mode = true');

// Check that risk_flags issue originally gets safe_composer, but in convergence mode becomes targeted_repair
$strategies = array_column($result['issues'], 'repair_strategy');
assert_test(!in_array('safe_composer', $strategies), 'safe_composer zamieniony na targeted_repair w convergence mode');

// In convergence mode, safe_composer -> targeted_repair
$hasTargetedRepair = in_array('targeted_repair', $strategies);
assert_test($hasTargetedRepair, 'Istnieje targeted_repair w wynikach convergence mode');

// Test without convergence — safe_composer should remain
$resultNoConv = repair_router_assess($checkWithRewrite, false);
assert_test($resultNoConv['convergence_mode'] === false, 'convergence_mode = false bez flagi');
$strategiesNoConv = array_column($resultNoConv['issues'], 'repair_strategy');
assert_test(in_array('safe_composer', $strategiesNoConv), 'safe_composer pozostaje bez convergence mode');

// --- TEST 10: Różne operation_type zużywają budżet ---
echo "\n=== TEST 10: Różne operacje zużywają budżet ===\n";

$pdo->exec('DELETE FROM article_generation_budget WHERE article_id=300');

// research_package
gemini_article_budget_increment($pdo, 300, 'research_package', 'research', 1, 'success');
$row = q1($pdo, 'SELECT used_calls FROM article_generation_budget WHERE article_id=300');
assert_test((int)$row['used_calls'] === 1, 'research_package inkrementuje budżet');

// quality_check
gemini_article_budget_increment($pdo, 300, 'quality_check', 'quality_check', 1, 'success');
$row = q1($pdo, 'SELECT used_calls FROM article_generation_budget WHERE article_id=300');
assert_test((int)$row['used_calls'] === 2, 'quality_check inkrementuje budżet');

// article_draft
gemini_article_budget_increment($pdo, 300, 'article_draft', 'draft', 1, 'success');
$row = q1($pdo, 'SELECT used_calls FROM article_generation_budget WHERE article_id=300');
assert_test((int)$row['used_calls'] === 3, 'article_draft inkrementuje budżet');

// --- TEST 11: GeminiArticleBudgetException domyślny maxCalls = 20 ---
echo "\n=== TEST 11: GeminiArticleBudgetException domyślne wartości ===\n";

$exc = new GeminiArticleBudgetException(999, 21);
assert_test($exc->articleId === 999, 'Exception articleId');
assert_test($exc->usedCalls === 21, 'Exception usedCalls');
assert_test($exc->maxCalls === 20, 'Exception maxCalls domyślnie = 20');
assert_test(str_contains($exc->getMessage(), '20'), 'Wiadomość zawiera limit 20');

// --- TEST 12: is_exhausted=1 po call 20 (ostatni dozwolony) ---
echo "\n=== TEST 12: is_exhausted po wyczerpaniu ===\n";

$row = q1($pdo, 'SELECT is_exhausted, convergence_active FROM article_generation_budget WHERE article_id=100');
assert_test((int)$row['is_exhausted'] === 1, 'Po call 20: is_exhausted=1');
assert_test((int)$row['convergence_active'] === 1, 'Po call 20: convergence_active=1');

// --- Summary ---
echo "\n========================================\n";
echo sprintf("Wyniki: %d PASS, %d FAIL\n", $passed, $failed);
if ($failed > 0) {
    echo "Nieudane testy:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
