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
    if (!is_array($draftRecord) || (int) $draftRecord['post_id'] !== $postId || !in_array((string) $draftRecord['status'], ['completed', 'frozen'], true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Nie znaleziono wskazanej wersji szkicu.';
        exit;
    }
    $draft = proposal_json_decode((string) $draftRecord['draft_json']);
    $post['title'] = trim((string) ($draft['title'] ?? $post['title']));
    $post['excerpt'] = mb_substr(trim((string) ($draft['brief'] ?? '')), 0, 500);
}

$previewBlocks = $draftId > 0
    ? article_draft_content_blocks($draft)
    : (json_decode((string) ($post['content_blocks'] ?? '[]'), true) ?: []);
if ($previewBlocks !== []) {
    $layoutAudit = [];
    $post['rendered_content_override'] = render_article_blocks_with_layout(
        $previewBlocks,
        article_image_required_records($postId),
        article_layout_plan_for_post($postId, $layoutAudit),
        article_related_context_blocks_for_post($postId),
        $layoutAudit
    );
    $post['rendered_content_includes_hero'] = true;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

echo render_post_page_html($post, true);
