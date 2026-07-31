<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function save_edited_gallery_image(array $upload): ?string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new RuntimeException('Nie udało się przesłać zdjęcia.');
    }

    if ((int) ($upload['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('Zdjęcie może mieć maksymalnie 8 MB.');
    }

    $imageInfo = @getimagesize($upload['tmp_name']);
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

function remove_edited_gallery_image(string $imagePath): void
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
$itemId = filter_input(INPUT_GET, 'item', FILTER_VALIDATE_INT) ?: 0;
$gallery = $galleryId > 0 ? find_gallery($galleryId) : null;
$item = $gallery !== null && $itemId > 0 ? find_gallery_item($itemId, $galleryId) : null;

if ($gallery === null || $item === null) {
    header('Location: admin-gallery.php', true, 303);
    exit;
}

$error = '';
$notice = (string) ($_GET['saved'] ?? '') !== '' ? 'Zdjęcie zostało zapisane.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif (mb_strlen($name) > 120 || mb_strlen($description) > 300) {
        $error = 'Tytuł zdjęcia jest wymagany, a opis może mieć maksymalnie 300 znaków.';
    } else {
        try {
            $newImagePath = save_edited_gallery_image($_FILES['image'] ?? []);
            $imageCrop = normalize_post_crop($_POST['image_crop'] ?? ($item['image_crop'] ?? ''));
            $mobileCropValue = $_POST['image_crop_mobile'] ?? ($item['image_crop_mobile'] ?? '');
            $imageCropMobile = is_string($mobileCropValue) && trim($mobileCropValue) !== ''
                ? normalize_post_crop($mobileCropValue)
                : $imageCrop;
            update_gallery_item($itemId, $galleryId, $name, $description, $newImagePath, $imageCrop, $imageCropMobile);

            if ($newImagePath !== null) {
                remove_edited_gallery_image((string) $item['image_path']);
            }

            write_gallery_page(find_gallery($galleryId) ?? $gallery);
            header('Location: admin-gallery-image-editor.php?gallery=' . $galleryId . '&item=' . $itemId . '&saved=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$item = find_gallery_item($itemId, $galleryId) ?? $item;
$desktopCropJson = (string) ($item['image_crop'] ?? '');
$mobileCropJson = (string) ($item['image_crop_mobile'] ?? '');
$mobileTwoUp = (int) ($gallery['tile_view'] ?? 0) !== 1 && (int) ($gallery['mobile_two_up'] ?? 0) === 1;

admin_page_open('Edytuj zdjęcie', 'gallery');
?>
<section class="post admin-card admin-gallery-editor-page">
    <header class="major admin-heading">
        <p class="admin-kicker"><?php echo escape_html($gallery['title']); ?></p>
        <h1>Edytuj zdjęcie</h1>
        <p>Zmień tytuł, opis albo plik zdjęcia w tej galerii.</p>
    </header>

    <?php if ($notice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($notice); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>

    <form method="post" action="admin-gallery-image-editor.php?gallery=<?php echo $galleryId; ?>&item=<?php echo $itemId; ?>" class="admin-form" enctype="multipart/form-data" data-gallery-single-crop data-gallery-mobile-two-up="<?php echo $mobileTwoUp ? 'true' : 'false'; ?>">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <label for="name">Tytuł zdjęcia</label>
        <input type="text" id="name" name="name" maxlength="120" value="<?php echo escape_html((string) $item['name']); ?>">
        <label for="description">Opis zdjęcia</label>
        <textarea id="description" name="description" rows="4" maxlength="300"><?php echo escape_html((string) $item['description']); ?></textarea>
        <label for="image">Nowy plik zdjęcia <span>(opcjonalnie)</span></label>
        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" data-gallery-image-input>
        <label>Dwa kadry responsywne</label>
        <div class="admin-gallery-crop-variants">
            <section class="admin-gallery-crop-variant">
                <h3>Kadr desktop 2:1</h3>
                <p>Szerokie ekrany, tablet i telefon obrócony poziomo.</p>
                <div class="admin-crop-editor" data-crop-editor data-crop-aspect="2">
                    <img src="<?php echo escape_html(admin_asset_url((string) $item['image_path'])); ?>" alt="Podgląd kadru desktop" data-crop-image>
                    <div class="admin-crop-box" data-crop-box><span data-crop-handle="nw"></span><span data-crop-handle="n"></span><span data-crop-handle="ne"></span><span data-crop-handle="e"></span><span data-crop-handle="se"></span><span data-crop-handle="s"></span><span data-crop-handle="sw"></span><span data-crop-handle="w"></span></div>
                    <input type="hidden" name="image_crop" value="<?php echo escape_html($desktopCropJson); ?>" data-crop-value>
                </div>
            </section>
            <section class="admin-gallery-crop-variant admin-gallery-crop-variant--mobile">
                <h3>Kadr mobile 1:2</h3>
                <p>Telefon trzymany pionowo w trybie jednego zdjęcia.</p>
                <div class="admin-crop-editor" data-crop-editor data-crop-aspect="0.5">
                    <img src="<?php echo escape_html(admin_asset_url((string) $item['image_path'])); ?>" alt="Podgląd kadru mobile" data-crop-image>
                    <div class="admin-crop-box" data-crop-box><span data-crop-handle="nw"></span><span data-crop-handle="n"></span><span data-crop-handle="ne"></span><span data-crop-handle="e"></span><span data-crop-handle="se"></span><span data-crop-handle="s"></span><span data-crop-handle="sw"></span><span data-crop-handle="w"></span></div>
                    <input type="hidden" name="image_crop_mobile" value="<?php echo escape_html($mobileCropJson); ?>" data-crop-value>
                </div>
            </section>
        </div>
        <p class="admin-post-image-drag-hint"><?php echo $mobileTwoUp
            ? 'Tryb dwóch zdjęć jest włączony — na telefonie używany będzie kadr desktopowy 2:1.'
            : 'Ustaw oba końce przejścia. Podczas zwężania ekranu pozycja i rozmiar kadru płynnie przejdą z 2:1 do 1:2.'; ?></p>
        <ul class="actions">
            <li><button type="submit">Zapisz zdjęcie</button></li>
            <li><a class="button" href="admin-gallery-editor.php?gallery=<?php echo $galleryId; ?>">Wróć do galerii</a></li>
        </ul>
    </form>
</section>
<?php admin_page_close(); ?>
