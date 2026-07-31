<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function mobile_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mobile_smoke_html(string $path): string
{
    $html = file_get_contents($path);
    if (!is_string($html)) {
        throw new RuntimeException('Nie można odczytać pliku: ' . $path);
    }

    return $html;
}

$home = mobile_smoke_html($root . '/index.html');
$articlePath = (glob($root . '/pages/post-*.html') ?: [])[0] ?? '';
mobile_smoke_assert($articlePath !== '', 'Brakuje wygenerowanego artykułu do testu mobilnego.');
$article = mobile_smoke_html($articlePath);
$gallery = mobile_smoke_html($root . '/pages/galerie.html');
$mainCss = mobile_smoke_html($root . '/assets/css/main.css');
$publicCss = mobile_smoke_html($root . '/assets/css/public-theme.css');
$newsFeedJs = mobile_smoke_html($root . '/assets/js/news-feed.js');
$htaccess = mobile_smoke_html($root . '/.htaccess');

foreach (['strona główna' => $home, 'artykuł' => $article, 'galerie' => $gallery] as $label => $html) {
    mobile_smoke_assert(
        str_contains($html, 'content="width=device-width, initial-scale=1"'),
        $label . ': nieprawidłowy viewport.'
    );
    mobile_smoke_assert(!str_contains($html, 'user-scalable=no'), $label . ': strona blokuje zoom.');
    preg_match_all('/<script\b[^>]*\bsrc=["\'][^"\']+["\'][^>]*>/i', $html, $scripts);
    foreach ($scripts[0] as $script) {
        mobile_smoke_assert(preg_match('/\bdefer\b/i', $script) === 1, $label . ': skrypt bez defer: ' . $script);
    }
}

mobile_smoke_assert(str_contains($home, 'data-news-rendered="server"'), 'Kanał główny nie jest renderowany po stronie serwera.');
mobile_smoke_assert(str_contains($newsFeedJs, "=== 'server'"), 'JavaScript nie chroni SSR przed ponownym renderem.');
mobile_smoke_assert(
    substr_count($home, 'assets/js/snap.js?v=cms-core-20260727-layout2') === 1,
    'Strona główna nie ładuje dokładnie jednej instancji snap.js.'
);
mobile_smoke_assert(
    substr_count($article, 'assets/js/snap.js?v=cms-core-20260727-layout2') === 1,
    'Artykuł nie ładuje dokładnie jednej instancji snap.js.'
);
mobile_smoke_assert(!str_contains($article, 'news-feed.js'), 'Artykuł ładuje nieużywany news-feed.js.');
mobile_smoke_assert(str_contains($gallery, 'gallery-overview.js'), 'Przegląd galerii utracił właściwy skrypt.');

preg_match_all('/<img\b[^>]*>/i', $article, $articleImages);
mobile_smoke_assert($articleImages[0] !== [], 'Artykuł testowy nie ma obrazu do kontroli stabilności.');
foreach ($articleImages[0] as $image) {
    mobile_smoke_assert(
        preg_match('/\bwidth=["\'][1-9][0-9]*["\']/i', $image) === 1
            && preg_match('/\bheight=["\'][1-9][0-9]*["\']/i', $image) === 1,
        'Obraz artykułu nie ma zadeklarowanych wymiarów.'
    );
}
mobile_smoke_assert(
    preg_match('/<img\b[^>]*\bfetchpriority=["\']high["\'][^>]*>/i', $article) === 1,
    'Najważniejszy obraz artykułu nie ma wysokiego priorytetu.'
);

preg_match_all('/@font-face\s*\{.*?\}/s', $mainCss, $fontFaces);
mobile_smoke_assert($fontFaces[0] !== [], 'Brakuje lokalnych deklaracji fontów.');
foreach ($fontFaces[0] as $fontFace) {
    mobile_smoke_assert(str_contains($fontFace, 'font-display:swap'), 'Font nie ma font-display: swap.');
}

mobile_smoke_assert(str_contains($publicCss, '@media screen and (max-width: 360px)'), 'Brakuje osłony dla 320–360 px.');
mobile_smoke_assert(str_contains($publicCss, 'min-height: 44px'), 'Publiczne elementy dotykowe nie mają minimalnego rozmiaru.');
mobile_smoke_assert(str_contains($mainCss, 'min-height:44px'), 'Formularze panelu nie mają minimalnego rozmiaru dotykowego.');
mobile_smoke_assert(str_contains($publicCss, ':focus-visible'), 'Brakuje widocznego focusu publicznego.');
mobile_smoke_assert(str_contains($mainCss, ':focus-visible'), 'Brakuje widocznego focusu panelu.');
mobile_smoke_assert(str_contains($htaccess, 'AddOutputFilterByType DEFLATE'), 'Brakuje kompresji zasobów tekstowych.');
mobile_smoke_assert(str_contains($htaccess, 'max-age=31536000'), 'Brakuje długiego cache dla wersjonowanych mediów.');

fwrite(STDOUT, "MOBILE_PERFORMANCE_SMOKE_OK\n");
