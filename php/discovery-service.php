<?php

declare(strict_types=1);

function discovery_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function discovery_post_lastmod(array $post): string
{
    [$iso] = post_display_datetime((string) ($post['content_updated_at'] ?? $post['published_at'] ?? ''));

    return $iso;
}

function discovery_rss_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_RSS);
    } catch (Throwable) {
        return '';
    }
}

function render_sitemap_xml(): string
{
    $urls = [
        ['loc' => app_public_url()],
    ];

    if (is_file(app_path('pages/galerie.html'))) {
        $urls[] = ['loc' => app_public_url('pages/galerie.html')];
    }
    if (function_exists('trust_public_page_filenames')) {
        foreach (trust_public_page_filenames() as $filename) {
            $path = 'pages/' . $filename;
            if (is_file(app_path($path))) {
                $urls[] = ['loc' => app_public_url($path)];
            }
        }
    }
    foreach (list_galleries() as $gallery) {
        $path = 'pages/' . rawurlencode((string) $gallery['slug']) . '.html';
        if (is_file(app_path($path))) {
            $urls[] = ['loc' => app_public_url($path)];
        }
    }
    $newsPages = array_merge(
        glob(app_path('pages/aktualnosci-*.html')) ?: [],
        glob(app_path('pages/kategoria-*.html')) ?: []
    );
    sort($newsPages, SORT_NATURAL);
    foreach ($newsPages as $newsPage) {
        $urls[] = ['loc' => app_public_url('pages/' . basename($newsPage))];
    }
    foreach (list_posts(null, true) as $post) {
        $urls[] = [
            'loc' => post_canonical_url($post),
            'lastmod' => discovery_post_lastmod($post),
        ];
    }

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($urls as $url) {
        $xml .= "  <url><loc>" . discovery_xml((string) $url['loc']) . "</loc>";
        if (!empty($url['lastmod'])) {
            $xml .= '<lastmod>' . discovery_xml((string) $url['lastmod']) . '</lastmod>';
        }
        $xml .= "</url>\n";
    }

    return $xml . "</urlset>\n";
}

function render_rss_xml(int $limit = 30): string
{
    $statement = bueno_database()->prepare(
        "SELECT * FROM posts
         WHERE status = 'published' AND deleted_at IS NULL
         ORDER BY datetime(COALESCE(published_at, created_at)) DESC, id DESC
         LIMIT :limit"
    );
    $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $statement->execute();
    $posts = $statement->fetchAll();
    $siteName = (string) app_config('site_name');
    $feedUrl = app_public_url('feed.xml');
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
    $xml .= '<title>' . discovery_xml($siteName) . '</title>';
    $xml .= '<link>' . discovery_xml(app_public_url()) . '</link>';
    $xml .= '<description>' . discovery_xml('Najnowsze publikacje serwisu ' . $siteName) . '</description>';
    $xml .= '<language>' . discovery_xml((string) app_config('language')) . '</language>';
    $xml .= '<atom:link href="' . discovery_xml($feedUrl) . '" rel="self" type="application/rss+xml"/>';

    foreach ($posts as $post) {
        $category = find_post_category((int) $post['category_id']);
        $url = post_canonical_url($post);
        $xml .= '<item>';
        $xml .= '<title>' . discovery_xml((string) $post['title']) . '</title>';
        $xml .= '<link>' . discovery_xml($url) . '</link>';
        $xml .= '<guid isPermaLink="true">' . discovery_xml($url) . '</guid>';
        $xml .= '<description>' . discovery_xml(post_meta_description($post)) . '</description>';
        $published = discovery_rss_date((string) ($post['published_at'] ?? ''));
        if ($published !== '') {
            $xml .= '<pubDate>' . discovery_xml($published) . '</pubDate>';
        }
        if (post_category_is_public($category)) {
            $xml .= '<category>' . discovery_xml((string) $category['title']) . '</category>';
        }
        $imageUrl = post_absolute_image_url($post);
        if ($imageUrl !== '') {
            $imagePath = app_path((string) $post['image_path']);
            $mime = function_exists('mime_content_type') ? (string) @mime_content_type($imagePath) : '';
            if (!str_starts_with($mime, 'image/')) {
                $mime = 'image/jpeg';
            }
            $length = is_file($imagePath) ? (int) filesize($imagePath) : 0;
            $xml .= '<enclosure url="' . discovery_xml($imageUrl) . '" length="' . $length . '" type="' . discovery_xml($mime) . '"/>';
        }
        $xml .= '</item>';
    }

    return $xml . "</channel></rss>\n";
}

function render_robots_txt(string $current): string
{
    $lines = preg_split('/\R/', $current) ?: [];
    $lines = array_values(array_filter($lines, static function (string $line): bool {
        return preg_match('/^\s*Sitemap\s*:/i', $line) !== 1
            && trim($line) !== 'sitemap.xml';
    }));
    while ($lines !== [] && trim((string) end($lines)) === '') {
        array_pop($lines);
    }
    $lines[] = '';
    $lines[] = 'Sitemap: ' . app_public_url('sitemap.xml');

    return implode("\n", $lines) . "\n";
}

function write_discovery_files(): void
{
    write_public_file_atomically(app_path('sitemap.xml'), render_sitemap_xml());
    write_public_file_atomically(app_path('feed.xml'), render_rss_xml());
    $robotsPath = app_path('robots.txt');
    $robots = is_file($robotsPath) ? (string) file_get_contents($robotsPath) : "User-agent: *\nAllow: /\n";
    write_public_file_atomically($robotsPath, render_robots_txt($robots));
}
