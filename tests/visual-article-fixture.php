<?php

declare(strict_types=1);

$length = max(2000, min(4000, (int) ($_GET['length'] ?? 2000)));
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

    return '<figure class="article-illustration article-illustration--' . $layout . '">'
        . '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt="Kontrolowana ilustracja fragmentu '
        . ($index + 1) . '" width="1280" height="720" loading="lazy">'
        . '<figcaption>Ilustracja fragmentu ' . ($index + 1)
        . '<small>Autor fixture · <a href="#" rel="license">CC BY 4.0</a></small></figcaption></figure>';
};
?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QA layoutu artykułu</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        body { background:#eef2f5; color:#26313a; font:18px/1.72 system-ui,sans-serif; margin:0; }
        main { background:#fff; box-sizing:border-box; margin:2rem auto; max-width:920px; min-height:100vh; padding:clamp(1.25rem,5vw,4rem); }
        h1 { font-size:clamp(2rem,5vw,3.6rem); line-height:1.08; }
        .hero img { border-radius:10px; width:100%; }
        @media(max-width:736px){ main{margin:0;padding:1.15rem;} body{font-size:17px;} }
    </style>
</head>
<body>
<main>
    <h1>Semantyczny kompozytor artykułu — <?php echo $length; ?> znaków</h1>
    <p>Scenariusz: <?php echo htmlspecialchars($scenario, ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="hero"><?php echo $image(0, 'full'); ?></div>
    <?php foreach ($chunks as $index => $chunk): ?>
        <section>
            <h2>Sekcja <?php echo $index + 1; ?></h2>
            <p><?php echo htmlspecialchars($chunk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
            <?php
            $show = $scenario !== 'no-inline' && !($scenario === 'missing-one' && $index === 1);
            if ($show) {
                echo $image($index + 1, ['full', 'left', 'right', 'breakout'][$index % 4]);
            }
            ?>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>

