<?php

declare(strict_types=1);

const ADVERTISING_MIN_ARTICLE_SLOTS = 2;
const ADVERTISING_MAX_ARTICLE_SLOTS = 6;

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
    $configuredSlotOffset = 0;
    if (function_exists('get_advertising_settings')) {
        try {
            $settings = get_advertising_settings();
            $configuredSlotOffset = (int) ($settings['ad_slot_offset'] ?? $configuredSlotOffset);
        } catch (Throwable) {
            // Database settings are optional until the application schema is initialized.
        }
    }
    $config = [
        // Local/development builds show layout placeholders by default.
        // Production remains opt-in and provider activation still requires CMP.
        'enabled' => advertising_environment_bool('CMS_ADS_ENABLED', $developmentPreview),
        'preview' => $developmentPreview && advertising_environment_bool('CMS_ADS_PREVIEW', true),
        'allowed_placements' => advertising_environment_list(
            'CMS_ADS_ALLOWED_PLACEMENTS',
            ['page-top', 'feed-inline', 'article-inline', 'post-article']
        ),
        // Article-specific capacity is derived later from the canonical VisualPlan.
        'max_slots_per_page' => 6,
        'max_inline_slots' => 4,
        'ad_slot_offset' => max(-2, min(2, $configuredSlotOffset)),
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
        $characterCount < 5200 => 3,
        default => 4,
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

    $minimumGap = max(1, (int) ($config['minimum_blocks_between_slots'] ?? 2));
    $candidateIndexes = array_keys($candidates);
    $states = array_fill(0, $limit + 1, []);
    foreach ($candidateIndexes as $candidatePosition => $candidateIndex) {
        $charactersBefore = $candidates[$candidateIndex];
        $target = (int) round($total / ($limit + 1));
        $states[1][$candidatePosition] = [
            'cost' => abs($charactersBefore - $target),
            'indexes' => [$candidateIndex],
        ];
        for ($count = 2; $count <= $limit; $count++) {
            $slotTarget = (int) round($total * $count / ($limit + 1));
            $best = null;
            foreach ($states[$count - 1] as $previousPosition => $state) {
                $previousIndex = $candidateIndexes[$previousPosition];
                if ($candidateIndex - $previousIndex < $minimumGap) continue;
                $next = [
                    'cost' => $state['cost'] + abs($charactersBefore - $slotTarget),
                    'indexes' => [...$state['indexes'], $candidateIndex],
                ];
                if ($best === null || $next['cost'] < $best['cost']) $best = $next;
            }
            if ($best !== null) $states[$count][$candidatePosition] = $best;
        }
    }

    for ($count = $limit; $count >= 1; $count--) {
        if ($states[$count] === []) continue;
        usort($states[$count], static fn (array $left, array $right): int => $left['cost'] <=> $right['cost']);
        return $states[$count][0]['indexes'];
    }

    return [];
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

/** Count canonical FinalVisualPlan coverage slots, never arbitrary HTML images. */
function advertising_article_visual_count(int $postId): int
{
    if ($postId < 1 || !function_exists('article_image_coverage_state')) {
        return 0;
    }

    $coverage = article_image_coverage_state($postId, null, false);

    return count((array) ($coverage['filled_slots'] ?? []));
}

/** Single source of truth for article ad capacity: clamp(W + global offset, 2, 6). */
function advertising_article_slot_count(int $postId, ?array $config = null): int
{
    $config ??= advertising_config();
    $visualCount = advertising_article_visual_count($postId);
    $offset = (int) ($config['ad_slot_offset'] ?? 0);

    return advertising_slot_count_from_visual_count($visualCount, $offset);
}

function advertising_slot_count_from_visual_count(int $visualCount, int $offset): int
{
    return max(
        ADVERTISING_MIN_ARTICLE_SLOTS,
        min(ADVERTISING_MAX_ARTICLE_SLOTS, $visualCount + $offset)
    );
}

/** Prepare one article-wide ad budget for public and private renderers. */
function advertising_article_render_config(
    int $postId,
    bool $preview = false,
    ?array $config = null
): array {
    $config = advertising_config($config ?? []);
    if ($preview && !empty($config['enabled'])) $config['preview'] = true;

    $targetAds = advertising_article_slot_count($postId, $config);
    $config['max_slots_per_page'] = $targetAds;
    $adBudget = $targetAds;
    $allowed = (array) ($config['allowed_placements'] ?? []);
    foreach (['page-top', 'post-article'] as $placement) {
        if ($adBudget > 0 && in_array($placement, $allowed, true)) $adBudget--;
    }
    $config['max_inline_slots'] = min(max(0, $targetAds), $adBudget);

    return $config;
}

function render_article_blocks_with_layout_and_advertising(
    array $blocks,
    array $images,
    array $layoutPlan,
    array $contextBlocks = [],
    ?array $config = null,
    ?AdProviderAdapter $provider = null,
    array &$layoutAudit = []
): string {
    $config ??= advertising_config();
    $boundaries = advertising_plan_article_boundaries($blocks, $config);
    $afterSectionHtml = [];
    $slot = 0;

    foreach (array_values($blocks) as $index => $block) {
        if (!in_array($index, $boundaries, true) || (string) ($block['type'] ?? '') !== 'section') {
            continue;
        }
        $section = (string) ($block['id'] ?? '');
        if ($section === '') {
            continue;
        }
        $slot++;
        $afterSectionHtml[$section] = ($afterSectionHtml[$section] ?? '')
            . render_ad_slot('article-inline', $slot, true, $config, $provider);
    }

    return render_article_blocks_with_layout(
        $blocks,
        $images,
        $layoutPlan,
        $contextBlocks,
        $layoutAudit,
        $afterSectionHtml
    );
}
