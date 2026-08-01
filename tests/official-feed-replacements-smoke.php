<?php

declare(strict_types=1);

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function replacement_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = bueno_database();
$blocked = ['https://www.jpl.nasa.gov/feeds/news/', 'https://www.nih.gov/news-releases/feed.xml'];
$query = $database->prepare('SELECT name FROM technical_sources WHERE is_active = 1 AND feed_url IN (?, ?)');
$query->execute($blocked);
replacement_assert($query->fetchAll() === [], 'Niedostępny endpoint JPL lub NIH nadal jest aktywny.');

foreach (['NASA Jet Propulsion Laboratory', 'NIH News Releases'] as $name) {
    $statement = $database->prepare('SELECT is_active, health_status, last_error FROM technical_sources WHERE name = ?');
    $statement->execute([$name]);
    $source = $statement->fetch();
    replacement_assert(is_array($source), 'Migracja usunęła historię źródła ' . $name . '.');
    replacement_assert((int) $source['is_active'] === 0, $name . ' nie zostało wyłączone.');
    replacement_assert($source['health_status'] === 'unavailable' && str_contains($source['last_error'], 'HTTP 403'), 'Brak diagnostyki 403 dla ' . $name . '.');
}

$nibib = $database->query("SELECT * FROM technical_sources WHERE name = 'NIBIB News'")->fetch();
replacement_assert(is_array($nibib) && (int) $nibib['is_active'] === 1, 'Oficjalny zamiennik NIBIB nie jest aktywny.');
replacement_assert($nibib['feed_url'] === 'https://www.nibib.nih.gov/rss', 'NIBIB używa nieoczekiwanego endpointu.');

$calls = 0;
try {
    fetch_remote_feed('https://example.org/feed', ['feed_max_attempts' => 3], static function () use (&$calls): never {
        $calls++;
        throw new FeedTransportException('HTTP 403', 'http_permanent', false, 403);
    });
    throw new RuntimeException('HTTP 403 nie został zgłoszony.');
} catch (FeedTransportException $exception) {
    replacement_assert($exception->category === 'http_permanent' && !$exception->transient && $calls === 1, 'HTTP 403 nie jest trwałą odmową bez retry.');
}

if (in_array('--live', $argv, true)) {
    $response = fetch_remote_feed((string) $nibib['feed_url'], $nibib);
    replacement_assert($response['status'] === 200, 'NIBIB nie zwrócił HTTP 200.');
    $items = parse_feed_document((string) $response['body'], $nibib);
    replacement_assert(count($items) > 0, 'NIBIB zwrócił pusty lub nieparsowalny kanał.');
    echo 'NIBIB_LIVE_OK items=' . count($items) . PHP_EOL;
}

echo "OFFICIAL_FEED_REPLACEMENTS_SMOKE_OK\n";
