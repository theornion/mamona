<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function save_uploaded_social_icon(array $upload): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Nie wybrano nowej ikony.');
    }

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new RuntimeException('Nie udało się odebrać pliku ikony.');
    }

    if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Ikona może mieć maksymalnie 2 MB.');
    }

    $temporaryPath = (string) $upload['tmp_name'];
    $contents = file_get_contents($temporaryPath);

    if ($contents === false) {
        throw new RuntimeException('Nie udało się odczytać pliku ikony.');
    }

    $extension = '';
    $imageInfo = @getimagesize($temporaryPath);
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    $rasterTypes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    if (isset($rasterTypes[$mime])) {
        $extension = $rasterTypes[$mime];
    } elseif (preg_match('/^\s*<svg\b/i', $contents)) {
        if (preg_match('/<\s*(script|foreignObject|iframe|object|embed)\b|\bon\w+\s*=|javascript\s*:|<!ENTITY/i', $contents)) {
            throw new RuntimeException('Plik SVG zawiera niedozwolone elementy.');
        }
        $extension = 'svg';
    } else {
        throw new RuntimeException('Dodaj ikonę w formacie SVG, PNG, JPG lub WebP.');
    }

    $directory = dirname(__DIR__) . '/images/social';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu ikon.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

    if (!move_uploaded_file($temporaryPath, $directory . '/' . $fileName)) {
        throw new RuntimeException('Nie udało się zapisać ikony.');
    }

    return 'images/social/' . $fileName;
}

function remove_social_icon(string $iconPath): void
{
    if (!str_starts_with($iconPath, 'images/social/')) {
        return;
    }

    $filePath = dirname(__DIR__) . '/' . $iconPath;

    if (is_file($filePath)) {
        unlink($filePath);
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        $action = (string) ($_POST['action'] ?? 'update');
        $socialId = filter_input(INPUT_POST, 'social_id', FILTER_VALIDATE_INT) ?: 0;
        $social = in_array($action, ['update', 'delete'], true) && $socialId > 0 ? find_social_medium($socialId) : null;
        $name = trim((string) ($_POST['name'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        $isVisible = isset($_POST['is_visible']);
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            $error = 'Nieprawidłowa operacja formularza.';
        } elseif (in_array($action, ['update', 'delete'], true) && $social === null) {
            $error = 'Nie znaleziono wybranego profilu social media.';
        } elseif ($action === 'delete') {
            $deletedSocial = delete_social_medium($socialId);
            if ($deletedSocial !== null) {
                remove_social_icon((string) ($deletedSocial['icon_path'] ?? ''));
            }
            header('Location: admin-social.php?deleted=' . $socialId, true, 303);
            exit;
        } elseif ($name === '') {
            $error = 'Podaj nazwę social media.';
        } elseif ($nameLength > 80) {
            $error = 'Nazwa social media może mieć maksymalnie 80 znaków.';
        } elseif ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $error = 'Podaj pełny link rozpoczynający się od https:// lub http://.';
        } else {
            $newIconPath = null;

            try {
                $upload = $_FILES['icon'] ?? [];

                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newIconPath = save_uploaded_social_icon($upload);
                }

                if ($action === 'create') {
                    if ($newIconPath === null) {
                        throw new RuntimeException('Wybierz plik ikony dla nowego social media.');
                    }

                    $createdId = create_social_medium($name, $url, $isVisible, $newIconPath);
                    header('Location: admin-social.php?created=' . $createdId, true, 303);
                    exit;
                }

                update_social_medium($socialId, $name, $url, $isVisible, $newIconPath);

                if ($newIconPath !== null) {
                    remove_social_icon((string) $social['icon_path']);
                }

                header('Location: admin-social.php?saved=' . $socialId, true, 303);
                exit;
            } catch (Throwable $exception) {
                if ($newIconPath !== null) {
                    remove_social_icon($newIconPath);
                }
                $error = $exception->getMessage();
            }
        }
    }
}

$socialMedia = list_social_media();

