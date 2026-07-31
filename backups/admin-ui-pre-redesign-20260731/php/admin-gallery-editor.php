<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function save_uploaded_gallery_image(array $upload): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Dodaj zdjęcie do galerii.');
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

    $directory = dirname(__DIR__) . '/images/galleries';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu zdjęć.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];

    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $fileName)) {
        throw new RuntimeException('Nie udało się zapisać zdjęcia na serwerze.');
    }

    return 'images/galleries/' . $fileName;
}

function save_uploaded_gallery_images(array $uploads): array
{
    $paths = [];
    $names = is_array($uploads['name'] ?? null) ? $uploads['name'] : [];
    foreach ($names as $index => $name) {
        $paths[] = save_uploaded_gallery_image([
            'name' => $name,
            'type' => $uploads['type'][$index] ?? '',
            'tmp_name' => $uploads['tmp_name'][$index] ?? '',
            'error' => $uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $uploads['size'][$index] ?? 0,
        ]);
    }
    return $paths;
}

function remove_uploaded_gallery_image(string $imagePath): void
{
    if (!str_starts_with($imagePath, 'images/galleries/')) {
        return;
    }

    $filePath = dirname(__DIR__) . '/' . $imagePath;

    if (is_file($filePath)) {
        unlink($filePath);
    }
}

$galleryId = filter_input(INPUT_GET, 'gallery', FILTER_VALIDATE_INT) ?: 0;
$gallery = $galleryId > 0 ? find_gallery($galleryId) : null;

if ($gallery === null) {
    header('Location: admin-gallery.php', true, 303);
    exit;
}

$tileViewEnabled = (int) ($gallery['tile_view'] ?? 0) === 1;
$mobileTwoUpEnabled = !$tileViewEnabled && (int) ($gallery['mobile_two_up'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'reorder_gallery_items') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!admin_valid_csrf()) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }

    try {
        reorder_gallery_items($galleryId, is_array($_POST['order'] ?? null) ? $_POST['order'] : []);
        echo json_encode(['ok' => true]);
    } catch (Throwable) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
    }
    exit;
}

