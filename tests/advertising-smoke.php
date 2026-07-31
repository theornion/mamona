<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/app-config.php';
require_once dirname(__DIR__) . '/php/article-image-service.php';
require_once dirname(__DIR__) . '/php/advertising.php';

function advertising_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function advertising_fixture_blocks(int $characters, int $blockCount): array
{
    $blocks = [];
    $baseLength = intdiv($characters, $blockCount);
    $remaining = $characters;
    for ($index = 0; $index < $blockCount; $index++) {
        $length = $index === $blockCount - 1 ? $remaining : $baseLength;
        $remaining -= $length;
        $blocks[] = [
            'type' => 'section',
            'id' => 'section-' . ($index + 1),
            'variant' => $index === 1 ? 'importance' : 'default',
            'blocks' => [[
                'type' => 'paragraph',
                'text' => str_repeat(chr(97 + ($index % 20)), $length),
            ]],
        ];
    }

    return $blocks;
}

$enabledPreview = advertising_config([
    'enabled' => true,
    'preview' => true,
    'allowed_placements' => ['page-top', 'feed-inline', 'article-inline', 'post-article'],
    'max_slots_per_page' => 5,
    'max_inline_slots' => 3,
    'minimum_blocks_between_slots' => 2,
]);

$cases = [
    2000 => [4, 1],
    3000 => [6, 2],
    4000 => [8, 3],
];
foreach ($cases as $characters => [$blockCount, $expectedSlots]) {
    $blocks = advertising_fixture_blocks($characters, $blockCount);
    $boundaries = advertising_plan_article_boundaries($blocks, $enabledPreview);
    advertising_assert(
        count($boundaries) === $expectedSlots,
        "Nieprawidłowy limit inline dla {$characters} znaków."
    );
    foreach (array_slice($boundaries, 1) as $index => $boundary) {
        advertising_assert(
            $boundary - $boundaries[$index] >= 2,
            'Sloty nie zachowują minimalnego odstępu bloków.'
        );
    }
}

$unsafe = [
    ['type' => 'paragraph', 'text' => str_repeat('a', 900)],
    ['type' => 'heading', 'level' => 2, 'text' => 'Sekcja'],
    ['type' => 'paragraph', 'text' => str_repeat('b', 900)],
    ['type' => 'list', 'items' => [str_repeat('c', 500), str_repeat('d', 500)]],
    ['type' => 'quote', 'text' => str_repeat('e', 500)],
];
$unsafeBoundaries = advertising_plan_article_boundaries($unsafe, $enabledPreview);
advertising_assert(!in_array(1, $unsafeBoundaries, true), 'Slot rozdziela nagłówek i pierwszy akapit.');

$complexBlocks = [
    [
        'type' => 'section',
        'id' => 'callout',
        'variant' => 'importance',
        'blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Ważne'],
            ['type' => 'list', 'items' => [str_repeat('a', 600), str_repeat('b', 600)]],
        ],
    ],
    ['type' => 'illustration', 'image_id' => 7],
    ['type' => 'paragraph', 'text' => str_repeat('c', 900)],
    ['type' => 'paragraph', 'text' => str_repeat('d', 900)],
];
$image = [
    'id' => 7,
    'status' => 'downloaded',
    'local_path' => 'images/posts/2c48fdab2b5b333c482d6a940cf767ca.jpg',
    'layout' => 'full',
    'alt' => 'Opis obrazu',
    'caption' => 'Podpis obrazu',
    'attribution' => 'Autor',
    'source_page_url' => 'https://example.test/source',
    'license' => 'CC BY 4.0',
    'license_url' => 'https://example.test/license',
    'width' => 1200,
    'height' => 675,
];
$complexHtml = render_article_blocks_with_advertising($complexBlocks, [$image], $enabledPreview);
advertising_assert(
    preg_match('#<section[^>]*id="callout"[^>]*>.*?</section>#s', $complexHtml, $callout) === 1
    && !str_contains($callout[0], 'ad-slot'),
    'Slot został wstawiony wewnątrz calloutu.'
);
advertising_assert(
    preg_match('#<figure[^>]*>.*?<figcaption>.*?</figcaption></figure>#s', $complexHtml, $figure) === 1
    && !str_contains($figure[0], 'ad-slot'),
    'Slot rozdziela obraz, podpis lub atrybucję.'
);
advertising_assert(
    preg_match('#<ul>.*?</ul>#s', $complexHtml, $list) === 1
    && !str_contains($list[0], 'ad-slot'),
    'Slot został wstawiony wewnątrz listy.'
);

$off = render_ad_slot('page-top', 1, false, array_replace($enabledPreview, ['enabled' => false]));
advertising_assert($off === '', 'Feature flag off pozostawia slot lub pustą przestrzeń.');

$preview = render_ad_slot('article-inline', 2, true, $enabledPreview);
advertising_assert(str_contains($preview, 'aria-label="Reklama"'), 'Slot nie ma dostępnej etykiety.');
advertising_assert(str_contains($preview, '>Reklama</span>'), 'Brak widocznego oznaczenia „Reklama”.');
advertising_assert(str_contains($preview, 'article-inline #2'), 'Placeholder nie opisuje placementu.');
advertising_assert(str_contains($preview, '300×250 / 468×60 / 728×90'), 'Placeholder nie opisuje wymiarów.');
advertising_assert(!str_contains($preview, 'tabindex='), 'Nieinteraktywny slot nie powinien trafiać do kolejności klawiatury.');

$limited = array_replace($enabledPreview, ['max_inline_slots' => 1, 'max_slots_per_page' => 3]);
advertising_assert(
    count(advertising_plan_article_boundaries(advertising_fixture_blocks(4000, 8), $limited)) === 1,
    'Konfiguracja maksymalnej liczby slotów nie jest respektowana.'
);

final class AdvertisingTestProvider implements AdProviderAdapter
{
    public int $calls = 0;

    public function name(): string
    {
        return 'fixture';
    }

    public function render(array $slot, string $consentState): string
    {
        $this->calls++;

        return '<div data-provider-fixture></div>';
    }
}

$provider = new AdvertisingTestProvider();
$productionConfig = array_replace($enabledPreview, ['preview' => false, 'consent_state' => 'unknown']);
advertising_assert(
    render_ad_slot('page-top', 1, false, $productionConfig, $provider) === '' && $provider->calls === 0,
    'Dostawca został aktywowany przed decyzją CMP.'
);
advertising_assert(
    render_ad_slot(
        'page-top',
        1,
        false,
        array_replace($productionConfig, ['consent_state' => 'denied']),
        $provider
    ) === '' && $provider->calls === 0,
    'Dostawca został aktywowany po odmowie zgody.'
);
advertising_assert(
    render_ad_slot(
        'page-top',
        1,
        false,
        array_replace($productionConfig, ['consent_state' => 'non-personalized']),
        $provider
    ) !== '' && $provider->calls === 1,
    'Adapter nie otrzymuje jawnego stanu zgody.'
);

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/advertising.css');
advertising_assert(
    str_contains($css, 'aspect-ratio:') && str_contains($css, 'min-height:'),
    'Style slotów nie rezerwują kontrolowanej przestrzeni dla redukcji CLS.'
);
advertising_assert(
    !str_contains($preview, '<script') && !preg_match('#https?://#i', $preview),
    'Neutralny placeholder zawiera skrypt lub zewnętrzny request.'
);

echo "ADVERTISING_SMOKE_OK\n";
