<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/contact-secrets.php';
require_once __DIR__ . '/admin-database.php';

function admin_reply_error_message(Throwable $exception): string
{
    $detail = $exception->getMessage();

    if (stripos($detail, 'connect error 10013') !== false
        || stripos($detail, 'could not connect to smtp host') !== false
        || stripos($detail, 'failed to connect to server') !== false) {
        return 'Nie udało się połączyć z Gmail SMTP. Połączenie wychodzące SMTP jest blokowane przez sieć, zaporę lub program antywirusowy na tym komputerze.';
    }

    if (stripos($detail, 'authenticate') !== false || stripos($detail, 'username') !== false) {
        return 'Gmail odrzucił dane logowania. W zakładce „Dane kontaktowe” zapisz nowe 16-znakowe hasło do aplikacji Gmail.';
    }

    return 'Nie udało się wysłać odpowiedzi. Spróbuj ponownie później.';
}

function create_admin_reply_mailer(array $settings, string $mailPassword, bool $allowLocalTlsFallback = false): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Username = $settings['email'];
    $mail->Password = $mailPassword;
    $mail->CharSet = 'UTF-8';

    if ($allowLocalTlsFallback) {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    return $mail;
}

function prepare_admin_reply(PHPMailer $mail, array $settings, string $email, string $name, string $subject, string $reply): void
{
    $presentation = get_site_style_settings();
    $siteName = trim((string) ($presentation['site_name'] ?? '')) ?: 'Strona WWW';
    $mail->setFrom($settings['email'], $siteName);
    $mail->addAddress($email, $name);
    $mail->isHTML(true);
    $mail->Subject = 'Re: ' . ($subject !== '' ? $subject : 'Wiadomość') . ' - ' . $siteName;
    $mail->Body = nl2br(htmlspecialchars($reply, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $mail->AltBody = $reply;
}

function send_admin_reply(string $email, string $name, string $subject, string $reply): void
{
    $settings = get_contact_settings();
    $mailPassword = $settings['mail_password'] !== null && $settings['mail_password'] !== ''
        ? $settings['mail_password']
        : SMTP_APP_PASSWORD;
    $mail = create_admin_reply_mailer($settings, $mailPassword);
    prepare_admin_reply($mail, $settings, $email, $name, $subject, $reply);

    try {
        $mail->send();
    } catch (Throwable $exception) {
        $isLocalRequest = in_array((string) ($_SERVER['SERVER_NAME'] ?? ''), ['localhost', '127.0.0.1', '::1'], true)
            || in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);

        if (!$isLocalRequest || stripos($exception->getMessage(), 'certificate verify failed') === false) {
            throw $exception;
        }

        // XAMPP on Windows may not have a CA bundle configured. This mirrors
        // the local-only fallback used by the contact form.
        $mail = create_admin_reply_mailer($settings, $mailPassword, true);
        prepare_admin_reply($mail, $settings, $email, $name, $subject, $reply);
        $mail->send();
    }
}
