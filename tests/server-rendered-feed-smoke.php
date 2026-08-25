<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_SSR_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_SSR_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_PUBLIC_URL=https://example.test');
putenv('CMS_ADS_ENABLED=false');
putenv('CMS_ADS_PREVIEW=false');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function ssr_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = bueno_database();
$token = bin2hex(random_bytes(5));
$categoryId = 0;
$postIds = [];
$rootPath = app_path('index.html');
$archivePath = app_path('pages/aktualnosci-2.html');
$categoryPath = app_path('pages/kategoria-ssr-' . $token . '.html');
$manifestPath = app_path('data/generated-news-pages.json');
$rootBefore = is_file($rootPath) ? file_get_contents($rootPath) : null;
$archiveBefore = is_file($archivePath) ? file_get_contents($archivePath) : null;
$manifestBefore = is_file($manifestPath) ? file_get_contents($manifestPath) : null;
$generatedPagesBefore = [];
foreach (array_merge(
    glob(app_path('pages/aktualnosci-*.html')) ?: [],
    glob(app_path('pages/kategoria-*.html')) ?: []
) as $generatedPage) {
    $generatedPagesBefore[$generatedPage] = file_get_contents($generatedPage);
}

try {
    $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order)
         VALUES (:title, :description, :slug, 999999)'
    )->execute([
        ':title' => 'SSR ' . $token,
        ':description' => 'Kategoria dostępna bez JavaScriptu.',
        ':slug' => 'ssr-' . $token,
    ]);
    $categoryId = (int) $database->lastInsertId();

    for ($number = 1; $number <= 6; $number++) {
        $image = $number >= 5 ? 'images/digital_rain.png' : '';
        $postIds[] = create_post(
            $categoryId,
            'SSR artykuł ' . $number . ' ' . $token,
            'Opis SSR ' . $number,
            'Treść dostępna bez skryptów.',
            $image,
            '',
            null,
            [],
            'cover',
            [],
            true
        );
    }

    write_root_index_page();
    $root = (string) file_get_contents($rootPath);
    $archive = (string) file_get_contents($archivePath);
    $category = (string) file_get_contents($categoryPath);

    ssr_assert(substr_count($root, '<article class="news-feed-item">') === 5, 'Pierwszy HTML nie zawiera pięciu artykułów.');
    ssr_assert(str_contains($root, 'href="pages/post-ssr-artykul-6-'), 'Artykuł nie ma zwykłego linku.');
    ssr_assert(str_contains($root, 'href="pages/aktualnosci-2.html"'), 'Brak statycznej paginacji.');
    ssr_assert(str_contains($archive, '<article class="news-feed-item">'), 'Druga strona nie zawiera SSR.');
    ssr_assert(str_contains($category, 'Kategoria dostępna bez JavaScriptu.'), 'Statyczna strona kategorii nie działa.');
    ssr_assert(preg_match('/<img[^>]+fetchpriority="high"[^>]*>/', $root) === 1, 'Pierwszy obraz nie ma wysokiego priorytetu.');
    ssr_assert(preg_match('/<img[^>]+loading="lazy"[^>]*>/', $root) === 1, 'Dalsze obrazy nie są ładowane leniwie.');
    ssr_assert(preg_match('/<img[^>]+width="[1-9][0-9]*"[^>]+height="[1-9][0-9]*"/', $root) === 1, 'Obraz nie ma wymiarów.');
    ssr_assert(str_contains($root, 'data-news-source="php/posts.php"'), 'JavaScript nie może progresywnie ulepszyć feedu.');

    ssr_assert(!str_contains($root, 'data-ad-placement='), 'Wyłączone reklamy zostawiają slot w SSR.');
    putenv('CMS_ADS_ENABLED=true');
    putenv('CMS_ADS_PREVIEW=true');
    $previewFeed = render_server_news_feed(list_posts(null, true), null, 1, 'aktualnosci-%d.html');
    ssr_assert(str_contains($previewFeed, 'data-ad-placement="page-top"'), 'Feed nie renderuje placementu page-top.');
    ssr_assert(str_contains($previewFeed, 'data-ad-placement="feed-inline"'), 'Feed nie renderuje slotu po trzeciej karcie.');
    ssr_assert(substr_count($previewFeed, 'aria-label="Reklama"') === 2, 'Feed ma nieprawidłową liczbę placeholderów.');
    putenv('CMS_ADS_MAX_SLOTS_PER_PAGE=1');
    $limitedPreviewFeed = render_server_news_feed(list_posts(null, true), null, 1, 'aktualnosci-%d.html');
    ssr_assert(substr_count($limitedPreviewFeed, 'aria-label="Reklama"') === 1, 'Feed przekracza globalny limit slotów.');
    putenv('CMS_ADS_MAX_SLOTS_PER_PAGE');
    putenv('CMS_ADS_ENABLED=false');
    putenv('CMS_ADS_PREVIEW=false');

    $database->prepare('UPDATE post_categories SET is_editorial_only = 1 WHERE id = :id')
        ->execute([':id' => $categoryId]);
    $editorialFeed = render_server_news_feed(list_posts(null, true), null, 1, 'aktualnosci-%d.html');
    ssr_assert(
        str_contains($editorialFeed, $token),
        'Post z kategorii redakcyjnej zniknal z publicznego feedu.'
    );
    ssr_assert(
        !str_contains($editorialFeed, '<p class="news-feed-category">SSR ' . $token . '</p>'),
        'Techniczna kategoria redakcyjna jest widoczna na karcie postu.'
    );

    echo "SERVER_RENDERED_FEED_SMOKE_OK\n";
} finally {
    foreach ($postIds as $postId) {
        $database->prepare("UPDATE posts SET image_path = '', content_image_path = '', content_images = '[]' WHERE id = :id")
            ->execute([':id' => $postId]);
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }
    if ($categoryId > 0) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $categoryId]);
    }
    if (is_string($rootBefore)) {
        write_public_file_atomically($rootPath, $rootBefore);
    }
    if (is_string($archiveBefore)) {
        write_public_file_atomically($archivePath, $archiveBefore);
    } elseif (is_file($archivePath)) {
        unlink($archivePath);
    }
    foreach (glob(app_path('pages/kategoria-ssr-' . $token . '*.html')) ?: [] as $path) {
        unlink($path);
    }
    foreach ($generatedPagesBefore as $path => $contents) {
        if (is_string($contents)) {
            write_public_file_atomically($path, $contents);
        }
    }
    if (is_string($manifestBefore)) {
        write_public_file_atomically($manifestPath, $manifestBefore);
    } elseif (is_file($manifestPath)) {
        unlink($manifestPath);
    }
}
