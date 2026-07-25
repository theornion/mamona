<?php

declare(strict_types=1);

const ARTICLE_IMAGE_ROLES = ['hero', 'inline'];
const ARTICLE_IMAGE_LAYOUTS = ['full', 'left', 'right', 'breakout'];
const ARTICLE_IMAGE_STATUSES = ['planned', 'selected', 'downloaded', 'manual_review', 'missing'];
const ARTICLE_IMAGE_SAFE_LICENSES = ['cc0', 'public-domain', 'cc-by'];

function article_image_schema(): array
{
    $string = ['type' => 'string'];

    return [
        'type' => 'object',
        'properties' => [
            'role' => ['type' => 'string', 'enum' => ARTICLE_IMAGE_ROLES],
            'section_id' => $string,
            'visual_intent' => $string,
            'search_queries' => ['type' => 'array', 'items' => $string],
            'expected_content' => $string,
            'source_page_url' => $string,
            'source_file_url' => $string,
            'local_path' => $string,
            'author' => $string,
            'license' => $string,
            'license_url' => $string,
            'attribution' => $string,
            'alt' => $string,
            'caption' => $string,
            'layout' => ['type' => 'string', 'enum' => ARTICLE_IMAGE_LAYOUTS],
            'status' => ['type' => 'string', 'enum' => ARTICLE_IMAGE_STATUSES],
        ],
        'required' => [
            'role', 'section_id', 'visual_intent', 'search_queries', 'expected_content',
            'source_page_url', 'source_file_url', 'local_path', 'author', 'license',
            'license_url', 'attribution', 'alt', 'caption', 'layout', 'status',
        ],
        'additionalProperties' => false,
    ];
}

function article_inline_image_target_count(int $characterCount): int
{
    if ($characterCount <= 0) {
        return 0;
    }

    return max(1, (int) round($characterCount / 775));
}

function article_section_blocks(array $draft): array
{
    $sections = [];
    $append = static function (string $id, string $heading, string $text) use (&$sections): void {
        $text = trim(strip_tags($text));
        if ($text !== '') {
            $sections[] = [
                'id' => $id,
                'heading' => $heading,
                'text' => $text,
                'character_count' => mb_strlen($text),
            ];
        }
    };
    $append('lead', '', (string) ($draft['lead']['text'] ?? ''));
    $append('why-important', 'Dlaczego to ważne', (string) ($draft['why_important']['text'] ?? ''));
    foreach ((array) ($draft['key_facts'] ?? []) as $index => $fact) {
        $append('fact-' . ($index + 1), 'Najważniejsze fakty', (string) ($fact['text'] ?? ''));
    }
    $append('comparison', 'Kontekst', (string) ($draft['comparison_context']['text'] ?? ''));
    foreach ((array) ($draft['unknowns'] ?? []) as $index => $unknown) {
        $append('unknown-' . ($index + 1), 'Czego jeszcze nie wiadomo', (string) ($unknown['text'] ?? ''));
    }
    foreach ((array) ($draft['narrative'] ?? []) as $key => $section) {
        $append('narrative-' . str_replace('_', '-', (string) $key), '', (string) ($section['text'] ?? ''));
    }
    $append('takeaway', 'Co z tego wynika', (string) ($draft['practical_takeaway']['text'] ?? ''));

    return $sections;
}

function article_illustration_plan_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'hero' => article_image_schema(),
            'inline' => ['type' => 'array', 'items' => article_image_schema()],
        ],
        'required' => ['hero', 'inline'],
        'additionalProperties' => false,
    ];
}

