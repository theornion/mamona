<?php

declare(strict_types=1);

const IMAGE_RIGHTS_REQUIRED_FIELDS = [
    'provider', 'asset_id', 'original_page_url', 'direct_asset_url', 'author_creator',
    'exact_credit_line', 'license_code', 'license_url', 'rights_statement_raw',
    'retrieved_at', 'license_snapshot', 'license_snapshot_hash',
    'commercial_use_allowed', 'derivatives_allowed', 'attribution_required',
    'share_alike', 'third_party_warning', 'identifiable_people', 'trademarks_logos',
    'chosen_query', 'topic_role',
];

function image_rights_normalize_license(string $value): string
{
    $value = strtolower(trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $value = str_replace(['_', ' '], '-', $value);
    if (preg_match('/(?:^|-)n[cd](?:-|$)|noncommercial|no-derivatives/', $value)) return $value;
    if (preg_match('~creativecommons\.org/publicdomain/zero|\bcc0(?:-1\.0)?\b~', $value)) return 'cc0';
    if (preg_match('~creativecommons\.org/publicdomain/mark|\b(public-domain|pdm|pd)\b~', $value)) return 'public-domain';
    if (preg_match('~creativecommons\.org/licenses/by-sa/|\bcc-by-sa(?:-[0-9.]+)?\b|^by-sa(?:-[0-9.]+)?$~', $value)) return 'cc-by-sa';
    if (preg_match('~creativecommons\.org/licenses/by/|\bcc-by(?:-[0-9.]+)?\b|^by(?:-[0-9.]+)?$~', $value)) return 'cc-by';
    if (preg_match('~\bpexels(?:-license)?\b~', $value)) return 'pexels-license';
    if (preg_match('~\blocal-editorial\b~', $value)) return 'local-editorial';
    return trim($value, '-');
}

function image_rights_license_policy(string $licenseCode): array
{
    return match (image_rights_normalize_license($licenseCode)) {
        'cc0', 'public-domain' => [true, true, false, false],
        'cc-by' => [true, true, true, false],
        'cc-by-sa' => [true, true, true, true],
        'pexels-license' => [true, true, true, false],
        'local-editorial' => [true, true, false, false],
        default => [false, false, false, false],
    };
}

function image_rights_url(string $value, string $field): string
{
    $value = trim($value);
    if (!filter_var($value, FILTER_VALIDATE_URL) || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
        throw new InvalidArgumentException("Manifest praw wymaga adresu HTTPS w polu {$field}.");
    }
    return $value;
}

function image_rights_bool(mixed $value, string $field): bool
{
    if (!is_bool($value)) throw new InvalidArgumentException("Manifest praw wymaga jednoznacznej wartości logicznej w polu {$field}.");
    return $value;
}

function image_rights_manifest(array $candidate, string $query = '', string $topicRole = ''): array
{
    if (isset($candidate['rights_manifest']) && is_array($candidate['rights_manifest'])) {
        $manifest = $candidate['rights_manifest'];
    } else {
        foreach (['third_party_warning', 'identifiable_people', 'trademarks_logos'] as $flag) {
            if (!array_key_exists($flag, $candidate) || !is_bool($candidate[$flag])) {
                throw new InvalidArgumentException("Kandydat nie ma jednoznacznej flagi prawnej {$flag}.");
            }
        }
        $license = image_rights_normalize_license((string) ($candidate['license'] ?? ''));
        [$commercial, $derivatives, $attribution, $shareAlike] = image_rights_license_policy($license);
        $provider = strtolower(trim((string) ($candidate['provider'] ?? '')));
        $raw = trim((string) ($candidate['rights_statement_raw'] ?? $candidate['license'] ?? ''));
        $retrievedAt = trim((string) ($candidate['retrieved_at'] ?? gmdate(DATE_ATOM)));
        $snapshot = [
            'provider' => $provider,
            'asset_id' => (string) ($candidate['provider_id'] ?? $candidate['asset_id'] ?? ''),
            'license_code' => $license,
            'license_url' => (string) ($candidate['license_url'] ?? ''),
            'rights_statement_raw' => $raw,
            'retrieved_at' => $retrievedAt,
        ];
        $manifest = [
            'provider' => $provider,
            'asset_id' => $snapshot['asset_id'],
            'original_page_url' => (string) ($candidate['source_page_url'] ?? ''),
            'direct_asset_url' => (string) ($candidate['source_file_url'] ?? ''),
            'author_creator' => trim((string) ($candidate['author'] ?? '')),
            'exact_credit_line' => trim((string) ($candidate['attribution'] ?? '')),
            'license_code' => $license,
            'license_url' => (string) ($candidate['license_url'] ?? ''),
            'rights_statement_raw' => $raw,
            'retrieved_at' => $retrievedAt,
            'license_snapshot' => $snapshot,
            'license_snapshot_hash' => hash('sha256', generation_json($snapshot)),
            'commercial_use_allowed' => $commercial,
            'derivatives_allowed' => $derivatives,
            'attribution_required' => $attribution,
            'share_alike' => $shareAlike,
            'third_party_warning' => $candidate['third_party_warning'],
            'identifiable_people' => $candidate['identifiable_people'],
            'trademarks_logos' => $candidate['trademarks_logos'],
            'chosen_query' => $query !== '' ? $query : (string) ($candidate['chosen_query'] ?? ''),
            'topic_role' => $topicRole !== '' ? $topicRole : (string) ($candidate['topic_role'] ?? 'unassigned'),
        ];
    }
    return validate_image_rights_manifest($manifest);
}

function validate_image_rights_manifest(array $manifest): array
{
    foreach (IMAGE_RIGHTS_REQUIRED_FIELDS as $field) {
        if (!array_key_exists($field, $manifest)) throw new InvalidArgumentException("Manifest praw nie zawiera pola {$field}.");
        if (is_string($manifest[$field]) && trim($manifest[$field]) === '') throw new InvalidArgumentException("Manifest praw ma puste pole {$field}.");
    }
    foreach (['original_page_url', 'direct_asset_url', 'license_url'] as $field) image_rights_url((string) $manifest[$field], $field);
    foreach (['commercial_use_allowed', 'derivatives_allowed', 'attribution_required', 'share_alike', 'third_party_warning', 'identifiable_people', 'trademarks_logos'] as $field) image_rights_bool($manifest[$field], $field);
    $normalized = image_rights_normalize_license((string) $manifest['license_code']);
    [$commercial, $derivatives, $attribution, $shareAlike] = image_rights_license_policy($normalized);
    if (!$commercial || !$derivatives || $manifest['commercial_use_allowed'] !== true || $manifest['derivatives_allowed'] !== true) {
        throw new InvalidArgumentException('Licencja assetu nie pozwala jednoznacznie na komercyjne użycie i przekształcenia.');
    }
    if ((bool) $manifest['attribution_required'] !== $attribution || (bool) $manifest['share_alike'] !== $shareAlike) {
        throw new InvalidArgumentException('Deklaracje atrybucji lub ShareAlike są sprzeczne z licencją.');
    }
    foreach (['third_party_warning', 'identifiable_people', 'trademarks_logos'] as $field) {
        if ($manifest[$field] === true) throw new InvalidArgumentException("Asset ma ostrzeżenie prawne: {$field}.");
    }
    $provider = strtolower((string) $manifest['provider']);
    $rightsRaw = trim((string) $manifest['rights_statement_raw']);
    $rightsLower = mb_strtolower($rightsRaw);
    if ($provider === 'smithsonian' && $normalized !== 'cc0') throw new InvalidArgumentException('Smithsonian full-auto wymaga CC0 per asset.');
    if ($provider === 'europeana') {
        $allowed = [
            'http://creativecommons.org/publicdomain/zero/1.0/', 'https://creativecommons.org/publicdomain/zero/1.0/',
            'http://creativecommons.org/publicdomain/mark/1.0/', 'https://creativecommons.org/publicdomain/mark/1.0/',
            'http://creativecommons.org/licenses/by/4.0/', 'https://creativecommons.org/licenses/by/4.0/',
            'http://creativecommons.org/licenses/by-sa/4.0/', 'https://creativecommons.org/licenses/by-sa/4.0/',
        ];
        if (!in_array(rtrim($rightsRaw, '/') . '/', $allowed, true)) throw new InvalidArgumentException('Europeana wymaga dokładnego dozwolonego edm:rights per asset.');
    }
    if ($provider === 'eso' && ($normalized !== 'cc-by' || !str_contains($rightsLower, 'cc by 4.0') || preg_match('/exception|excluded|not covered/u', $rightsLower))) {
        throw new InvalidArgumentException('ESO wymaga domyślnej CC BY 4.0 bez wyjątku per asset.');
    }
    if ($provider === 'nasa' && !str_contains($rightsLower, 'nasa-produced')) throw new InvalidArgumentException('NASA full-auto wymaga potwierdzenia treści własnej NASA.');
    if ($provider === 'usgs' && !str_contains($rightsLower, 'usgs-produced')) throw new InvalidArgumentException('USGS full-auto wymaga potwierdzenia treści własnej USGS.');
    if ($provider === 'nci' && ($normalized !== 'public-domain' || !str_contains($rightsLower, 'public domain'))) throw new InvalidArgumentException('NCI full-auto wymaga Public Domain per asset.');
    if ($provider === 'pexels' && ($normalized !== 'pexels-license' || !str_contains((string) $manifest['exact_credit_line'], ' on Pexels'))) {
        throw new InvalidArgumentException('Pexels wymaga per-item Pexels License i pełnego credit line.');
    }
    if (!is_array($manifest['license_snapshot'])) throw new InvalidArgumentException('Manifest praw wymaga migawki licencji.');
    $expectedHash = hash('sha256', generation_json($manifest['license_snapshot']));
    if (!hash_equals($expectedHash, (string) $manifest['license_snapshot_hash'])) throw new InvalidArgumentException('Skrót migawki licencji jest nieprawidłowy.');
    $manifest['license_code'] = $normalized;
    return $manifest;
}

function image_rights_manifest_from_record(array $image): ?array
{
    $manifest = $image['rights_manifest'] ?? null;
    if (!is_array($manifest)) $manifest = json_decode((string) ($image['rights_manifest_json'] ?? ''), true);
    if (!is_array($manifest) || $manifest === []) return null;
    try { return validate_image_rights_manifest($manifest); } catch (InvalidArgumentException) { return null; }
}

function image_rights_risk_flags(string ...$texts): array
{
    $text = mb_strtolower(implode(' ', $texts));
    return [
        'third_party_warning' => preg_match('/third[- ]party|copyright(?:ed| protected)|rights reserved|courtesy of|©/u', $text) === 1,
        'identifiable_people' => preg_match('/\b(portrait|person|people|astronaut|scientist|patient|celebrity)\b/u', $text) === 1,
        'trademarks_logos' => preg_match('/\b(logo|trademark|brand|insignia|emblem|artwork|painting|sculpture)\b/u', $text) === 1,
    ];
}

function image_provider_diagnostics(): array
{
    return [
        'smithsonian' => ['mode' => app_config('smithsonian_api_key') !== '' ? 'full_auto' : 'disabled_missing_key'],
        'europeana' => ['mode' => app_config('europeana_api_key') !== '' ? 'full_auto' : 'disabled_missing_key'],
        'eso' => ['mode' => app_config('eso_asset_catalog_url') !== '' ? 'full_auto' : 'asset_validation_only'],
        'nasa' => ['mode' => 'full_auto'],
        'usgs' => ['mode' => app_config('usgs_asset_catalog_url') !== '' ? 'full_auto' : 'asset_validation_only'],
        'nci' => ['mode' => app_config('nci_asset_catalog_url') !== '' ? 'full_auto' : 'asset_validation_only'],
        'pexels' => ['mode' => app_config('pexels_api_key') !== '' ? 'full_auto' : 'disabled_missing_key'],
        'unsplash' => ['mode' => 'manual_only', 'reason' => 'API wymaga hotlinkowania, download triggera i nieautomatycznych żądań.'],
        'pixabay' => ['mode' => 'manual_only', 'reason' => 'API jest przeznaczone dla rzeczywistych żądań człowieka, nie masowego automatu.'],
    ];
}

function image_provider_cache_get(string $provider, string $query): ?array
{
    $statement = bueno_database()->prepare('SELECT response_json, expires_at FROM image_provider_cache WHERE provider=:provider AND query_hash=:hash');
    $statement->execute([':provider' => $provider, ':hash' => hash('sha256', mb_strtolower(trim($query)))]);
    $row = $statement->fetch();
    if (!is_array($row) || strtotime((string) $row['expires_at']) <= time()) return null;
    $value = json_decode((string) $row['response_json'], true);
    return is_array($value) ? $value : null;
}

function image_provider_cache_put(string $provider, string $query, array $response): void
{
    $ttl = (int) app_config('source_image_provider_cache_seconds');
    bueno_database()->prepare('INSERT INTO image_provider_cache(provider,query_hash,response_json,expires_at) VALUES(:provider,:hash,:json,:expires)
        ON CONFLICT(provider,query_hash) DO UPDATE SET response_json=excluded.response_json, expires_at=excluded.expires_at, updated_at=CURRENT_TIMESTAMP')
        ->execute([':provider' => $provider, ':hash' => hash('sha256', mb_strtolower(trim($query))), ':json' => generation_json($response), ':expires' => gmdate('Y-m-d H:i:s', time() + $ttl)]);
}

function image_provider_rate_limit_acquire(string $provider, int $limit, int $windowSeconds = 3600): void
{
    $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
    $window = gmdate('Y-m-d H:i:s', $windowStart);
    $database = bueno_database();
    $database->prepare('INSERT INTO image_provider_rate_windows(provider,window_started_at,request_count) VALUES(:provider,:window,1)
        ON CONFLICT(provider,window_started_at) DO UPDATE SET request_count=request_count+1')
        ->execute([':provider' => $provider, ':window' => $window]);
    $statement = $database->prepare('SELECT request_count FROM image_provider_rate_windows WHERE provider=:provider AND window_started_at=:window');
    $statement->execute([':provider' => $provider, ':window' => $window]);
    if ((int) $statement->fetchColumn() > $limit) throw new RuntimeException('Lokalny limit zapytań providera został osiągnięty; pipeline użyje kolejnego źródła.');
}
