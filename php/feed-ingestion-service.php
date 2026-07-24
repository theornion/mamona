<?php

declare(strict_types=1);

const FEED_RESPONSE_MAX_BYTES = 2097152;
const FEED_REQUEST_TIMEOUT_SECONDS = 12;
const FEED_MAX_ITEMS_PER_SOURCE = 50;

function assert_public_feed_url(string $url): array
{
    $url = normalize_technical_source_url($url, 'URL kanału');
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        throw new InvalidArgumentException('Adres kanału nie może wskazywać lokalnego hosta.');
    }

    $addresses = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses[] = $host;
    } else {
        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($address !== '') {
                $addresses[] = $address;
            }
        }
    }
    $addresses = array_values(array_unique($addresses));
    if ($addresses === []) {
        throw new RuntimeException('Nie można rozwiązać publicznego adresu kanału.');
    }
    foreach ($addresses as $address) {
        if (!filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            throw new InvalidArgumentException('Adres kanału wskazuje prywatny lub zastrzeżony zasób sieciowy.');
        }
    }

    return [$url, $host, $addresses[0]];
}

function fetch_remote_feed(string $url, int $redirectsRemaining = 2): string
{
    [$url, $host, $address] = assert_public_feed_url($url);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $body = '';
    $responseHeaders = [];
    $tooLarge = false;
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić klienta HTTP.');
    }
    $resolveAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => FEED_REQUEST_TIMEOUT_SECONDS,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MamonaFeedReader/1.0; +https://example.com/)',
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9'],
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolveAddress],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > FEED_RESPONSE_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    $caBundle = trim((string) app_config('feed_ca_bundle'));
    if ($caBundle !== '') {
        if (!is_file($caBundle)) {
            curl_close($curl);
            throw new RuntimeException('CMS_FEED_CA_BUNDLE nie wskazuje istniejącego pliku.');
        }
        $curlOptions[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($curl, $curlOptions);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($tooLarge) {
        throw new RuntimeException('Odpowiedź kanału przekracza limit 2 MB.');
    }
    if ($success === false) {
        throw new RuntimeException('Błąd pobierania kanału: ' . ($error !== '' ? $error : 'nieznany błąd HTTP'));
    }
    if ($status >= 300 && $status < 400) {
        $location = (string) ($responseHeaders['location'] ?? '');
        if ($redirectsRemaining <= 0 || $location === '' || !str_starts_with($location, 'https://')) {
            throw new RuntimeException('Kanał zwrócił niedozwolone lub zbyt liczne przekierowanie.');
        }
        return fetch_remote_feed($location, $redirectsRemaining - 1);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Kanał zwrócił HTTP ' . $status . '.');
    }
    if (trim($body) === '') {
        throw new RuntimeException('Kanał zwrócił pustą odpowiedź.');
    }

    return $body;
}

function feed_text(?DOMNode $context, string $expression, DOMXPath $xpath): string
{
    if ($context === null) {
        return '';
    }
    $nodes = $xpath->query($expression, $context);
    if ($nodes === false || $nodes->length === 0) {
        return '';
    }

    return trim((string) $nodes->item(0)?->textContent);
}

function normalize_feed_summary(string $summary): string
{
    $summary = html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $summary = preg_replace('/\s+/u', ' ', $summary) ?? '';

    return mb_substr(trim($summary), 0, 2000);
}

function normalize_feed_date(string $value): ?string
{
    if (trim($value) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function normalize_feed_item(array $item, array $source): array
{
    $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 500);
    $url = trim((string) ($item['url'] ?? ''));
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($title === '' || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Wpis feedu nie ma poprawnego tytułu lub adresu.');
    }
    $summary = normalize_feed_summary((string) ($item['summary'] ?? ''));
    $externalId = mb_substr(trim((string) ($item['external_id'] ?? '')), 0, 1000);
    if ($externalId === '') {
        $externalId = $url;
    }
    // The source registry owns the canonical taxonomy for the editorial
    // profile. Publisher labels vary too much to drive scoring reliably.
    $category = mb_substr(trim((string) ($source['topic_category'] ?? $item['category'] ?? 'technology')), 0, 100);
    $hashInput = mb_strtolower($title) . "\n" . mb_strtolower($summary);

    return [
        'external_id' => $externalId,
        'source_url' => $url,
        'title' => $title,
        'source_name' => (string) $source['name'],
        'published_at' => normalize_feed_date((string) ($item['published_at'] ?? '')),
        'summary' => $summary,
        'category' => $category,
        'content_hash' => hash('sha256', $hashInput),
    ];
}

function parse_feed_document(string $xml, array $source): array
{
    if (strlen($xml) > FEED_RESPONSE_MAX_BYTES) {
        throw new RuntimeException('Odpowiedź kanału przekracza limit 2 MB.');
    }
    // Full article bodies are intentionally ignored. Removing content:encoded
    // also lets us tolerate publisher-side defects inside an unused extension
    // without weakening validation of the RSS/Atom metadata we actually save.
    $xml = (string) preg_replace(
        [
            '/<content:encoded\b[^>]*><!\[CDATA\[.*?\]\]><\/content:encoded>/su',
            '/<content:encoded<!\[CDATA\[.*?\]\]>\s*\/>/su',
        ],
        '',
        $xml
    );
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT)) {
            $error = libxml_get_last_error();
            throw new RuntimeException('Uszkodzony XML kanału' . ($error ? ': ' . trim($error->message) : '.'));
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    $nodes = $xpath->query('//*[local-name()="item"]');
    $isAtom = false;
    if ($nodes === false || $nodes->length === 0) {
        $nodes = $xpath->query('//*[local-name()="entry"]');
        $isAtom = true;
    }
    if ($nodes === false) {
        throw new RuntimeException('Nie można odczytać wpisów kanału.');
    }

    $items = [];
    foreach ($nodes as $node) {
        if (count($items) >= FEED_MAX_ITEMS_PER_SOURCE) {
            break;
        }
        if ($isAtom) {
            $linkNode = $xpath->query('./*[local-name()="link" and (not(@rel) or @rel="alternate")][1]', $node)?->item(0);
            $raw = [
                'title' => feed_text($node, './*[local-name()="title"][1]', $xpath),
                'url' => $linkNode instanceof DOMElement ? $linkNode->getAttribute('href') : '',
                'external_id' => feed_text($node, './*[local-name()="id"][1]', $xpath),
                'published_at' => feed_text($node, './*[local-name()="published" or local-name()="updated"][1]', $xpath),
                'summary' => feed_text($node, './*[local-name()="summary"][1]', $xpath),
                'category' => ($categoryNode = $xpath->query('./*[local-name()="category"][1]', $node)?->item(0)) instanceof DOMElement
                    ? ($categoryNode->getAttribute('term') ?: $categoryNode->textContent) : '',
            ];
        } else {
            $raw = [
                'title' => feed_text($node, './*[local-name()="title"][1]', $xpath),
                'url' => feed_text($node, './*[local-name()="link"][1]', $xpath),
                'external_id' => feed_text($node, './*[local-name()="guid"][1]', $xpath),
                'published_at' => feed_text($node, './*[local-name()="pubDate" or local-name()="date"][1]', $xpath),
                'summary' => feed_text($node, './*[local-name()="description" or local-name()="summary"][1]', $xpath),
                'category' => feed_text($node, './*[local-name()="category"][1]', $xpath),
            ];
        }
        try {
            $items[] = normalize_feed_item($raw, $source);
        } catch (InvalidArgumentException) {
            // A malformed entry does not invalidate the remaining feed.
        }
    }

    return $items;
}

function ensure_feed_idea_category(): int
{
    $database = bueno_database();
    $statement = $database->prepare('SELECT id FROM post_categories WHERE slug = :slug AND deleted_at IS NULL');
    $statement->execute([':slug' => 'automatyczne-znaleziska']);
    $categoryId = (int) $statement->fetchColumn();
    if ($categoryId > 0) {
        return $categoryId;
    }
    $statement = $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order, is_editorial_only)
         VALUES (:title, :description, :slug, 999999, 1)'
    );
    $statement->execute([
        ':title' => 'Automatyczne znaleziska',
        ':description' => 'Niepubliczna kategoria pomysłów wykrytych w kanałach źródłowych.',
        ':slug' => 'automatyczne-znaleziska',
    ]);

    return (int) $database->lastInsertId();
}

