<?php

declare(strict_types=1);

/**
 * Neutral advertising layout layer.
 *
 * This module deliberately contains no network SDK, publisher identifier or
 * provider-specific markup. A future adapter may implement AdProviderAdapter,
 * but activation remains gated by an explicit consent decision.
 */
interface AdProviderAdapter
{
    public function name(): string;

    public function render(array $slot, string $consentState): string;
}

final class NullAdProviderAdapter implements AdProviderAdapter
{
    public function name(): string
    {
        return 'none';
    }

    public function render(array $slot, string $consentState): string
    {
        return '';
    }
}

function advertising_environment_bool(string $name, bool $default): bool
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    return is_bool($normalized) ? $normalized : $default;
}

function advertising_environment_int(string $name, int $default, int $minimum, int $maximum): int
{
    $value = getenv($name);
    if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
        return $default;
    }

    return max($minimum, min($maximum, (int) $value));
}

function advertising_environment_list(string $name, array $default): array
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    $items = array_map(
        static fn (string $item): string => strtolower(trim($item)),
        explode(',', $value)
    );

    return array_values(array_unique(array_filter(
        $items,
        static fn (string $item): bool => preg_match('/^[a-z][a-z0-9-]{2,40}$/', $item) === 1
    )));
}

function advertising_slot_catalog(): array
{
    return [
        'page-top' => [
            'format' => 'leaderboard',
            'sizes' => ['mobile' => [320, 100], 'tablet' => [728, 90], 'desktop' => [970, 90]],
        ],
        'feed-inline' => [
            'format' => 'in-feed',
            'sizes' => ['mobile' => [320, 100], 'tablet' => [468, 60], 'desktop' => [728, 90]],
        ],
        'article-inline' => [
            'format' => 'in-article',
            'sizes' => ['mobile' => [300, 250], 'tablet' => [468, 60], 'desktop' => [728, 90]],
        ],
        'post-article' => [
            'format' => 'post-article',
            'sizes' => ['mobile' => [320, 100], 'tablet' => [468, 60], 'desktop' => [728, 90]],
        ],
        'sidebar' => [
            'format' => 'sidebar',
            'sizes' => ['mobile' => [0, 0], 'tablet' => [0, 0], 'desktop' => [300, 600]],
        ],
    ];
}

function advertising_config(array $overrides = []): array
{
    $environment = strtolower((string) (getenv('CMS_ENV') ?: 'development'));
    $developmentPreview = $environment !== 'production';
    $config = [
        // Local/development builds show layout placeholders by default.
        // Production remains opt-in and provider activation still requires CMP.
        'enabled' => advertising_environment_bool('CMS_ADS_ENABLED', $developmentPreview),
        'preview' => $developmentPreview && advertising_environment_bool('CMS_ADS_PREVIEW', true),
        'allowed_placements' => advertising_environment_list(
            'CMS_ADS_ALLOWED_PLACEMENTS',
            ['page-top', 'feed-inline', 'article-inline', 'post-article']
        ),
        'max_slots_per_page' => advertising_environment_int('CMS_ADS_MAX_SLOTS_PER_PAGE', 5, 0, 8),
        'max_inline_slots' => advertising_environment_int('CMS_ADS_MAX_INLINE_SLOTS', 3, 0, 3),
        'minimum_blocks_between_slots' => advertising_environment_int(
            'CMS_ADS_MIN_BLOCK_GAP',
            2,
            1,
            6
        ),
        'consent_state' => 'unknown',
    ];

    return array_replace($config, $overrides);
}

function advertising_slot_definition(string $placement, int $instance = 1): array
{
    $catalog = advertising_slot_catalog();
    if (!isset($catalog[$placement])) {
        throw new InvalidArgumentException('Nieznany placement reklamowy: ' . $placement);
    }

    return array_merge($catalog[$placement], [
        'placement' => $placement,
        'instance' => max(1, $instance),
    ]);
}

function advertising_size_label(array $sizes): string
{
    return implode(' / ', array_map(
        static fn (array $size): string => $size[0] > 0 ? $size[0] . '×' . $size[1] : 'ukryty',
        $sizes
    ));
}

