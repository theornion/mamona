<?php

declare(strict_types=1);

function normalize_technical_source_url(string $url, string $fieldLabel): string
{
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new InvalidArgumentException($fieldLabel . ' musi być pełnym adresem HTTPS.');
    }
    if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
        throw new InvalidArgumentException($fieldLabel . ' nie może zawierać danych logowania.');
    }
    $query = (string) parse_url($url, PHP_URL_QUERY);
    if ($query !== '') {
        parse_str($query, $parameters);
        foreach (array_keys($parameters) as $name) {
            if (preg_match('/(?:api[_-]?key|token|secret|password|credential|authorization)/i', (string) $name) === 1) {
                throw new InvalidArgumentException($fieldLabel . ' wygląda jak adres zawierający sekret lub klucz API.');
            }
        }
    }

    return $url;
}

function normalize_technical_source(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        throw new InvalidArgumentException('Nazwa źródła jest wymagana i może mieć do 150 znaków.');
    }
    $sourceType = strtolower(trim((string) ($input['source_type'] ?? 'rss')));
    if (!in_array($sourceType, ['rss', 'api'], true)) {
        throw new InvalidArgumentException('Typ źródła musi mieć wartość RSS lub API.');
    }
    $category = strtolower(trim((string) ($input['topic_category'] ?? 'technology')));
    if (preg_match('/^[a-z0-9_-]{2,50}$/', $category) !== 1) {
        throw new InvalidArgumentException('Kategoria tematyczna ma nieprawidłowy format.');
    }
    $language = strtolower(trim((string) ($input['language'] ?? 'en')));
    if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) !== 1) {
        throw new InvalidArgumentException('Język musi mieć format en lub en-us.');
    }
    $credibility = filter_var($input['credibility_level'] ?? null, FILTER_VALIDATE_INT);
    if ($credibility === false || $credibility < 1 || $credibility > 5) {
        throw new InvalidArgumentException('Poziom wiarygodności musi mieścić się od 1 do 5.');
    }

    $normalized = [
        'name' => $name,
        'website_url' => normalize_technical_source_url((string) ($input['website_url'] ?? ''), 'URL strony'),
        'feed_url' => normalize_technical_source_url((string) ($input['feed_url'] ?? ''), 'URL kanału'),
        'source_type' => $sourceType,
        'topic_category' => $category,
        'language' => $language,
        'credibility_level' => (int) $credibility,
        'is_primary' => !empty($input['is_primary']) ? 1 : 0,
        'is_active' => !empty($input['is_active']) ? 1 : 0,
    ];
    foreach ([
        'feed_connect_timeout_seconds'=>[2,20], 'feed_transfer_timeout_seconds'=>[10,90],
        'feed_low_speed_limit'=>[1,65536], 'feed_low_speed_time_seconds'=>[5,60],
        'feed_max_attempts'=>[1,4], 'feed_job_budget_seconds'=>[30,600],
    ] as $field => [$minimum, $maximum]) {
        $raw = trim((string)($input[$field] ?? ''));
        $value = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || ($value !== null && ($value < $minimum || $value > $maximum))) throw new InvalidArgumentException('Nieprawidłowa wartość transportu: ' . $field . '.');
        $normalized[$field] = $value;
    }
    return $normalized;
}

function list_technical_sources(bool $activeOnly = false): array
{
    $query = 'SELECT * FROM technical_sources' . ($activeOnly ? ' WHERE is_active = 1' : '');

    return bueno_database()->query($query . ' ORDER BY is_active DESC, credibility_level DESC, name ASC')->fetchAll();
}

function find_technical_source(int $sourceId): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM technical_sources WHERE id = :id');
    $statement->execute([':id' => $sourceId]);
    $source = $statement->fetch();

    return is_array($source) ? $source : null;
}