function build_planned_illustration_fixture(array $draft): array
{
    $makeImage = static fn (string $role, string $sectionId, string $layout, string $intent): array => [
        'role' => $role,
        'section_id' => $sectionId,
        'visual_intent' => $intent,
        'search_queries' => ['popular science ' . str_replace('-', ' ', $sectionId)],
        'expected_content' => $intent,
        'source_page_url' => '',
        'source_file_url' => '',
        'local_path' => '',
        'author' => '',
        'license' => '',
        'license_url' => '',
        'attribution' => '',
        'alt' => $intent,
        'caption' => $intent,
        'layout' => $layout,
        'status' => 'planned',
    ];
    $plan = [
        'hero' => $makeImage('hero', 'article', 'full', 'Reprezentatywny obraz całego tematu artykułu'),
        'inline' => [],
    ];
    $target = article_inline_image_target_count(article_draft_main_content_length($draft));
    foreach (array_slice(article_section_blocks($draft), 0, $target) as $index => $section) {
        $plan['inline'][] = $makeImage(
            'inline',
            (string) $section['id'],
            ['full', 'left', 'right', 'breakout'][$index % 4],
            'Konkretna ilustracja treści sekcji ' . (string) $section['id']
        );
    }

    return $plan;
}

function validate_planned_article_image(array $image, string $expectedRole, array $sectionIds): void
{
    validate_generation_value($image, article_image_schema());
    if (($image['role'] ?? '') !== $expectedRole || ($image['status'] ?? '') !== 'planned') {
        throw new InvalidArgumentException('Plan ilustracji ma nieprawidłową rolę albo status.');
    }
    if ($expectedRole === 'hero') {
        if (($image['section_id'] ?? '') !== 'article') {
            throw new InvalidArgumentException('Grafika hero musi być powiązana z całym artykułem.');
        }
    } elseif (!in_array((string) ($image['section_id'] ?? ''), $sectionIds, true)) {
        throw new InvalidArgumentException('Ilustracja inline wskazuje nieistniejącą sekcję.');
    }
    foreach (['visual_intent', 'expected_content', 'alt', 'caption'] as $field) {
        if (trim((string) ($image[$field] ?? '')) === '') {
            throw new InvalidArgumentException("Plan ilustracji wymaga pola {$field}.");
        }
    }
    if ((array) ($image['search_queries'] ?? []) === []) {
        throw new InvalidArgumentException('Plan ilustracji wymaga co najmniej jednego zapytania.');
    }
    foreach (['source_page_url', 'source_file_url', 'local_path', 'author', 'license', 'license_url', 'attribution'] as $field) {
        if (trim((string) ($image[$field] ?? '')) !== '') {
            throw new InvalidArgumentException("Model nie może wypełniać pola źródłowego {$field}.");
        }
    }
}

function validate_article_illustration_plan(array $plan, array $sections, int $characterCount): void
{
    validate_generation_value($plan, article_illustration_plan_schema());
    $sectionIds = array_column($sections, 'id');
    validate_planned_article_image((array) $plan['hero'], 'hero', $sectionIds);
    $inline = (array) $plan['inline'];
    $target = article_inline_image_target_count($characterCount);
    if (count($inline) !== $target) {
        throw new InvalidArgumentException("Plan wymaga {$target} ilustracji inline, otrzymano " . count($inline) . '.');
    }
    $usedSections = [];
    foreach ($inline as $image) {
        validate_planned_article_image((array) $image, 'inline', $sectionIds);
        if (isset($usedSections[$image['section_id']])) {
            throw new InvalidArgumentException('Dwie ilustracje nie mogą mechanicznie wskazywać tej samej sekcji.');
        }
        $usedSections[$image['section_id']] = true;
    }
}

function normalize_reuse_license(string $license): string
{
    $license = strtolower(trim($license));
    $license = str_replace(['_', ' '], '-', $license);
    if (str_contains($license, 'public-domain') || in_array($license, ['pd', 'pdm'], true)) {
        return 'public-domain';
    }
    if (preg_match('/\bcc0(?:-1\.0)?\b/', $license) === 1) {
        return 'cc0';
    }
    if (preg_match('/\bcc-by(?:-[0-9.]+)?\b/', $license) === 1
        && !str_contains($license, '-sa')
        && !str_contains($license, '-nc')
        && !str_contains($license, '-nd')) {
        return 'cc-by';
    }

    return $license;
}

function article_image_license_is_auto_safe(string $license): bool
{
    return in_array(normalize_reuse_license($license), ARTICLE_IMAGE_SAFE_LICENSES, true);
}

