<?php

declare(strict_types=1);

function editorial_datetime_to_utc(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $timezone = new DateTimeZone((string) app_config('timezone'));
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Podano nieprawidłową datę lub godzinę.');
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function editorial_datetime_for_input(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) app_config('timezone')))
            ->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
}

function normalize_ai_components(array $components): array
{
    $allowed = ['research', 'text', 'seo', 'image'];

    return array_values(array_intersect($allowed, array_unique(array_map('strval', $components))));
}

function parse_editorial_sources(array $input): array
{
    $urls = is_array($input['source_url'] ?? null) ? $input['source_url'] : [];
    $titles = is_array($input['source_title'] ?? null) ? $input['source_title'] : [];
    $publishers = is_array($input['source_publisher'] ?? null) ? $input['source_publisher'] : [];
    $sources = [];
    foreach ($urls as $index => $url) {
        if (trim((string) $url) === '') {
            continue;
        }
        $sources[] = [
            'source_url' => $url,
            'source_title' => $titles[$index] ?? '',
            'publisher_name' => $publishers[$index] ?? '',
            'source_type' => $index === 0 ? 'primary' : 'secondary',
        ];
    }

    return $sources;
}

function validate_post_editorial_fields_input(array $fields, array $sources): void
{
    $authorId = filter_var($fields['author_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if ($authorId <= 0 || find_author($authorId) === null) {
        throw new InvalidArgumentException('Wybierz istniejącego autora.');
    }
    if (mb_strlen(trim((string) ($fields['seo_description'] ?? ''))) > 160
        || mb_strlen(trim((string) ($fields['image_alt'] ?? ''))) > 250
        || mb_strlen(trim((string) ($fields['ai_disclosure'] ?? ''))) > 1000) {
        throw new InvalidArgumentException('Opis SEO, alt obrazu lub informacja o AI przekracza dozwoloną długość.');
    }
    $publishedAt = editorial_datetime_to_utc($fields['published_at'] ?? null);
    editorial_datetime_to_utc($fields['scheduled_at'] ?? null);
    $updatedAt = editorial_datetime_to_utc($fields['content_updated_at'] ?? null);
    if ($publishedAt !== null && $updatedAt !== null && strtotime($updatedAt) < strtotime($publishedAt)) {
        throw new InvalidArgumentException('Data aktualizacji nie może być wcześniejsza od daty publikacji.');
    }
    foreach ($sources as $source) {
        normalize_post_source($source);
    }
}

function update_post_editorial_fields(int $postId, array $fields, array $sources): void
{
    validate_post_editorial_fields_input($fields, $sources);
    $existingPost = find_post($postId);
    if ($existingPost === null) {
        throw new RuntimeException('Nie znaleziono materiału do aktualizacji.');
    }
    $authorId = filter_var($fields['author_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if ($authorId <= 0 || find_author($authorId) === null) {
        throw new InvalidArgumentException('Wybierz istniejącego autora.');
    }
    $seoDescription = trim((string) ($fields['seo_description'] ?? ''));
    $imageAlt = trim((string) ($fields['image_alt'] ?? ''));
    $aiDisclosure = trim((string) ($fields['ai_disclosure'] ?? ''));
    if (mb_strlen($seoDescription) > 160 || mb_strlen($imageAlt) > 250 || mb_strlen($aiDisclosure) > 1000) {
        throw new InvalidArgumentException('Opis SEO, alt obrazu lub informacja o AI przekracza dozwoloną długość.');
    }

    $publishedAt = editorial_datetime_to_utc($fields['published_at'] ?? null)
        ?? (trim((string) ($existingPost['published_at'] ?? '')) ?: null);
    $scheduledAt = editorial_datetime_to_utc($fields['scheduled_at'] ?? null);
    $updatedAt = editorial_datetime_to_utc($fields['content_updated_at'] ?? null);
    $currentUpdatedAt = trim((string) ($existingPost['content_updated_at'] ?? ''));
    if ($currentUpdatedAt !== '' && ($updatedAt === null || strtotime($updatedAt) < strtotime($currentUpdatedAt))) {
        $updatedAt = $currentUpdatedAt;
    }
    if ($publishedAt !== null && $updatedAt !== null && strtotime($updatedAt) < strtotime($publishedAt)) {
        throw new InvalidArgumentException('Data aktualizacji nie może być wcześniejsza od daty publikacji.');
    }
    $components = normalize_ai_components(is_array($fields['ai_components'] ?? null) ? $fields['ai_components'] : []);
    $aiAssisted = $components !== [] || $aiDisclosure !== '';

    foreach ($sources as $source) {
        normalize_post_source($source);
    }

    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'UPDATE posts SET author_id = :author_id, seo_description = :seo_description,
             image_alt = :image_alt, published_at = :published_at,
             scheduled_at = :scheduled_at, content_updated_at = :content_updated_at,
             ai_assisted = :ai_assisted, ai_components = :ai_components,
             ai_disclosure = :ai_disclosure, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND deleted_at IS NULL'
        );
        $statement->execute([
            ':id' => $postId,
            ':author_id' => $authorId,
            ':seo_description' => $seoDescription,
            ':image_alt' => $imageAlt,
            ':published_at' => $publishedAt,
            ':scheduled_at' => $scheduledAt,
            ':content_updated_at' => $updatedAt ?? gmdate('Y-m-d H:i:s'),
            ':ai_assisted' => $aiAssisted ? 1 : 0,
            ':ai_components' => json_encode($components, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':ai_disclosure' => $aiDisclosure,
        ]);
        replace_post_sources($postId, $sources);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function editorial_title_similarity(string $first, string $second): float
{
    $tokens = static function (string $title): array {
        $title = mb_strtolower($title);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => mb_strlen($part) >= 3)));
    };
    $a = $tokens($first);
    $b = $tokens($second);
    if ($a === [] || $b === []) {
        return 0.0;
    }

    return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
}

function editorial_post_warnings(array $post, array $sources): array
{
    $warnings = [];
    if ($sources === []) $warnings[] = 'Brak źródeł.';
    if (mb_strlen((string) $post['title']) > 100) $warnings[] = 'Tytuł jest bardzo długi.';
    if (trim((string) ($post['seo_description'] ?? '')) === '') $warnings[] = 'Opis SEO jest pusty.';
    $imagePath = trim((string) ($post['image_path'] ?? ''));
    if ($imagePath === '' || !is_file(app_path($imagePath))) {
        $warnings[] = 'Brak głównego obrazu.';
    } else {
        $info = @getimagesize(app_path($imagePath));
        if ((int) ($info[0] ?? 0) < 1200 || (int) ($info[1] ?? 0) < 630) {
            $warnings[] = 'Główny obraz jest mniejszy niż zalecane 1200 × 630 px.';
        }
    }
    if (preg_match('/<\s*\/?\s*(script|iframe|object|embed|form|style)\b/i', (string) $post['content']) === 1) {
        $warnings[] = 'Treść zawiera niedozwolony znacznik HTML.';
    }
    foreach (list_posts(null, true) as $published) {
        if ((int) $published['id'] !== (int) $post['id']
            && editorial_title_similarity((string) $post['title'], (string) $published['title']) >= 0.75) {
            $warnings[] = 'Istnieje bardzo podobny opublikowany materiał: „' . $published['title'] . '”.';
            break;
        }
    }

    return $warnings;
}
