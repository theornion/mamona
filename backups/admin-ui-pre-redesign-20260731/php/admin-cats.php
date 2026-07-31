<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function save_uploaded_cat_image(array $upload): ?string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Nie udało się przesłać zdjęcia.');
    }

    if ((int) $upload['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Zdjęcie może mieć maksymalnie 8 MB.');
    }

    $imageInfo = getimagesize($upload['tmp_name']);
    $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Dodaj zdjęcie w formacie JPG, PNG lub WebP.');
    }

    $directory = dirname(__DIR__) . '/images/cats';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu zdjęć.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];

    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $fileName)) {
        throw new RuntimeException('Nie udało się zapisać zdjęcia na serwerze.');
    }

    return 'images/cats/' . $fileName;
}

function remove_uploaded_cat_image(string $imagePath): void
{
    if (!str_starts_with($imagePath, 'images/cats/')) {
        return;
    }

    $filePath = dirname(__DIR__) . '/' . $imagePath;

    if (is_file($filePath)) {
        unlink($filePath);
    }
}

$error = '';
$selectedId = filter_input(INPUT_GET, 'cat', FILTER_VALIDATE_INT) ?: 0;
$isNew = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $selectedId = filter_input(INPUT_POST, 'cat_id', FILTER_VALIDATE_INT) ?: 0;

    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif ($action === 'delete') {
        $cat = $selectedId > 0 ? delete_cat($selectedId) : null;

        if ($cat === null) {
            $error = 'Nie znaleziono wybranego elementu galerii.';
        } else {
            header('Location: admin-cats.php?deleted=1', true, 303);
            exit;
        }
    } elseif ($action === 'save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $existingCat = $selectedId > 0 ? find_cat($selectedId) : null;

        if ($name === '') {
            $error = 'Wpisz imię.';
        } elseif (strlen($name) > 120 || strlen($description) > 3000) {
            $error = 'Imię lub opis są zbyt długie.';
        } elseif ($selectedId > 0 && $existingCat === null) {
            $error = 'Nie znaleziono wybranego elementu galerii.';
        } else {
            try {
                $newImagePath = save_uploaded_cat_image($_FILES['image'] ?? []);

                if ($existingCat === null && $newImagePath === null) {
                    throw new RuntimeException('Dodaj zdjęcie nowego kota.');
                }

                if ($existingCat === null) {
                    create_cat($name, $description, $newImagePath);
                } else {
                    update_cat($selectedId, $name, $description, $newImagePath);

                    if ($newImagePath !== null) {
                        remove_uploaded_cat_image($existingCat['image_path']);
                    }
                }

                header('Location: admin-cats.php?saved=1', true, 303);
                exit;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}

$selectedCat = $selectedId > 0 ? find_cat($selectedId) : null;
$cats = list_cats();

admin_page_open('Edycja kotów', 'gallery');
?>
<section class="post admin-card admin-cats-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Galeria</p>
        <h1>Nasze koty</h1>
        <p>Kliknij miniaturę, aby otworzyć okno edycji. Zdjęcia oraz opisy z tego miejsca są widoczne na stronie kotków.</p>
    </header>

    <?php if (isset($_GET['saved'])): ?>
        <p class="admin-notice is-success" role="status">Zmiany w galerii zostały zapisane.</p>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <p class="admin-notice is-success" role="status">Element galerii został usunięty.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
    <?php endif; ?>

    <div class="admin-cat-toolbar">
        <a class="button" href="admin-cats.php?new=1">Dodaj kota</a>
        <a class="button" href="admin-gallery.php">Wróć do galerii</a>
    </div>

    <div class="admin-cat-grid">
        <?php foreach ($cats as $cat): ?>
            <a class="admin-cat-thumb" href="admin-cats.php?cat=<?php echo (int) $cat['id']; ?>">
                <img src="<?php echo escape_html(admin_asset_url($cat['image_path'])); ?>" alt="<?php echo escape_html($cat['name']); ?>">
                <span><?php echo escape_html($cat['name']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($isNew || $selectedCat !== null): ?>
        <div class="admin-editor-backdrop" role="presentation">
            <section class="admin-cat-editor" role="dialog" aria-modal="true" aria-labelledby="cat-editor-title">
                <a class="admin-editor-close" href="admin-cats.php" aria-label="Zamknij edycję">×</a>
                <h2 id="cat-editor-title"><?php echo $isNew ? 'Dodaj kota' : 'Edytuj kota'; ?></h2>
                <?php if ($selectedCat !== null): ?>
                    <img class="admin-cat-editor-image" src="<?php echo escape_html(admin_asset_url($selectedCat['image_path'])); ?>" alt="<?php echo escape_html($selectedCat['name']); ?>">
                <?php endif; ?>
                <form method="post" action="admin-cats.php" class="admin-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                    <input type="hidden" name="cat_id" value="<?php echo (int) ($selectedCat['id'] ?? 0); ?>">
                    <div class="fields">
                        <div class="field">
                            <label for="name">Imię</label>
                            <input type="text" name="name" id="name" value="<?php echo escape_html((string) ($selectedCat['name'] ?? '')); ?>" required>
                        </div>
                        <div class="field">
                            <label for="description">Opis</label>
                            <textarea name="description" id="description" rows="5"><?php echo escape_html((string) ($selectedCat['description'] ?? '')); ?></textarea>
                        </div>
                        <div class="field">
                            <label for="image">Zdjęcie <?php echo $selectedCat === null ? '' : '(opcjonalnie, aby zastąpić obecne)'; ?></label>
                            <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp"<?php echo $selectedCat === null ? ' required' : ''; ?>>
                        </div>
                    </div>
                    <ul class="actions">
                        <li><button type="submit" name="action" value="save">Zapisz</button></li>
                    </ul>
                </form>
                <?php if ($selectedCat !== null): ?>
                    <form method="post" action="admin-cats.php" class="admin-delete-form" onsubmit="return confirm('Usunąć ten element galerii?');">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="cat_id" value="<?php echo (int) $selectedCat['id']; ?>">
                        <button class="admin-danger-action" type="submit" name="action" value="delete">Usuń z galerii</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</section>
<?php admin_page_close(); ?>
