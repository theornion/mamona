<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $settings = get_contact_settings();
    $presentation = get_site_style_settings();
    echo json_encode([
        'address' => $settings['address'],
        'phone' => $settings['phone'],
        'email' => $settings['email'],
        'site_name' => $presentation['site_name'],
        'site_tagline' => $presentation['site_tagline'],
        'copyright_text' => $presentation['copyright_text'],
        'social_media' => array_map(static function (array $social): array {
            return [
                'name' => $social['name'],
                'url' => $social['url'],
                'icon_path' => $social['icon_path'],
                'icon_class' => $social['icon_class'],
            ];
        }, list_social_media(true)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Contact settings endpoint: ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine());
    http_response_code(500);
    echo json_encode([]);
}
