<?php

declare(strict_types=1);

/**
 * Environment-backed application configuration.
 *
 * Secrets must be provided by the server environment. This file contains only
 * safe defaults that keep an existing local installation operational.
 */
function app_environment_value(string $name): ?string
{
    $value = getenv($name);

    if ($value === false && isset($_ENV[$name]) && is_string($_ENV[$name])) {
        $value = $_ENV[$name];
    }

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : $value;
}

function app_environment_bool(string $name, bool $default): bool
{
    $value = app_environment_value($name);

    if ($value === null) {
        return $default;
    }

    $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    return is_bool($normalized) ? $normalized : $default;
}

function app_environment_int(string $name, int $default, int $minimum, int $maximum): int
{
    $value = app_environment_value($name);

    if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false) {
        return $default;
    }

    return max($minimum, min($maximum, (int) $value));
}

function app_environment_list(string $name, array $default): array
{
    $value = app_environment_value($name);
    if ($value === null) {
        return $default;
    }
    $items = array_map(
        static fn (string $item): string => strtolower(trim($item)),
        explode(',', $value)
    );

    return array_values(array_unique(array_filter(
        $items,
        static fn (string $item): bool => preg_match('/^[a-z0-9_-]{2,50}$/', $item) === 1
    )));
}

function app_normalize_public_url(?string $url): string
{
    if ($url === null) {
        return '';
    }

    $url = rtrim($url, '/');
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return $url;
}

function app_normalize_relative_directory(?string $directory, string $default): string
{
    $directory = trim(str_replace('\\', '/', $directory ?? $default), '/');

    if ($directory === ''
        || str_contains($directory, '..')
        || preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$#', $directory) !== 1) {
        return $default;
    }

    return $directory;
}

function app_generation_mode_from_environment(): string
{
    $mode = strtolower(app_environment_value('CMS_GENERATION_MODE') ?? 'manual');

    return in_array($mode, ['manual', 'api'], true) ? $mode : 'manual';
}

function app_config(?string $key = null): mixed
{
    static $configuration = null;

    if (!is_array($configuration)) {
        $timezone = app_environment_value('CMS_TIMEZONE') ?? 'Europe/Warsaw';
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            $timezone = 'Europe/Warsaw';
        }

        $configuration = [
            'environment' => app_environment_value('CMS_ENV') ?? 'development',
            'public_url' => app_normalize_public_url(app_environment_value('CMS_PUBLIC_URL')),
            'site_name' => app_environment_value('CMS_SITE_NAME') ?? 'Twoja marka',
            'language' => app_environment_value('CMS_LANGUAGE') ?? 'pl-PL',
            'timezone' => $timezone,
            'publisher_name' => app_environment_value('CMS_PUBLISHER_NAME') ?? 'Twoja marka',
            'publisher_legal_name' => app_environment_value('CMS_PUBLISHER_LEGAL_NAME') ?? '',
            'editorial_contact_email' => app_environment_value('CMS_EDITORIAL_CONTACT_EMAIL') ?? '',
            'privacy_contact_email' => app_environment_value('CMS_PRIVACY_CONTACT_EMAIL') ?? '',
            'contact_retention_policy' => app_environment_value('CMS_CONTACT_RETENTION_POLICY') ?? '',
            'default_author' => app_environment_value('CMS_DEFAULT_AUTHOR') ?? 'Redakcja',
            'post_image_directory' => app_normalize_relative_directory(
                app_environment_value('CMS_POST_IMAGE_DIRECTORY'),
                'images/posts'
            ),
            'automatic_publishing' => app_environment_bool('CMS_AUTOMATIC_PUBLISHING', false),
            'daily_publication_limit' => app_environment_int('CMS_DAILY_PUBLICATION_LIMIT', 5, 1, 50),
            'preferred_topic_categories' => app_environment_list(
                'CMS_PREFERRED_TOPIC_CATEGORIES',
                [
                    'new-technologies',
                    'how-it-works',
                    'space',
                    'earth-nature',
                    'energy-climate',
                    'robotics-transport',
                    'materials-inventions',
                    'human-technology',
                ]
            ),
            'feed_ca_bundle' => app_environment_value('CMS_FEED_CA_BUNDLE') ?? '',
            'generation_mode' => app_generation_mode_from_environment(),
            'openai_model' => app_environment_value('OPENAI_MODEL') ?? 'gpt-5.6-terra',
            'openai_image_model' => app_environment_value('OPENAI_IMAGE_MODEL') ?? 'gpt-image-2',
            'image_processor_python' => app_environment_value('CMS_IMAGE_PROCESSOR_PYTHON') ?? '',
            'openai_api_base_url' => app_normalize_public_url(
                app_environment_value('OPENAI_API_BASE_URL') ?? 'https://api.openai.com/v1'
            ),
            'openai_timeout_seconds' => app_environment_int('OPENAI_TIMEOUT_SECONDS', 60, 10, 180),
            'openai_max_attempts' => app_environment_int('OPENAI_MAX_ATTEMPTS', 3, 1, 4),
            'openai_mock' => app_environment_bool('OPENAI_API_MOCK', false),
            'openai_ca_bundle' => app_environment_value('OPENAI_CA_BUNDLE') ?? '',
        ];

        date_default_timezone_set((string) $configuration['timezone']);
    }

    if ($key === null) {
        return $configuration;
    }

    if (!array_key_exists($key, $configuration)) {
        throw new InvalidArgumentException('Nieznany klucz konfiguracji aplikacji: ' . $key);
    }

    return $configuration[$key];
}

