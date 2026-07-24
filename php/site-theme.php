<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-database.php';

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function theme_css_string(string $value): string
{
    return str_replace(["\\", '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
}

try {
    $settings = get_site_style_settings();
    $definitions = site_style_definitions();
    $fontStacks = [
        'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif',
        'serif' => 'Georgia, "Times New Roman", serif',
        'humanist' => '"Trebuchet MS", "Segoe UI", Arial, sans-serif',
        'display' => 'Georgia, "Times New Roman", serif',
        'mono' => '"Cascadia Code", Consolas, "Courier New", monospace',
    ];
    $shadows = [
        'none' => 'none',
        'subtle' => '0 10px 30px rgba(31, 41, 51, 0.08)',
        'medium' => '0 14px 40px rgba(31, 41, 51, 0.14)',
        'strong' => '0 18px 56px rgba(31, 41, 51, 0.22)',
    ];
    $backgroundImage = trim((string) $settings['background_image']);
    if ($backgroundImage !== '' && !str_starts_with($backgroundImage, '/') && !preg_match('#^https?://#i', $backgroundImage)) {
        $backgroundImage = '../' . ltrim($backgroundImage, '/');
    }
    $backgroundValue = $backgroundImage === '' ? 'none' : 'url("' . theme_css_string($backgroundImage) . '")';

    $css = ":root {\n";
    $variables = [
        'page-background' => $settings['page_background'],
        'surface' => $settings['surface'],
        'surface-alt' => $settings['surface_alt'],
        'text' => $settings['text_color'],
        'heading' => $settings['heading_color'],
        'muted' => $settings['muted_color'],
        'accent' => $settings['accent_color'],
        'accent-hover' => $settings['accent_hover'],
        'accent-contrast' => $settings['accent_contrast'],
        'border' => $settings['border_color'],
        'nav-background' => $settings['nav_background'],
        'nav-text' => $settings['nav_text'],
        'footer-background' => $settings['footer_background'],
        'footer-text' => $settings['footer_text'],
        'input-background' => $settings['input_background'],
        'hero-background' => $settings['hero_background'],
        'hero-text' => $settings['hero_text'],
        'font-body' => $fontStacks[$settings['body_font']] ?? $fontStacks['system'],
        'font-heading' => $fontStacks[$settings['heading_font']] ?? $fontStacks['system'],
        'base-font-size' => $settings['base_font_size'] . 'px',
        'line-height' => $settings['line_height'],
        'heading-weight' => $settings['heading_weight'],
        'content-width' => $settings['content_width'] . 'px',
        'section-spacing' => $settings['section_spacing'] . 'px',
        'radius' => $settings['border_radius'] . 'px',
        'nav-height-public' => $settings['nav_height'] . 'px',
        'hero-height' => $settings['hero_height'] . 'vh',
        'card-shadow' => $shadows[$settings['card_shadow']] ?? $shadows['subtle'],
        'background-image-public' => $backgroundValue,
        'background-position-public' => $settings['background_position'],
        'background-size-public' => $settings['background_size'],
        'transition-duration' => $settings['transition_duration'] . 'ms',
    ];
    foreach ($variables as $name => $value) {
        $css .= '  --theme-' . $name . ': ' . $value . ";\n";
    }
    $css .= "}\n";

    if ($settings['animations_enabled'] !== '1') {
        $css .= "body:not(.admin-page) *, body:not(.admin-page) *::before, body:not(.admin-page) *::after { animation: none !important; transition-duration: 0ms !important; scroll-behavior: auto !important; }\n";
    }

    $customCss = trim((string) $settings['custom_css']);
    if ($customCss !== '') {
        $css .= "\n/* Własny CSS zapisany w panelu */\n" . $customCss . "\n";
    }

    echo $css;
} catch (Throwable $exception) {
    error_log('Site theme endpoint: ' . $exception->getMessage());
    http_response_code(500);
    echo "/* Nie udało się wczytać ustawień motywu. */\n";
}
