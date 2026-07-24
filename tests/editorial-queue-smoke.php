<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_QUEUE_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_QUEUE_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_PUBLIC_URL=https://example.test');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function queue_assert(bool $condition, string $message): void
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
        ':title' => 'Queue ' . $token,
        ':description' => 'Test kolejki.',
        ':slug' => 'queue-' . $token,
    ]);
    $categoryId = (int) $database->lastInsertId();
    $postId = create_post($categoryId, 'Materiał kolejki ' . $token, 'Opis.', 'Treść.');
    replace_post_sources($postId, [[
        'source_url' => 'https://example.org/queue-' . $token,
        'source_title' => 'Źródło kolejki',
        'source_type' => 'primary',
    ]]);
    $runId = create_post_generation_run($postId, 'research', 'test', 'test-model');
    finish_post_generation_run($runId, 'failed', '', '', [], 'Kontrolowany błąd automatyzacji');
    $database->prepare(
        "UPDATE posts SET quality_score = 77, editorial_origin = 'automatic',
         scheduled_at = '2026-08-01 10:00:00' WHERE id = :id"
    )->execute([':id' => $postId]);

    $drafts = list_editorial_queue('draft');
    $row = null;
    foreach ($drafts as $candidate) {
        if ((int) $candidate['id'] === $postId) {
            $row = $candidate;
            break;
        }
    }
    queue_assert(is_array($row), 'Filtr szkiców nie zwrócił materiału.');
    queue_assert((int) $row['source_count'] === 1, 'Kolejka nie pokazuje liczby źródeł.');
    queue_assert((int) $row['quality_score'] === 77, 'Kolejka nie pokazuje jakości.');
    queue_assert($row['editorial_origin'] === 'automatic', 'Kolejka nie pokazuje pochodzenia.');
    queue_assert(str_contains((string) $row['last_generation_error'], 'Kontrolowany błąd'), 'Kolejka nie pokazuje ostatniego błędu.');

    change_post_editorial_status($postId, 'research');
    change_post_editorial_status($postId, 'review');
    $beforeRejectHistory = count(list_post_status_history($postId));
    change_post_editorial_status($postId, 'rejected', 'Materiał dubluje istniejącą publikację.');
    $rejected = find_post($postId);
    queue_assert($rejected['status'] === 'rejected', 'Odrzucenie nie zmieniło statusu.');
    queue_assert($rejected['deleted_at'] === null, 'Odrzucenie usunęło materiał.');
    queue_assert($rejected['rejection_reason'] !== '', 'Nie zapisano przyczyny odrzucenia.');
    queue_assert(count(list_post_status_history($postId)) === $beforeRejectHistory + 1, 'Zmiana nie trafiła do historii.');

    $missingReasonRejected = false;
    try {
        change_post_editorial_status($postId, 'draft');
        change_post_editorial_status($postId, 'rejected');
    } catch (InvalidArgumentException) {
        $missingReasonRejected = true;
    }
    queue_assert($missingReasonRejected, 'Odrzucenie bez przyczyny nie zostało zablokowane.');

    if (find_post($postId)['status'] === 'rejected') {
        change_post_editorial_status($postId, 'draft');
    }
    change_post_editorial_status($postId, 'published');
    $duplicatePublicationBlocked = false;
    try {
        change_post_editorial_status($postId, 'published');
    } catch (InvalidArgumentException) {
        $duplicatePublicationBlocked = true;
    }
    queue_assert($duplicatePublicationBlocked, 'Ponowna publikacja nie została zablokowana.');
    queue_assert(is_file(post_page_path((string) find_post($postId)['slug'])), 'Publikacja z kolejki nie utworzyła HTML-a.');

    echo "EDITORIAL_QUEUE_SMOKE_OK\n";
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
