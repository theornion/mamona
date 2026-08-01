<?php

declare(strict_types=1);

function public_theme_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$css = file_get_contents(dirname(__DIR__) . '/assets/css/public-theme.css');
public_theme_assert(is_string($css) && $css !== '', 'Nie można odczytać publicznego motywu.');
public_theme_assert(str_contains($css, 'body:not(.admin-page)'), 'Motyw nie jest odseparowany od panelu admina.');
public_theme_assert(
    !preg_match('/#main\s*>\s*\.post\.featured\s+p[^{]*\{[^}]*background\s*:\s*var\(--(?:theme|mamona)-surface\)/s', $css),
    'Ogólna reguła akapitów nadal nadaje im nieprzezroczyste tło.'
);

foreach (['importance', 'fact', 'context', 'unknowns', 'takeaway'] as $variant) {
    public_theme_assert(str_contains($css, '.article-section--' . $variant), 'Brak calloutu: ' . $variant);
}

public_theme_assert(
    preg_match('/article-section--takeaway[\s\S]*?>\s*:where\(p,\s*ul,\s*ol,\s*h2,\s*h3,\s*h4,\s*strong,\s*em\)\s*\{[^}]*background\s*:\s*transparent\s*!important/s', $css) === 1,
    'Bezpośrednia zawartość calloutów nie ma przezroczystego tła.'
);
public_theme_assert(str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'Brak reduced motion.');
public_theme_assert(str_contains($css, ':focus-visible'), 'Brak focus-visible.');
public_theme_assert(
    str_contains($css, 'nasa-pillars-of-creation.webp'),
    'Publiczny motyw nie używa lokalnego tła NASA.'
);
public_theme_assert(
    preg_match('/#main\s*\{[\s\S]*?rgba\(4,\s*12,\s*26,\s*0\.56\)/', $css) === 1,
    'Główny kontener nie ma ciemnej, półprzezroczystej powierzchni.'
);
public_theme_assert(str_contains($css, 'backdrop-filter: blur(1px);'), 'Rozmycie głównego kontenera jest inne niż 1 px.');
public_theme_assert(
    preg_match('/#nav\s*\{[\s\S]*?position:\s*sticky\s*!important;[\s\S]*?top:\s*0\s*!important;/', $css) === 1,
    'Nawigacja nie jest przyklejona do górnej krawędzi treści.'
);
public_theme_assert(
    preg_match('/#nav\s*\{[\s\S]*?transform:\s*none\s*!important;/', $css) === 1,
    'Nawigacja nadal może dziedziczyć przesunięcie starego szablonu.'
);
public_theme_assert(
    str_contains($css, '--mamona-nav-height: calc(var(--theme-nav-height-public, 4rem) * 0.6);')
        && str_contains($css, 'margin-bottom: calc(-1 * var(--mamona-nav-height)) !important;'),
    'Nawigacja nie nachodzi na górną krawędź głównego kontenera.'
);
public_theme_assert(
    preg_match('/\.news-feed-card\s+\.news-feed-content\s*\{[^}]*background:\s*transparent\s*!important;/s', $css) === 1,
    'Treść karty aktualności nadal może zasłaniać ciemną powierzchnię.'
);
public_theme_assert(
    preg_match('/figcaption\s*\{[^}]*background:\s*transparent\s*!important;[^}]*text-align:\s*center\s*!important;/s', $css) === 1,
    'Podpis ilustracji nie jest wycentrowany na przezroczystym tle.'
);
public_theme_assert(
    preg_match('/body:not\(\.admin-page\)\s+\.post-ai-disclosure\s*\{[^}]*border:\s*0;[^}]*background:\s*transparent;[^}]*font-size:\s*0\.82rem;[^}]*text-align:\s*center;/s', $css) === 1,
    'Informacja o wsparciu AI nadal ma box albo nie jest dyskretnym, wycentrowanym tekstem.'
);
public_theme_assert(
    !str_contains($css, ':where(.post-sources, .post-related, .post-ai-disclosure)'),
    'Informacja o wsparciu AI nadal dziedziczy wygląd paneli źródeł i materiałów powiązanych.'
);
$renderer = (string) file_get_contents(dirname(__DIR__) . '/php/admin-database.php');
public_theme_assert(
    strpos($renderer, '{$relatedHtml}') < strpos($renderer, '{$aiHtml}')
        && strpos($renderer, '{$aiHtml}') < strpos($renderer, '<ul class="actions special">'),
    'Informacja o wsparciu AI nie znajduje się bezpośrednio nad powrotem do aktualności.'
);