function validate_source_image_candidate(array $candidate): array
{
    $required = [
        'source_page_url', 'source_file_url', 'author', 'license', 'license_url',
        'attribution', 'width', 'height', 'provider', 'provider_id',
    ];
    foreach ($required as $field) {
        if (!array_key_exists($field, $candidate) || (is_string($candidate[$field]) && trim($candidate[$field]) === '')) {
            throw new InvalidArgumentException("Wynik źródła nie zawiera pola {$field}.");
        }
    }
    foreach (['source_page_url', 'source_file_url', 'license_url'] as $field) {
        $url = (string) $candidate[$field];
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException("Pole {$field} musi być rzeczywistym adresem HTTPS.");
        }
    }
    if ((int) $candidate['width'] <= 0 || (int) $candidate['height'] <= 0) {
        throw new InvalidArgumentException('Wynik źródła ma nieprawidłowe wymiary.');
    }
    $candidate['license_normalized'] = normalize_reuse_license((string) $candidate['license']);
    $candidate['status'] = article_image_license_is_auto_safe((string) $candidate['license'])
        ? 'selected'
        : 'manual_review';

    return $candidate;
}

function source_image_json_transport(string $url): array
{
    validate_remote_image_url($url);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić klienta źródła obrazów.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => (int) app_config('source_image_timeout_seconds'),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'MamonaSourceImageSearch/1.0',
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Źródło obrazów zwróciło HTTP ' . $status . '.');
    }
    $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Źródło obrazów zwróciło nieprawidłowy JSON.');
    }

    return $decoded;
}

function search_wikimedia_commons_images(string $query, ?callable $transport = null): array
{
    $transport ??= 'source_image_json_transport';
    $url = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search'
        . '&gsrnamespace=6&gsrlimit=12&prop=imageinfo&iiprop=url%7Csize%7Cextmetadata'
        . '&format=json&origin=*&gsrsearch=' . rawurlencode($query);
    $response = $transport($url);
    $results = [];
    foreach ((array) ($response['query']['pages'] ?? []) as $page) {
        $info = (array) ($page['imageinfo'][0] ?? []);
        $meta = (array) ($info['extmetadata'] ?? []);
        $value = static fn (string $key): string => trim(strip_tags((string) ($meta[$key]['value'] ?? '')));
        $fileUrl = (string) ($info['url'] ?? '');
        $pageUrl = (string) ($info['descriptionurl'] ?? '');
        $licenseUrl = $value('LicenseUrl');
        $author = $value('Artist') ?: $value('Credit');
        $license = $value('LicenseShortName') ?: $value('UsageTerms');
        if ($fileUrl === '' || $pageUrl === '' || $licenseUrl === '' || $author === '' || $license === '') {
            continue;
        }
        $results[] = validate_source_image_candidate([
            'source_page_url' => $pageUrl,
            'source_file_url' => $fileUrl,
            'author' => $author,
            'license' => $license,
            'license_url' => $licenseUrl,
            'attribution' => trim($value('Attribution')) ?: trim($author . ', ' . $license),
            'width' => (int) ($info['width'] ?? 0),
            'height' => (int) ($info['height'] ?? 0),
            'provider' => 'wikimedia',
            'provider_id' => (string) ($page['pageid'] ?? sha1($pageUrl)),
        ]);
    }

    return $results;
}

function search_openverse_images(string $query, ?callable $transport = null): array
{
    $transport ??= 'source_image_json_transport';
    $response = $transport(
        'https://api.openverse.org/v1/images/?page_size=12&mature=false&q=' . rawurlencode($query)
    );
    $results = [];
    foreach ((array) ($response['results'] ?? []) as $item) {
        $license = trim((string) ($item['license'] ?? ''));
        $version = trim((string) ($item['license_version'] ?? ''));
        if ($version !== '') {
            $license .= '-' . $version;
        }
        $candidate = [
            'source_page_url' => (string) ($item['foreign_landing_url'] ?? ''),
            'source_file_url' => (string) ($item['url'] ?? ''),
            'author' => trim((string) ($item['creator'] ?? '')),
            'license' => $license,
            'license_url' => (string) ($item['license_url'] ?? ''),
            'attribution' => trim((string) ($item['attribution'] ?? '')),
            'width' => (int) ($item['width'] ?? 0),
            'height' => (int) ($item['height'] ?? 0),
            'provider' => 'openverse',
            'provider_id' => (string) ($item['id'] ?? ''),
        ];
        try {
            $results[] = validate_source_image_candidate($candidate);
        } catch (InvalidArgumentException) {
            continue;
        }
    }

    return $results;
}

