<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_RENDERER_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_RENDERER_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

if (getenv('CMS_PUBLIC_URL') === false) {
    putenv('CMS_PUBLIC_URL=https://example.test');
}
putenv('CMS_SKIP_PUBLIC_SYNC=1');

require_once dirname(__DIR__) . '/php/admin-database.php';

function renderer_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function renderer_json_ld(string $html): array
{
    if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches) !== 1) {
        throw new RuntimeException('Brak JSON-LD w artykule.');
    }
    $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

$database = bueno_database();
$token = bin2hex(random_bytes(5));
$categoryId = 0;
$postIds = [];

try {
    $statement = $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order)
         VALUES (:title, :description, :slug, 999999)'
    );
    $statement->execute([
        ':title' => 'Renderer ' . $token,
        ':description' => 'Kategoria testowa.',
        ':slug' => 'renderer-' . $token,
    ]);
    $categoryId = (int) $database->lastInsertId();

    foreach ([1, 2] as $number) {
        $imagePath = $number === 1 ? 'images/posts/2c48fdab2b5b333c482d6a940cf767ca.jpg' : '';
        $postId = create_post(
            $categoryId,
            'Test <SEO> & artykuł ' . $number . ' ' . $token,
            'Opis zapasowy ' . $number,
            'Treść działająca bez JavaScriptu.',
            $imagePath,
            '',
            null,
            [],
            'cover',
            [],
            false
        );
        $postIds[] = $postId;
        $database->prepare(
            'UPDATE posts SET seo_description = :description, image_alt = :image_alt,
             ai_assisted = :ai_assisted, ai_disclosure = :disclosure WHERE id = :id'
        )->execute([
            ':description' => 'Unikalny opis SEO numer ' . $number . ' & test',
            ':image_alt' => 'Znaczący opis obrazu numer ' . $number,
            ':ai_assisted' => $number === 1 ? 1 : 0,
            ':disclosure' => $number === 1 ? 'AI wsparło research, a redakcja zweryfikowała tekst.' : '',
            ':id' => $postId,
        ]);
        replace_post_sources($postId, [[
            'source_url' => 'https://example.org/source-' . $number,
            'source_title' => 'Źródło numer ' . $number,
            'source_type' => 'primary',
        ]]);
        $post = find_post($postId);
        update_post($postId, (string) $post['title'], (string) $post['excerpt'], (string) $post['content'], $imagePath, true);
    }

    $first = find_post($postIds[0]);
    $second = find_post($postIds[1]);
    $firstHtml = render_post_page_html($first);
    $secondHtml = render_post_page_html($second);
    $draft = $first;
    $draft['status'] = 'draft';
    $draftHtml = render_post_page_html($draft, true);

    renderer_assert($firstHtml !== $secondHtml, 'Artykuły mają identyczny HTML.');
    renderer_assert(str_contains($firstHtml, '&lt;SEO&gt; &amp; artykuł 1'), 'Tytuł nie jest prawidłowo kodowany.');
    renderer_assert(str_contains($firstHtml, 'Unikalny opis SEO numer 1 &amp; test'), 'Opis nie jest prawidłowo kodowany.');
    renderer_assert(str_contains($firstHtml, 'https://example.test/pages/' . post_page_filename((string) $first['slug'])), 'Pierwszy canonical jest błędny.');
    renderer_assert(str_contains($secondHtml, 'https://example.test/pages/' . post_page_filename((string) $second['slug'])), 'Drugi canonical jest błędny.');
    renderer_assert(post_canonical_url($first) !== post_canonical_url($second), 'Canonical nie jest unikalny.');
    renderer_assert(post_meta_description($first) !== post_meta_description($second), 'Description nie jest unikalny.');
    renderer_assert(str_contains($firstHtml, 'content="index,follow,max-image-preview:large"'), 'Brak reguły robots dla publikacji.');
    renderer_assert(str_contains($draftHtml, 'content="noindex,nofollow,noarchive"'), 'Szkic nie ma noindex.');
    renderer_assert(!str_contains($draftHtml, 'rel="canonical"'), 'Podgląd szkicu publikuje canonical.');
    renderer_assert(str_contains($firstHtml, 'Źródło numer 1</a>'), 'Źródło nie jest widoczne i klikalne.');
    renderer_assert(str_contains($firstHtml, 'AI wsparło research'), 'Brak disclosure automatyzacji.');
    renderer_assert(str_contains($firstHtml, 'Powiązane artykuły'), 'Brak artykułów powiązanych.');
    renderer_assert(str_contains($firstHtml, '<article class="post featured bueno-post-page">'), 'Brak semantycznego article.');
    renderer_assert(str_contains($firstHtml, 'Autor:'), 'Brak widocznego autora.');
    renderer_assert(str_contains($firstHtml, '<time datetime="'), 'Brak widocznej daty.');
    renderer_assert(str_contains($firstHtml, 'property="og:type" content="article"'), 'Brak Open Graph.');
    renderer_assert(str_contains($firstHtml, 'name="twitter:card"'), 'Brak metadanych społecznościowych.');
    $structuredData = renderer_json_ld($firstHtml);
    renderer_assert(($structuredData['@type'] ?? '') === 'NewsArticle', 'Nieprawidłowy typ JSON-LD.');
    renderer_assert(($structuredData['headline'] ?? '') === (string) $first['title'], 'Headline nie zgadza się z H1.');
    renderer_assert(($structuredData['description'] ?? '') === post_meta_description($first), 'Description JSON-LD jest niespójny.');
    renderer_assert(($structuredData['mainEntityOfPage']['@id'] ?? '') === post_canonical_url($first), 'Adres JSON-LD jest niespójny.');
    renderer_assert(($structuredData['author']['name'] ?? '') !== '', 'Brak prawdziwego autora w JSON-LD.');
    renderer_assert(($structuredData['publisher']['name'] ?? '') === app_config('publisher_name'), 'Publisher jest niespójny z konfiguracją.');
    renderer_assert(($structuredData['inLanguage'] ?? '') === app_config('language'), 'Język JSON-LD jest nieprawidłowy.');
    renderer_assert(($structuredData['articleSection'] ?? '') === 'Renderer ' . $token, 'Sekcja artykułu jest nieprawidłowa.');
    renderer_assert(preg_match('/[+-][0-9]{2}:[0-9]{2}$/', (string) ($structuredData['datePublished'] ?? '')) === 1, 'Data publikacji nie ma strefy.');
    renderer_assert(preg_match('/[+-][0-9]{2}:[0-9]{2}$/', (string) ($structuredData['dateModified'] ?? '')) === 1, 'Data modyfikacji nie ma strefy.');
    renderer_assert(
        ($structuredData['image'][0] ?? '') === 'https://example.test/images/posts/2c48fdab2b5b333c482d6a940cf767ca.jpg',
        'Obraz JSON-LD nie ma poprawnego absolutnego adresu.'
    );
    renderer_assert(str_contains($firstHtml, 'alt="Znaczący opis obrazu numer 1"'), 'Widoczny obraz i JSON-LD używają niespójnych danych.');
    $secondStructuredData = renderer_json_ld($secondHtml);
    renderer_assert(!array_key_exists('image', $secondStructuredData), 'Nieistniejący obraz trafił do JSON-LD.');

    $withoutAuthor = $first;
    $withoutAuthor['author_id'] = null;
    $withoutAuthorData = renderer_json_ld(render_post_page_html($withoutAuthor));
    renderer_assert(!array_key_exists('author', $withoutAuthorData), 'Brak autora zastąpiono fikcyjną osobą.');

    echo "POST_RENDERER_SMOKE_OK\n";
} finally {
    foreach ($postIds as $postId) {
        $post = find_post($postId, true);
        $database->prepare("UPDATE posts SET image_path = '', content_image_path = '', content_images = '[]' WHERE id = :id")
            ->execute([':id' => $postId]);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
        $database->prepare('DELETE FROM post_sources WHERE post_id = :post_id')->execute([':post_id' => $postId]);
    }
    if ($categoryId > 0) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $categoryId]);
    }
}
