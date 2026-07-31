<?php

declare(strict_types=1);

const FEED_RESPONSE_MAX_BYTES = 3145728;
const FEED_MAX_ITEMS_PER_SOURCE = 50;

final class FeedTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly bool $transient = false,
        public readonly int $httpStatus = 0,
        public readonly int $bytesReceived = 0,
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message);
    }
}

function feed_transport_defaults(): array
{
    return [
        'connect_timeout_seconds' => (int) app_config('feed_connect_timeout_seconds'),
        'transfer_timeout_seconds' => (int) app_config('feed_transfer_timeout_seconds'),
        'low_speed_limit' => (int) app_config('feed_low_speed_limit'),
        'low_speed_time_seconds' => (int) app_config('feed_low_speed_time_seconds'),
        'max_attempts' => (int) app_config('feed_max_attempts'),
        'job_budget_seconds' => (int) app_config('feed_job_budget_seconds'),
        'max_bytes' => FEED_RESPONSE_MAX_BYTES,
        'max_redirects' => 3,
    ];
}

function feed_source_transport_options(array $source): array
{
    $options = feed_transport_defaults();
    foreach (array_keys($options) as $key) {
        $column = 'feed_' . $key;
        if (isset($source[$column]) && (int) $source[$column] > 0) $options[$key] = (int) $source[$column];
    }
    // Compatibility with the earlier source setting.
    if (isset($source['request_timeout_seconds']) && (int) $source['request_timeout_seconds'] > 0) {
        $options['transfer_timeout_seconds'] = (int) $source['request_timeout_seconds'];
    }
    if (isset($source['response_max_bytes']) && (int) $source['response_max_bytes'] > 0) {
        $options['max_bytes'] = (int) $source['response_max_bytes'];
    }
    $options['connect_timeout_seconds'] = max(2, min(20, $options['connect_timeout_seconds']));
    $options['transfer_timeout_seconds'] = max(10, min(90, $options['transfer_timeout_seconds']));
    $options['low_speed_limit'] = max(1, min(65536, $options['low_speed_limit']));
    $options['low_speed_time_seconds'] = max(5, min(60, $options['low_speed_time_seconds']));
    $options['max_attempts'] = max(1, min(4, $options['max_attempts']));
    $options['max_bytes'] = max(65536, min(10485760, $options['max_bytes']));
    return $options;
}

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