function search_source_images(string $query, ?string $provider = null, ?callable $transport = null): array
{
    $query = trim($query);
    if ($query === '' || mb_strlen($query) > 200) {
        throw new InvalidArgumentException('Zapytanie obrazu musi mieć od 1 do 200 znaków.');
    }
    $provider = strtolower($provider ?? (string) app_config('source_image_provider'));

    return match ($provider) {
        'wikimedia' => search_wikimedia_commons_images($query, $transport),
        'openverse' => search_openverse_images($query, $transport),
        default => throw new InvalidArgumentException('Dozwolone źródła obrazów to Wikimedia Commons i Openverse.'),
    };
}

function select_source_image_from_results(array $plannedImage, array $results, string $providerId): array
{
    validate_planned_article_image(
        $plannedImage,
        (string) ($plannedImage['role'] ?? ''),
        [(string) ($plannedImage['section_id'] ?? '')]
    );
    foreach ($results as $result) {
        if (!is_array($result) || (string) ($result['provider_id'] ?? '') !== $providerId) {
            continue;
        }
        $candidate = validate_source_image_candidate($result);

        return array_merge($plannedImage, [
            'source_page_url' => $candidate['source_page_url'],
            'source_file_url' => $candidate['source_file_url'],
            'author' => $candidate['author'],
            'license' => $candidate['license'],
            'license_url' => $candidate['license_url'],
            'attribution' => $candidate['attribution'],
            'status' => $candidate['status'],
            'provider' => $candidate['provider'],
            'provider_id' => $candidate['provider_id'],
            'width' => (int) $candidate['width'],
            'height' => (int) $candidate['height'],
        ]);
    }
    throw new InvalidArgumentException('Wybrany obraz nie występuje w rzeczywistych wynikach źródła.');
}

function article_image_ip_is_public(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function article_image_resolve_public_ips(string $host, ?callable $resolver = null): array
{
    $ips = $resolver !== null
        ? (array) $resolver($host)
        : array_values(array_unique(array_filter([
            gethostbyname($host) !== $host ? gethostbyname($host) : null,
            ...array_map(
                static fn (array $record): string => (string) ($record['ipv6'] ?? $record['ip'] ?? ''),
                function_exists('dns_get_record') ? (dns_get_record($host, DNS_A | DNS_AAAA) ?: []) : []
            ),
        ])));
    if ($ips === []) {
        throw new InvalidArgumentException('Nie można rozwiązać hosta obrazu źródłowego.');
    }
    foreach ($ips as $ip) {
        if (!is_string($ip) || !article_image_ip_is_public($ip)) {
            throw new InvalidArgumentException('Adres obrazu wskazuje sieć lokalną, prywatną lub zastrzeżoną.');
        }
    }

    return $ips;
}

function validate_remote_image_url(string $url, ?callable $resolver = null): void
{
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null
        || parse_url($url, PHP_URL_PASS) !== null) {
        throw new InvalidArgumentException('Obraz źródłowy musi używać bezpiecznego adresu HTTPS.');
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
        throw new InvalidArgumentException('Adres lokalny obrazu jest niedozwolony.');
    }
    article_image_resolve_public_ips($host, $resolver);
}

