<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_EDITOR_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_EDITOR_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_PUBLIC_URL=https://example.test');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function editor_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = bueno_database();
$token = bin2hex(random_bytes(5));
$categoryId = 0;
$postId = 0;

try {
    $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order)
         VALUES (:title, :description, :slug, 999999)'
    )->execute([
        ':title' => 'Editor ' . $token,
        ':description' => 'Test edytora.',
        ':slug' => 'editor-' . $token,
    ]);
    $categoryId = (int) $database->lastInsertId();
    $postId = create_post($categoryId, 'Test edytora ' . $token, 'Opis.', 'Treść.');

    $invalidDateBlocked = false;
    try {
        validate_post_editorial_fields_input([
            'author_id' => default_author_id(),
            'published_at' => '2026-08-10T12:00',
            'content_updated_at' => '2026-08-09T12:00',
        ], []);
    } catch (InvalidArgumentException) {
        $invalidDateBlocked = true;
    }
    editor_assert($invalidDateBlocked, 'Data aktualizacji wcześniejsza od publikacji nie została zablokowana.');

    $fields = [
        'author_id' => default_author_id(),
        'published_at' => '2026-08-10T12:00',
        'scheduled_at' => '2026-08-11T09:30',
        'content_updated_at' => '2026-08-10T12:30',
        'seo_description' => 'Opis SEO testu edytora.',
        'image_alt' => 'Alternatywny opis obrazu.',
        'ai_components' => ['research', 'seo', 'invalid'],
        'ai_disclosure' => 'Research oraz opis SEO powstały ze wsparciem AI.',
    ];
    $sources = [[
        'source_url' => 'https://example.org/editor-' . $token,
        'source_title' => 'Źródło testu edytora',
        'publisher_name' => 'Example',
        'source_type' => 'primary',
    ]];
    update_post_editorial_fields($postId, $fields, $sources);
    $saved = find_post($postId);
    editor_assert((int) $saved['author_id'] === default_author_id(), 'Nie zapisano autora.');
    editor_assert($saved['seo_description'] === $fields['seo_description'], 'Nie zapisano SEO.');
    editor_assert($saved['image_alt'] === $fields['image_alt'], 'Nie zapisano altu.');
    editor_assert((int) $saved['ai_assisted'] === 1, 'Nie zapisano informacji o AI.');
    editor_assert(json_decode((string) $saved['ai_components'], true) === ['research', 'seo'], 'Komponenty AI nie zostały znormalizowane.');
    editor_assert(count(list_post_sources($postId)) === 1, 'Nie zapisano źródeł.');

    change_post_editorial_status($postId, 'review');
    update_post(
        $postId,
        (string) $saved['title'],
        (string) $saved['excerpt'],
        'Zmieniona treść.',
        '',
        false,
        '',
        null,
        [],
        'cover',
        [],
        'review'
    );
    editor_assert(find_post($postId)['status'] === 'review', 'Zapis treści samoczynnie zmienił status.');

    $warnings = editorial_post_warnings(find_post($postId), list_post_sources($postId));
    editor_assert(in_array('Brak głównego obrazu.', $warnings, true), 'Brak obrazu nie generuje ostrzeżenia.');
    editor_assert(!in_array('Brak źródeł.', $warnings, true), 'Edytor zgłasza brak istniejącego źródła.');

    echo "EDITORIAL_EDITOR_SMOKE_OK\n";
} finally {
    if ($postId > 0) {
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }
    if ($categoryId > 0) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $categoryId]);
    }
}
