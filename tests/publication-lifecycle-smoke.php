<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_LIFECYCLE_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_LIFECYCLE_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

if (getenv('CMS_PUBLIC_URL') === false) {
    putenv('CMS_PUBLIC_URL=https://example.test');
}
putenv('CMS_SKIP_PUBLIC_SYNC=1');

require_once dirname(__DIR__) . '/php/admin-database.php';

function lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function public_list_contains_post(int $postId): bool
{
    foreach (list_posts(null, true) as $post) {
        if ((int) $post['id'] === $postId) {
            return true;
        }
    }

    return false;
}

$database = bueno_database();
$token = bin2hex(random_bytes(6));
$categoryId = 0;
$postId = 0;

try {
    $categoryStatement = $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order)
         VALUES (:title, :description, :slug, :sort_order)'
    );
    $categoryStatement->execute([
        ':title' => 'Test cyklu publikacji ' . $token,
        ':description' => 'Tymczasowa kategoria testowa.',
        ':slug' => 'test-lifecycle-' . $token,
        ':sort_order' => 999999,
    ]);
    $categoryId = (int) $database->lastInsertId();

    $postId = create_post(
        $categoryId,
        'Prywatny szkic ' . $token,
        'Skrót testowego szkicu.',
        'Treść testowego szkicu.',
        '',
        '',
        null,
        [],
        'cover',
        [],
        false
    );
    $draft = find_post($postId);
    lifecycle_assert(is_array($draft), 'Nie utworzono szkicu.');
    lifecycle_assert($draft['status'] === 'draft', 'Nowy szkic ma nieprawidłowy status.');
    lifecycle_assert((int) $draft['is_published'] === 0, 'Pole zgodności oznacza szkic jako publiczny.');
    lifecycle_assert(!is_file(post_page_path((string) $draft['slug'])), 'Szkic otrzymał publiczny plik HTML.');
    lifecycle_assert(!public_list_contains_post($postId), 'Szkic pojawił się na publicznej liście.');
    $privatePreview = render_post_page_html($draft, true);
    lifecycle_assert(
        str_contains($privatePreview, 'content="noindex,nofollow,noarchive"'),
        'Prywatny podgląd nie zawiera reguły noindex.'
    );

    update_post($postId, (string) $draft['title'], (string) $draft['excerpt'], (string) $draft['content'], '', true);
    $published = find_post($postId);
    lifecycle_assert(is_array($published) && $published['status'] === 'published', 'Nie zapisano publikacji.');
    lifecycle_assert((int) $published['is_published'] === 1, 'Pole zgodności nie odzwierciedla publikacji.');
    lifecycle_assert($published['published_at'] !== null, 'Publikacja nie otrzymała daty pierwszej publikacji.');
    lifecycle_assert(is_file(post_page_path((string) $published['slug'])), 'Nie utworzono publicznego HTML-a.');
    lifecycle_assert(public_list_contains_post($postId), 'Publikacja nie pojawiła się na publicznej liście.');

    $oldPath = post_page_path((string) $published['slug']);
    update_post($postId, 'Zmieniony tytuł ' . $token, (string) $published['excerpt'], (string) $published['content'], '', true);
    $renamed = find_post($postId);
    lifecycle_assert(is_array($renamed), 'Nie znaleziono wpisu po zmianie tytułu.');
    lifecycle_assert(!is_file($oldPath), 'Zmiana sluga pozostawiła poprzedni publiczny plik.');
    lifecycle_assert(is_file(post_page_path((string) $renamed['slug'])), 'Zmiana sluga nie utworzyła nowego pliku.');

    update_post($postId, (string) $renamed['title'], (string) $renamed['excerpt'], (string) $renamed['content'], '', false);
    $withdrawn = find_post($postId);
    lifecycle_assert(is_array($withdrawn) && $withdrawn['status'] === 'draft', 'Wycofanie nie ustawiło szkicu.');
    lifecycle_assert(!is_file(post_page_path((string) $withdrawn['slug'])), 'Wycofanie pozostawiło publiczny plik.');
    lifecycle_assert(!public_list_contains_post($postId), 'Wycofany wpis pozostał na publicznej liście.');

    delete_post($postId);
    restore_post($postId);
    $restoredDraft = find_post($postId);
    lifecycle_assert(is_array($restoredDraft) && $restoredDraft['status'] === 'draft', 'Przywrócenie opublikowało szkic.');
    lifecycle_assert((int) $restoredDraft['is_published'] === 0, 'Przywrócony szkic ma publiczną flagę.');
    lifecycle_assert(!is_file(post_page_path((string) $restoredDraft['slug'])), 'Przywrócony szkic otrzymał publiczny plik.');

    update_post($postId, (string) $restoredDraft['title'], (string) $restoredDraft['excerpt'], (string) $restoredDraft['content'], '', true);
    $republished = find_post($postId);
    lifecycle_assert(is_array($republished), 'Nie znaleziono ponownie opublikowanego wpisu.');
    delete_post($postId);
    restore_post($postId);
    $restoredPublication = find_post($postId);
    lifecycle_assert(is_array($restoredPublication) && $restoredPublication['status'] === 'published', 'Przywrócenie utraciło status publikacji.');
    lifecycle_assert(is_file(post_page_path((string) $restoredPublication['slug'])), 'Przywrócona publikacja nie otrzymała pliku.');

    lifecycle_assert(count(list_post_status_history($postId)) >= 4, 'Historia nie zawiera zmian publikacji.');

    echo "PUBLICATION_LIFECYCLE_SMOKE_OK\n";
} finally {
    if ($postId > 0) {
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }

    if ($categoryId > 0) {
        $statement = $database->prepare('DELETE FROM post_categories WHERE id = :id');
        $statement->execute([':id' => $categoryId]);
    }

}