function source_image_curl_once(string $url): array
{
    validate_remote_image_url($url);
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $ips = article_image_resolve_public_ips($host);
    $pinnedIp = str_contains((string) $ips[0], ':') ? '[' . $ips[0] . ']' : (string) $ips[0];
    $body = '';
    $headers = [];
    $tooLarge = false;
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić downloadera obrazu.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => (int) app_config('source_image_timeout_seconds'),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinnedIp],
        CURLOPT_USERAGENT => 'MamonaSourceImageFetcher/1.0',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > (int) app_config('source_image_max_bytes')) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $mime = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    $error = curl_error($curl);
    curl_close($curl);
    if ($tooLarge) {
        throw new InvalidArgumentException('Obraz źródłowy przekracza maksymalny rozmiar.');
    }
    if ($success === false) {
        throw new RuntimeException($error !== '' ? $error : 'Błąd pobierania obrazu.');
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'mime' => $mime];
}

function download_source_image(
    array $selectedImage,
    ?callable $transport = null,
    ?callable $resolver = null,
    ?string $directory = null
): array {
    if (($selectedImage['status'] ?? '') !== 'selected') {
        throw new InvalidArgumentException('Automatycznie można pobrać wyłącznie obraz z zaakceptowaną licencją.');
    }
    $transport ??= 'source_image_curl_once';
    $url = (string) ($selectedImage['source_file_url'] ?? '');
    $redirects = 0;
    do {
        validate_remote_image_url($url, $resolver);
        $response = $transport($url);
        $status = (int) ($response['status'] ?? 0);
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            if ($redirects >= (int) app_config('source_image_max_redirects')) {
                throw new InvalidArgumentException('Przekroczono limit przekierowań obrazu.');
            }
            $location = trim((string) ($response['headers']['location'] ?? ''));
            if ($location === '' || !str_starts_with($location, 'https://')) {
                throw new InvalidArgumentException('Niedozwolone przekierowanie obrazu źródłowego.');
            }
            $url = $location;
            $redirects++;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Źródło obrazu zwróciło HTTP ' . $status . '.');
        }
        break;
    } while (true);

    $bytes = (string) ($response['body'] ?? '');
    if ($bytes === '' || strlen($bytes) > (int) app_config('source_image_max_bytes')) {
        throw new InvalidArgumentException('Obraz jest pusty albo przekracza maksymalny rozmiar.');
    }
    $info = @getimagesizefromstring($bytes);
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $detectedMime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
    $reportedMime = strtolower(trim(explode(';', (string) ($response['mime'] ?? ''))[0]));
    if (!isset($allowedMimes[$detectedMime]) || ($reportedMime !== '' && $reportedMime !== $detectedMime)) {
        throw new InvalidArgumentException('MIME obrazu nie zgadza się z rzeczywistą zawartością.');
    }
    $urlExtension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ($urlExtension !== '') {
        $extensionMime = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp',
        ][$urlExtension] ?? null;
        if ($extensionMime === null || $extensionMime !== $detectedMime) {
            throw new InvalidArgumentException('Rozszerzenie obrazu nie zgadza się z rzeczywistym MIME.');
        }
    }
    if ((int) $info[0] < (int) app_config('source_image_min_width')
        || (int) $info[1] < (int) app_config('source_image_min_height')) {
        throw new InvalidArgumentException('Obraz ma zbyt małą rozdzielczość.');
    }
    $hash = hash('sha256', $bytes);
    $directory ??= app_post_image_path('sources');
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu obrazów źródłowych.');
    }
    $filename = 'source-' . $hash . '.' . $allowedMimes[$detectedMime];
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new RuntimeException('Nie udało się zapisać obrazu źródłowego.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    $relative = str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', app_project_root()) . '/')
        ? substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', app_project_root())) + 1)
        : $path;

    return array_merge($selectedImage, [
        'source_file_url' => $url,
        'local_path' => $relative,
        'status' => 'downloaded',
        'downloaded_at' => gmdate(DATE_ATOM),
        'sha256' => $hash,
        'mime' => $detectedMime,
        'width' => (int) $info[0],
        'height' => (int) $info[1],
    ]);
}

