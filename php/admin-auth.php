<?php

declare(strict_types=1);

define('ADMIN_CREDENTIALS_FILE', dirname(__DIR__) . '/data/admin-credentials.json');

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionDirectory = dirname(__DIR__) . '/data/sessions';

    if (!is_dir($sessionDirectory)) {
        mkdir($sessionDirectory, 0700, true);
    }

    if (is_dir($sessionDirectory) && is_writable($sessionDirectory)) {
        session_save_path($sessionDirectory);
    }

    session_name('cms_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_csrf'];
}

function admin_valid_csrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['admin_csrf'])
        && is_string($_POST['csrf'])
        && hash_equals($_SESSION['admin_csrf'], $_POST['csrf']);
}

function admin_credentials(): ?array
{
    if (!is_readable(ADMIN_CREDENTIALS_FILE)) {
        return null;
    }

    $credentials = json_decode((string) file_get_contents(ADMIN_CREDENTIALS_FILE), true);

    if (!is_array($credentials)
        || !isset($credentials['username'], $credentials['password_hash'])
        || !is_string($credentials['username'])
        || !is_string($credentials['password_hash'])) {
        return null;
    }

    return $credentials;
}

function save_admin_credentials(array $credentials): bool
{
    $directory = dirname(ADMIN_CREDENTIALS_FILE);
    $temporaryFile = tempnam($directory, 'admin-');

    if ($temporaryFile === false) {
        return false;
    }

    $contents = json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $written = file_put_contents($temporaryFile, $contents, LOCK_EX);

    if ($written === false || !rename($temporaryFile, ADMIN_CREDENTIALS_FILE)) {
        @unlink($temporaryFile);
        return false;
    }

    return true;
}

function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true;
}

function require_admin_login(): void
{
    start_admin_session();

    if (!admin_is_logged_in()) {
        header('Location: admin-login.php', true, 303);
        exit;
    }
}

function admin_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], (bool) $parameters['secure'], (bool) $parameters['httponly']);
    }

    session_destroy();
}

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
