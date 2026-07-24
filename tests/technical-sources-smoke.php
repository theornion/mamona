<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_SOURCES_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_SOURCES_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/php/admin-database.php';

function sources_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = bueno_database();
$token = bin2hex(random_bytes(5));
$sourceId = 0;

try {
    $seeded = list_technical_sources();
    sources_assert(count($seeded) >= 5, 'Brakuje pięciu źródeł startowych.');
    sources_assert(count(array_filter($seeded, static fn (array $source): bool => (int) $source['is_primary'] === 1)) >= 5, 'Źródła oficjalne nie są oznaczone jako pierwotne.');

    $secretBlocked = false;
    try {
        normalize_technical_source_url('https://example.org/feed?api_key=sekret', 'Kanał');
    } catch (InvalidArgumentException) {
        $secretBlocked = true;
    }
    sources_assert($secretBlocked, 'URL zawierający klucz API nie został zablokowany.');

    $httpBlocked = false;
    try {
        normalize_technical_source_url('http://example.org/feed', 'Kanał');
    } catch (InvalidArgumentException) {
        $httpBlocked = true;
    }
    sources_assert($httpBlocked, 'Nieszyfrowany URL nie został zablokowany.');

    $sourceId = save_technical_source([
        'name' => 'Test source ' . $token,
        'website_url' => 'https://example.org/',
        'feed_url' => 'https://example.org/feed-' . $token . '.xml',
        'source_type' => 'rss',
        'topic_category' => 'testing',
        'language' => 'pl',
        'credibility_level' => 3,
        'is_primary' => 0,
        'is_active' => 1,
    ]);
    sources_assert(find_technical_source($sourceId) !== null, 'Nie zapisano poprawnego źródła.');
    save_technical_source([
        'name' => 'Test source updated ' . $token,
        'website_url' => 'https://example.org/',
        'feed_url' => 'https://example.org/feed-' . $token . '.xml',
        'source_type' => 'api',
        'topic_category' => 'testing',
        'language' => 'pl',
        'credibility_level' => 4,
        'is_primary' => 0,
        'is_active' => 1,
    ], $sourceId);
    sources_assert(find_technical_source($sourceId)['source_type'] === 'api', 'Nie można edytować istniejącego źródła.');
    set_technical_source_active($sourceId, false);
    sources_assert((int) find_technical_source($sourceId)['is_active'] === 0, 'Nie można wyłączyć źródła.');
    sources_assert(!in_array($sourceId, array_column(list_technical_sources(true), 'id'), true), 'Wyłączone źródło jest zwracane jako aktywne.');

    record_technical_source_check($sourceId, false, 'Kontrolowany błąd źródła.');
    $failedSource = find_technical_source($sourceId);
    sources_assert(str_contains((string) $failedSource['last_error'], 'Kontrolowany błąd'), 'Nie zapisano błędu źródła.');
    sources_assert(count(list_technical_sources()) >= 6, 'Błąd jednego źródła naruszył pozostały rejestr.');
    record_technical_source_check($sourceId, true);
    $successfulSource = find_technical_source($sourceId);
    sources_assert($successfulSource['last_error'] === '', 'Sukces nie wyczyścił poprzedniego błędu.');
    sources_assert($successfulSource['last_success_at'] !== null, 'Nie zapisano daty sukcesu.');

    echo "TECHNICAL_SOURCES_SMOKE_OK\n";
} finally {
    if ($sourceId > 0) {
        $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
    }
}
