<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_DISCOVERY_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_DISCOVERY_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_PUBLIC_URL=https://example.test');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function discovery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function discovery_snapshot(array $paths): array
{
    $snapshot = [];
    foreach ($paths as $path) {
        $snapshot[$path] = is_file($path) ? file_get_contents($path) : null;
    }

    return $snapshot;
}

function discovery_restore(array $snapshot): void
{
    foreach ($snapshot as $path => $contents) {
        if (is_string($contents)) {
            write_public_file_atomically($path, $contents);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}

$database = bueno_database();
$paths = [app_path('sitemap.xml'), app_path('feed.xml'), app_path('robots.txt')];
$snapshot = discovery_snapshot($paths);
$token = bin2hex(random_bytes(5));
$categoryId = 0;
$publishedId = 0;
$draftId = 0;

try {
    $statement = $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order)
         VALUES (:title, :description, :slug, 999999)'
    );
    $statement->execute([
        ':title' => 'Discovery ' . $token,
        ':description' => 'Kategoria testowa.',
        ':slug' => 'discovery-' . $token,
    ]);
    $categoryId = (int) $database->lastInsertId();

    $publishedId = create_post(
        $categoryId,
        'Publiczny RSS ' . $token,
        'Opis kanału & mapa.',
        'Treść.',
        'images/posts/2c48fdab2b5b333c482d6a940cf767ca.jpg',
        '',
        null,
        [],
        'cover',
        [],
        true
    );
    $draftId = create_post(
        $categoryId,
        'Prywatny RSS ' . $token,
        'Szkic nie może trafić do XML.',
        'Treść szkicu.'
    );

    write_discovery_files();
    $sitemap = (string) file_get_contents(app_path('sitemap.xml'));
    $feed = (string) file_get_contents(app_path('feed.xml'));
    $robots = (string) file_get_contents(app_path('robots.txt'));
    $published = find_post($publishedId);
    $draft = find_post($draftId);
    $publishedUrl = post_canonical_url($published);
    $draftUrl = post_canonical_url($draft);

    discovery_assert(str_starts_with($sitemap, '<?xml version="1.0" encoding="UTF-8"?>'), 'Sitemap nie deklaruje UTF-8.');
    discovery_assert(str_starts_with($feed, '<?xml version="1.0" encoding="UTF-8"?>'), 'RSS nie deklaruje UTF-8.');
    discovery_assert(simplexml_load_string($sitemap) !== false, 'Sitemap nie jest poprawnym XML-em.');
    discovery_assert(simplexml_load_string($feed) !== false, 'RSS nie jest poprawnym XML-em.');
    discovery_assert(str_contains($sitemap, discovery_xml($publishedUrl)), 'Publikacji brakuje w sitemapie.');
    discovery_assert(!str_contains($sitemap, discovery_xml($draftUrl)), 'Szkic trafił do sitemapy.');
    discovery_assert(str_contains($feed, discovery_xml($publishedUrl)), 'Publikacji brakuje w RSS.');
    discovery_assert(!str_contains($feed, discovery_xml($draftUrl)), 'Szkic trafił do RSS.');
    discovery_assert(str_contains($feed, '<category>Discovery ' . $token . '</category>'), 'RSS nie zawiera kategorii.');
    discovery_assert(str_contains($feed, '<enclosure url="https://example.test/images/posts/'), 'RSS nie zawiera absolutnego obrazu.');
    discovery_assert(str_contains($robots, 'Sitemap: https://example.test/sitemap.xml'), 'robots.txt nie wskazuje sitemapy.');
    discovery_assert(substr_count($robots, 'Sitemap:') === 1, 'robots.txt zawiera duplikaty wpisu Sitemap.');
    discovery_assert(
        preg_match('#<lastmod>[^<]*[+-][0-9]{2}:[0-9]{2}</lastmod>#', $sitemap) === 1,
        'lastmod nie zawiera strefy czasowej.'
    );

    $database->prepare('UPDATE post_categories SET is_editorial_only = 1 WHERE id = :id')
        ->execute([':id' => $categoryId]);
    $editorialFeed = render_rss_xml();
    discovery_assert(
        str_contains($editorialFeed, discovery_xml($publishedUrl)),
        'Artykul w kategorii redakcyjnej zniknal z RSS.'
    );
    discovery_assert(
        !str_contains($editorialFeed, '<category>Discovery ' . $token . '</category>'),
        'Techniczna kategoria redakcyjna jest widoczna w RSS.'
    );

    update_post(
        $publishedId,
        (string) $published['title'],
        (string) $published['excerpt'],
        (string) $published['content'],
        (string) $published['image_path'],
        false
    );
    write_discovery_files();
    $withdrawnSitemap = (string) file_get_contents(app_path('sitemap.xml'));
    $withdrawnFeed = (string) file_get_contents(app_path('feed.xml'));
    discovery_assert(!str_contains($withdrawnSitemap, discovery_xml($publishedUrl)), 'Wycofany artykuł pozostał w sitemapie.');
    discovery_assert(!str_contains($withdrawnFeed, discovery_xml($publishedUrl)), 'Wycofany artykuł pozostał w RSS.');

    echo "DISCOVERY_FILES_SMOKE_OK\n";
} finally {
    foreach ([$publishedId, $draftId] as $postId) {
        if ($postId <= 0) {
            continue;
        }
        $database->prepare("UPDATE posts SET image_path = '', content_image_path = '', content_images = '[]' WHERE id = :id")
            ->execute([':id' => $postId]);
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
        $database->prepare('DELETE FROM post_sources WHERE post_id = :post_id')->execute([':post_id' => $postId]);
    }
    if ($categoryId > 0) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $categoryId]);
    }
    discovery_restore($snapshot);
}
