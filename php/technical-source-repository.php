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

    return [
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
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute($source + [':id' => $sourceId]);
            return $sourceId;
        }
        $statement = $database->prepare(
            'INSERT INTO technical_sources (
                name, website_url, feed_url, source_type, topic_category,
                language, credibility_level, is_primary, is_active
             ) VALUES (
                :name, :website_url, :feed_url, :source_type, :topic_category,
                :language, :credibility_level, :is_primary, :is_active
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

function record_technical_source_check(int $sourceId, bool $success, string $error = ''): void
{
    $statement = bueno_database()->prepare(
        'UPDATE technical_sources SET
            last_checked_at = CURRENT_TIMESTAMP,
            last_success_at = CASE WHEN CAST(:success AS INTEGER) = 1 THEN CURRENT_TIMESTAMP ELSE last_success_at END,
            last_error = CASE WHEN CAST(:success AS INTEGER) = 1 THEN "" ELSE :error END,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':success' => $success ? 1 : 0,
        ':error' => mb_substr(trim($error), 0, 2000),
        ':id' => $sourceId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Nie znaleziono źródła.');
    }
}