function persist_article_image(int $postId, array $image, string $query = ''): int
{
    $statement = bueno_database()->prepare(
        'INSERT INTO article_images (
            post_id, role, section_id, visual_intent, search_queries_json,
            source_page_url, source_file_url, local_path, author, license,
            license_url, attribution, alt, caption, layout, status,
            width, height, downloaded_at
         ) VALUES (
            :post_id, :role, :section_id, :visual_intent, :search_queries_json,
            :source_page_url, :source_file_url, :local_path, :author, :license,
            :license_url, :attribution, :alt, :caption, :layout, :status,
            :width, :height, :downloaded_at
         )
         ON CONFLICT(post_id, role, section_id) DO UPDATE SET
            visual_intent = excluded.visual_intent,
            search_queries_json = excluded.search_queries_json,
            source_page_url = excluded.source_page_url,
            source_file_url = excluded.source_file_url,
            local_path = excluded.local_path,
            author = excluded.author,
            license = excluded.license,
            license_url = excluded.license_url,
            attribution = excluded.attribution,
            alt = excluded.alt,
            caption = excluded.caption,
            layout = excluded.layout,
            status = excluded.status,
            width = excluded.width,
            height = excluded.height,
            downloaded_at = excluded.downloaded_at,
            updated_at = CURRENT_TIMESTAMP'
    );
    $queries = (array) ($image['search_queries'] ?? []);
    if ($query !== '' && !in_array($query, $queries, true)) {
        $queries[] = $query;
    }
    $statement->execute([
        ':post_id' => $postId,
        ':role' => $image['role'],
        ':section_id' => $image['section_id'],
        ':visual_intent' => $image['visual_intent'],
        ':search_queries_json' => generation_json($queries),
        ':source_page_url' => $image['source_page_url'] ?? '',
        ':source_file_url' => $image['source_file_url'] ?? '',
        ':local_path' => $image['local_path'] ?? '',
        ':author' => $image['author'] ?? '',
        ':license' => $image['license'] ?? '',
        ':license_url' => $image['license_url'] ?? '',
        ':attribution' => $image['attribution'] ?? '',
        ':alt' => $image['alt'],
        ':caption' => $image['caption'],
        ':layout' => $image['layout'],
        ':status' => $image['status'],
        ':width' => $image['width'] ?? null,
        ':height' => $image['height'] ?? null,
        ':downloaded_at' => $image['downloaded_at'] ?? null,
    ]);

    $idStatement = bueno_database()->prepare(
        'SELECT id FROM article_images
         WHERE post_id = :post_id AND role = :role AND section_id = :section_id'
    );
    $idStatement->execute([
        ':post_id' => $postId,
        ':role' => $image['role'],
        ':section_id' => $image['section_id'],
    ]);

    return (int) $idStatement->fetchColumn();
}

function list_article_images(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_images WHERE post_id = :post_id ORDER BY id'
    );
    $statement->execute([':post_id' => $postId]);

    return $statement->fetchAll();
}

function validate_article_blocks(array $blocks): void
{
    $allowed = ['heading', 'paragraph', 'list', 'quote', 'section', 'illustration', 'gallery'];
    foreach ($blocks as $index => $block) {
        if (!is_array($block) || !in_array((string) ($block['type'] ?? ''), $allowed, true)) {
            throw new InvalidArgumentException("Blok artykułu #{$index} ma niedozwolony typ.");
        }
        $type = (string) $block['type'];
        if (in_array($type, ['heading', 'paragraph', 'quote'], true)
            && trim((string) ($block['text'] ?? '')) === '') {
            throw new InvalidArgumentException("Blok {$type} wymaga tekstu.");
        }
        if ($type === 'heading' && !in_array((int) ($block['level'] ?? 2), [2, 3], true)) {
            throw new InvalidArgumentException('Nagłówek może mieć wyłącznie poziom 2 lub 3.');
        }
        if ($type === 'list' && (array) ($block['items'] ?? []) === []) {
            throw new InvalidArgumentException('Lista wymaga elementów.');
        }
        if ($type === 'section') {
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', (string) ($block['id'] ?? '')) !== 1) {
                throw new InvalidArgumentException('Sekcja wymaga bezpiecznego identyfikatora.');
            }
            validate_article_blocks((array) ($block['blocks'] ?? []));
        }
        if ($type === 'illustration' && (int) ($block['image_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Blok ilustracji wymaga identyfikatora obrazu.');
        }
        if ($type === 'gallery' && (array) ($block['image_ids'] ?? []) === []) {
            throw new InvalidArgumentException('Galeria wymaga obrazów.');
        }
    }
}

