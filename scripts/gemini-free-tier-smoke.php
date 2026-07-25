<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('CMS_ALLOW_REAL_GEMINI_SMOKE') !== '1') {
    fwrite(STDERR, "Ten test wykonuje prawdziwe zapytanie. Ustaw CMS_ALLOW_REAL_GEMINI_SMOKE=1 świadomie.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/php/app-config.php';
require_once dirname(__DIR__) . '/php/generation-service.php';

$apiKey = app_environment_value('GEMINI_API_KEY');
if ($apiKey === null) {
    fwrite(STDERR, "Brakuje GEMINI_API_KEY.\n");
    exit(2);
}

$schema = [
    'type' => 'object',
    'properties' => [
        'ok' => ['type' => 'boolean'],
        'provider' => ['type' => 'string', 'enum' => ['gemini']],
    ],
    'required' => ['ok', 'provider'],
    'additionalProperties' => false,
];
$payload = [
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => 'Zwróć wyłącznie JSON potwierdzający test: ok=true, provider="gemini".']],
    ]],
    'generationConfig' => [
        'responseMimeType' => 'application/json',
        'responseJsonSchema' => $schema,
        'temperature' => 0,
    ],
];
$response = gemini_curl_transport(
    $payload,
    $apiKey,
    'explicit-smoke-' . bin2hex(random_bytes(6)),
    (string) app_config('gemini_model')
);
if ((int) $response['status'] === 429) {
    fwrite(STDERR, "GEMINI_FREE_TIER_LIMIT: ponów później albo użyj importu ręcznego.\n");
    exit(3);
}
if ((int) $response['status'] < 200 || (int) $response['status'] >= 300) {
    fwrite(STDERR, "GEMINI_SMOKE_HTTP_" . (int) $response['status'] . "\n");
    exit(1);
}
$decoded = json_decode((string) $response['body'], true, 128, JSON_THROW_ON_ERROR);
$output = gemini_extract_output($decoded);
$value = decode_generation_response((string) $output['text']);
validate_generation_value($value, $schema);
if (($value['ok'] ?? false) !== true || ($value['provider'] ?? '') !== 'gemini') {
    throw new RuntimeException('Gemini zwróciło nieoczekiwany wynik.');
}

echo "GEMINI_FREE_TIER_SMOKE_OK\n";

