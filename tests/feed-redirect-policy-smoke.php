<?php

declare(strict_types=1);

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function redirect_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function expect_redirect_category(callable $callback, string $category): void {
    try { $callback(); } catch (FeedTransportException $exception) {
        redirect_assert($exception->category === $category, 'Oczekiwano ' . $category . ', otrzymano ' . $exception->category);
        return;
    }
    throw new RuntimeException('Oczekiwano blokady ' . $category . '.');
}

$public = static fn(string $host): array => match ($host) {
    'origin.example', 'cdn.example' => ['93.184.216.34'],
    'mixed.example' => ['93.184.216.34', '127.0.0.1'],
    'private.example' => ['10.0.0.8'],
    default => [],
};
$visited = [feed_normalized_url('https://origin.example/feed') => true];
redirect_assert(feed_http_status_kind(304) === 'not_modified' && feed_http_status_kind(301) === 'redirect', 'HTTP 304 jest błędnie klasyfikowane jako redirect.');

$target = feed_redirect_url('https://origin.example/path/feed.xml', '../rss/latest.xml');
redirect_assert($target === 'https://origin.example/path/../rss/latest.xml', 'Nie rozwiązano względnego Location.');
redirect_assert(feed_normalized_url($target) === 'https://origin.example/rss/latest.xml', 'Normalizacja ścieżki daje fałszywą pętlę.');
redirect_assert(feed_validate_redirect_target('https://origin.example/feed', 'https://cdn.example/rss.xml', $visited, $public)[1] === 'cdn.example', 'Publiczny oficjalny redirect cross-host/CDN został zablokowany.');

foreach ([
    'https://private.example/feed' => 'private',
    'https://localhost/feed' => 'localhost',
    'https://mixed.example/feed' => 'dns_rebinding',
    'https://user:pass@cdn.example/feed' => 'userinfo',
    'https://cdn.example:8443/feed' => 'port',
] as $url => $case) {
    expect_redirect_category(static fn() => feed_validate_redirect_target('https://origin.example/feed', $url, $visited, $public), 'redirect_blocked');
}
expect_redirect_category(static fn() => feed_validate_redirect_target('https://origin.example/feed', 'http://cdn.example/feed', $visited, $public), 'redirect_blocked');
expect_redirect_category(static fn() => feed_validate_redirect_target('https://cdn.example/feed', 'https://origin.example/feed', $visited, $public), 'redirect_loop');

expect_redirect_category(static fn() => feed_assert_redirect_budget(0), 'redirect_limit');
foreach (['redirect_blocked', 'redirect_loop', 'redirect_limit'] as $category) {
    redirect_assert(feed_error_advice($category) !== feed_error_advice('unknown'), 'UI nie rozróżnia ' . $category . '.');
}

echo "FEED_REDIRECT_POLICY_SMOKE_OK\n";