function persist_discovered_feed_item(array $source, array $item): ?int
{
    $database = bueno_database();
    $check = $database->prepare(
        'SELECT id, post_id FROM discovered_feed_items
         WHERE technical_source_id = :source_id AND (external_id = :external_id OR source_url = :source_url)
         LIMIT 1'
    );
    $check->execute([
        ':source_id' => (int) $source['id'],
        ':external_id' => $item['external_id'],
        ':source_url' => $item['source_url'],
    ]);
    $existing = $check->fetch();
    if (is_array($existing) && (int) $existing['post_id'] > 0) {
        $grouping = group_discovered_feed_item((int) $existing['id']);
        score_editorial_topic((int) $grouping['topic_id']);
        return null;
    }

    $categoryId = ensure_feed_idea_category();
    $database->beginTransaction();
    try {
        $slug = unique_post_slug($database, (string) $item['title']);
        $statement = $database->prepare(
            'INSERT INTO posts (
                category_id, title, excerpt, content, slug, status, is_published,
                author_id, editorial_origin, content_updated_at
             ) VALUES (
                :category_id, :title, :excerpt, :content, :slug, "idea", 0,
                :author_id, "automatic", CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            ':category_id' => $categoryId,
            ':title' => $item['title'],
            ':excerpt' => $item['summary'],
            ':content' => $item['summary'],
            ':slug' => $slug,
            ':author_id' => default_author_id(),
        ]);
        $postId = (int) $database->lastInsertId();
        $statement = $database->prepare(
            'INSERT INTO discovered_feed_items (
                technical_source_id, post_id, external_id, source_url, title,
                source_name, published_at, summary, category, content_hash
             ) VALUES (
                :technical_source_id, :post_id, :external_id, :source_url, :title,
                :source_name, :published_at, :summary, :category, :content_hash
             )'
        );
        $statement->execute([
            ':technical_source_id' => (int) $source['id'],
            ':post_id' => $postId,
            ':external_id' => $item['external_id'],
            ':source_url' => $item['source_url'],
            ':title' => $item['title'],
            ':source_name' => $item['source_name'],
            ':published_at' => $item['published_at'],
            ':summary' => $item['summary'],
            ':category' => $item['category'],
            ':content_hash' => $item['content_hash'],
        ]);
        $feedItemId = (int) $database->lastInsertId();
        record_post_status_change($postId, null, 'idea', 'Wykryto w kanale: ' . $source['name'], 'feed-ingestion');
        replace_post_sources($postId, [[
            'source_url' => $item['source_url'],
            'source_title' => $item['title'],
            'publisher_name' => $source['name'],
            'source_type' => !empty($source['is_primary']) ? 'primary' : 'secondary',
            'source_published_at' => $item['published_at'],
        ]]);
        $database->commit();
        $grouping = group_discovered_feed_item($feedItemId);
        score_editorial_topic((int) $grouping['topic_id']);

        return $postId;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        if ($exception instanceof PDOException && str_contains(strtolower($exception->getMessage()), 'unique')) {
            return null;
        }
        throw $exception;
    }
}

function run_feed_ingestion(?callable $fetcher = null): array
{
    $fetcher ??= static fn (string $url, array $source): string => fetch_remote_feed($url);
    $result = ['sources' => [], 'created' => 0, 'duplicates' => 0, 'failed' => 0];
    foreach (list_technical_sources(true) as $source) {
        if ($source['source_type'] !== 'rss') {
            continue;
        }
        $sourceResult = ['source_id' => (int) $source['id'], 'name' => $source['name'], 'created' => 0, 'duplicates' => 0, 'error' => ''];
        try {
            $items = parse_feed_document((string) $fetcher((string) $source['feed_url'], $source), $source);
            foreach ($items as $item) {
                if (persist_discovered_feed_item($source, $item) === null) {
                    $sourceResult['duplicates']++;
                    $result['duplicates']++;
                } else {
                    $sourceResult['created']++;
                    $result['created']++;
                }
            }
            record_technical_source_check((int) $source['id'], true);
        } catch (Throwable $exception) {
            $sourceResult['error'] = $exception->getMessage();
            $result['failed']++;
            record_technical_source_check((int) $source['id'], false, $exception->getMessage());
        }
        $result['sources'][] = $sourceResult;
    }

    return $result;
}
