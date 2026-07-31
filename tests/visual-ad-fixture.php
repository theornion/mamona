<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/app-config.php';
require_once dirname(__DIR__) . '/php/article-image-service.php';
require_once dirname(__DIR__) . '/php/advertising.php';

$view = in_array((string) ($_GET['view'] ?? ''), ['home', 'category', 'article'], true)
    ? (string) $_GET['view']
    : 'article';
$preview = ($_GET['preview'] ?? '1') !== '0';
$config = advertising_config([
    'enabled' => true,
    'preview' => $preview,
    'allowed_placements' => ['page-top', 'feed-inline', 'article-inline', 'post-article'],
    'max_slots_per_page' => 5,
    'max_inline_slots' => 3,
    'minimum_blocks_between_slots' => 2,
]);

$articleBlocks = [];
for ($index = 1; $index <= 8; $index++) {
    $articleBlocks[] = [
        'type' => 'section',
        'id' => 'fixture-' . $index,
        'variant' => in_array($index, [2, 7], true) ? 'importance' : 'default',
        'blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Sekcja ' . $index],
            [
                'type' => 'paragraph',
                'text' => str_repeat(
                    'To kontrolowany fragment artykułu pokazujący naturalne granice treści, czytelny rytm i bezpieczne odstępy. ',
                    5
                ),
            ],
        ],
    ];
}

function fixture_feed(array $config, string $heading): string
{
    $cards = '';
    for ($index = 1; $index <= 5; $index++) {
        $cards .= '<article class="fixture-card"><p class="fixture-kicker">Nauka</p><h2>Przykładowy materiał '
            . $index . '</h2><p>Krótki opis wiadomości w publicznym feedzie Mamona.</p></article>';
        if ($index === 3) {
            $cards .= render_ad_slot('feed-inline', 1, true, $config);
        }
    }

    return render_ad_slot('page-top', 1, false, $config)
        . '<section class="fixture-feed"><header><p class="fixture-kicker">Najnowsze</p><h1>'
        . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8')
        . '</h1><p>Neutralny podgląd rozmieszczenia bez sieci reklamowej.</p></header>'
        . '<div class="fixture-grid">' . $cards . '</div></section>';
}

$content = '';
if ($view === 'article') {
    $content = render_ad_slot('page-top', 1, false, $config)
        . '<article class="fixture-article"><header><p class="fixture-kicker">Technologia</p>'
        . '<h1>Jak przygotować spokojny rytm artykułu pod przyszłe reklamy</h1>'
        . '<p>Sloty pojawiają się tylko pomiędzy kompletnymi sekcjami.</p></header>'
        . '<div class="post-page-body">' . render_article_blocks_with_advertising($articleBlocks, [], $config)
        . '</div></article>' . render_ad_slot('post-article', 1, true, $config);
} else {
    $content = fixture_feed($config, $view === 'home' ? 'Aktualności' : 'Kategoria: Technologia');
}
?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QA slotów reklamowych — <?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="../assets/css/advertising.css">
    <style>
        :root {
            --theme-surface:#fff;
            --theme-surface-alt:#f2f5f6;
            --theme-text:#26313a;
            --theme-heading:#172129;
            --theme-muted:#66737f;
            --theme-accent:#6c5b7b;
            --theme-font-body:system-ui,sans-serif;
        }
        * { box-sizing:border-box; }
        body { margin:0; background:#e9eef0; color:var(--theme-text); font:17px/1.68 system-ui,sans-serif; }
        .fixture-shell { width:min(100% - 2rem,1120px); margin:1rem auto 4rem; background:#fff; }
        .fixture-site-header { padding:1.25rem clamp(1rem,4vw,2.5rem); border-bottom:1px solid #d8e0e3; font-weight:800; }
        .fixture-feed,.fixture-article { padding:clamp(1.25rem,5vw,4rem); }
        .fixture-feed > header,.fixture-article > header { max-width:760px; margin:0 auto 2.5rem; text-align:center; }
        h1 { margin:.25rem 0 1rem; font-size:clamp(2rem,5vw,4rem); line-height:1.08; }
        h2 { line-height:1.2; }
        .fixture-kicker { color:var(--theme-accent); font-size:.75rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
        .fixture-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1.25rem; }
        .fixture-card { padding:1.5rem; border:1px solid #d8e0e3; border-radius:.5rem; background:#f8fafb; }
        .fixture-grid > .ad-slot { grid-column:1/-1; }
        .post-page-body { max-width:760px; margin:auto; }
        .article-section { margin:0 0 2rem; }
        .article-section--importance { padding:1.4rem; border-left:.3rem solid var(--theme-accent); background:#f6f1f5; }
        @media(max-width:736px) {
            .fixture-shell { width:100%; margin:0; }
            .fixture-grid { grid-template-columns:1fr; }
            .fixture-feed,.fixture-article { padding:1.1rem; }
        }
    </style>
</head>
<body>
    <div class="fixture-shell">
        <header class="fixture-site-header">Mamona · podgląd techniczny</header>
        <main><?php echo $content; ?></main>
    </div>
</body>
</html>
