<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$target = (string) ($_GET['target'] ?? '/index.html');
$targetPath = parse_url($target, PHP_URL_PATH);

if (!is_string($targetPath)
    || !preg_match('#^/(?:index\.html|pages/[a-z0-9-]+\.html|qa/article-preview\.html)$#', $targetPath)) {
    http_response_code(400);
    exit('Unsupported performance target.');
}

$absoluteTarget = $root . str_replace('/', DIRECTORY_SEPARATOR, $targetPath);
$html = file_get_contents($absoluteTarget);

if (!is_string($html)) {
    http_response_code(404);
    exit('Performance target not found.');
}

$basePath = rtrim(str_replace('\\', '/', dirname($targetPath)), '/');
$baseHref = ($basePath === '' ? '/' : $basePath . '/');
$variant = (string) ($_GET['variant'] ?? 'legacy');
if (in_array($variant, ['no-parallax', 'no-snap', 'no-scroll-js'], true)) {
    if ($variant === 'no-parallax' || $variant === 'no-scroll-js') {
        $html = preg_replace('#<script\b[^>]*src=["\'][^"\']*parallax\.js[^"\']*["\'][^>]*></script>#i', '', $html) ?? $html;
    }
    if ($variant === 'no-snap' || $variant === 'no-scroll-js') {
        $html = preg_replace('#<script\b[^>]*src=["\'][^"\']*snap\.js[^"\']*["\'][^>]*></script>#i', '', $html) ?? $html;
    }
}
$headInjection = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">';
if ($variant === 'no-blur') {
    $headInjection .= '<style>body:not(.admin-page) #main{-webkit-backdrop-filter:none!important;backdrop-filter:none!important}</style>';
}
$bodyInjection = '<output id="qa-performance-status" style="position:fixed;z-index:2147483647;right:.5rem;bottom:.5rem;padding:.35rem .55rem;background:#00130f;color:#8fffe0;font:12px monospace">QA waiting</output>'
    . '<script src="/qa/performance/scroll-probe.js"></script>';

$html = preg_replace('/<head>/i', '<head>' . $headInjection, $html, 1) ?? $html;
$html = preg_replace('/<\/body>/i', $bodyInjection . '</body>', $html, 1) ?? $html;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
echo $html;
