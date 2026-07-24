<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $galleries = [];

    foreach (list_galleries() as $gallery) {
        $items = list_gallery_items((int) $gallery['id']);
        $galleries[] = [
            'title' => $gallery['title'],
            'description' => $gallery['description'] !== '' ? $gallery['description'] : 'Galeria jest gotowa do uzupełnienia.',
            'url' => rawurlencode((string) $gallery['slug']) . '.html',
            'image' => isset($items[0]['image_path']) ? '../' . ltrim((string) $items[0]['image_path'], '/') : '',
        ];
    }

    echo json_encode(['galleries' => $galleries], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['galleries' => []]);
}
