<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_TRUST_SMOKE') !== '1') {
    fwrite(STDOUT, "SKIP: ustaw CMS_ALLOW_TRUST_SMOKE=1, aby uruchomić test stron zaufania.\n");
    exit(0);
}

require_once dirname(__DIR__) . '/php/admin-database.php';

function trust_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = dirname(__DIR__) . '/data/trust-pages-smoke-' . bin2hex(random_bytes(4));
$files = [];

try {
    $files = write_trust_pages($directory);
    foreach (array_keys(TRUST_PUBLIC_PAGES) as $filename) {
        trust_smoke_assert(in_array($filename, $files, true), 'Brakuje strony: ' . $filename);
        trust_smoke_assert(is_file($directory . '/' . $filename), 'Generator nie zapisał: ' . $filename);
    }

    $about = (string) file_get_contents($directory . '/o-serwisie.html');
    $privacy = (string) file_get_contents($directory . '/polityka-prywatnosci.html');
    $corrections = (string) file_get_contents($directory . '/korekty-i-aktualizacje.html');
    trust_smoke_assert(str_contains($about, 'trust-footer-links'), 'Strony nie zawierają nawigacji zaufania.');
    trust_smoke_assert(str_contains($privacy, 'Twoje prawa'), 'Polityka prywatności nie opisuje praw użytkownika.');
    trust_smoke_assert(str_contains($corrections, 'Korekta: tytuł lub URL'), 'Brakuje jednoznacznej procedury korekty.');
    trust_smoke_assert(str_contains($corrections, 'fragment, którego dotyczy zgłoszenie'), 'Procedura korekty nie określa danych zgłoszenia.');

    $authors = list_authors(true);
    foreach ($authors as $author) {
        $filename = trust_author_filename($author);
        trust_smoke_assert(is_file($directory . '/' . $filename), 'Brakuje lokalnego profilu autora: ' . $filename);
        $profile = (string) file_get_contents($directory . '/' . $filename);
        trust_smoke_assert(str_contains($profile, trust_escape((string) $author['name'])), 'Profil nie zawiera prawdziwej nazwy autora.');
    }

    $issues = trust_configuration_issues(true);
    if ($issues !== []) {
        trust_smoke_assert(
            str_contains((string) $issues[0]['message'], 'BLOKADA STARTU PRODUKCYJNEGO'),
            'Braki nie są oznaczone jako blokada produkcyjna.'
        );
        try {
            assert_trust_configuration_allows_publication(true);
            throw new RuntimeException('Braki danych nie zablokowały publikacji produkcyjnej.');
        } catch (RuntimeException $exception) {
            trust_smoke_assert(
                str_contains($exception->getMessage(), 'BLOKADA STARTU PRODUKCYJNEGO'),
                'Blokada zwróciła nieczytelny komunikat.'
            );
        }
    }

    $sampleAuthor = $authors[0] ?? null;
    if (is_array($sampleAuthor)) {
        $authorLink = trust_author_filename($sampleAuthor);
        trust_smoke_assert(
            str_contains(trust_render_author_profile($sampleAuthor), 'polityka-redakcyjna.html'),
            'Profil autora nie prowadzi do polityki redakcyjnej.'
        );
        trust_smoke_assert($authorLink !== '', 'Nazwa pliku autora jest pusta.');
    }

    $post = bueno_database()->query(
        'SELECT * FROM posts WHERE author_id IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1'
    )->fetch();
    if (is_array($post)) {
        $postAuthor = find_author((int) $post['author_id']);
        $postHtml = render_post_page_html($post, true);
        trust_smoke_assert(str_contains($postHtml, 'polityka-redakcyjna.html'), 'Artykuł nie linkuje polityki redakcyjnej.');
        trust_smoke_assert(
            is_array($postAuthor) && str_contains($postHtml, trust_author_filename($postAuthor)),
            'Artykuł nie linkuje lokalnego profilu autora.'
        );
    }

    fwrite(STDOUT, 'OK: wygenerowano ' . count($files) . " stron zaufania i profili; procedura korekt oraz blokery działają.\n");
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
