<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function rendering_performance_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rendering_performance_read(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('Nie można odczytać: ' . $path);
    return $contents;
}

$mode = rendering_performance_read($root . '/assets/js/performance-mode.js');
$parallax = rendering_performance_read($root . '/assets/js/parallax.js');
$snap = rendering_performance_read($root . '/assets/js/snap.js');
$heading = rendering_performance_read($root . '/assets/js/heading-scroll.js');
$scrollbar = rendering_performance_read($root . '/assets/js/main-scrollbar.js');
$adminPanel = rendering_performance_read($root . '/assets/js/admin-panel.js');
$mainCss = rendering_performance_read($root . '/assets/css/main.css');
$publicCss = rendering_performance_read($root . '/assets/css/public-theme.css');
$adminUi = rendering_performance_read($root . '/php/admin-ui.php');
$imageService = rendering_performance_read($root . '/php/article-image-service.php');

rendering_performance_assert(str_contains($mode, "'mamona-effects-mode'"), 'Tryb efektów nie zapisuje preferencji lokalnie.');
rendering_performance_assert(str_contains($mode, "'auto'"), 'Tryb efektów nie ma bezpiecznego wariantu automatycznego.');
rendering_performance_assert(str_contains($mode, 'prefers-reduced-motion: reduce'), 'Tryb efektów ignoruje reduced-motion.');
rendering_performance_assert(str_contains($mode, 'pointer: coarse'), 'Tryb efektów nie upraszcza renderingu dotykowego.');
rendering_performance_assert(str_contains($mode, 'hardwareConcurrency') && str_contains($mode, 'deviceMemory'), 'Automatyka nie łączy stabilnego i opcjonalnego sygnału urządzenia.');
rendering_performance_assert(str_contains($mode, 'dataEffectsToggle') || str_contains($mode, 'effectsToggle'), 'Brakuje użytkowego przełącznika efektów.');

foreach (array_merge([$root . '/index.html'], glob($root . '/pages/*.html') ?: []) as $page) {
    $html = rendering_performance_read($page);
    rendering_performance_assert(substr_count($html, 'assets/js/performance-mode.js') === 1, 'Brak jednej instancji trybu wydajności: ' . basename($page));
}

rendering_performance_assert(str_contains($parallax, "classList.contains('admin-page')"), 'Publiczna paralaksa nadal uruchamia się w adminie.');
rendering_performance_assert(str_contains($parallax, 'shouldReduceEffects()'), 'Paralaksa nie respektuje trybu lekkiego.');
rendering_performance_assert(str_contains($parallax, 'if (document.hidden) return;'), 'Paralaksa wykonuje pracę w ukrytej karcie.');
rendering_performance_assert(str_contains($parallax, "addEventListener('scroll', requestParallaxUpdate, { passive: true })"), 'Listener scroll paralaksy nie jest passive.');
rendering_performance_assert(!str_contains($parallax, 'setInterval('), 'Paralaksa tworzy stałą pętlę czasową.');
rendering_performance_assert(substr_count($snap, 'MamonaPerformance.shouldReduceEffects()') >= 2, 'Obie części snap.js nie zatrzymują się w trybie lekkim.');
rendering_performance_assert(str_contains($scrollbar, 'MamonaPerformance.shouldReduceEffects()'), 'Niestandardowy scrollbar działa w trybie natywnym.');
rendering_performance_assert(str_contains($heading, 'IntersectionObserver'), 'Nagłówki nadal są mierzone przy każdym scrollu.');
rendering_performance_assert(!str_contains($heading, "addEventListener('scroll'"), 'Nagłówki nadal mają globalny handler scroll.');
rendering_performance_assert(!str_contains($adminPanel, "main.addEventListener('scroll'"), 'Admin nadal synchronizuje stałą nawigację w każdym evencie scroll.');

rendering_performance_assert(str_contains($mainCss, 'digital_rain.webp'), 'Admin nadal używa wielkiego PNG jako tła desktopowego.');
rendering_performance_assert(str_contains($mainCss, 'digital_rain-mobile.webp'), 'Brakuje lekkiego tła admina.');
rendering_performance_assert(str_contains($mainCss, 'background-attachment:scroll'), 'Tryb lekki admina zachowuje fixed background.');
rendering_performance_assert(str_contains($mainCss, 'will-change:auto !important'), 'Tryb lekki zachowuje promowaną warstwę tła.');
rendering_performance_assert(str_contains($publicCss, 'content-visibility: auto'), 'Długie sekcje nie korzystają z odroczonego renderingu.');
rendering_performance_assert(str_contains($publicCss, 'backdrop-filter: none'), 'Tryb lekki nie usuwa blur dużego kontenera.');

rendering_performance_assert(str_contains($adminUi, 'performance-mode.js'), 'Panel admina nie ładuje trybu wydajności.');
rendering_performance_assert(!str_contains($adminUi, 'parallax.js'), 'Panel admina nadal ładuje publiczną paralaksę.');
rendering_performance_assert(str_contains($imageService, 'create_article_image_variants'), 'Pipeline obrazów nie tworzy wariantów.');
rendering_performance_assert(str_contains($imageService, ' srcset="') && str_contains($imageService, ' sizes="'), 'Renderer obrazów nie emituje srcset/sizes.');

$rainPng = filesize($root . '/images/digital_rain.png');
$rainWebp = filesize($root . '/images/digital_rain.webp');
$rainMobile = filesize($root . '/images/digital_rain-mobile.webp');
rendering_performance_assert(is_int($rainPng) && is_int($rainWebp) && $rainWebp < $rainPng * 0.1, 'Tło WebP admina nie jest znacząco lżejsze od PNG.');
rendering_performance_assert(is_int($rainMobile) && $rainMobile < 300000, 'Mobilne tło admina jest zbyt ciężkie.');

echo "RENDERING_PERFORMANCE_SMOKE_OK\n";

