<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (admin_valid_csrf()) {
        admin_logout();
    }

    header('Location: admin-login.php', true, 303);
    exit;
}

$error = '';
$success = '';
$credentials = admin_credentials();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif ($credentials === null) {
        $error = 'Nie można odczytać danych administratora.';
    } else {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, $credentials['password_hash'])) {
            $error = 'Aktualne hasło jest nieprawidłowe.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Nowe hasło musi mieć co najmniej 8 znaków.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Nowe hasła nie są takie same.';
        } else {
            $credentials['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);

            if (save_admin_credentials($credentials)) {
                $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
                $success = 'Hasło zostało zmienione.';
            } else {
                $error = 'Nie udało się zapisać nowego hasła. Sprawdź uprawnienia katalogu data na serwerze.';
            }
        }
    }
}
admin_page_open('Profil administratora', 'profile');
?>
<section class="post admin-card">
                <div class="admin-profile-top">
                    <header class="major admin-heading">
                        <p class="admin-kicker">CMS</p>
                        <h1>Profil administratora</h1>
                        <p>Zalogowano jako <strong><?php echo escape_html((string) $_SESSION['admin_username']); ?></strong>.</p>
                    </header>
                    <form method="post" action="admin-profile.php" class="admin-logout-form">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <button type="submit" name="logout" value="1">Wyloguj</button>
                    </form>
                </div>

                <hr>
                <h2>Zmień hasło</h2>

                <?php if ($success !== ''): ?>
                    <p class="admin-notice is-success" role="status"><?php echo escape_html($success); ?></p>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
                <?php endif; ?>

                <form method="post" action="admin-profile.php" class="admin-form">
                    <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                    <div class="fields">
                        <div class="field">
                            <label for="current_password">Aktualne hasło</label>
                            <input type="password" name="current_password" id="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="field">
                            <label for="new_password">Nowe hasło</label>
                            <input type="password" name="new_password" id="new_password" autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="field">
                            <label for="confirm_password">Powtórz nowe hasło</label>
                            <input type="password" name="confirm_password" id="confirm_password" autocomplete="new-password" minlength="8" required>
                        </div>
                    </div>
                    <ul class="actions special">
                        <li><button type="submit" name="change_password" value="1">Zapisz nowe hasło</button></li>
                    </ul>
                </form>
</section>
<?php admin_page_close(); ?>
