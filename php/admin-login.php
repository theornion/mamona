<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

start_admin_session();

if (admin_is_logged_in()) {
    header('Location: admin-posts.php', true, 303);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Spróbuj ponownie.';
    } else {
        $credentials = admin_credentials();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($credentials !== null
            && hash_equals($credentials['username'], $username)
            && password_verify($password, $credentials['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_username'] = $credentials['username'];
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

            if (password_needs_rehash($credentials['password_hash'], PASSWORD_DEFAULT)) {
                $credentials['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                save_admin_credentials($credentials);
            }

            header('Location: admin-posts.php', true, 303);
            exit;
        }

        $error = 'Nieprawidłowa nazwa użytkownika lub hasło.';
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Logowanie administratora | CMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="stylesheet" href="../assets/css/main.css?v=bueno-release-20260721c">
</head>
<body class="is-preload admin-page admin-login-page">
    <div id="wrapper" class="fade-in">
        <main id="main" class="admin-main">
            <section class="post admin-card">
                <header class="major admin-heading">
                    <p class="admin-kicker">CMS</p>
                    <h1>Panel administratora</h1>
                    <p>Zaloguj się, aby przejść do swojego profilu.</p>
                </header>

                <?php if ($error !== ''): ?>
                    <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
                <?php endif; ?>

                <form method="post" action="admin-login.php" class="admin-form">
                    <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                    <div class="fields">
                        <div class="field">
                            <label for="username">Nazwa użytkownika</label>
                            <input type="text" name="username" id="username" autocomplete="username" required autofocus>
                        </div>
                        <div class="field">
                            <label for="password">Hasło</label>
                            <input type="password" name="password" id="password" autocomplete="current-password" required>
                        </div>
                    </div>
                    <ul class="actions special">
                        <li><button type="submit">Zaloguj się</button></li>
                    </ul>
                </form>
            </section>
        </main>
    </div>
    <script src="../assets/js/jquery.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/breakpoints.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/browser.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/util.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/main.js?v=bueno-release-20260721c"></script>
</body>
</html>