admin_page_open('Social media', 'social');
?>
<section class="post admin-card admin-social-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Dane kontaktowe</p>
        <h1>Social media</h1>
        <p>Ustaw linki, widoczność oraz ikony profili wyświetlanych w nawigacji i stopce strony.</p>
    </header>

    <?php if (isset($_GET['saved'])): ?>
        <p class="admin-notice is-success" role="status">Ustawienia social media zostały zapisane.</p>
    <?php endif; ?>
    <?php if (isset($_GET['created'])): ?>
        <p class="admin-notice is-success" role="status">Nowe social media zostało dodane.</p>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <p class="admin-notice is-success" role="status">Social media zostało usunięte.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
    <?php endif; ?>

    <div class="admin-social-list">
        <form method="post" action="admin-social.php" enctype="multipart/form-data" class="admin-form admin-social-card admin-social-create-card">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">

            <div class="admin-social-card-heading">
                <div class="admin-social-icon-preview admin-social-add-preview" aria-hidden="true">+</div>
                <div>
                    <h2>Dodaj nowe social media</h2>
                    <label class="admin-social-visibility">
                        <input type="checkbox" name="is_visible" value="1" checked>
                        <span class="admin-toggle-track" aria-hidden="true"><span class="admin-toggle-knob"></span></span>
                        <span class="admin-toggle-label">Widoczne na stronie</span>
                    </label>
                </div>
            </div>

            <label for="new-social-name">Nazwa</label>
            <input type="text" id="new-social-name" name="name" maxlength="80" required placeholder="np. YouTube">

            <label for="new-social-url">Link do profilu</label>
            <input type="url" id="new-social-url" name="url" placeholder="https://...">

            <label for="new-social-icon">Plik ikony</label>
            <input type="file" id="new-social-icon" name="icon" accept="image/svg+xml,image/png,image/jpeg,image/webp" required>
            <p class="admin-social-help">Dodaj ikonę SVG, PNG, JPG lub WebP, maksymalnie 2 MB.</p>

            <ul class="actions">
                <li><button type="submit" name="action" value="create">Dodaj social media</button></li>
            </ul>
        </form>

        <?php foreach ($socialMedia as $social): ?>
            <form method="post" action="admin-social.php" enctype="multipart/form-data" class="admin-form admin-social-card">
                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                <input type="hidden" name="social_id" value="<?php echo (int) $social['id']; ?>">

                <div class="admin-social-card-heading">
                    <div class="admin-social-icon-preview" aria-hidden="true">
                        <?php if ($social['icon_path'] !== ''): ?>
                            <img src="<?php echo escape_html(admin_asset_url($social['icon_path'])); ?>" alt="">
                        <?php else: ?>
                            <span class="icon brands <?php echo escape_html($social['icon_class']); ?>"></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2><?php echo escape_html($social['name']); ?></h2>
                        <label class="admin-social-visibility">
                            <input type="checkbox" name="is_visible" value="1"<?php echo (int) $social['is_visible'] === 1 ? ' checked' : ''; ?>>
                            <span class="admin-toggle-track" aria-hidden="true"><span class="admin-toggle-knob"></span></span>
                            <span class="admin-toggle-label">Widoczne na stronie</span>
                        </label>
                    </div>
                </div>

                <label for="name-<?php echo (int) $social['id']; ?>">Nazwa</label>
                <input type="text" id="name-<?php echo (int) $social['id']; ?>" name="name" maxlength="80" required value="<?php echo escape_html($social['name']); ?>">

                <label for="url-<?php echo (int) $social['id']; ?>">Link do profilu</label>
                <input type="url" id="url-<?php echo (int) $social['id']; ?>" name="url" value="<?php echo escape_html($social['url']); ?>" placeholder="https://...">

                <label for="icon-<?php echo (int) $social['id']; ?>">Nowy plik ikony</label>
                <input type="file" id="icon-<?php echo (int) $social['id']; ?>" name="icon" accept="image/svg+xml,image/png,image/jpeg,image/webp">
                <p class="admin-social-help">Pozostaw puste, aby zachować obecną ikonę. Obsługiwane formaty: SVG, PNG, JPG i WebP.</p>

                <ul class="actions">
                    <li><button type="submit" name="action" value="update">Zapisz <?php echo escape_html($social['name']); ?></button></li>
                    <li><button type="submit" name="action" value="delete" class="admin-danger-action" formnovalidate onclick="return window.confirm('Usunąć to social media?');">Usuń</button></li>
                </ul>
            </form>
        <?php endforeach; ?>
    </div>
</section>
<?php admin_page_close(); ?>