function delete_technical_source(int $sourceId): array
{
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare('SELECT id, name FROM technical_sources WHERE id = :id');
        $statement->execute([':id' => $sourceId]);
        $source = $statement->fetch();
        if (!is_array($source)) {
            throw new RuntimeException('Nie znaleziono źródła.');
        }

        $countStatement = $database->prepare(
            'SELECT COUNT(*) FROM discovered_feed_items WHERE technical_source_id = :id'
        );
        $countStatement->execute([':id' => $sourceId]);
        $discoveredFeedItemCount = (int) $countStatement->fetchColumn();

        $deleteStatement = $database->prepare('DELETE FROM technical_sources WHERE id = :id');
        $deleteStatement->execute([':id' => $sourceId]);
        if ($deleteStatement->rowCount() !== 1) {
            throw new RuntimeException('Nie usunięto źródła.');
        }

        $database->commit();

        return [
            'source_id' => (int) $source['id'],
            'source_name' => (string) $source['name'],
            'discovered_feed_item_count' => $discoveredFeedItemCount,
        ];
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function save_technical_source(array $input, int $sourceId = 0): int
{
    $source = normalize_technical_source($input);
    $database = bueno_database();
    if ($sourceId > 0 && find_technical_source($sourceId) === null) {
        throw new RuntimeException('Nie znaleziono źródła.');
    }
    try {
        if ($sourceId > 0) {
            $statement = $database->prepare(
                'UPDATE technical_sources SET
                    name = :name, website_url = :website_url, feed_url = :feed_url,
                    source_type = :source_type, topic_category = :topic_category,
                    language = :language, credibility_level = :credibility_level,
                    is_primary = :is_primary, is_active = :is_active,
                    feed_connect_timeout_seconds=:feed_connect_timeout_seconds,
                    feed_transfer_timeout_seconds=:feed_transfer_timeout_seconds,
                    feed_low_speed_limit=:feed_low_speed_limit,
                    feed_low_speed_time_seconds=:feed_low_speed_time_seconds,
                    feed_max_attempts=:feed_max_attempts,
                    feed_job_budget_seconds=:feed_job_budget_seconds,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute($source + [':id' => $sourceId]);
            return $sourceId;
        }
        $statement = $database->prepare(
            'INSERT INTO technical_sources (
                name, website_url, feed_url, source_type, topic_category,
                language, credibility_level, is_primary, is_active,
                feed_connect_timeout_seconds, feed_transfer_timeout_seconds,
                feed_low_speed_limit, feed_low_speed_time_seconds, feed_max_attempts, feed_job_budget_seconds
             ) VALUES (
                :name, :website_url, :feed_url, :source_type, :topic_category,
                :language, :credibility_level, :is_primary, :is_active,
                :feed_connect_timeout_seconds, :feed_transfer_timeout_seconds,
                :feed_low_speed_limit, :feed_low_speed_time_seconds, :feed_max_attempts, :feed_job_budget_seconds
             )'
        );
        $statement->execute($source);
        return (int) $database->lastInsertId();
    } catch (PDOException $exception) {
        if (str_contains(strtolower($exception->getMessage()), 'unique')) {
            throw new InvalidArgumentException('Źródło o tej nazwie lub adresie kanału już istnieje.', 0, $exception);
        }
        throw $exception;
    }
}

function set_technical_source_active(int $sourceId, bool $active): void
{
    $statement = bueno_database()->prepare(
        'UPDATE technical_sources SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $statement->execute([':active' => $active ? 1 : 0, ':id' => $sourceId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Nie znaleziono źródła.');
    }
}

function record_technical_source_check(int $sourceId, bool $success, string $error = '', array $metadata = []): void
{
    $threshold = (int) app_config('feed_failure_threshold');
    $statement = bueno_database()->prepare(
        'UPDATE technical_sources SET
            last_checked_at = CURRENT_TIMESTAMP,
            last_success_at = CASE WHEN CAST(:success AS INTEGER) = 1 THEN CURRENT_TIMESTAMP ELSE last_success_at END,
            last_error = CASE WHEN CAST(:success AS INTEGER) = 1 THEN "" ELSE :error END,
            consecutive_failures = CASE WHEN CAST(:success AS INTEGER) = 1 THEN 0 ELSE consecutive_failures + 1 END,
            health_status = CASE
                WHEN CAST(:success AS INTEGER) = 1 THEN "healthy"
                WHEN consecutive_failures + 1 >= :threshold THEN "unavailable"
                ELSE "degraded" END,
            muted_until = CASE WHEN CAST(:success AS INTEGER) = 0 AND consecutive_failures + 1 >= :threshold
                THEN datetime("now", "+30 minutes") ELSE NULL END,
            feed_etag = CASE WHEN :etag <> "" THEN :etag ELSE feed_etag END,
            feed_last_modified = CASE WHEN :last_modified <> "" THEN :last_modified ELSE feed_last_modified END,
            last_http_status = :http_status,
            last_transport_diagnostics = :diagnostics,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':success' => $success ? 1 : 0,
        ':error' => mb_substr(trim($error), 0, 2000),
        ':threshold' => $threshold,
        ':etag' => mb_substr((string) ($metadata['etag'] ?? ''), 0, 500),
        ':last_modified' => mb_substr((string) ($metadata['last_modified'] ?? ''), 0, 200),
        ':http_status' => (int) ($metadata['http_status'] ?? 0),
        ':diagnostics' => json_encode($metadata['diagnostics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id' => $sourceId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Nie znaleziono źródła.');
    }
}
