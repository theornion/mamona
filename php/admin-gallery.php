<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'reorder_galleries') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!admin_valid_csrf()) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }

    try {
        reorder_galleries(is_array($_POST['order'] ?? null) ? $_POST['order'] : []);
        echo json_encode(['ok' => true]);
    } catch (Throwable) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create_gallery') {
    if (!admin_valid_csrf()) {
        header('Location: admin-gallery.php?error=csrf', true, 303);
        exit;
    }

    try {
        $galleryId = create_gallery();
        header('Location: admin-gallery-editor.php?gallery=' . $galleryId . '&created=1', true, 303);
        exit;
    } catch (Throwable $exception) {
        header('Location: admin-gallery.php?error=create', true, 303);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_gallery') {
    if (!admin_valid_csrf()) {
        header('Location: admin-gallery.php?error=csrf', true, 303);
        exit;
    }

    $galleryId = filter_input(INPUT_POST, 'gallery_id', FILTER_VALIDATE_INT) ?: 0;

    try {
        if (delete_gallery($galleryId) === null) {
            throw new RuntimeException('Nie znaleziono galerii.');
        }
        header('Location: admin-gallery.php?deleted=1', true, 303);
        exit;
    } catch (Throwable) {
        header('Location: admin-gallery.php?error=delete', true, 303);
        exit;
    }
}

$galleries = list_galleries();
$error = (string) ($_GET['error'] ?? '');
$deleted = isset($_GET['deleted']);

admin_page_open('Galerie', 'gallery');
?>
<section class="post admin-card admin-gallery-panel">
    <header class="major admin-heading admin-gallery-heading">
        <p class="admin-kicker">CMS</p>
        <h1>Galerie</h1>
        <p>Tu możesz dodać i porządkować zdjęcia w galeriach i tworzyć nowe galerie na stronie.</p>
    </header>

    <?php if ($deleted): ?>
        <p class="admin-notice is-success" role="status">Galeria i jej zdjęcia zostały usunięte.</p>
    <?php elseif ($error === 'delete'): ?>
        <p class="admin-notice is-error" role="alert">Nie udało się usunąć galerii.</p>
    <?php elseif ($error === 'csrf'): ?>
        <p class="admin-notice is-error" role="alert">Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.</p>
    <?php elseif ($error === 'create'): ?>
        <p class="admin-notice is-error" role="alert">Nie udało się utworzyć nowej strony galerii.</p>
    <?php endif; ?>

    <div class="admin-gallery-list admin-sortable-list" data-sort-url="admin-gallery.php" data-sort-action="reorder_galleries" data-sort-csrf="<?php echo escape_html(admin_csrf_token()); ?>">
        <?php foreach ($galleries as $gallery): ?>
            <article class="admin-sortable-item" data-sort-id="<?php echo (int) $gallery['id']; ?>" draggable="true">
                <h2><?php echo escape_html($gallery['title']); ?></h2>
                <p><?php echo $gallery['description'] !== '' ? escape_html($gallery['description']) : 'Galeria jest gotowa do uzupełnienia.'; ?></p>
                <ul class="actions">
                    <li><a class="button" href="admin-gallery-editor.php?gallery=<?php echo (int) $gallery['id']; ?>">Edytuj galerię</a></li>
                    <li><a class="button admin-preview-action" href="../pages/<?php echo rawurlencode($gallery['slug']); ?>.html" target="_blank" rel="noopener">Podgląd strony</a></li>
                    <li><form method="post" action="admin-gallery.php" onsubmit="return confirm('Usunąć tę galerię wraz ze wszystkimi zdjęciami?');"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="delete_gallery"><input type="hidden" name="gallery_id" value="<?php echo (int) $gallery['id']; ?>"><button class="admin-danger-action" type="submit">Usuń</button></form></li>
                </ul>
            </article>
        <?php endforeach; ?>

        <form method="post" action="admin-gallery.php" class="admin-gallery-add">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <button type="submit" name="action" value="create_gallery" aria-label="Dodaj nową galerię">
                <span class="admin-gallery-add-icon" aria-hidden="true">+</span>
                <span>Nowa galeria</span>
            </button>
        </form>
    </div>
</section>
<?php admin_page_close(); ?>