function render_article_image_record(array $image, bool $hero = false): string
{
    if (($image['status'] ?? '') !== 'downloaded') {
        return '';
    }
    $path = ltrim(str_replace('\\', '/', (string) ($image['local_path'] ?? '')), '/');
    if ($path === '' || !is_file(app_path($path))) {
        return '';
    }
    $layout = in_array((string) ($image['layout'] ?? ''), ARTICLE_IMAGE_LAYOUTS, true)
        ? (string) $image['layout']
        : 'full';
    $alt = htmlspecialchars((string) $image['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $caption = htmlspecialchars((string) $image['caption'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attribution = htmlspecialchars((string) $image['attribution'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $source = htmlspecialchars((string) $image['source_page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $license = htmlspecialchars((string) $image['license'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $licenseUrl = htmlspecialchars((string) $image['license_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $loading = $hero ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"';
    $html = '<figure class="article-illustration article-illustration--' . $layout . '">';
    $html .= '<img src="../' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt
        . '" width="' . max(1, (int) ($image['width'] ?? 1))
        . '" height="' . max(1, (int) ($image['height'] ?? 1))
        . '" decoding="async"' . $loading . '>';
    if ($caption !== '' || $attribution !== '') {
        $html .= '<figcaption>' . $caption;
        if ($attribution !== '') {
            $html .= '<small> ' . $attribution;
            if ($source !== '') {
                $html .= ' · <a href="' . $source . '" rel="noopener noreferrer">źródło</a>';
            }
            if ($license !== '') {
                $html .= ' · ' . ($licenseUrl !== ''
                    ? '<a href="' . $licenseUrl . '" rel="license noopener noreferrer">' . $license . '</a>'
                    : $license);
            }
            $html .= '</small>';
        }
        $html .= '</figcaption>';
    }

    return $html . '</figure>';
}

function render_article_blocks(array $blocks, array $images): string
{
    validate_article_blocks($blocks);
    $byId = [];
    foreach ($images as $image) {
        $byId[(int) ($image['id'] ?? 0)] = $image;
    }
    $escape = static fn (string $text): string => htmlspecialchars(
        $text,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $html = '';
    foreach ($blocks as $block) {
        $type = (string) $block['type'];
        if ($type === 'heading') {
            $level = (int) ($block['level'] ?? 2);
            $html .= "<h{$level}>" . $escape((string) $block['text']) . "</h{$level}>";
        } elseif ($type === 'paragraph') {
            $html .= '<p>' . nl2br($escape((string) $block['text'])) . '</p>';
        } elseif ($type === 'quote') {
            $html .= '<blockquote><p>' . $escape((string) $block['text']) . '</p></blockquote>';
        } elseif ($type === 'list') {
            $html .= '<ul>';
            foreach ((array) $block['items'] as $item) {
                $html .= '<li>' . $escape((string) $item) . '</li>';
            }
            $html .= '</ul>';
        } elseif ($type === 'section') {
            $html .= '<section id="' . $escape((string) $block['id']) . '">'
                . render_article_blocks((array) $block['blocks'], $images) . '</section>';
        } elseif ($type === 'illustration') {
            $html .= isset($byId[(int) $block['image_id']])
                ? render_article_image_record($byId[(int) $block['image_id']])
                : '';
        } elseif ($type === 'gallery') {
            $gallery = '';
            foreach ((array) $block['image_ids'] as $imageId) {
                if (isset($byId[(int) $imageId])) {
                    $gallery .= render_article_image_record($byId[(int) $imageId]);
                }
            }
            if ($gallery !== '') {
                $html .= '<div class="article-mini-gallery">' . $gallery . '</div>';
            }
        }
    }

    return $html;
}
