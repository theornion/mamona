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

function app_generation_provider_from_environment(): string
{
    $provider = strtolower(app_environment_value('CMS_GENERATION_PROVIDER') ?? 'gemini');

    return in_array($provider, ['gemini', 'openai'], true) ? $provider : 'gemini';
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
            'feed_connect_timeout_seconds' => app_environment_int('CMS_FEED_CONNECT_TIMEOUT_SECONDS', 8, 2, 20),
            'feed_transfer_timeout_seconds' => app_environment_int('CMS_FEED_TRANSFER_TIMEOUT_SECONDS', 45, 10, 90),
            'feed_low_speed_limit' => app_environment_int('CMS_FEED_LOW_SPEED_LIMIT', 32, 1, 65536),
            'feed_low_speed_time_seconds' => app_environment_int('CMS_FEED_LOW_SPEED_TIME_SECONDS', 20, 5, 60),
            'feed_max_attempts' => app_environment_int('CMS_FEED_MAX_ATTEMPTS', 3, 1, 4),
            'feed_job_budget_seconds' => app_environment_int('CMS_FEED_JOB_BUDGET_SECONDS', 150, 30, 600),
            'feed_failure_threshold' => app_environment_int('CMS_FEED_FAILURE_THRESHOLD', 3, 1, 20),
            'generation_mode' => app_generation_mode_from_environment(),
            'generation_provider' => app_generation_provider_from_environment(),
            'gemini_model' => app_environment_value('GEMINI_MODEL') ?? 'gemini-3.1-flash-lite',
            'gemini_api_base_url' => app_normalize_public_url(
                app_environment_value('GEMINI_API_BASE_URL') ?? 'https://generativelanguage.googleapis.com/v1beta'
            ),
            'gemini_timeout_seconds' => app_environment_int('GEMINI_TIMEOUT_SECONDS', 60, 10, 180),
            'gemini_max_attempts' => app_environment_int('GEMINI_MAX_ATTEMPTS', 3, 1, 4),
            'gemini_initial_backoff_ms' => app_environment_int('GEMINI_INITIAL_BACKOFF_MS', 750, 100, 10000),
            'gemini_mock' => app_environment_bool('GEMINI_API_MOCK', false),
            'gemini_model_fallbacks' => array_values(array_filter(array_map('trim', explode(',', app_environment_value('GEMINI_MODEL_FALLBACKS') ?? '')))),
            'gemini_quota_project' => app_environment_value('GEMINI_QUOTA_PROJECT') ?? 'default',
            'gemini_rpm_target' => app_environment_int('GEMINI_RPM_TARGET', 10, 1, 60),
            'gemini_tpm_target' => app_environment_int('GEMINI_TPM_TARGET', 250000, 1000, 10000000),
            'gemini_rpd_target' => app_environment_int('GEMINI_RPD_TARGET', 500, 1, 1000000),
            'gemini_model_concurrency' => 1,
            'gemini_quota_lease_seconds' => app_environment_int('GEMINI_QUOTA_LEASE_SECONDS', 180, 30, 900),
            'gemini_quota_reset_timezone' => app_environment_value('GEMINI_QUOTA_RESET_TIMEZONE') ?? 'UTC',
            'allow_live_gemini_test' => app_environment_bool('CMS_ALLOW_LIVE_GEMINI_TEST', false),
            'batch_worker_concurrency' => app_environment_int('CMS_BATCH_WORKER_CONCURRENCY', 1, 1, 2),
            'batch_max_topics' => app_environment_int('CMS_BATCH_MAX_TOPICS', 50, 10, 500),
            'batch_lease_seconds' => app_environment_int('CMS_BATCH_LEASE_SECONDS', 900, 120, 1800),
            'batch_rate_limit_backoff_seconds' => app_environment_int('CMS_BATCH_RATE_LIMIT_BACKOFF_SECONDS', 60, 5, 3600),
            'automatic_dispatch_paused' => app_environment_bool('CMS_AUTOMATIC_DISPATCH_PAUSED', false),
            'full_auto_enabled' => app_environment_bool('FULL_AUTO_ENABLED', false),
            'openai_model' => app_environment_value('OPENAI_MODEL') ?? 'gpt-5.6-terra',
            'openai_image_model' => app_environment_value('OPENAI_IMAGE_MODEL') ?? 'gpt-image-2',
            'image_processor_python' => app_environment_value('CMS_IMAGE_PROCESSOR_PYTHON') ?? '',
            'openai_api_base_url' => app_normalize_public_url(
                app_environment_value('OPENAI_API_BASE_URL') ?? 'https://api.openai.com/v1'
            ),
            'openai_timeout_seconds' => app_environment_int('OPENAI_TIMEOUT_SECONDS', 60, 10, 180),
            'openai_max_attempts' => app_environment_int('OPENAI_MAX_ATTEMPTS', 3, 1, 4),
            'title_repair_max_attempts' => app_environment_int('TITLE_REPAIR_MAX_ATTEMPTS', 2, 1, 5),
            'openai_mock' => app_environment_bool('OPENAI_API_MOCK', false),
            'openai_ca_bundle' => app_environment_value('OPENAI_CA_BUNDLE') ?? '',
            'ai_image_generation_enabled' => app_environment_bool('CMS_AI_IMAGE_GENERATION_ENABLED', false),
            'source_image_provider' => strtolower(app_environment_value('CMS_SOURCE_IMAGE_PROVIDER') ?? 'wikimedia'),
            'source_image_mock' => app_environment_bool('CMS_SOURCE_IMAGE_MOCK', false),
            'source_image_timeout_seconds' => app_environment_int('CMS_SOURCE_IMAGE_TIMEOUT_SECONDS', 20, 5, 60),
            'source_image_max_bytes' => app_environment_int('CMS_SOURCE_IMAGE_MAX_BYTES', 12582912, 1048576, 26214400),
            'source_image_min_width' => app_environment_int('CMS_SOURCE_IMAGE_MIN_WIDTH', 800, 320, 4000),
            'source_image_min_height' => app_environment_int('CMS_SOURCE_IMAGE_MIN_HEIGHT', 450, 240, 3000),
            'source_image_max_redirects' => app_environment_int('CMS_SOURCE_IMAGE_MAX_REDIRECTS', 3, 0, 5),
            'source_image_query_budget_per_slot' => app_environment_int('CMS_SOURCE_IMAGE_QUERY_BUDGET_PER_SLOT', 12, 1, 40),
            'source_image_candidate_budget_per_query' => app_environment_int('CMS_SOURCE_IMAGE_CANDIDATE_BUDGET_PER_QUERY', 20, 1, 50),
            'source_image_provider_cache_seconds' => app_environment_int('CMS_SOURCE_IMAGE_PROVIDER_CACHE_SECONDS', 86400, 300, 604800),
            'smithsonian_api_key' => app_environment_value('SMITHSONIAN_API_KEY') ?? '',
            'europeana_api_key' => app_environment_value('EUROPEANA_API_KEY') ?? '',
            'pexels_api_key' => app_environment_value('PEXELS_API_KEY') ?? '',
            'pexels_api_hourly_limit' => app_environment_int('PEXELS_API_HOURLY_LIMIT', 180, 1, 200),
            'eso_asset_catalog_url' => app_environment_value('CMS_ESO_ASSET_CATALOG_URL') ?? '',
            'usgs_asset_catalog_url' => app_environment_value('CMS_USGS_ASSET_CATALOG_URL') ?? '',
            'nci_asset_catalog_url' => app_environment_value('CMS_NCI_ASSET_CATALOG_URL') ?? '',
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
