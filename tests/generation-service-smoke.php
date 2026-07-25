<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_GENERATION_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_GENERATION_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function generation_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function generation_expect_exception(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        generation_assert(
            str_contains($exception->getMessage(), $messagePart),
            'Nieoczekiwany komunikat błędu: ' . $exception->getMessage()
        );
        return;
    }

    throw new RuntimeException('Oczekiwany wyjątek nie został zgłoszony.');
}

$database = bueno_database();
$originalMode = generation_mode();
$originalApiKey = getenv('GEMINI_API_KEY');
$operationIds = [];
$baselineFeedItems = (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn();
$schema = [
    'type' => 'object',
    'properties' => [
        'summary' => ['type' => 'string'],
        'decision' => ['type' => 'string', 'enum' => ['ready', 'needs_review']],
        'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    'required' => ['summary', 'decision', 'notes'],
    'additionalProperties' => false,
];
$input = [
    'title' => 'Test wspólnego kontraktu generowania',
    'facts' => ['Fakt A', 'Fakt B'],
];
$validOutput = [
    'summary' => 'Wynik poprawny.',
    'decision' => 'ready',
    'notes' => [],
];

try {
    update_generation_mode('manual');
    $manualId = prepare_generation_operation('contract_test', $input, $schema);
    $operationIds[] = $manualId;
    $manual = find_generation_operation($manualId);
    generation_assert($manual !== null, 'Nie zapisano operacji manualnej.');
    generation_assert($manual['execution_mode'] === 'manual', 'Nie zapisano trybu manual.');
    generation_assert($manual['provider'] === 'manual-json', 'Nie zapisano pochodzenia importu manualnego.');
    generation_assert(str_contains($manual['prompt_text'], generation_json($input)), 'Prompt nie zawiera kompletnych danych wejściowych.');
    generation_assert(str_contains($manual['prompt_text'], generation_json($schema)), 'Prompt nie zawiera kompletnego schematu.');

    $manualOutput = import_manual_generation_response(
        $manualId,
        "```json\n" . generation_json($validOutput) . "\n```"
    );
    generation_assert($manualOutput === $validOutput, 'Import manualny zmienił poprawną odpowiedź.');

    $invalidManualId = prepare_generation_operation('contract_test', $input, $schema);
    $operationIds[] = $invalidManualId;
    generation_expect_exception(
        static fn () => import_manual_generation_response(
            $invalidManualId,
            generation_json(['summary' => 'Brak wymaganych pól.'])
        ),
        'Brakuje wymaganego pola'
    );
    generation_assert(
        find_generation_operation($invalidManualId)['status'] === 'prepared',
        'Niepoprawny import manualny został uznany za zakończony.'
    );

    update_generation_mode('api');
    generation_assert(find_generation_operation($manualId) !== null, 'Zmiana trybu usunęła wcześniejszą operację.');
    $missingKeyId = prepare_generation_operation('contract_test', $input, $schema);
    $operationIds[] = $missingKeyId;
    putenv('GEMINI_API_KEY');
    generation_expect_exception(
        static fn () => execute_generation_operation($missingKeyId),
        'Brakuje GEMINI_API_KEY'
    );
    generation_assert(
        find_generation_operation($missingKeyId)['status'] === 'prepared',
        'Brak klucza zmienił stan operacji bez wykonania żądania.'
    );

    $apiId = prepare_generation_operation('contract_test', $input, $schema);
    $operationIds[] = $apiId;
    $attempts = 0;
    $operationKeys = [];
    $transport = static function (array $payload, string $apiKey, string $operationKey, string $model) use (
        &$attempts,
        &$operationKeys,
        $validOutput
    ): array {
        $attempts++;
        $operationKeys[] = $operationKey;
        generation_assert($apiKey === 'smoke-secret-key', 'Transport nie otrzymał przekazanego klucza.');
        generation_assert($model !== '', 'Transport Gemini nie otrzymał modelu.');
        generation_assert(
            ($payload['generationConfig']['responseMimeType'] ?? '') === 'application/json',
            'Żądanie Gemini nie wymusza JSON.'
        );
        generation_assert(
            ($payload['generationConfig']['responseJsonSchema']['additionalProperties'] ?? null) === false,
            'Żądanie Gemini nie przekazuje ścisłego schematu.'
        );
        if ($attempts === 1) {
            return [
                'status' => 500,
                'body' => generation_json(['error' => ['message' => 'Transient server error']]),
                'headers' => [],
                'network_error' => '',
            ];
        }

        return [
            'status' => 200,
            'body' => generation_json([
                'responseId' => 'resp_smoke',
                'candidates' => [[
                    'content' => ['parts' => [['text' => generation_json($validOutput)]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 10, 'totalTokenCount' => 30],
            ]),
            'headers' => [],
            'network_error' => '',
        ];
    };
    $apiOutput = execute_generation_operation($apiId, $transport, 'smoke-secret-key');
    generation_assert($apiOutput === $validOutput, 'Tryb API zwrócił inny kontrakt niż tryb manualny.');
    generation_assert($attempts === 2, 'Błąd przejściowy nie został ponowiony dokładnie raz.');
    generation_assert(count(array_unique($operationKeys)) === 1, 'Ponowienie zmieniło klucz idempotencji.');
    $apiOperation = find_generation_operation($apiId);
    generation_assert((int) $apiOperation['attempt_count'] === 2, 'Nie zapisano liczby prób.');
    generation_assert($apiOperation['provider_response_id'] === 'resp_smoke', 'Nie zapisano identyfikatora odpowiedzi.');
    generation_assert(
        json_decode($apiOperation['usage_json'], true)['totalTokenCount'] === 30,
        'Nie zapisano użycia tokenów.'
    );
    generation_assert(
        !str_contains(generation_json($apiOperation), 'smoke-secret-key'),
        'Klucz API trafił do rejestru operacji.'
    );

    $callsAfterCompletion = 0;
    execute_generation_operation(
        $apiId,
        static function () use (&$callsAfterCompletion): array {
            $callsAfterCompletion++;
            throw new RuntimeException('Transport nie powinien zostać uruchomiony ponownie.');
        },
        'smoke-secret-key'
    );
    generation_assert($callsAfterCompletion === 0, 'Zakończona operacja spowodowała kolejne żądanie.');

    $invalidApiId = prepare_generation_operation('contract_test', $input, $schema);
    $operationIds[] = $invalidApiId;
    generation_expect_exception(
        static fn () => execute_generation_operation(
            $invalidApiId,
            static fn (): array => [
                'status' => 200,
                'body' => generation_json([
                    'responseId' => 'resp_invalid',
                    'candidates' => [[
                        'content' => ['parts' => [['text' => generation_json(['summary' => 123])]]],
                        'finishReason' => 'STOP',
                    ]],
                ]),
                'headers' => [],
                'network_error' => '',
            ],
            'smoke-secret-key'
        ),
        'Nieprawidłowa odpowiedź Gemini API'
    );
    generation_assert(
        find_generation_operation($invalidApiId)['status'] === 'failed',
        'Niepoprawna odpowiedź API nie została oznaczona jako błąd.'
    );

    $quotaId = prepare_generation_operation('contract_test', $input, $schema);
    $operationIds[] = $quotaId;
    $quotaAttempts = 0;
    generation_expect_exception(
        static function () use ($quotaId, &$quotaAttempts): void {
            execute_generation_operation(
                $quotaId,
                static function () use (&$quotaAttempts): array {
                    $quotaAttempts++;
                    return [
                        'status' => 429,
                        'body' => generation_json([
                            'error' => [
                                'code' => 'insufficient_quota',
                                'message' => 'Quota exhausted',
                            ],
                        ]),
                        'headers' => [],
                        'network_error' => '',
                    ];
                },
                'smoke-secret-key'
            );
        },
        'Quota exhausted'
    );
    generation_assert(
        $quotaAttempts === (int) app_config('gemini_max_attempts'),
        'Limit Free Tier nie użył kontrolowanej liczby prób: ' . $quotaAttempts . '.'
    );
    $manualFallback = import_manual_generation_response($quotaId, generation_json($validOutput));
    generation_assert(
        $manualFallback === $validOutput
        && find_generation_operation($quotaId)['status'] === 'completed'
        && find_generation_operation($quotaId)['provider_response_id'] === 'manual-fallback',
        'Po limicie Free Tier nie można kontynuować ręcznym importem.'
    );
    generation_assert(
        (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItems,
        'Test generowania zmienił kolejkę pobranych źródeł.'
    );

    echo "GENERATION_SERVICE_SMOKE_OK\n";
} finally {
    foreach ($operationIds as $operationId) {
        $database->prepare('DELETE FROM generation_operations WHERE id = :id')->execute([':id' => $operationId]);
    }
    update_generation_mode($originalMode);
    if ($originalApiKey === false) {
        putenv('GEMINI_API_KEY');
    } else {
        putenv('GEMINI_API_KEY=' . $originalApiKey);
    }
}
