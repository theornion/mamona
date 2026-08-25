<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/cli-reset-invalid-articles.php';

if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php php/cli-reset-nonready-topics.php --apply\n");
    exit(2);
}

$db = bueno_database();
$readyStatuses = ['ready', 'ready_for_preview', 'ready_with_notes'];
$readyPlaceholders = implode(',', array_fill(0, count($readyStatuses), '?'));
$selection = $db->prepare(
    'SELECT p.id FROM posts p WHERE p.is_published = 0 AND p.editorial_origin = "automatic"'
    . ' AND NOT EXISTS (SELECT 1 FROM generation_batch_items i WHERE i.post_id = p.id AND i.status IN (' . $readyPlaceholders . '))'
    . ' ORDER BY p.id'
);
$selection->execute($readyStatuses);
$articleIds = array_map('intval', array_column($selection->fetchAll(), 'id'));

if ($articleIds === []) {
    echo json_encode(['ok' => true, 'reset' => 0], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$backupDir = __DIR__ . '/../data/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Nie można utworzyć katalogu backupów.');
}
$stamp = gmdate('Ymd_His');
$snapshot = $backupDir . '/before-reset-nonready-' . $stamp . '.sqlite';
$quotedSnapshot = str_replace("'", "''", str_replace('\\', '/', $snapshot));
$db->exec("VACUUM INTO '" . $quotedSnapshot . "'");
$manifest = backup_affected_records($db, $articleIds);

$placeholders = implode(',', array_fill(0, count($articleIds), '?'));
$db->beginTransaction();
try {
    $db->prepare('DELETE FROM generation_batch_audit WHERE item_id IN (SELECT id FROM generation_batch_items WHERE post_id IN (' . $placeholders . '))')->execute($articleIds);
    $db->prepare('DELETE FROM generation_batch_items WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM narrative_plans WHERE article_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM thumbnail_versions WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM quality_check_runs WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM article_feedback_operations WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM article_proposal_audit WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM article_draft_versions WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM generation_operations WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM research_packages WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);
    $db->prepare('DELETE FROM article_images WHERE post_id IN (' . $placeholders . ')')->execute($articleIds);

    $history = $db->prepare('INSERT INTO post_status_history (post_id, previous_status, new_status, reason, actor) SELECT id, status, "idea", "Pełny reset niegotowej propozycji; zachowano tylko dane RSS.", "reset-tool" FROM posts WHERE id IN (' . $placeholders . ') AND status <> "idea"');
    $history->execute($articleIds);
    $reset = $db->prepare(
        'UPDATE posts SET title = COALESCE((SELECT title FROM discovered_feed_items d WHERE d.post_id = posts.id ORDER BY d.id DESC LIMIT 1), title),'
        . ' excerpt = COALESCE((SELECT summary FROM discovered_feed_items d WHERE d.post_id = posts.id ORDER BY d.id DESC LIMIT 1), ""),'
        . ' content = "", image_path = "", content_image_path = "", content_images = "[]", content_blocks = "[]", image_alt = "",'
        . ' status = "idea", is_published = 0, published_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id IN (' . $placeholders . ')'
    );
    $reset->execute($articleIds);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

echo json_encode([
    'ok' => true,
    'reset' => count($articleIds),
    'snapshot' => $snapshot,
    'snapshot_sha256' => hash_file('sha256', $snapshot),
    'manifest' => $manifest,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
