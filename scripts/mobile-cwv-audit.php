<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pages = [
    'home' => $root . '/index.html',
    'article' => (glob($root . '/pages/post-*.html') ?: [])[0] ?? '',
];

function audit_asset_path(string $htmlPath, string $source): string
{
    $source = (string) (parse_url(html_entity_decode($source), PHP_URL_PATH) ?? '');
    $path = dirname($htmlPath) . '/' . ltrim($source, '/');
    $resolved = realpath($path);

    return is_string($resolved) ? $resolved : '';
}

function audit_page(string $path): array
{
    if ($path === '' || !is_file($path)) {
        return ['available' => false];
    }
    $html = (string) file_get_contents($path);
    preg_match_all('/<script\b([^>]*)\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $scripts, PREG_SET_ORDER);
    preg_match_all('/<link\b([^>]*)\brel=["\']stylesheet["\'][^>]*>/i', $html, $styles, PREG_SET_ORDER);
    preg_match_all('/<img\b([^>]*)>/i', $html, $images, PREG_SET_ORDER);
    $scriptBytes = 0;
    $deferred = 0;
    $scriptNames = [];
    foreach ($scripts as $script) {
        $asset = audit_asset_path($path, $script[2]);
        $scriptBytes += $asset !== '' ? (int) filesize($asset) : 0;
        $deferred += preg_match('/\b(?:defer|async)\b/i', $script[0]) === 1 ? 1 : 0;
        $scriptNames[] = basename((string) (parse_url($script[2], PHP_URL_PATH) ?? $script[2]));
    }
    $reservedImages = 0;
    $priorityImages = 0;
    foreach ($images as $image) {
        $hasDimensions = preg_match('/\bwidth=["\'][1-9][0-9]*["\']/i', $image[0]) === 1
            && preg_match('/\bheight=["\'][1-9][0-9]*["\']/i', $image[0]) === 1;
        $reservedImages += $hasDimensions ? 1 : 0;
        $priorityImages += preg_match('/\bfetchpriority=["\']high["\']/i', $image[0]) === 1 ? 1 : 0;
    }
    preg_match('/<meta\s+name=["\']viewport["\']\s+content=["\']([^"\']+)/i', $html, $viewport);

    return [
        'available' => true,
        'html_bytes' => filesize($path),
        'scripts' => count($scripts),
        'script_bytes' => $scriptBytes,
        'deferred_scripts' => $deferred,
        'includes_snap_bundle' => in_array('snap.js', $scriptNames, true),
        'render_blocking_stylesheets' => count($styles),
        'viewport_allows_zoom' => !str_contains(strtolower((string) ($viewport[1] ?? '')), 'user-scalable=no'),
        'images' => count($images),
        'images_with_dimensions' => $reservedImages,
        'priority_images' => $priorityImages,
        'server_rendered_feed' => str_contains($html, 'news-feed-list'),
    ];
}

$mainCss = (string) file_get_contents($root . '/assets/css/main.css');
$publicCss = (string) file_get_contents($root . '/assets/css/public-theme.css');
preg_match_all('/@font-face\s*\{.*?\}/s', $mainCss, $fontFaces);
$fontDisplayCount = 0;
foreach ($fontFaces[0] as $fontFace) {
    $fontDisplayCount += str_contains($fontFace, 'font-display:') ? 1 : 0;
}

$result = [
    'generated_at' => gmdate(DATE_ATOM),
    'method' => 'static-repeatable-budget-audit',
    'pages' => array_map('audit_page', $pages),
    'css' => [
        'main_bytes' => filesize($root . '/assets/css/main.css'),
        'public_theme_bytes' => filesize($root . '/assets/css/public-theme.css'),
        'font_faces' => count($fontFaces[0]),
        'font_faces_with_display' => $fontDisplayCount,
        'mobile_320_guard' => str_contains($publicCss, '@media screen and (max-width: 360px)'),
        'touch_target_guard' => str_contains($publicCss, 'min-height: 44px'),
        'focus_visible_guard' => str_contains($publicCss, ':focus-visible'),
    ],
    'javascript' => [
        'ssr_feed_preserved' => str_contains((string) file_get_contents($root . '/assets/js/news-feed.js'), 'data-news-rendered')
            && str_contains((string) file_get_contents($root . '/assets/js/news-feed.js'), "=== 'server'"),
    ],
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