function feed_http_once(string $url, array $options, array $validators = [], int $redirectsRemaining = 3): array
{
    [$url, $host, $address] = assert_public_feed_url($url);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $temporary = tmpfile();
    if ($temporary === false) throw new RuntimeException('Nie można utworzyć bufora kanału.');
    $bytes = 0;
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
        CURLOPT_CONNECTTIMEOUT => $options['connect_timeout_seconds'],
        CURLOPT_TIMEOUT => $options['transfer_timeout_seconds'],
        CURLOPT_LOW_SPEED_LIMIT => $options['low_speed_limit'],
        CURLOPT_LOW_SPEED_TIME => $options['low_speed_time_seconds'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CERTINFO => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 0,
        CURLOPT_USERAGENT => 'Mamona-Content-Studio/1.0 (+https://mamona.pl/kontakt)',
        CURLOPT_ENCODING => '',
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolveAddress],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($temporary, &$bytes, &$tooLarge, $options): int {
            if ($bytes + strlen($chunk) > $options['max_bytes']) {
                $tooLarge = true;
                return 0;
            }
            $written = fwrite($temporary, $chunk);
            if ($written !== false) $bytes += $written;
            return $written === false ? 0 : $written;
        },
    ];
    $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.1'];
    if (($validators['etag'] ?? '') !== '') $headers[] = 'If-None-Match: ' . $validators['etag'];
    if (($validators['last_modified'] ?? '') !== '') $headers[] = 'If-Modified-Since: ' . $validators['last_modified'];
    $curlOptions[CURLOPT_HTTPHEADER] = $headers;
    $caBundle = trim((string) app_config('feed_ca_bundle'));
    if ($caBundle !== '') {
        if (!is_file($caBundle)) {
            curl_close($curl);
            throw new RuntimeException('CMS_FEED_CA_BUNDLE nie wskazuje istniejącego pliku.');
        }
        $curlOptions[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($curl, $curlOptions);
    $started = microtime(true);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlCode = curl_errno($curl);
    $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    $certificateInfo = curl_getinfo($curl, CURLINFO_CERTINFO);
    $error = curl_error($curl);
    curl_close($curl);

    if ($tooLarge) {
        fclose($temporary);
        throw new FeedTransportException('Kanał przekracza limit rozmiaru.', 'size_limit', false, $status, $bytes);
    }
    if ($success === false) {
        fclose($temporary);
        $tlsCodes = array_filter([defined('CURLE_SSL_CONNECT_ERROR') ? CURLE_SSL_CONNECT_ERROR : -1, defined('CURLE_PEER_FAILED_VERIFICATION') ? CURLE_PEER_FAILED_VERIFICATION : -1, defined('CURLE_SSL_CACERT_BADFILE') ? CURLE_SSL_CACERT_BADFILE : -1]);
        $partial = defined('CURLE_PARTIAL_FILE') && $curlCode === CURLE_PARTIAL_FILE;
        $timeout = $curlCode === CURLE_OPERATION_TIMEDOUT;
        $tls = in_array($curlCode, $tlsCodes, true);
        $category = $partial ? 'partial_transfer' : ($tls ? 'tls' : ($timeout ? ($bytes > 0 ? 'slow_transfer' : 'timeout') : 'transport'));
        throw new FeedTransportException('Nie udało się pobrać pełnego kanału: ' . ($error ?: 'błąd transportu'), $category, $partial || $timeout || $tls || $curlCode === CURLE_COULDNT_CONNECT, $status, $bytes);
    }
    if ($status >= 300 && $status < 400) {
        $location = (string) ($responseHeaders['location'] ?? '');
        if ($redirectsRemaining <= 0 || $location === '' || !str_starts_with($location, 'https://')) {
            fclose($temporary);
            throw new FeedTransportException('Kanał zwrócił niedozwolone lub zbyt liczne przekierowanie.', 'redirect', false, $status, $bytes);
        }
        fclose($temporary);
        if ($redirectsRemaining <= 0 || $location === '') throw new FeedTransportException('Pętla lub brak adresu przekierowania.', 'redirect', false, $status, $bytes);
        $next = str_starts_with($location, '/') ? 'https://' . $host . $location : $location;
        return feed_http_once($next, $options, $validators, $redirectsRemaining - 1);
    }
    if ($status === 304) {
        fclose($temporary);
        return ['status'=>304, 'body'=>'', 'bytes'=>$bytes, 'duration_ms'=>(int)((microtime(true)-$started)*1000), 'url'=>$effectiveUrl ?: $url, 'headers'=>$responseHeaders, 'certificate_chain'=>feed_certificate_summary($certificateInfo)];
    }
    if ($status < 200 || $status >= 300) {
        fclose($temporary);
        $retryable = in_array($status, [408, 425, 429], true) || $status >= 500;
        $retryAfter = isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null;
        throw new FeedTransportException('Kanał zwrócił HTTP ' . $status . '.', $retryable ? 'http_temporary' : 'http_permanent', $retryable, $status, $bytes, $retryAfter);
    }
    rewind($temporary);
    $body = stream_get_contents($temporary);
    fclose($temporary);
    if (!is_string($body) || trim($body) === '') throw new FeedTransportException('Kanał zwrócił pustą odpowiedź.', 'empty', false, $status, $bytes);
    return ['status'=>$status, 'body'=>$body, 'bytes'=>$bytes, 'duration_ms'=>(int)((microtime(true)-$started)*1000), 'url'=>$effectiveUrl ?: $url, 'headers'=>$responseHeaders, 'certificate_chain'=>feed_certificate_summary($certificateInfo)];
}

function fetch_remote_feed(string $url, array $source = [], ?callable $transport = null, ?callable $onRetry = null): array
{
    $options = feed_source_transport_options($source);
    $transport ??= 'feed_http_once';
    $validators = ['etag'=>(string)($source['feed_etag'] ?? ''), 'last_modified'=>(string)($source['feed_last_modified'] ?? '')];
    $deadline = microtime(true) + min($options['job_budget_seconds'], $options['transfer_timeout_seconds'] * $options['max_attempts'] + 10);
    $last = null;
    for ($attempt = 1; $attempt <= $options['max_attempts']; $attempt++) {
        try {
            $response = $transport($url, $options, $validators, $options['max_redirects']);
            $response['attempts'] = $attempt;
            return $response;
        } catch (FeedTransportException $exception) {
            $last = $exception;
            if (!$exception->transient || $attempt >= $options['max_attempts']) break;
            $delayMs = $exception->retryAfterSeconds !== null ? min(30000, $exception->retryAfterSeconds * 1000) : min(8000, (500 * (2 ** ($attempt - 1))) + random_int(0, 350));
            if (microtime(true) + ($delayMs / 1000) >= $deadline) break;
            if ($onRetry !== null) $onRetry($attempt, $options['max_attempts'], $delayMs, $exception);
            usleep($delayMs * 1000);
        }
    }
    throw $last ?? new FeedTransportException('Przekroczono budżet pobierania kanału.', 'budget', true);
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

function run_feed_ingestion(?callable $fetcher = null, ?callable $progress = null): array
{
    $fetcher ??= static fn (string $url, array $source, ?callable $retry = null): array => fetch_remote_feed($url, $source, null, $retry);
    $result = ['sources' => [], 'processed' => 0, 'succeeded' => 0, 'not_modified' => 0, 'retried' => 0, 'created' => 0, 'duplicates' => 0, 'failed' => 0];
    $sources = array_values(array_filter(
        list_technical_sources(true),
        static fn (array $source): bool => $source['source_type'] === 'rss'
            && (empty($source['muted_until']) || strtotime((string)$source['muted_until']) <= time())
    ));
    $total = count($sources);
    foreach ($sources as $index => $source) {
        if ($progress !== null) {
            $progress($source, $index, $total, 'started', null);
        }
        $sourceResult = ['source_id'=>(int)$source['id'], 'name'=>$source['name'], 'status'=>'running', 'category'=>'', 'advice'=>'', 'attempts'=>1, 'duration_ms'=>0, 'bytes'=>0, 'created'=>0, 'duplicates'=>0, 'error'=>''];
        $started = microtime(true);
        try {
            $retryReporter = static function (int $attempt, int $maximum, int $delayMs, FeedTransportException $exception) use ($progress, $source, $index, $total, &$sourceResult, &$result): void {
                $sourceResult['attempts'] = $attempt + 1;
                $sourceResult['category'] = $exception->category;
                $sourceResult['retry_in_ms'] = $delayMs;
                $result['retried']++;
                if ($progress !== null) $progress($source, $index, $total, 'retry', $sourceResult + ['attempt'=>$attempt, 'max_attempts'=>$maximum]);
            };
            $fetched = $fetcher((string) $source['feed_url'], $source, $retryReporter);
            $response = is_array($fetched) ? $fetched : ['status'=>200, 'body'=>(string)$fetched, 'bytes'=>strlen((string)$fetched), 'duration_ms'=>(int)((microtime(true)-$started)*1000), 'headers'=>[], 'attempts'=>1];
            $sourceResult['attempts'] = (int) ($response['attempts'] ?? $sourceResult['attempts']);
            $sourceResult['duration_ms'] = (int) ($response['duration_ms'] ?? 0);
            $sourceResult['bytes'] = (int) ($response['bytes'] ?? 0);
            if ((int) ($response['status'] ?? 200) === 304) {
                $sourceResult['status'] = 'not_modified';
                $result['not_modified']++;
                record_technical_source_check((int)$source['id'], true, '', ['http_status'=>304, 'diagnostics'=>feed_runtime_diagnostics()]);
            } else {
                $items = parse_feed_document((string) ($response['body'] ?? ''), $source);
                foreach ($items as $item) {
                    if (persist_discovered_feed_item($source, $item) === null) {
                        $sourceResult['duplicates']++; $result['duplicates']++;
                    } else {
                        $sourceResult['created']++; $result['created']++;
                    }
                }
                $sourceResult['status'] = 'succeeded';
                $result['succeeded']++;
                $headers = (array) ($response['headers'] ?? []);
                record_technical_source_check((int)$source['id'], true, '', ['etag'=>$headers['etag'] ?? '', 'last_modified'=>$headers['last-modified'] ?? '', 'http_status'=>(int)($response['status'] ?? 200), 'diagnostics'=>feed_runtime_diagnostics() + ['certificate_chain'=>$response['certificate_chain'] ?? []]]);
            }
        } catch (Throwable $exception) {
            $sourceResult['status'] = 'failed';
            $sourceResult['error'] = $exception->getMessage();
            $sourceResult['duration_ms'] = (int)((microtime(true)-$started)*1000);
            if ($exception instanceof FeedTransportException) {
                $sourceResult['category'] = $exception->category;
                $sourceResult['bytes'] = $exception->bytesReceived;
            } else $sourceResult['category'] = str_contains($exception->getMessage(), 'XML') ? 'invalid_xml' : 'processing';
            $sourceResult['advice'] = feed_error_advice($sourceResult['category'], $exception instanceof FeedTransportException ? $exception->httpStatus : 0);
            $result['failed']++;
            record_technical_source_check((int)$source['id'], false, $exception->getMessage(), ['http_status'=>$exception instanceof FeedTransportException ? $exception->httpStatus : 0, 'diagnostics'=>feed_runtime_diagnostics()]);
        }
        $result['processed']++;
        $result['sources'][] = $sourceResult;
        if ($progress !== null) {
            $progress($source, $index + 1, $total, 'completed', $sourceResult);
        }
    }

    return $result;
}

function feed_error_advice(string $category, int $status = 0): string
{
    return match ($category) {
        'timeout', 'slow_transfer', 'partial_transfer', 'transport' => 'Serwer odpowiada zbyt wolno lub przerwał transfer — ponowimy automatycznie.',
        'tls' => 'Nie udało się zweryfikować bezpiecznego połączenia TLS — sprawdź magazyn CA i certyfikat źródła.',
        'http_permanent' => $status === 403 ? 'Źródło odmawia automatycznego dostępu (HTTP 403); nie będziemy obchodzić zabezpieczeń.' : 'Źródło odrzuciło żądanie trwale; sprawdź oficjalny endpoint.',
        'http_temporary' => 'Źródło jest chwilowo niedostępne — ponowimy z bezpiecznym opóźnieniem.',
        'invalid_xml' => 'Odpowiedź nie jest prawidłowym RSS/Atom; nie zapisano częściowych danych.',
        'size_limit' => 'Kanał przekroczył skonfigurowany limit rozmiaru.',
        'redirect' => 'Wykryto niedozwolone przekierowanie lub pętlę.',
        default => 'Sprawdź konfigurację źródła i szczegóły diagnostyczne.',
    };
}

function feed_runtime_diagnostics(): array
{
    $curl = curl_version();
    $configured = trim((string) app_config('feed_ca_bundle'));
    return [
        'curl_version'=>(string)($curl['version'] ?? ''),
        'ssl_version'=>(string)($curl['ssl_version'] ?? ''),
        'ca_bundle'=>$configured !== '' ? $configured : (string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: 'system/native'),
        'tls_verification'=>true,
    ];
}

function feed_certificate_summary(mixed $certificateInfo): array
{
    if (!is_array($certificateInfo)) return [];
    return array_map(static function (array $certificate): array {
        return [
            'subject'=>(string)($certificate['Subject'] ?? ''),
            'issuer'=>(string)($certificate['Issuer'] ?? ''),
            'start'=>(string)($certificate['Start date'] ?? ''),
            'expire'=>(string)($certificate['Expire date'] ?? ''),
        ];
    }, $certificateInfo);
}
