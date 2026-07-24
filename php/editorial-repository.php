<?php

declare(strict_types=1);

function editorial_post_statuses(): array
{
    return ['idea', 'research', 'draft', 'review', 'scheduled', 'published', 'rejected'];
}

function editorial_status_labels(): array
{
    return [
        'idea' => 'Pomysł',
        'research' => 'Research',
        'draft' => 'Szkic',
        'review' => 'Do sprawdzenia',
        'scheduled' => 'Zaplanowany',
        'published' => 'Opublikowany',
        'rejected' => 'Odrzucony',
    ];
}

function normalize_editorial_status(string $status): string
{
    $status = strtolower(trim($status));

    if (!in_array($status, editorial_post_statuses(), true)) {
        throw new InvalidArgumentException('Nieprawidłowy status redakcyjny.');
    }

    return $status;
}

function list_authors(bool $activeOnly = false): array
{
    $query = 'SELECT * FROM authors';

    if ($activeOnly) {
        $query .= ' WHERE is_active = 1';
    }

    return bueno_database()->query($query . ' ORDER BY name ASC, id ASC')->fetchAll();
}

function find_author(int $authorId): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM authors WHERE id = :id');
    $statement->execute([':id' => $authorId]);
    $author = $statement->fetch();

    return is_array($author) ? $author : null;
}

function default_author_id(): int
{
    $slug = editorial_author_slug((string) app_config('default_author'));
    $statement = bueno_database()->prepare('SELECT id FROM authors WHERE slug = :slug');
    $statement->execute([':slug' => $slug]);
    $authorId = (int) $statement->fetchColumn();

    if ($authorId <= 0) {
        throw new RuntimeException('Brakuje domyślnego autora w bazie.');
    }

    return $authorId;
}

function create_author(string $name, string $bio = '', string $profileUrl = ''): int
{
    $name = trim($name);

    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Podaj poprawną nazwę autora.');
    }

    $profileUrl = trim($profileUrl);
    if ($profileUrl !== '' && filter_var($profileUrl, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Podaj poprawny adres profilu autora.');
    }

    $database = bueno_database();
    $baseSlug = editorial_author_slug($name);
    $slug = $baseSlug;
    $suffix = 2;
    $check = $database->prepare('SELECT COUNT(*) FROM authors WHERE slug = :slug');

    do {
        $check->execute([':slug' => $slug]);
        if ((int) $check->fetchColumn() === 0) {
            break;
        }
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    } while (true);

    $statement = $database->prepare(
        'INSERT INTO authors (name, slug, bio, profile_url)
         VALUES (:name, :slug, :bio, :profile_url)'
    );
    $statement->execute([
        ':name' => $name,
        ':slug' => $slug,
        ':bio' => trim($bio),
        ':profile_url' => $profileUrl,
    ]);

    return (int) $database->lastInsertId();
}

function list_post_sources(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT *
         FROM post_sources
         WHERE post_id = :post_id
         ORDER BY is_primary DESC, id ASC'
    );
    $statement->execute([':post_id' => $postId]);

    return $statement->fetchAll();
}

function normalize_post_source(array $source): array
{
    $url = trim((string) ($source['source_url'] ?? $source['url'] ?? ''));
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Źródło artykułu musi mieć poprawny adres HTTP lub HTTPS.');
    }

    $sourceType = strtolower(trim((string) ($source['source_type'] ?? 'secondary')));
    if (!in_array($sourceType, ['primary', 'secondary', 'reference'], true)) {
        $sourceType = 'secondary';
    }

    return [
        'source_url' => $url,
        'source_title' => mb_substr(trim((string) ($source['source_title'] ?? $source['title'] ?? '')), 0, 500),
        'publisher_name' => mb_substr(trim((string) ($source['publisher_name'] ?? '')), 0, 200),
        'source_type' => $sourceType,
        'is_primary' => $sourceType === 'primary' || !empty($source['is_primary']) ? 1 : 0,
        'source_published_at' => trim((string) ($source['source_published_at'] ?? '')) ?: null,
        'accessed_at' => trim((string) ($source['accessed_at'] ?? '')) ?: gmdate('Y-m-d H:i:s'),
    ];
}

