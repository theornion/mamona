<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$categoryId = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT) ?: 0;
$category = $categoryId > 0 ? find_post_category($categoryId) : null;

if ($category === null) {
    header('Location: admin-posts.php', true, 303);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif ((string) ($_POST['action'] ?? '') === 'save_category') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($title === '' || mb_strlen($title) > 100 || mb_strlen($description) > 300) {
            $error = 'Nazwa kategorii jest wymagana, a opis może mieć maksymalnie 1000 znaków.';
        } else {
            update_post_category($categoryId, $title, $description);
            header('Location: admin-post-category-editor.php?category=' . $categoryId . '&saved=1', true, 303);
            exit;
        }
    } elseif ((string) ($_POST['action'] ?? '') === 'delete_category') {
        try {
            if (delete_post_category($categoryId) === null) {
                throw new RuntimeException('Nie znaleziono kategorii.');
            }
            header('Location: admin-posts.php?deleted=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ((string) ($_POST['action'] ?? '') === 'delete_post') {
        $postId = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT) ?: 0;

        try {
            if ($postId <= 0 || ($post = find_post($postId)) === null || (int) $post['category_id'] !== $categoryId || delete_post($postId) === null) {
                throw new RuntimeException('Nie znaleziono postu.');
            }
            header('Location: admin-post-category-editor.php?category=' . $categoryId . '&deleted=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$category = find_post_category($categoryId) ?? $category;
$posts = list_posts($categoryId);
$notice = (string) ($_GET['saved'] ?? '') !== '' ? 'Kategoria została zapisana.' : ((string) ($_GET['created'] ?? '') !== '' ? 'Utworzono nową kategorię.' : ((string) ($_GET['deleted'] ?? '') !== '' ? 'Post został usunięty.' : ''));

admin_page_open('Edytuj kategorię', 'posts');
?>
<section class="post admin-card admin-gallery-editor-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Kategoria aktualności</p>
        <h1><?php echo escape_html($category['title']); ?></h1>
        <p>Posty z tej kategorii będą dostępne w rozwijanym menu Aktualności.</p>
    </header>
    <?php if ($notice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo $notice; ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="save_category">
        <label for="title">Nazwa kategorii</label>
        <input id="title" name="title" maxlength="100" value="<?php echo escape_html($category['title']); ?>" required>
        <label for="description">Opis kategorii</label>
        <textarea id="description" name="description" rows="4" maxlength="300"><?php echo escape_html($category['description']); ?></textarea>
        <ul class="actions"><li><button type="submit">Zapisz kategorię</button></li><li><a class="button" href="admin-posts.php">Wróć do postów</a></li><li><button class="admin-danger-action" type="submit" form="delete-category-form">Usuń kategorię</button></li></ul>
    </form>
    <form id="delete-category-form" method="post" action="admin-post-category-editor.php?category=<?php echo $categoryId; ?>" onsubmit="return confirm('Usunąć tę kategorię wraz ze wszystkimi postami?');">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="delete_category">
    </form>

    <hr class="admin-gallery-editor-divider">
    <section class="admin-gallery-images">
        <h2>Posty w kategorii</h2>
        <p>Dodawaj, edytuj i publikuj aktualności.</p>
        <ul class="actions"><li><a class="button" href="admin-post-editor.php?category=<?php echo $categoryId; ?>">Dodaj post</a></li></ul>
        <?php if ($posts === []): ?>
            <p class="admin-gallery-empty">Ta kategoria nie ma jeszcze postów.</p>
        <?php else: ?>
            <div class="admin-post-list">
                <?php foreach ($posts as $post): ?>
                    <article>
                        <div>
                            <h3><?php echo escape_html($post['title']); ?></h3>
                            <p><?php echo escape_html($post['excerpt']); ?></p>
                        </div>
                        <span class="admin-post-card-actions"><a class="button" href="admin-post-editor.php?post=<?php echo (int) $post['id']; ?>">Edytuj post</a><a class="button admin-preview-action" href="../pages/<?php echo escape_html(post_page_filename((string) $post['slug'])); ?>" target="_blank" rel="noopener">Podgląd strony</a><form method="post" action="admin-post-category-editor.php?category=<?php echo $categoryId; ?>" onsubmit="return confirm('Usunąć ten post?');"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>"><button class="admin-danger-action" type="submit">Usuń</button></form></span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
<?php admin_page_close(); ?>
