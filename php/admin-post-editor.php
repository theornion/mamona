<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function save_uploaded_post_image(array $upload): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new RuntimeException('Nie udało się przesłać zdjęcia postu.');
    }

    $imageInfo = @getimagesize($upload['tmp_name']);
    $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if (!isset($extensions[$mime]) || (int) ($upload['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('Zdjęcie musi być plikiem JPG, PNG lub WEBP o wielkości do 8 MB.');
    }

    $directory = dirname(__DIR__) . '/images/posts';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu na zdjęcia postów.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];

    if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Nie udało się zapisać zdjęcia postu.');
    }

    return 'images/posts/' . $filename;
}

function remove_uploaded_post_image(string $imagePath): void
{
    $normalizedPath = ltrim(str_replace('\\', '/', trim($imagePath)), '/');
    if (!str_starts_with($normalizedPath, 'images/posts/')) {
        return;
    }

    $filePath = dirname(__DIR__) . '/' . $normalizedPath;
    if (is_file($filePath)) {
        unlink($filePath);
    }
}

function save_uploaded_post_images(array $uploads, array $crops = []): array
{
    if (!isset($uploads['name'])) {
        return [];
    }

    $names = is_array($uploads['name']) ? $uploads['name'] : [$uploads['name']];
    $saved = [];

    foreach (array_keys($names) as $index) {
        $upload = [
            'name' => is_array($uploads['name']) ? ($uploads['name'][$index] ?? '') : $uploads['name'],
            'type' => is_array($uploads['type'] ?? null) ? ($uploads['type'][$index] ?? '') : ($uploads['type'] ?? ''),
            'tmp_name' => is_array($uploads['tmp_name'] ?? null) ? ($uploads['tmp_name'][$index] ?? '') : ($uploads['tmp_name'] ?? ''),
            'error' => is_array($uploads['error'] ?? null) ? ($uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($uploads['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => is_array($uploads['size'] ?? null) ? ($uploads['size'][$index] ?? 0) : ($uploads['size'] ?? 0),
        ];
        $path = save_uploaded_post_image($upload);
        if ($path !== '') {
            $saved[] = [
                'path' => $path,
                'crop' => normalize_post_crop($crops[$index] ?? []),
            ];
        }
    }

    return $saved;
}

$postId = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT) ?: 0;
$categoryId = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT) ?: 0;
$post = $postId > 0 ? find_post($postId) : null;

if ($postId > 0 && $post === null) {
    header('Location: admin-posts.php', true, 303);
    exit;
}

if ($post !== null) {
    $categoryId = (int) $post['category_id'];
}

$category = $categoryId > 0 ? find_post_category($categoryId) : null;
$galleries = list_galleries();

if ($category === null) {
    header('Location: admin-posts.php', true, 303);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((string) ($_POST['action'] ?? '') === 'delete_post') {
        if (!admin_valid_csrf()) {
            $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
        } else {
            try {
                if (delete_post($postId) === null) {
                    throw new RuntimeException('Nie znaleziono postu.');
                }
                header('Location: admin-post-category-editor.php?category=' . $categoryId . '&deleted=1', true, 303);
                exit;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }

    if ((string) ($_POST['action'] ?? '') === 'delete_post') {
        // Keep the delete request from falling through to the save validation below.
    } else {
    $title = trim((string) ($_POST['title'] ?? ''));
    $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $galleryId = filter_input(INPUT_POST, 'gallery_id', FILTER_VALIDATE_INT) ?: null;
    $isPublished = isset($_POST['is_published']);

    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif ($title === '' || $excerpt === '' || $content === '' || mb_strlen($title) > 160 || mb_strlen($excerpt) > 500 || mb_strlen($content) > 8000) {
        $error = 'Uzupełnij tytuł, skrót i treść. Tytuł może mieć do 160 znaków, a treść do 8000.';
    } else {
        try {
            $newImagePath = save_uploaded_post_image($_FILES['image'] ?? []);
            $oldImagePath = (string) ($post['image_path'] ?? '');
            $removeMainImage = isset($_POST['remove_main_image']) && (string) $_POST['remove_main_image'] === '1';
            $imagePath = $newImagePath !== '' ? $newImagePath : ($removeMainImage ? '' : $oldImagePath);
            $imageCrop = $imagePath === ''
                ? normalize_post_crop([])
                : normalize_post_crop($_POST['image_crop'] ?? ($post['image_crop'] ?? ''));

            $existingItems = post_content_image_items($post ?? []);
            $allowedPaths = array_column($existingItems, 'path');
            $postedPaths = is_array($_POST['existing_content_image_paths'] ?? null) ? $_POST['existing_content_image_paths'] : [];
            $postedCrops = is_array($_POST['existing_content_image_crops'] ?? null) ? $_POST['existing_content_image_crops'] : [];
            $contentImages = [];
            foreach ($postedPaths as $index => $postedPath) {
                $normalizedPath = ltrim(str_replace('\\', '/', trim((string) $postedPath)), '/');
                if (in_array($normalizedPath, $allowedPaths, true)) {
                    $contentImages[] = [
                        'path' => $normalizedPath,
                        'crop' => normalize_post_crop($postedCrops[$index] ?? []),
                    ];
                }
            }
            if ($post !== null && $postedPaths === [] && !isset($_POST['content_images_managed'])) {
                $contentImages = $existingItems;
            }

            $newContentCrops = is_array($_POST['content_image_crops'] ?? null) ? $_POST['content_image_crops'] : [];
            $newContentImages = save_uploaded_post_images($_FILES['content_images'] ?? [], $newContentCrops);
            $contentImages = array_values(array_merge($contentImages, $newContentImages));
            $content = preg_replace_callback(
                '/\[\[Z(\d+)\]\]/',
                static fn (array $match): string => (int) $match[1] >= 1 && (int) $match[1] <= count($contentImages) ? $match[0] : '',
                $content
            ) ?? $content;
            $contentImagePath = (string) ($contentImages[0]['path'] ?? '');
            if ($post === null) {
                $newPostId = create_post($categoryId, $title, $excerpt, $content, $imagePath, $contentImagePath, $galleryId, $contentImages, 'cover', $imageCrop);
                update_post($newPostId, $title, $excerpt, $content, $imagePath, $isPublished, $contentImagePath, $galleryId, $contentImages, 'cover', $imageCrop);
                header('Location: admin-post-editor.php?post=' . $newPostId . '&saved=1', true, 303);
            } else {
                update_post($postId, $title, $excerpt, $content, $imagePath, $isPublished, $contentImagePath, $galleryId, $contentImages, 'cover', $imageCrop);
                foreach (array_diff($allowedPaths, array_column($contentImages, 'path')) as $removedContentImagePath) {
                    remove_uploaded_post_image((string) $removedContentImagePath);
                }
                if ($oldImagePath !== '' && $oldImagePath !== $imagePath) {
                    remove_uploaded_post_image($oldImagePath);
                }
                header('Location: admin-post-editor.php?post=' . $postId . '&saved=1', true, 303);
            }
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
    }
}

$post = $postId > 0 ? (find_post($postId) ?? $post) : $post;
$notice = (string) ($_GET['saved'] ?? '') !== '' ? 'Post został zapisany.' : '';
$contentImageItems = post_content_image_items($post ?? []);
$mainImageCrop = post_main_image_crop($post ?? []);

admin_page_open($post === null ? 'Dodaj post' : 'Edytuj post', 'posts');
?>
<section class="post admin-card admin-gallery-editor-page">
    <header class="major admin-heading">
        <p class="admin-kicker"><?php echo escape_html($category['title']); ?></p>
        <h1><?php echo $post === null ? 'Nowy post' : 'Edytuj post'; ?></h1>
        <p>Po publikacji post pojawi się w aktualnościach na stronie.</p>
    </header>
    <?php if ($notice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo $notice; ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <label for="title">Tytuł</label>
        <input id="title" name="title" maxlength="160" value="<?php echo escape_html((string) ($post['title'] ?? '')); ?>" required>
        <label for="excerpt">Krótki opis</label>
        <textarea id="excerpt" name="excerpt" rows="3" maxlength="500" required><?php echo escape_html((string) ($post['excerpt'] ?? '')); ?></textarea>
        <label for="content">Treść postu</label>
        <textarea id="content" name="content" rows="12" maxlength="8000" required data-post-content-dropzone><?php echo escape_html((string) ($post['content'] ?? '')); ?></textarea>
        <p class="admin-form-help admin-post-content-help">Kliknij w wybrane miejsce tekstu i użyj przycisku <strong>Wstaw zdjęcie tutaj</strong>. Możesz też przeciągnąć kafelek zdjęcia bezpośrednio na pole treści.</p>
        <label>Zdjęcia w treści postu</label>
        <input type="hidden" name="content_images_managed" value="1">
        <div id="post-content-images" class="admin-post-content-images" data-existing-count="<?php echo count($contentImageItems); ?>">
            <?php foreach ($contentImageItems as $index => $contentImageItem): ?>
                <?php $token = '[[Z' . ($index + 1) . ']]'; $cropJson = json_encode($contentImageItem['crop'], JSON_UNESCAPED_SLASHES); ?>
                <article class="admin-post-content-image-card" data-post-image-card data-image-token="<?php echo $token; ?>" draggable="true">
                    <header class="admin-post-image-card-header">
                        <strong>Zdjęcie <?php echo $token; ?></strong>
                        <span class="admin-post-image-actions">
                            <button type="button" data-insert-image-token>Wstaw zdjęcie tutaj</button>
                            <button class="admin-message-quick-trash admin-post-image-remove" type="button" data-remove-post-image title="Usuń zdjęcie" aria-label="Usuń zdjęcie <?php echo $index + 1; ?>">
                                <img src="<?php echo escape_html(admin_asset_url('images/icons/kosz.svg')); ?>" alt="" aria-hidden="true">
                            </button>
                        </span>
                    </header>
                    <input type="hidden" name="existing_content_image_paths[]" value="<?php echo escape_html($contentImageItem['path']); ?>">
                    <div class="admin-crop-editor" data-crop-editor>
                        <img src="<?php echo escape_html(admin_asset_url($contentImageItem['path'])); ?>" alt="Podgląd zdjęcia <?php echo $index + 1; ?>" data-crop-image>
                        <div class="admin-crop-box" data-crop-box><span data-crop-handle="nw"></span><span data-crop-handle="n"></span><span data-crop-handle="ne"></span><span data-crop-handle="e"></span><span data-crop-handle="se"></span><span data-crop-handle="s"></span><span data-crop-handle="sw"></span><span data-crop-handle="w"></span></div>
                        <input type="hidden" name="existing_content_image_crops[]" value="<?php echo escape_html((string) $cropJson); ?>" data-crop-value>
                    </div>
                    <p class="admin-post-image-drag-hint">Użyj przycisku powyżej albo przeciągnij napis „Zdjęcie” na pole treści.</p>
                </article>
            <?php endforeach; ?>
            <?php $nextImageIndex = count($contentImageItems) + 1; ?>
            <article class="admin-post-content-image-card admin-post-content-image-input" data-post-image-card data-image-token="[[Z<?php echo $nextImageIndex; ?>]]" draggable="true">
                <header class="admin-post-image-card-header">
                    <strong>Nowe zdjęcie [[Z<?php echo $nextImageIndex; ?>]]</strong>
                    <span class="admin-post-image-actions">
                        <button type="button" data-insert-image-token>Wstaw zdjęcie tutaj</button>
                        <button class="admin-message-quick-trash admin-post-image-remove" type="button" data-remove-post-image title="Usuń zdjęcie" aria-label="Usuń nowe zdjęcie">
                            <img src="<?php echo escape_html(admin_asset_url('images/icons/kosz.svg')); ?>" alt="" aria-hidden="true">
                        </button>
                    </span>
                </header>
                <input id="content_image_<?php echo $nextImageIndex; ?>" type="file" name="content_images[]" accept="image/jpeg,image/png,image/webp" data-content-image-input>
                <div class="admin-crop-editor" data-crop-editor hidden>
                    <img alt="Podgląd nowego zdjęcia" data-crop-image>
                    <div class="admin-crop-box" data-crop-box><span data-crop-handle="nw"></span><span data-crop-handle="n"></span><span data-crop-handle="ne"></span><span data-crop-handle="e"></span><span data-crop-handle="se"></span><span data-crop-handle="s"></span><span data-crop-handle="sw"></span><span data-crop-handle="w"></span></div>
                    <input type="hidden" name="content_image_crops[]" value="" data-crop-value>
                </div>
                <p class="admin-post-image-drag-hint">Po wybraniu pliku ustaw kadr. Aby wstawić zdjęcie, użyj przycisku lub przeciągnij napis „Zdjęcie” na pole treści.</p>
            </article>
        </div>
        <button type="button" class="admin-post-add-image" data-add-post-image>＋ Dodaj kolejne zdjęcie</button>
        <label for="image">Zdjęcie główne postu</label>
        <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-main-image-input>
        <input type="hidden" name="remove_main_image" value="0" data-remove-main-image>
        <div class="admin-post-main-image-card" data-main-image-card>
            <header class="admin-post-image-card-header">
                <strong>Kadr zdjęcia głównego</strong>
                <button class="admin-message-quick-trash admin-post-image-remove" type="button" data-remove-main-image-button title="Usuń zdjęcie główne" aria-label="Usuń zdjęcie główne"<?php echo empty($post['image_path']) ? ' hidden' : ''; ?>>
                    <img src="<?php echo escape_html(admin_asset_url('images/icons/kosz.svg')); ?>" alt="" aria-hidden="true">
                </button>
            </header>
            <div class="admin-crop-editor" data-crop-editor<?php echo empty($post['image_path']) ? ' hidden' : ''; ?>>
                <img<?php echo !empty($post['image_path']) ? ' src="' . escape_html(admin_asset_url((string) $post['image_path'])) . '"' : ''; ?> alt="Podgląd zdjęcia głównego" data-crop-image>
                <div class="admin-crop-box" data-crop-box><span data-crop-handle="nw"></span><span data-crop-handle="n"></span><span data-crop-handle="ne"></span><span data-crop-handle="e"></span><span data-crop-handle="se"></span><span data-crop-handle="s"></span><span data-crop-handle="sw"></span><span data-crop-handle="w"></span></div>
                <input type="hidden" name="image_crop" value="<?php echo escape_html((string) json_encode($mainImageCrop, JSON_UNESCAPED_SLASHES)); ?>" data-crop-value>
            </div>
            <p class="admin-post-image-drag-hint">Przesuń ramkę lub złap za jej uchwyty, aby ustawić dokładny kadr.</p>
        </div>
        <label for="gallery_id">Galeria do podlinkowania (opcjonalnie)</label>
        <select id="gallery_id" name="gallery_id"><option value="">Bez galerii</option><?php foreach ($galleries as $galleryOption): ?><option value="<?php echo (int) $galleryOption['id']; ?>"<?php echo (int) ($post['gallery_id'] ?? 0) === (int) $galleryOption['id'] ? ' selected' : ''; ?>><?php echo escape_html($galleryOption['title']); ?></option><?php endforeach; ?></select>
        <label class="admin-post-published"><input type="checkbox" name="is_published"<?php echo $post === null || (int) $post['is_published'] === 1 ? ' checked' : ''; ?>> <span>Opublikuj post na stronie</span></label>
        <ul class="actions"><li><button type="submit">Zapisz post</button></li><li><a class="button" href="admin-post-category-editor.php?category=<?php echo $categoryId; ?>">Wróć do kategorii</a></li><?php if ($post !== null): ?><li><button class="admin-danger-action" type="submit" name="action" value="delete_post" form="delete-post-form">Usuń post</button></li><?php endif; ?></ul>
    </form>
    <?php if ($post !== null): ?><form id="delete-post-form" method="post" action="admin-post-editor.php?post=<?php echo $postId; ?>" onsubmit="return confirm('Usunąć ten post?');"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="delete_post"></form><?php endif; ?>
</section>
<?php admin_page_close(); ?>