function replace_post_sources(int $postId, array $sources): void
{
    if (find_post($postId, true) === null) {
        throw new RuntimeException('Nie znaleziono artykułu dla podanych źródeł.');
    }

    $normalizedSources = [];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $normalized = normalize_post_source($source);
        $normalizedSources[$normalized['source_url']] = $normalized;
    }

    $database = bueno_database();
    $ownsTransaction = !$database->inTransaction();
    if ($ownsTransaction) {
        $database->beginTransaction();
    }

    try {
        $delete = $database->prepare('DELETE FROM post_sources WHERE post_id = :post_id');
        $delete->execute([':post_id' => $postId]);
        $insert = $database->prepare(
            'INSERT INTO post_sources (
                post_id, source_url, source_title, publisher_name, source_type,
                is_primary, source_published_at, accessed_at
             ) VALUES (
                :post_id, :source_url, :source_title, :publisher_name, :source_type,
                :is_primary, :source_published_at, :accessed_at
             )'
        );

        foreach ($normalizedSources as $source) {
            $insert->execute([
                ':post_id' => $postId,
                ':source_url' => $source['source_url'],
                ':source_title' => $source['source_title'],
                ':publisher_name' => $source['publisher_name'],
                ':source_type' => $source['source_type'],
                ':is_primary' => $source['is_primary'],
                ':source_published_at' => $source['source_published_at'],
                ':accessed_at' => $source['accessed_at'],
            ]);
        }

        if ($ownsTransaction) {
            $database->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function record_post_status_change(
    int $postId,
    ?string $previousStatus,
    string $newStatus,
    string $reason = '',
    string $actor = 'system'
): int {
    $newStatus = normalize_editorial_status($newStatus);
    $previousStatus = $previousStatus === null ? null : normalize_editorial_status($previousStatus);
    $statement = bueno_database()->prepare(
        'INSERT INTO post_status_history (
            post_id, previous_status, new_status, reason, actor
         ) VALUES (
            :post_id, :previous_status, :new_status, :reason, :actor
         )'
    );
    $statement->execute([
        ':post_id' => $postId,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':reason' => trim($reason),
        ':actor' => trim($actor) !== '' ? trim($actor) : 'system',
    ]);

    return (int) bueno_database()->lastInsertId();
}

function list_post_status_history(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT *
         FROM post_status_history
         WHERE post_id = :post_id
         ORDER BY datetime(created_at) DESC, id DESC'
    );
    $statement->execute([':post_id' => $postId]);

    return $statement->fetchAll();
}

function list_editorial_queue(?string $status = null): array
{
    $parameters = [];
    $where = 'posts.deleted_at IS NULL AND post_categories.deleted_at IS NULL';
    if ($status !== null && $status !== '') {
        $status = normalize_editorial_status($status);
        $where .= ' AND posts.status = :status';
        $parameters[':status'] = $status;
    }

    $statement = bueno_database()->prepare(
        "SELECT posts.*,
                post_categories.title AS category_title,
                post_categories.slug AS category_slug,
                (SELECT COUNT(*) FROM post_sources WHERE post_sources.post_id = posts.id) AS source_count,
                (SELECT GROUP_CONCAT(COALESCE(NULLIF(source_title, ''), source_url), ' • ')
                   FROM post_sources WHERE post_sources.post_id = posts.id) AS source_labels,
                (SELECT error_message FROM post_generation_runs
                   WHERE post_generation_runs.post_id = posts.id
                     AND error_message != ''
                   ORDER BY datetime(started_at) DESC, id DESC LIMIT 1) AS last_generation_error
         FROM posts
         INNER JOIN post_categories ON post_categories.id = posts.category_id
         WHERE {$where}
         ORDER BY
            CASE posts.status
                WHEN 'review' THEN 1 WHEN 'scheduled' THEN 2 WHEN 'draft' THEN 3
                WHEN 'research' THEN 4 WHEN 'idea' THEN 5
                WHEN 'rejected' THEN 6 ELSE 7
            END,
            datetime(COALESCE(posts.scheduled_at, posts.created_at)) DESC,
            posts.id DESC"
    );
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function create_post_generation_run(
    ?int $postId,
    string $generationType,
    string $provider = '',
    string $model = '',
    array $metadata = []
): int {
    $generationType = strtolower(trim($generationType));
    if (!in_array($generationType, ['research', 'text', 'image', 'quality'], true)) {
        throw new InvalidArgumentException('Nieprawidłowy typ generowania.');
    }

    $statement = bueno_database()->prepare(
        'INSERT INTO post_generation_runs (
            post_id, generation_type, provider, model, metadata_json
         ) VALUES (
            :post_id, :generation_type, :provider, :model, :metadata_json
         )'
    );
    $statement->execute([
        ':post_id' => $postId,
        ':generation_type' => $generationType,
        ':provider' => trim($provider),
        ':model' => trim($model),
        ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    return (int) bueno_database()->lastInsertId();
}

function finish_post_generation_run(
    int $runId,
    string $status,
    string $providerResponseId = '',
    string $resultReference = '',
    array $usage = [],
    string $errorMessage = ''
): void {
    $status = strtolower(trim($status));
    if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
        throw new InvalidArgumentException('Nieprawidłowy status generowania.');
    }

    $statement = bueno_database()->prepare(
        'UPDATE post_generation_runs
         SET status = :status,
             provider_response_id = :provider_response_id,
             result_reference = :result_reference,
             usage_json = :usage_json,
             error_message = :error_message,
             finished_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        ':id' => $runId,
        ':status' => $status,
        ':provider_response_id' => trim($providerResponseId),
        ':result_reference' => trim($resultReference),
        ':usage_json' => json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ':error_message' => trim($errorMessage),
    ]);

    if ($statement->rowCount() === 0) {
        throw new RuntimeException('Nie znaleziono operacji generowania.');
    }
}
