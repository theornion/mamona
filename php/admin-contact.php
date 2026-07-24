<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        $address = trim((string) ($_POST['address'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $mailPassword = preg_replace('/\s+/', '', (string) ($_POST['mail_password'] ?? ''));
        $changes = [];

        if ($address !== '') {
            if (strlen($address) > 500) {
                $error = 'Adres jest zbyt długi.';
            } else {
                $changes['address'] = $address;
            }
        }

        if ($error === '' && $phone !== '') {
            if (!preg_match('/^[0-9+(). -]{5,40}$/', $phone)) {
                $error = 'Podaj poprawny numer telefonu.';
            } else {
                $changes['phone'] = $phone;
            }
        }

        if ($error === '' && $email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Podaj poprawny adres e-mail.';
            } else {
                $changes['email'] = $email;
            }
        }

        if ($error === '' && $mailPassword !== '') {
            if (strlen($mailPassword) !== 16) {
                $error = 'Hasło aplikacji Gmail musi mieć dokładnie 16 znaków.';
            } else {
                $changes['mail_password'] = $mailPassword;
            }
        }

        if ($error === '' && $changes === []) {
            $error = 'Wpisz przynajmniej jedną wartość do zmiany.';
        }

        if ($error === '') {
            update_contact_settings($changes);
            header('Location: admin-contact.php?saved=1', true, 303);
            exit;
        }
    }
}

$settings = get_contact_settings();

admin_page_open('Dane kontaktowe', 'contact');
?>
<section class="post admin-card">
    <header class="major admin-heading">
        <p class="admin-kicker">CMS</p>
        <h1>Dane kontaktowe</h1>
        <p>Możesz zmienić pojedynczą wartość albo kilka naraz. Puste pola pozostaną bez zmian.</p>
    </header>

    <?php if (isset($_GET['saved'])): ?>
        <p class="admin-notice is-success" role="status">Dane kontaktowe zostały zapisane.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
    <?php endif; ?>

    <form method="post" action="admin-contact.php" class="admin-form admin-contact-form" id="contactSettingsForm">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">

        <div class="admin-contact-setting">
            <h2>Adres</h2>
            <p>Obecny adres:<br><strong><?php echo nl2br(escape_html($settings['address'])); ?></strong></p>
            <label for="address">Nowy adres</label>
            <textarea name="address" id="address" rows="3" placeholder="Wpisz nowy adres..."></textarea>
        </div>

        <div class="admin-contact-setting">
            <h2>Telefon</h2>
            <p>Obecny telefon: <strong><?php echo escape_html($settings['phone']); ?></strong></p>
            <label for="phone">Nowy telefon</label>
            <input type="text" name="phone" id="phone" inputmode="tel" placeholder="Wpisz nowy numer telefonu...">
        </div>

        <div class="admin-contact-setting">
            <h2>E-mail</h2>
            <p>Obecny mail: <strong><?php echo escape_html($settings['email']); ?></strong></p>
            <label for="email">Nowy e-mail</label>
            <input type="email" name="email" id="email" placeholder="Wpisz nowy e-mail...">
        </div>

        <div class="admin-contact-setting">
            <h2>Hasło do maila</h2>
            <p>Nie wyświetlamy obecnego hasła. Aby wygenerować hasło aplikacji, najpierw włącz w Koncie Google <strong>weryfikację dwuetapową</strong>. Następnie przejdź do: Konto Google → Bezpieczeństwo → Hasła do aplikacji i wygeneruj <strong>16-znakowe hasło do aplikacji</strong>.</p>
            <label for="mail_password">Nowe hasło aplikacji Gmail</label>
            <input type="password" name="mail_password" id="mail_password" autocomplete="new-password" placeholder="Wpisz lub wklej nowe 16-znakowe hasło..." aria-describedby="mail-password-help">
            <p id="mail-password-help" class="admin-password-help">Spacje z hasła w formacie 4 × 4 znaki są automatycznie usuwane przed zapisem.</p>
        </div>

        <ul class="actions special">
            <li><button type="submit">Zapisz zmiany</button></li>
            <li><a class="button" href="admin-social.php">Edytuj social media</a></li>
        </ul>
    </form>
</section>
<script>
    (function () {
        var passwordInput = document.getElementById('mail_password');

        if (!passwordInput) return;

        passwordInput.addEventListener('input', function () {
            var cleanedValue = passwordInput.value.replace(/\s+/g, '');

            if (cleanedValue !== passwordInput.value) {
                passwordInput.value = cleanedValue;
            }
        });
    }());
</script>
<?php admin_page_close(); ?>
