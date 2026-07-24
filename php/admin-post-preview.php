<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';

require_admin_login();

$postId = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT) ?: 0;
$post = $postId > 0 ? find_post($postId, true) : null;

if ($post === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Nie znaleziono artykułu.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

echo render_post_page_html($post, true);
