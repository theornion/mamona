<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'reorder_post_categories') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!admin_valid_csrf()) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }

    try {
        reorder_post_categories(is_array($_POST['order'] ?? null) ? $_POST['order'] : []);
        echo json_encode(['ok' => true]);
    } catch (Throwable) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create_category') {
    if (!admin_valid_csrf()) {
        header('Location: admin-posts.php?error=csrf', true, 303);
        exit;
    }

    try {
        $categoryId = create_post_category();
        header('Location: admin-post-category-editor.php?category=' . $categoryId . '&created=1', true, 303);
        exit;
    } catch (Throwable) {
        header('Location: admin-posts.php?error=create', true, 303);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_category') {
    if (!admin_valid_csrf()) {
        header('Location: admin-posts.php?error=csrf', true, 303);
        exit;
    }

    $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT) ?: 0;

    try {
        if (delete_post_category($categoryId) === null) {
            throw new RuntimeException('Nie znaleziono kategorii.');
        }
        header('Location: admin-posts.php?deleted=1', true, 303);
        exit;
    } catch (Throwable) {
        header('Location: admin-posts.php?error=delete', true, 303);
        exit;
    }
}

$categories = list_post_categories();
$error = (string) ($_GET['error'] ?? '');
$deleted = isset($_GET['deleted']);

admin_page_open('Posty', 'posts');
?>
<section class="post admin-card admin-gallery-panel">
    <header class="major admin-heading admin-gallery-heading">
        <p class="admin-kicker">Aktualności</p>
        <h1>Posty</h1>
        <p>Twórz kategorie aktualności oraz dodawaj posty widoczne na stronie głównej. Chwyć kafelek, aby zmienić kolejność kategorii — zmiana będzie również widoczna w menu strony.</p>
    </header>

    <?php if ($deleted): ?>
        <p class="admin-notice is-success" role="status">Kategoria i jej posty zostały usunięte.</p>
    <?php elseif ($error === 'delete'): ?>
        <p class="admin-notice is-error" role="alert">Nie udało się usunąć kategorii.</p>
    <?php elseif ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert">Nie udało się utworzyć nowej kategorii.</p>
    <?php endif; ?>

    <div class="admin-gallery-list admin-post-category-list admin-sortable-list" data-sort-url="admin-posts.php" data-sort-action="reorder_post_categories" data-sort-csrf="<?php echo escape_html(admin_csrf_token()); ?>">
        <?php foreach ($categories as $category): ?>
            <article class="admin-sortable-item" data-sort-id="<?php echo (int) $category['id']; ?>" draggable="true">
                <h2><?php echo escape_html($category['title']); ?></h2>
                <p><?php echo $category['description'] !== '' ? escape_html($category['description']) : 'Kategoria jest gotowa do uzupełnienia.'; ?></p>
                <p class="admin-post-count"><?php echo (int) $category['post_count']; ?> posty</p>
                <ul class="actions">
                    <li><a class="button" href="admin-post-category-editor.php?category=<?php echo (int) $category['id']; ?>">Edytuj kategorię</a></li>
                    <li><a class="button admin-preview-action" href="../pages/index.html?category=<?php echo rawurlencode($category['slug']); ?>" target="_blank" rel="noopener">Podgląd strony</a></li>
                    <li><form method="post" action="admin-posts.php" onsubmit="return confirm('Usunąć tę kategorię wraz ze wszystkimi postami?');"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?php echo (int) $category['id']; ?>"><button class="admin-danger-action" type="submit">Usuń</button></form></li>
                </ul>
            </article>
        <?php endforeach; ?>

        <form method="post" action="admin-posts.php" class="admin-gallery-add">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <button type="submit" name="action" value="create_category" aria-label="Dodaj nową kategorię postów">
                <span class="admin-gallery-add-icon" aria-hidden="true">+</span>
                <span>Nowa kategoria</span>
            </button>
        </form>
    </div>
</section>
<?php admin_page_close(); ?>
