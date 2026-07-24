<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $slug = trim((string) ($_GET['gallery'] ?? ''));
    $gallery = $slug !== '' ? find_gallery_by_slug($slug) : null;

    if ($gallery === null) {
        http_response_code(404);
        echo json_encode(['items' => []]);
        exit;
    }

    $items = array_map(static function (array $item): array {
        $desktopCropValue = trim((string) ($item['image_crop'] ?? ''));
        $mobileCropValue = trim((string) ($item['image_crop_mobile'] ?? ''));
        return [
            'id' => (int) $item['id'],
            'name' => $item['name'],
            'description' => $item['description'],
            'image' => '../' . ltrim((string) $item['image_path'], '/'),
            'crop' => $desktopCropValue !== '' ? normalize_post_crop($desktopCropValue) : null,
            'mobileCrop' => $mobileCropValue !== '' ? normalize_post_crop($mobileCropValue) : null,
        ];
    }, list_gallery_items((int) $gallery['id']));

    echo json_encode([
        'items' => $items,
        'mobileTwoUp' => (int) ($gallery['tile_view'] ?? 0) !== 1 && (int) ($gallery['mobile_two_up'] ?? 0) === 1,
        'tileView' => (int) ($gallery['tile_view'] ?? 0) === 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['items' => []]);
}
