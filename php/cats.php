<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $cats = array_map(static function (array $cat): array {
        return [
            'id' => (int) $cat['id'],
            'name' => $cat['name'],
            'description' => $cat['description'],
            'image' => '../' . ltrim((string) $cat['image_path'], '/'),
        ];
    }, list_cats());

    echo json_encode(['cats' => $cats], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['cats' => []]);
}
