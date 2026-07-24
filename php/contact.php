<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/contact-secrets.php';
require __DIR__ . '/admin-database.php';

header('Content-Type: application/json; charset=UTF-8');

const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const FROM_NAME = 'Formularz kontaktowy';
const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

function respond(bool $ok, string $message, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function isLocalRequest(): bool
{
    $serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
    $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $localHosts = ['localhost', '127.0.0.1', '::1'];

    return in_array($serverName, $localHosts, true)
        || in_array($serverAddr, $localHosts, true)
        || in_array($remoteAddr, $localHosts, true);
}

function isTurnstileConfigured(): bool
{
    return TURNSTILE_SECRET_KEY !== '' && strpos(TURNSTILE_SECRET_KEY, 'TU_WKLEJ') !== 0;
}

function verifyTurnstileToken(string $token): bool
{
    if (!isTurnstileConfigured()) {
        error_log('Bueno contact form: Turnstile secret key is not configured.');
        return true;
    }

    if ($token === '') {
        return false;
    }

    $payload = http_build_query([
        'secret' => TURNSTILE_SECRET_KEY,
        'response' => $token,
        'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);

    if (function_exists('curl_init')) {
        $curl = curl_init(TURNSTILE_VERIFY_URL);

        if ($curl === false) {
            error_log('Bueno contact form: Turnstile cURL initialization failed. Allowing message fallback.');
            return true;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            error_log('Bueno contact form: Turnstile verification service unavailable. HTTP ' . $httpCode . ' ' . $curlError . '. Allowing message fallback.');
            return true;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        $response = file_get_contents(TURNSTILE_VERIFY_URL, false, $context);

        if ($response === false) {
            error_log('Bueno contact form: Turnstile verification request failed. Allowing message fallback.');
            return true;
        }
    }

    $result = json_decode((string) $response, true);

    if (!is_array($result)) {
        error_log('Bueno contact form: Turnstile verification returned invalid JSON. Allowing message fallback.');
        return true;
    }

    return is_array($result) && ($result['success'] ?? false) === true;
}

function createMailer(string $name, string $email, string $subject, string $message): PHPMailer
{
    $settings = get_contact_settings();
    $mailPassword = $settings['mail_password'] !== null && $settings['mail_password'] !== ''
        ? $settings['mail_password']
        : SMTP_APP_PASSWORD;
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Username = $settings['email'];
    $mail->Password = $mailPassword;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($settings['email'], FROM_NAME);
    $mail->addAddress($settings['email']);
    $mail->addReplyTo($email, $name);

    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    $mail->isHTML(true);
    $mail->Subject = $subject . ' - Formularz kontaktowy';
    $mail->Body = <<<HTML
<h2>Nowa wiadomość z formularza kontaktowego</h2>
<p><strong>Imię:</strong> {$safeName}</p>
<p><strong>Temat:</strong> {$safeSubject}</p>
<p><strong>Email:</strong> {$safeEmail}</p>
<p><strong>Wiadomość:</strong><br>{$safeMessage}</p>
HTML;
    $mail->AltBody = "Imię: {$name}\nTemat: {$subject}\nEmail: {$email}\n\nWiadomość:\n{$message}";

    return $mail;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Nieprawidłowa metoda żądania.', 405);
}

$name = trim(strip_tags((string) ($_POST['name'] ?? '')));
$email = trim((string) ($_POST['email'] ?? ''));
$subject = preg_replace('/[\r\n]+/', ' ', trim(strip_tags((string) ($_POST['subject'] ?? '')))) ?? '';
$message = trim(strip_tags((string) ($_POST['message'] ?? '')));
$website = trim((string) ($_POST['website'] ?? ''));
$turnstileToken = trim((string) ($_POST['cf-turnstile-response'] ?? ''));
$turnstileUnavailable = (string) ($_POST['turnstile-unavailable'] ?? '') === '1';

if ($website !== '') {
    respond(true, 'Dziękujemy, wiadomość została wysłana.');
}

if ($name === '') {
    respond(false, 'Podaj imię.', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Podaj poprawny adres email.', 422);
}

if (strcasecmp($email, get_contact_settings()['email']) === 0) {
    respond(false, 'Podaj swój własny adres e-mail.', 422);
}

$subjectLength = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);

if ($subjectLength < 2 || $subjectLength > 150) {
    respond(false, 'Temat musi mieć od 2 do 150 znaków.', 422);
}

$messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

if ($messageLength < 5) {
    respond(false, 'Wiadomość musi mieć co najmniej 5 znaków.', 422);
}

if ($messageLength > 3000) {
    respond(false, 'Wiadomość może mieć maksymalnie 3000 znaków.', 422);
}

if ($turnstileUnavailable && $turnstileToken === '') {
    error_log('Bueno contact form: Turnstile unavailable in browser. Allowing message fallback.');
} elseif (!verifyTurnstileToken($turnstileToken)) {
    respond(false, 'Nie udało się potwierdzić captcha. Spróbuj ponownie.', 422);
}

$messageId = 0;

try {
    $messageId = save_contact_message($name, $email, $subject, $message);
} catch (Throwable $exception) {
    error_log('Contact form database write failed: ' . $exception->getMessage());
    respond(false, 'Nie udało się zapisać wiadomości. Spróbuj ponownie później.', 500);
}

try {
    $mail = createMailer($name, $email, $subject, $message);
    $mail->send();

    mark_contact_email_sent($messageId);

    respond(true, 'Dziękujemy, wiadomość została wysłana.');
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();

    if (isLocalRequest() && stripos($errorMessage, 'certificate verify failed') !== false) {
        try {
            $mail = createMailer($name, $email, $subject, $message);
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
            $mail->send();

            mark_contact_email_sent($messageId);

            error_log('Bueno contact form: local TLS certificate verification disabled fallback used. Configure CA bundle in PHP for a permanent fix.');
            respond(true, 'Dziękujemy, wiadomość została wysłana.');
        } catch (Throwable $fallbackException) {
            error_log('Błąd wysyłki formularza kontaktowego po fallbacku TLS: ' . $fallbackException->getMessage());
            respond(false, 'Nie udało się wysłać wiadomości. Spróbuj ponownie później.', 500);
        }
    }

    error_log('Błąd wysyłki formularza kontaktowego: ' . $errorMessage);
    respond(false, 'Nie udało się wysłać wiadomości. Spróbuj ponownie później.', 500);
}
