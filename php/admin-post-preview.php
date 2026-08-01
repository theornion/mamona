<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';

require_admin_login();

$postId = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT) ?: 0;
$post = $postId > 0 ? find_post($postId, true) : null;
$draftId = filter_input(INPUT_GET, 'draft', FILTER_VALIDATE_INT) ?: 0;

if ($post === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Nie znaleziono artykułu.';
    exit;
}

if ($draftId > 0) {
    $draftRecord = find_proposal_draft($draftId);
    if (!is_array($draftRecord) || (int) $draftRecord['post_id'] !== $postId || (string) $draftRecord['status'] !== 'completed') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Nie znaleziono wskazanej wersji szkicu.';
        exit;
    }
    $draft = proposal_json_decode((string) $draftRecord['draft_json']);
    $post['title'] = trim((string) ($draft['title'] ?? $post['title']));
    $post['excerpt'] = mb_substr(trim((string) ($draft['brief'] ?? '')), 0, 500);
    $post['content'] = render_article_blocks(article_draft_content_blocks($draft), list_article_images($postId));
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

echo render_post_page_html($post, true);