$notice = isset($_GET['created']) ? 'Nowa galeria została utworzona. Uzupełnij jej dane i zdjęcia.' : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif ($action === 'save_details') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($title === '' || strlen($title) > 100 || strlen($description) > 300) {
            $error = 'Nazwa galerii jest wymagana, a opis może mieć maksymalnie 1000 znaków.';
        } else {
            try {
                update_gallery(
                    $galleryId,
                    $title,
                    $description,
                    (int) ($gallery['mobile_two_up'] ?? 0) === 1,
                    (int) ($gallery['tile_view'] ?? 0) === 1
                );
                header('Location: admin-gallery-editor.php?gallery=' . $galleryId . '&saved=1', true, 303);
                exit;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'save_mobile_layout') {
        try {
            $tileView = isset($_POST['tile_view']);
            update_gallery(
                $galleryId,
                (string) $gallery['title'],
                (string) $gallery['description'],
                !$tileView && isset($_POST['mobile_two_up']),
                $tileView
            );
            header('Location: admin-gallery-editor.php?gallery=' . $galleryId . '&layout_saved=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'add_image' || $action === 'add_images') {
        $name = trim((string) ($_POST['image_name'] ?? ''));
        $description = trim((string) ($_POST['image_description'] ?? ''));

        if (strlen($name) > 120 || strlen($description) > 300) {
            $error = 'Tytuł zdjęcia jest wymagany, a opis może mieć maksymalnie 1000 znaków.';
        } else {
            try {
                $imagePaths = save_uploaded_gallery_images($_FILES['images'] ?? []);
                if ($imagePaths === [] && isset($_FILES['image'])) $imagePaths[] = save_uploaded_gallery_image($_FILES['image']);
                if ($imagePaths === []) throw new RuntimeException('Wybierz przynajmniej jedno zdjęcie.');
                $imageCrops = is_array($_POST['image_crops'] ?? null) ? $_POST['image_crops'] : [];
                $imageMobileCrops = is_array($_POST['image_mobile_crops'] ?? null) ? $_POST['image_mobile_crops'] : [];
                foreach ($imagePaths as $index => $imagePath) {
                    $desktopCrop = normalize_post_crop($imageCrops[$index] ?? []);
                    $mobileCropValue = $imageMobileCrops[$index] ?? null;
                    $mobileCrop = is_string($mobileCropValue) && trim($mobileCropValue) !== ''
                        ? normalize_post_crop($mobileCropValue)
                        : $desktopCrop;
                    create_gallery_item($galleryId, $name, $description, $imagePath, $desktopCrop, $mobileCrop);
                }
                write_gallery_page(find_gallery($galleryId) ?? $gallery);
                header('Location: admin-gallery-editor.php?gallery=' . $galleryId . '&image_saved=1', true, 303);
                exit;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'delete_images') {
        $itemIds = is_array($_POST['item_ids'] ?? null) ? $_POST['item_ids'] : [];
        $deletedCount = 0;
        foreach ($itemIds as $rawItemId) {
            $itemId = filter_var($rawItemId, FILTER_VALIDATE_INT) ?: 0;
            if ($itemId > 0 && delete_gallery_item($itemId, $galleryId) !== null) $deletedCount++;
        }
        if ($deletedCount === 0) {
            $error = 'Zaznacz przynajmniej jedno zdjęcie.';
        } else {
            write_gallery_page(find_gallery($galleryId) ?? $gallery);
            header('Location: admin-gallery-editor.php?gallery=' . $galleryId . '&image_deleted=' . $deletedCount, true, 303);
            exit;
        }
    } elseif ($action === 'delete_image') {
        $itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?: 0;
        $item = $itemId > 0 ? delete_gallery_item($itemId, $galleryId) : null;

        if ($item === null) {
            $error = 'Nie znaleziono zdjęcia galerii.';
        } else {
            write_gallery_page(find_gallery($galleryId) ?? $gallery);
            header('Location: admin-gallery-editor.php?gallery=' . $galleryId . '&image_deleted=1', true, 303);
            exit;
        }
    } elseif ($action === 'delete_gallery') {
        try {
            if (delete_gallery($galleryId) === null) {
                throw new RuntimeException('Nie znaleziono galerii.');
            }
            header('Location: admin-gallery.php?deleted=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Zmiany zostały zapisane.';
}
if (isset($_GET['image_saved'])) {
    $notice = 'Zdjęcie zostało dodane do galerii.';
}
if (isset($_GET['image_deleted'])) {
    $notice = 'Zdjęcie zostało usunięte z galerii.';
}
if (isset($_GET['layout_saved'])) {
    $notice = 'Układ galerii został zapisany.';
}

$gallery = find_gallery($galleryId) ?? $gallery;
$items = list_gallery_items($galleryId);

admin_page_open('Edytuj galerię', 'gallery');
?>
<section class="post admin-card admin-gallery-editor-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Galerie</p>
        <h1><?php echo escape_html($gallery['title']); ?></h1>
        <p>Edytuj dane galerii. Po zapisaniu utworzy się lub odświeży plik <strong><?php echo escape_html($gallery['slug']); ?>.html</strong>.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="admin-notice is-success" role="status"><?php echo escape_html($notice); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
    <?php endif; ?>

    <form method="post" action="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="save_details">
        <label for="title">Nazwa galerii</label>
        <input type="text" id="title" name="title" maxlength="100" value="<?php echo escape_html($gallery['title']); ?>" required>
        <label for="description">Opis galerii</label>
        <textarea id="description" name="description" rows="4" maxlength="300"><?php echo escape_html($gallery['description']); ?></textarea>
        <ul class="actions">
            <li><button type="submit">Zapisz dane galerii</button></li>
            <li><a class="button" href="admin-gallery.php">Wróć do galerii</a></li>
            <li><button class="admin-danger-action" type="submit" form="delete-gallery-form">Usuń galerię</button></li>
        </ul>
    </form>
    <form id="delete-gallery-form" method="post" action="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>" onsubmit="return confirm('Usunąć tę galerię wraz ze wszystkimi zdjęciami?');">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="delete_gallery">
    </form>

    <hr class="admin-gallery-editor-divider">

    <section class="admin-gallery-images">
        <h2>Zdjęcia w galerii</h2>
        <p>Dodane zdjęcia pojawią się na stronie <strong><?php echo escape_html($gallery['slug']); ?>.html</strong>.</p>
        <p class="admin-sortable-help">Chwyć kafelek i przeciągnij go, aby zmienić kolejność zdjęć na stronie.</p>

        <form id="bulk-delete-form" method="post" action="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>" onsubmit="return confirm('Usunąć zaznaczone zdjęcia?');">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="delete_images">
            <button class="admin-danger-action" type="submit">Usuń zaznaczone</button>
        </form>
        <form method="post" action="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>" class="admin-form admin-gallery-upload" enctype="multipart/form-data" data-gallery-crop-upload data-gallery-mobile-two-up="<?php echo $mobileTwoUpEnabled ? 'true' : 'false'; ?>">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="add_images">
            <label for="image_name">Tytuł zdjęcia</label>
            <input type="text" id="image_name" name="image_name" maxlength="120" placeholder="Np. Seria A (opcjonalnie)">
            <label for="image_description">Opis zdjęcia</label>
            <textarea id="image_description" name="image_description" rows="3" maxlength="300" placeholder="Opcjonalny opis..."></textarea>
            <label for="image">Plik zdjęcia</label>
            <input type="file" id="image" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required data-gallery-images-input>
            <div class="admin-gallery-crop-previews" data-gallery-crop-previews></div>
            <p class="admin-post-image-drag-hint"><?php echo $mobileTwoUpEnabled
                ? 'Tryb dwóch zdjęć jest włączony — na telefonie używany będzie kadr desktopowy 2:1.'
                : 'Dla każdego pliku ustaw dwa końce płynnego przejścia: desktop 2:1 i mobile 1:2.'; ?></p>
            <ul class="actions">
                <li><button type="submit">Dodaj zdjęcie</button></li>
            </ul>
        </form>

        <?php if ($items === []): ?>
            <p class="admin-gallery-empty">Ta galeria nie ma jeszcze zdjęć.</p>
        <?php else: ?>
            <div class="admin-cat-grid admin-gallery-image-grid admin-sortable-list" data-sort-url="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>" data-sort-action="reorder_gallery_items" data-sort-csrf="<?php echo escape_html(admin_csrf_token()); ?>">
                <?php foreach ($items as $item): ?>
                    <article class="admin-cat-thumb admin-sortable-item" data-sort-id="<?php echo (int) $item['id']; ?>" draggable="true">
                        <input class="admin-gallery-item-select" type="checkbox" name="item_ids[]" value="<?php echo (int) $item['id']; ?>" form="bulk-delete-form" aria-label="Zaznacz zdjęcie">
                        <img src="<?php echo escape_html(admin_asset_url($item['image_path'])); ?>" alt="<?php echo escape_html($item['name']); ?>">
                        <span><?php echo escape_html($item['name']); ?></span>
                        <span class="admin-gallery-item-actions">
                            <a class="button admin-gallery-item-edit" href="admin-gallery-image-editor.php?gallery=<?php echo $galleryId; ?>&item=<?php echo (int) $item['id']; ?>">Edytuj zdjęcie</a>
                            <form method="post" action="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>" onsubmit="return confirm('Usunąć to zdjęcie z galerii?');">
                                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete_image">
                                <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                <button class="admin-message-quick-trash" type="submit" title="Usuń zdjęcie" aria-label="Usuń zdjęcie">
                                    <img src="<?php echo escape_html(admin_asset_url('images/icons/kosz.svg')); ?>" alt="" aria-hidden="true">
                                </button>
                            </form>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>" class="admin-gallery-mobile-setting" data-gallery-layout-settings>
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_mobile_layout">
            <label class="admin-social-visibility admin-gallery-mobile-toggle admin-gallery-tile-toggle">
                <input type="checkbox" name="tile_view" value="1" data-gallery-tile-view-input<?php echo $tileViewEnabled ? ' checked' : ''; ?>>
                <span class="admin-toggle-track" aria-hidden="true"><span class="admin-toggle-knob"></span></span>
                <span class="admin-toggle-label">Widok galerii: kafelki / slider</span>
                <span class="admin-gallery-tile-preview" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            </label>
            <p>Włączone: kafelki otwierają nowy animowany podgląd zdjęć. Wyłączone: galeria działa jako pełnoekranowy slider.</p>
            <div class="admin-gallery-mobile-layout-option<?php echo $tileViewEnabled ? ' is-disabled' : ''; ?>" data-gallery-mobile-layout-option>
                <label class="admin-social-visibility admin-gallery-mobile-toggle">
                    <input type="checkbox" name="mobile_two_up" value="1" data-gallery-mobile-two-up-input<?php echo $tileViewEnabled ? ' disabled' : ($mobileTwoUpEnabled ? ' checked' : ''); ?>>
                    <span class="admin-toggle-track" aria-hidden="true"><span class="admin-toggle-knob"></span></span>
                    <span class="admin-toggle-label">Na telefonie pokazuj 2 zdjęcia pod sobą</span>
                    <span class="admin-gallery-layout-preview" aria-hidden="true"><i></i><i></i></span>
                </label>
                <p>Opcja jest dostępna tylko dla slidera. W widoku kafelków kliknięcie zawsze uruchamia animowany podgląd.</p>
            </div>
            <ul class="actions">
                <li><button type="submit">Zapisz układ galerii</button></li>
            </ul>
        </form>
    </section>
</section>
<?php admin_page_close(); ?>