function render_ad_slot(
    string $placement,
    int $instance = 1,
    bool $belowFold = true,
    ?array $config = null,
    ?AdProviderAdapter $provider = null
): string {
    $config ??= advertising_config();
    if (empty($config['enabled'])
        || !in_array($placement, (array) ($config['allowed_placements'] ?? []), true)
        || (int) ($config['max_slots_per_page'] ?? 0) < 1) {
        return '';
    }

    $slot = advertising_slot_definition($placement, $instance);
    $provider ??= new NullAdProviderAdapter();
    $content = '';
    $state = 'empty';

    if (!empty($config['preview'])) {
        $state = 'preview';
        $content = '<span class="ad-slot__preview-title">' . htmlspecialchars(
            $placement . ($instance > 1 ? ' #' . $instance : ''),
            ENT_QUOTES,
            'UTF-8'
        ) . '</span><span class="ad-slot__preview-meta">'
            . htmlspecialchars($slot['format'] . ' · ' . advertising_size_label($slot['sizes']), ENT_QUOTES, 'UTF-8')
            . '</span>';
    } elseif (in_array(
        (string) ($config['consent_state'] ?? 'unknown'),
        ['non-personalized', 'personalized'],
        true
    )) {
        $content = trim($provider->render($slot, (string) $config['consent_state']));
        $state = $content === '' ? 'empty' : 'ready';
    }

    if ($content === '') {
        return '';
    }

    $sizes = $slot['sizes'];
    $style = sprintf(
        '--ad-mobile-w:%d;--ad-mobile-h:%d;--ad-tablet-w:%d;--ad-tablet-h:%d;--ad-desktop-w:%d;--ad-desktop-h:%d',
        $sizes['mobile'][0],
        $sizes['mobile'][1],
        $sizes['tablet'][0],
        $sizes['tablet'][1],
        $sizes['desktop'][0],
        $sizes['desktop'][1]
    );

    return '<aside class="ad-slot ad-slot--' . htmlspecialchars($slot['format'], ENT_QUOTES, 'UTF-8')
        . '" aria-label="Reklama" data-ad-placement="' . htmlspecialchars($placement, ENT_QUOTES, 'UTF-8')
        . '" data-ad-instance="' . $instance . '" data-ad-state="' . $state . '"'
        . ($belowFold ? ' data-ad-lazy="true"' : '')
        . ' style="' . $style . '"><span class="ad-slot__label">Reklama</span>'
        . '<div class="ad-slot__surface">' . $content . '</div></aside>';
}

function advertising_block_text_length(array $block): int
{
    $length = mb_strlen(trim(strip_tags((string) ($block['text'] ?? ''))));
    foreach ((array) ($block['items'] ?? []) as $item) {
        $length += mb_strlen(trim(strip_tags((string) $item)));
    }
    foreach ((array) ($block['blocks'] ?? []) as $child) {
        if (is_array($child)) {
            $length += advertising_block_text_length($child);
        }
    }

    return $length;
}

function advertising_article_character_count(array $blocks): int
{
    return array_sum(array_map(
        static fn (array $block): int => advertising_block_text_length($block),
        array_values(array_filter($blocks, 'is_array'))
    ));
}

function advertising_article_inline_limit(int $characterCount, ?array $config = null): int
{
    $config ??= advertising_config();
    $lengthLimit = match (true) {
        $characterCount < 1800 => 0,
        $characterCount < 2600 => 1,
        $characterCount < 3700 => 2,
        default => 3,
    };

    return min(
        $lengthLimit,
        (int) ($config['max_inline_slots'] ?? 3)
    );
}

function advertising_boundary_is_safe(array $left, array $right): bool
{
    $leftType = (string) ($left['type'] ?? '');
    $rightType = (string) ($right['type'] ?? '');

    if ($leftType === 'heading' && $rightType === 'paragraph') {
        return false;
    }

    return !in_array($leftType, ['heading'], true);
}

/**
 * Returns zero-based block indexes after which an inline slot may be rendered.
 */
function advertising_plan_article_boundaries(array $blocks, ?array $config = null): array
{
    $config ??= advertising_config();
    $blocks = array_values(array_filter($blocks, 'is_array'));
    $total = advertising_article_character_count($blocks);
    $limit = advertising_article_inline_limit($total, $config);
    if ($limit === 0 || count($blocks) < 3) {
        return [];
    }

    $candidates = [];
    $running = 0;
    for ($index = 0, $last = count($blocks) - 1; $index < $last; $index++) {
        $running += advertising_block_text_length($blocks[$index]);
        if (advertising_boundary_is_safe($blocks[$index], $blocks[$index + 1])) {
            $candidates[$index] = $running;
        }
    }
    if ($candidates === []) {
        return [];
    }

    $selected = [];
    $minimumGap = max(1, (int) ($config['minimum_blocks_between_slots'] ?? 2));
    for ($slot = 1; $slot <= $limit; $slot++) {
        $target = (int) round($total * $slot / ($limit + 1));
        $bestIndex = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $index => $charactersBefore) {
            if ($selected !== [] && $index - end($selected) < $minimumGap) {
                continue;
            }
            $distance = abs($charactersBefore - $target);
            if ($distance < $bestDistance) {
                $bestIndex = $index;
                $bestDistance = $distance;
            }
        }
        if ($bestIndex !== null) {
            $selected[] = $bestIndex;
        }
    }

    sort($selected);

    return $selected;
}

function render_article_blocks_with_advertising(
    array $blocks,
    array $images,
    ?array $config = null,
    ?AdProviderAdapter $provider = null
): string {
    $config ??= advertising_config();
    $boundaries = advertising_plan_article_boundaries($blocks, $config);
    $html = '';
    $slot = 0;

    foreach (array_values($blocks) as $index => $block) {
        $html .= render_article_blocks([$block], $images);
        if (in_array($index, $boundaries, true)) {
            $slot++;
            $html .= render_ad_slot('article-inline', $slot, true, $config, $provider);
        }
    }

    return $html;
}
