<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/advertising.php';

$length = max(3000, min(5000, (int) ($_GET['length'] ?? 3000)));
$scenario = in_array((string) ($_GET['scenario'] ?? ''), ['full', 'missing-one', 'no-inline'], true)
    ? (string) $_GET['scenario']
    : 'full';
$paragraph = 'Badacze opisują kontrolowany wynik i wyjaśniają jego znaczenie. '
    . 'Każdy fragment pozostaje związany z konkretnym faktem, ograniczeniami danych i praktycznym kontekstem. ';
$text = mb_substr(str_repeat($paragraph, 40), 0, $length);
$chunks = str_split($text, 780);
$image = static function (int $index, string $layout): string {
    $color = ['#355c7d', '#6c5b7b', '#c06c84', '#2a9d8f', '#e76f51'][$index % 5];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"><rect width="1280" height="720" fill="'
        . $color . '"/><circle cx="' . (300 + $index * 90) . '" cy="330" r="150" fill="#fff" opacity=".25"/></svg>';

    $src = $index === 1
        ? '../images/posts/sources/source-b0aaa71a115e9e1271525ac124c60bce74ab13463ecd8067c08d51af04bb324f.jpg'
        : 'data:image/svg+xml;base64,' . base64_encode($svg);
    $width = $index === 1 ? 1024 : 1280;
    $height = $index === 1 ? 1024 : 720;

    return '<figure class="article-illustration article-illustration--' . $layout . '">'
        . '<img src="' . $src . '" alt="Kontrolowana ilustracja fragmentu '
        . ($index + 1) . '" width="' . $width . '" height="' . $height . '" loading="lazy">'
        . '<figcaption>Ilustracja fragmentu ' . ($index + 1)
        . '<small>Autor fixture · <a href="#" rel="license">CC BY 4.0</a></small></figcaption></figure>';
};
$adConfig = advertising_config([
    'enabled' => true,
    'preview' => true,
    'allowed_placements' => ['article-inline'],
    'max_slots_per_page' => 1,
    'max_inline_slots' => 1,
]);
?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QA layoutu artykułu</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/public-theme.css">
    <link rel="stylesheet" href="../assets/css/advertising.css">
    <style>
        main { background:var(--mamona-paper); box-sizing:border-box; margin:2rem auto; max-width:1080px; min-height:100vh; padding:clamp(1.25rem,5vw,4rem); }
        h1 { font-size:clamp(2rem,5vw,3.6rem); line-height:1.08; }
        .hero img { border-radius:var(--mamona-radius); width:100%; }
        @media(max-width:736px){ main{margin:0;padding:1.15rem;} }
    </style>
</head>
<body class="qa-public-theme">
<main class="post-page-body">
    <h1>Semantyczny kompozytor artykułu — <?php echo $length; ?> znaków</h1>
    <p>Scenariusz: <?php echo htmlspecialchars($scenario, ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="hero"><?php echo $image(0, 'full'); ?></div>
    <section class="article-section article-section--importance"><p>Dlaczego to ważne: wewnętrzny akapit pozostaje częścią jednej kolorowej powierzchni.</p></section>
    <section class="article-section article-section--facts">
        <div class="article-section article-section--fact"><p>Fakt kontrolny z akapitem bez osobnego białego tła.</p></div>
        <div class="article-section article-section--context"><p>Kontekst badania przedstawiony spokojnym, niebieskim tonem.</p></div>
        <div class="article-section article-section--unknowns"><p class="article-section--unknown">Niepewność i ograniczenia danych.</p></div>
    </section>
    <?php foreach ($chunks as $index => $chunk): ?>
        <section>
            <h2>Sekcja <?php echo $index + 1; ?></h2>
            <p><?php echo htmlspecialchars($chunk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
            <?php
            $show = $scenario !== 'no-inline' && !($scenario === 'missing-one' && $index === 1);
            if ($show) {
                echo $image($index + 1, ['full', 'left', 'right', 'breakout'][$index % 4]);
                if ($index === 0) {
                    echo render_ad_slot('article-inline', 1, true, $adConfig);
                }
            }
            ?>
        </section>
    <?php endforeach; ?>
    <section class="article-section article-section--takeaway"><p>Najważniejszy wniosek pozostaje czytelny na ciemnej powierzchni.</p></section>
</main>
</body>
</html>
