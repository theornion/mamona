<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin-database.php';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if (count($arguments) !== 1 || !in_array($arguments[0], ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Użycie: php php/cli-rerender-public-posts.php [--dry-run|--apply]\n");
    exit(2);
}

$database = bueno_database();
$posts = array_values(array_filter(list_posts(), static function (array $post) use ($database): bool {
    if ((array) (json_decode((string) ($post['content_blocks'] ?? '[]'), true) ?: []) === []) return false;
    $statement = $database->prepare('SELECT 1 FROM generation_operations WHERE post_id=:post AND operation_type=:type AND status=:status LIMIT 1');
    $statement->execute([':post'=>(int) $post['id'], ':type'=>'layout_plan', ':status'=>'completed']);
    return $statement->fetchColumn() !== false;
}));
$result = [
    'mode' => $arguments[0],
    'saved_artifact_posts' => count($posts),
    'gemini_calls' => 0,
    'posts' => array_values(array_map(
        static fn (array $post): array => ['id'=>(int) $post['id'], 'status'=>(string) $post['status']],
        $posts
    )),
];

if ($arguments[0] === '--apply') {
    foreach ($posts as $post) {
        refresh_article_image_rendering((int) $post['id']);
        $refreshed = find_post((int) $post['id']);
        if (is_array($refreshed) && post_is_public($refreshed)) write_post_page($refreshed);
    }
    $result['rerendered'] = count($posts);
}

fwrite(STDOUT, json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL);
