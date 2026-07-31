<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/admin-database.php';

$title = trim(implode(' ', array_slice($argv, 1)));
if ($title === '') {
    fwrite(STDERR, "Użycie: php scripts/refill-article-images.php <tytuł>\n");
    exit(2);
}
$statement = bueno_database()->prepare('SELECT id, title, is_published FROM posts WHERE title = :title ORDER BY id DESC LIMIT 1');
$statement->execute([':title' => $title]);
$post = $statement->fetch();
if (!is_array($post)) {
    fwrite(STDERR, "Nie znaleziono artykułu.\n");
    exit(3);
}
if ((int) $post['is_published'] === 1) {
    fwrite(STDERR, "Odmowa: skrypt migracyjny nie modyfikuje opublikowanego artykułu.\n");
    exit(4);
}
$summary = fulfill_article_source_images((int) $post['id']);
echo generation_json(['post_id' => (int) $post['id'], 'title' => $post['title'], 'published' => false, 'summary' => $summary]), PHP_EOL;