$parallax = (string) file_get_contents(dirname(__DIR__) . '/assets/js/parallax.js');
public_theme_assert(
    str_contains($parallax, 'images/nasa-pillars-of-creation.webp?v=20260728-fit')
        && str_contains($parallax, 'images/nasa-pillars-of-creation-mobile.webp?v=20260728-fit'),
    'Paralaksa nadal ładuje stare tło.'
);
public_theme_assert(
    str_contains($parallax, 'const bgWidth = 1920;') && str_contains($parallax, 'const bgHeight = 3326;'),
    'Paralaksa nie używa wymiarów tła HD.'
);
public_theme_assert(
    str_contains($parallax, "bg.className = 'bg fixed'"),
    'Paralaksa nie potrafi odtworzyć brakującej warstwy tła.'
);
$snap = (string) file_get_contents(dirname(__DIR__) . '/assets/js/snap.js');
public_theme_assert(
    str_contains($snap, "nav.style.removeProperty('left')"),
    'Snap nadal ustawia poziome przesunięcie nawigacji w stylu inline.'
);

foreach ([dirname(__DIR__) . '/index.html', dirname(__DIR__) . '/pages/index.html'] as $templatePath) {
    $template = (string) file_get_contents($templatePath);
    public_theme_assert(
        str_contains($template, 'assets/js/snap.js?v=cms-core-20260727-layout2'),
        'Publiczny szablon nie ładuje odroczonego snap.js: ' . basename($templatePath)
    );
}

$backgroundInfo = getimagesize(dirname(__DIR__) . '/images/nasa-pillars-of-creation.webp');
$mobileBackgroundInfo = getimagesize(dirname(__DIR__) . '/images/nasa-pillars-of-creation-mobile.webp');
public_theme_assert(
    is_array($backgroundInfo) && $backgroundInfo[0] === 1920 && $backgroundInfo[1] === 3326,
    'Desktopowe tło nie ma rozdzielczości 1920 × 3326.'
);
public_theme_assert(
    is_array($mobileBackgroundInfo) && $mobileBackgroundInfo[0] === 960 && $mobileBackgroundInfo[1] === 1663,
    'Mobilne tło nie ma rozdzielczości 960 × 1663.'
);
public_theme_assert(str_contains($css, '--mamona-reading: 58rem;'), 'Kolumna tekstowa artykułu nie została poszerzona.');
public_theme_assert(
    str_contains($css, 'width: min(73.6vw, 62.4rem) !important;'),
    'Główna ilustracja nie zajmuje 80% szerokości artykułu.'
);
public_theme_assert(
    preg_match('/article-illustration--left,\s*\.article-illustration--right\)\s*\{[^}]*width:\s*38%;/s', $css) === 1,
    'Boczne ilustracje nie zostawiają wystarczającej szerokości dla tekstu.'
);

public_theme_assert(
    preg_match('/article-illustration--full:not\(\.article-illustration--hero\)\s*\{[^}]*width:\s*80%;[^}]*max-width:\s*52rem;[^}]*margin:\s*2\.25rem auto;/s', $css) === 1,
    'Standardowa ilustracja inline nie ma 80% szerokosci i automatycznego centrowania.'
);
public_theme_assert(
    preg_match('/article-mini-gallery\s+\.article-illustration--full\s*\{[^}]*width:\s*100%;[^}]*margin:\s*0;/s', $css) === 1,
    'Regula standardowej ilustracji narusza uklad mini-galerii.'
);
public_theme_assert(
    preg_match('/@media screen and \(max-width:\s*980px\)[\s\S]*?article-illustration--full:not\(\.article-illustration--hero\)\s*\{[^}]*width:\s*100%;/s', $css) === 1,
    'Standardowa ilustracja inline nie przechodzi do pelnej szerokosci na waskim ekranie.'
);

$contactPage = (string) file_get_contents(dirname(__DIR__) . '/pages/kontakt.html');
public_theme_assert(str_contains($contactPage, 'class="trust-contact-form"'), 'Formularz nie znajduje się w main strony Kontakt.');
public_theme_assert(str_contains($contactPage, 'assets/js/contact-form.js'), 'Strona Kontakt nie ładuje obsługi formularza.');
foreach (array_merge([dirname(__DIR__) . '/index.html'], glob(dirname(__DIR__) . '/pages/*.html') ?: []) as $publicPagePath) {
    $publicPage = (string) file_get_contents($publicPagePath);
    if (basename($publicPagePath) === 'kontakt.html') {
        continue;
    }
    public_theme_assert(!str_contains($publicPage, 'id="contactForm"'), 'Formularz pozostał poza stroną Kontakt: ' . basename($publicPagePath));
    public_theme_assert(!str_contains($publicPage, 'assets/js/contact-form.js'), 'Zbędny skrypt formularza pozostał na stronie: ' . basename($publicPagePath));
}

echo "PUBLIC_THEME_SMOKE_OK\n";
