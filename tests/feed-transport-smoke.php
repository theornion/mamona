<?php

declare(strict_types=1);

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function transport_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$source = ['feed_max_attempts'=>3, 'feed_job_budget_seconds'=>30, 'feed_transfer_timeout_seconds'=>10];
$calls = 0;
$retryEvents = [];
$response = fetch_remote_feed('https://example.org/feed', $source,
    static function () use (&$calls): array {
        $calls++;
        if ($calls === 1) throw new FeedTransportException('timeout', 'timeout', true);
        return ['status'=>200, 'body'=>'<rss><channel/></rss>', 'bytes'=>22, 'duration_ms'=>1, 'headers'=>[]];
    },
    static function (...$arguments) use (&$retryEvents): void { $retryEvents[] = $arguments; }
);
transport_assert($calls === 2 && $response['attempts'] === 2 && count($retryEvents) === 1, 'Timeout nie został ponowiony dokładnie raz przed sukcesem.');

$calls = 0;
try {
    fetch_remote_feed('https://example.org/feed', $source, static function () use (&$calls): never {
        $calls++;
        throw new FeedTransportException('HTTP 403', 'http_permanent', false, 403);
    });
    throw new RuntimeException('HTTP 403 nie został zgłoszony.');
} catch (FeedTransportException $exception) {
    transport_assert($exception->httpStatus === 403 && $calls === 1, 'Trwały HTTP 403 został ponowiony.');
}

$notModified = fetch_remote_feed('https://example.org/feed', $source, static fn (): array => [
    'status'=>304, 'body'=>'', 'bytes'=>0, 'duration_ms'=>1, 'headers'=>[],
]);
transport_assert($notModified['status'] === 304, 'HTTP 304 nie jest poprawnym wynikiem bez danych.');

$calls = 0; $retryDelay = null;
fetch_remote_feed('https://example.org/feed', $source, static function () use (&$calls): array {
    if ($calls++ === 0) throw new FeedTransportException('HTTP 429', 'http_temporary', true, 429, 0, 0);
    return ['status'=>200, 'body'=>'<rss><channel/></rss>', 'bytes'=>22, 'duration_ms'=>1, 'headers'=>[]];
}, static function (int $attempt, int $maximum, int $delay) use (&$retryDelay): void { $retryDelay = $delay; });
transport_assert($calls === 2 && $retryDelay === 0, 'HTTP 429 nie respektuje Retry-After.');

$ssrfBlocked = false;
try { assert_public_feed_url('https://127.0.0.1/feed'); } catch (Throwable) { $ssrfBlocked = true; }
transport_assert($ssrfBlocked, 'Ochrona SSRF dopuściła adres lokalny.');

$invalidBlocked = false;
try { parse_feed_document('<rss><channel>', ['name'=>'test']); }
catch (RuntimeException) { $invalidBlocked = true; }
transport_assert($invalidBlocked, 'Niepełny XML dotarł jako poprawny feed.');

$oversizeBlocked = false;
try { parse_feed_document(str_repeat('x', FEED_RESPONSE_MAX_BYTES + 1), ['name'=>'test']); }
catch (RuntimeException) { $oversizeBlocked = true; }
transport_assert($oversizeBlocked, 'Limit rozmiaru parsera nie działa.');

$sourceCode = (string) file_get_contents(dirname(__DIR__) . '/php/feed-ingestion-service.php');
foreach (['CURLOPT_SSL_VERIFYPEER => true', "CURLOPT_ENCODING => ''", 'CURLOPT_LOW_SPEED_LIMIT', 'If-None-Match:', 'If-Modified-Since:', 'CURLOPT_FOLLOWLOCATION => false'] as $contract) {
    transport_assert(str_contains($sourceCode, $contract), 'Brak kontraktu bezpieczeństwa/transportu: ' . $contract);
}

echo "FEED_TRANSPORT_SMOKE_OK\n";