function app_project_root(): string
{
    return dirname(__DIR__);
}

function app_path(string $relativePath = ''): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return app_project_root() . ($relativePath === '' ? '' : '/' . $relativePath);
}

function app_post_image_directory(): string
{
    return (string) app_config('post_image_directory');
}

function app_post_image_path(string $filename = ''): string
{
    $directory = app_path(app_post_image_directory());
    $filename = ltrim(str_replace('\\', '/', $filename), '/');

    return $directory . ($filename === '' ? '' : '/' . $filename);
}

function app_detect_request_base_url(): string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return '';
    }

    $host = trim((string) $_SERVER['HTTP_HOST']);
    if (preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/', $host) !== 1) {
        return '';
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $forwardedProto === 'https';
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('#/php/[^/]*$#', '', $scriptName) ?? '';
    $basePath = rtrim($basePath, '/');

    return ($isHttps ? 'https' : 'http') . '://' . $host . $basePath;
}

function app_public_base_url(): string
{
    $configuredUrl = (string) app_config('public_url');

    if ($configuredUrl !== '') {
        return $configuredUrl;
    }

    $detectedUrl = app_detect_request_base_url();

    if ($detectedUrl !== '') {
        return $detectedUrl;
    }

    throw new RuntimeException(
        'Brakuje CMS_PUBLIC_URL. Ustaw pełny publiczny adres witryny, np. https://example.com.'
    );
}

function app_public_url(string $path = ''): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');

    return app_public_base_url() . ($path === '' ? '' : '/' . $path);
}

function app_config_issues(): array
{
    $issues = [];
    $environment = strtolower((string) app_config('environment'));

    if ((string) app_config('public_url') === '') {
        $issues[] = [
            'level' => in_array($environment, ['production', 'prod'], true) ? 'error' : 'warning',
            'message' => 'Brakuje CMS_PUBLIC_URL. Żądania WWW użyją wykrytego adresu, ale generatory CLI nie zbudują absolutnych URL-i.',
        ];
    }

    if (app_environment_value('CMS_TIMEZONE') !== null
        && app_environment_value('CMS_TIMEZONE') !== (string) app_config('timezone')) {
        $issues[] = [
            'level' => 'warning',
            'message' => 'CMS_TIMEZONE jest nieprawidłowe. Użyto Europe/Warsaw.',
        ];
    }

    return $issues;
}
