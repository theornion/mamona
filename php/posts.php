<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $categorySlug = trim((string) ($_GET['category'] ?? ''));
    $category = $categorySlug !== '' ? find_post_category_by_slug($categorySlug) : null;

    if ($categorySlug !== '' && $category === null) {
        http_response_code(404);
        echo json_encode(['category' => null, 'posts' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $posts = array_map(static function (array $post): array {
        $imagePath = ltrim(str_replace('\\', '/', (string) $post['image_path']), '/');
        $image = $imagePath !== '' && is_file(dirname(__DIR__) . '/' . $imagePath) ? '../' . $imagePath : '';

        return [
            'id' => (int) $post['id'],
            'title' => $post['title'],
            'excerpt' => $post['excerpt'],
            'content' => $post['content'],
            'image' => $image,
            'url' => post_page_filename((string) $post['slug']),
            'category' => $post['category_title'],
            'createdAt' => $post['created_at'],
        ];
    }, list_posts($category !== null ? (int) $category['id'] : null, true));

    echo json_encode([
        'category' => $category === null ? null : [
            'title' => $category['title'],
            'description' => $category['description'],
        ],
        'posts' => $posts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['category' => null, 'posts' => []]);
}
