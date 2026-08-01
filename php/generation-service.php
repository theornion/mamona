<?php

declare(strict_types=1);

require_once __DIR__ . '/gemini-quota-service.php';

const GENERATION_RESPONSE_MAX_BYTES = 2097152;

final class GenerationFieldConstraintException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $jsonPath,
        public readonly int $actualLength,
        public readonly ?int $minimumLength,
        public readonly ?int $maximumLength
    ) {
        $range = ($minimumLength ?? 0) . '–' . ($maximumLength ?? '∞');
        parent::__construct("Pole {$jsonPath} ma {$actualLength} znaków; wymagany zakres {$range}.");
    }
}

function generation_error_classification(Throwable $exception, ?int $httpStatus = null): array
{
    if ($exception instanceof InvalidArgumentException || $exception instanceof JsonException) {
        return ['class' => 'validation_contract', 'retryable' => false];
    }
    $message = mb_strtolower($exception->getMessage());
    $retryable = $httpStatus === 0 || $httpStatus === 408 || $httpStatus === 429
        || ($httpStatus !== null && $httpStatus >= 500 && $httpStatus <= 599)
        || str_contains($message, 'timeout') || str_contains($message, 'timed out')
        || str_contains($message, 'network') || str_contains($message, 'worker')
        || str_contains($message, '429') || str_contains($message, 'rate limit')
        || preg_match('/http\s+5\d\d/', $message) === 1;
    return ['class' => $retryable ? 'retryable_transport' : 'non_retryable', 'retryable' => $retryable];
}

function generation_schema_at_path(array $schema, string $path): array
{
    if ($path === '$' || $path === '') return $schema;
    preg_match_all('/\.([A-Za-z0-9_-]+)|\[(\d+)\]/', $path, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if (($match[1] ?? '') !== '') {
            $schema = is_array($schema['properties'][$match[1]] ?? null) ? $schema['properties'][$match[1]] : [];
        } else {
            $schema = is_array($schema['items'] ?? null) ? $schema['items'] : [];
        }
        if ($schema === []) break;
    }
    return $schema;
}

/** Builds a bounded, machine-readable correction request without copying arbitrary provider or secret data. */
function generation_validation_repair_message(array $operation, Throwable $exception, array $output): array
{
    $message = mb_substr(trim($exception->getMessage()), 0, 700);
    preg_match('/(\$(?:\.[A-Za-z0-9_-]+|\[\d+\])+)/', $message, $pathMatch);
    $path = (string) ($pathMatch[1] ?? '$');
    $schema = json_decode((string) $operation['output_schema_json'], true) ?: [];
    $pathSchema = generation_schema_at_path($schema, $path);
    $input = json_decode((string) $operation['input_json'], true) ?: [];
    $allowed = [];
    if (is_array($pathSchema['enum'] ?? null)) $allowed = array_values($pathSchema['enum']);
    if (isset($pathSchema['minLength']) || isset($pathSchema['maxLength'])) {
        $allowed = [
            'minLength' => isset($pathSchema['minLength']) ? (int) $pathSchema['minLength'] : null,
            'maxLength' => isset($pathSchema['maxLength']) ? (int) $pathSchema['maxLength'] : null,
            'actualLength' => $exception instanceof GenerationFieldConstraintException
                ? $exception->actualLength
                : mb_strlen((string) generation_value_at_path($output, $path)),
        ];
    }
    if ($allowed === [] && isset($pathSchema['minimum'], $pathSchema['maximum'])) {
        $allowed = ['minimum' => $pathSchema['minimum'], 'maximum' => $pathSchema['maximum']];
    }
    if (str_contains($path, 'source_id')) {
        $allowed = array_values(array_filter(array_map(
            static fn (array $source): string => (string) ($source['source_id'] ?? ''),
            (array) ($input['numbered_sources'] ?? [])
        )));
    } elseif (str_contains($path, 'research_unknown_indexes')) {
        $allowed = array_values(array_map(
            static fn (array $unknown): int => (int) ($unknown['id'] ?? -1),
            (array) ($input['allowed_research_unknowns'] ?? [])
        ));
    }

    $safeContext = [];
    if (str_contains($path, 'evidence')) {
        $sourceId = '';
        if (preg_match('/\.(?:claims|shared_facts)\[(\d+)\]\.evidence\[(\d+)\]/', $path, $indexes)) {
            $container = str_contains($path, '.shared_facts[') ? 'shared_facts' : 'claims';
            $sourceId = (string) ($output[$container][(int) $indexes[1]]['evidence'][(int) $indexes[2]]['source_id'] ?? '');
        }
        foreach ((array) ($input['numbered_sources'] ?? []) as $source) {
            if ($sourceId !== '' && (string) ($source['source_id'] ?? '') !== $sourceId) continue;
            $safeContext[] = [
                'source_id' => (string) ($source['source_id'] ?? ''),
                'exact_title' => mb_substr((string) ($source['title'] ?? ''), 0, 350),
                'exact_material_fragment' => mb_substr((string) ($source['material'] ?? ''), 0, 900),
            ];
            if ($sourceId !== '' || count($safeContext) >= 2) break;
        }
    } elseif (str_contains($path, 'research_unknown_indexes')) {
        $safeContext = array_slice((array) ($input['allowed_research_unknowns'] ?? []), 0, 50);
    } else {
        $safeContext = ['operation_type' => (string) $operation['operation_type']];
    }

    return [
        'repair_type' => 'validation_contract',
        'json_path' => $path,
        'rule' => $message,
        'allowed_values' => $allowed,
        'safe_context' => $safeContext,
        'instruction' => 'Popraw wyłącznie bieżący obiekt JSON. Nie dodawaj faktów spoza wejścia. Evidence musi być dosłownym fragmentem title/material wskazanego źródła. Zwróć cały obiekt zgodny ze schematem.',
    ];
}

function generation_value_at_path(array $value, string $path): mixed
{
    if ($path === '$' || $path === '') return $value;
    preg_match_all('/\.([A-Za-z0-9_-]+)|\[(\d+)\]/', $path, $matches, PREG_SET_ORDER);
    $cursor = $value;
    foreach ($matches as $match) {
        $key = ($match[1] ?? '') !== '' ? $match[1] : (int) $match[2];
        if (!is_array($cursor) || !array_key_exists($key, $cursor)) return null;
        $cursor = $cursor[$key];
    }
    return $cursor;
}

function generation_set_value_at_path(array $value, string $path, mixed $replacement): array
{
    preg_match_all('/\.([A-Za-z0-9_-]+)|\[(\d+)\]/', $path, $matches, PREG_SET_ORDER);
    if ($matches === []) return $value;
    $cursor =& $value;
    foreach ($matches as $index => $match) {
        $key = ($match[1] ?? '') !== '' ? $match[1] : (int) $match[2];
        if ($index === count($matches) - 1) {
            $cursor[$key] = $replacement;
            break;
        }
        if (!isset($cursor[$key]) || !is_array($cursor[$key])) return $value;
        $cursor =& $cursor[$key];
    }
    unset($cursor);
    return $value;
}

function generation_field_repair_schema(GenerationFieldConstraintException $error): array
{
    return [
        'type' => 'object',
        'properties' => ['value' => array_filter([
            'type' => 'string',
            'minLength' => $error->minimumLength,
            'maxLength' => $error->maximumLength,
        ], static fn (mixed $value): bool => $value !== null)],
        'required' => ['value'],
        'additionalProperties' => false,
    ];
}

function generation_saved_field_constraint(array $operation): ?GenerationFieldConstraintException
{
    $output = json_decode((string) ($operation['output_json'] ?? ''), true);
    if (!is_array($output) || $output === []) return null;
    $schema = json_decode((string) ($operation['output_schema_json'] ?? ''), true) ?: [];
    try {
        validate_generation_value($output, $schema);
    } catch (GenerationFieldConstraintException $error) {
        return $error;
    } catch (Throwable) {
        // Older operations may predate length metadata; handle their known field message below.
    }
    $message = mb_strtolower((string) ($operation['error_message'] ?? ''));
    if (str_contains($message, 'brief') && str_contains($message, '80') && str_contains($message, '220')) {
        return new GenerationFieldConstraintException('$.brief', mb_strlen((string) ($output['brief'] ?? '')), 80, 220);
    }
    return null;
}

function generation_field_safe_context(array $operation, string $path): array
{
    $input = json_decode((string) $operation['input_json'], true) ?: [];
    $research = (array) ($input['research_package'] ?? []);
    $eventSummary = $research['event_summary'] ?? $input['event_summary'] ?? '';
    if (is_array($eventSummary)) $eventSummary = (string) ($eventSummary['text'] ?? '');
    return [
        'field' => ltrim($path, '$.'),
        'event_summary' => mb_substr((string) $eventSummary, 0, 700),
        'verified_facts' => array_slice(array_values(array_filter(array_map(
            static fn (array $claim): string => mb_substr(trim((string) ($claim['claim'] ?? $claim['text'] ?? '')), 0, 350),
            (array) ($research['claims'] ?? [])
        ))), 0, 5),
        'rule' => 'Nie dodawaj nowego twierdzenia. Zachowaj znaczenie istniejącego pola.',
    ];
}

function generation_deterministic_text_fit(string $text, int $minimum, int $maximum, array $safeContext = []): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    $text = rtrim($text, " \t\n\r\0\x0B.!?");
    if (mb_strlen($text) > $maximum - 1) {
        $cut = mb_substr($text, 0, max(1, $maximum - 1));
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= max(1, $minimum - 1)) $cut = mb_substr($cut, 0, $space);
        $text = rtrim($cut, " ,;:–—-");
    }
    $fragments = array_values(array_filter([
        (string) ($safeContext['event_summary'] ?? ''),
        ...((array) ($safeContext['verified_facts'] ?? [])),
        'opis opiera się wyłącznie na zweryfikowanych informacjach i zachowuje ich pierwotne znaczenie',
    ]));
    foreach ($fragments as $fragment) {
        if (mb_strlen($text) >= $minimum - 1) break;
        $fragment = rtrim(trim(preg_replace('/\s+/u', ' ', strip_tags($fragment)) ?? ''), ".!?");
        if ($fragment === '' || str_contains(mb_strtolower($text), mb_strtolower($fragment))) continue;
        $candidate = $text === '' ? $fragment : $text . ' — ' . mb_strtolower($fragment);
        if (mb_strlen($candidate) > $maximum - 1) {
            $room = $maximum - 1 - mb_strlen($text === '' ? '' : $text . ' — ');
            $fragment = mb_substr($fragment, 0, max(0, $room));
            $space = mb_strrpos($fragment, ' ');
            if ($space !== false) $fragment = mb_substr($fragment, 0, $space);
            $candidate = $text === '' ? $fragment : $text . ' — ' . mb_strtolower($fragment);
        }
        $text = rtrim($candidate, " ,;:–—-");
    }
    while (mb_strlen($text) < $minimum - 1) {
        $addition = ' w granicach potwierdzonych danych';
        if (mb_strlen($text . $addition) > $maximum - 1) break;
        $text .= $addition;
    }
    return rtrim(mb_substr($text, 0, $maximum - 1), " ,;:–—-") . '.';
}

function generation_contract_repair_audit(array $operation, string $result, int $attempt, array $details = []): void
{
    $statement = bueno_database()->prepare(
        'SELECT id, batch_id FROM generation_batch_items
         WHERE research_operation_id = :operation OR draft_operation_id = :operation OR quality_operation_id = :operation
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':operation' => (int) $operation['id']]);
    $item = $statement->fetch();
    if (!is_array($item)) return;
    generation_batch_audit((int) $item['batch_id'], (int) $item['id'], 'contract_validation_retry', 'worker', [
        'operation_id' => (int) $operation['id'], 'operation_type' => (string) $operation['operation_type'],
        'attempt' => $attempt, 'result' => $result, ...$details,
    ]);
}

function generation_mode(): string
{
    $mode = (string) bueno_database()->query(
        'SELECT generation_mode FROM generation_settings WHERE id = 1'
    )->fetchColumn();

    return in_array($mode, ['manual', 'api'], true) ? $mode : 'manual';
}

function update_generation_mode(string $mode): void
{
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['manual', 'api'], true)) {
        throw new InvalidArgumentException('Tryb generowania musi mieć wartość manual albo api.');
    }
    bueno_database()->prepare(
        'UPDATE generation_settings
         SET generation_mode = :mode, updated_at = CURRENT_TIMESTAMP WHERE id = 1'
    )->execute([':mode' => $mode]);
}

function validate_generation_schema(array $schema): void
{
    if (($schema['type'] ?? '') !== 'object' || !is_array($schema['properties'] ?? null)) {
        throw new InvalidArgumentException('Schemat odpowiedzi musi opisywać obiekt JSON.');
    }
    if (!isset($schema['required']) || !is_array($schema['required'])) {
        throw new InvalidArgumentException('Schemat musi jawnie wskazywać wymagane pola.');
    }
    if (($schema['additionalProperties'] ?? null) !== false) {
        throw new InvalidArgumentException('Schemat musi blokować dodatkowe pola.');
    }
}

function generation_json(array $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function build_generation_prompt(string $operationType, array $input, array $outputSchema): string
{
    return "Wykonaj operację redakcyjną: {$operationType}.\n"
        . "Korzystaj wyłącznie z danych wejściowych. Nie dodawaj faktów spoza nich.\n"
        . "Zwróć wyłącznie jeden obiekt JSON zgodny z podanym schematem, bez markdownu i komentarzy.\n\n"
        . "DANE WEJŚCIOWE:\n" . generation_json($input) . "\n\n"
        . "SCHEMAT ODPOWIEDZI:\n" . generation_json($outputSchema);
}

function prepare_generation_operation(
    string $operationType,
    array $input,
    array $outputSchema,
    ?int $postId = null,
    ?int $topicId = null
): int {
    $operationType = strtolower(trim($operationType));
    if (preg_match('/^[a-z][a-z0-9_-]{2,50}$/', $operationType) !== 1) {
        throw new InvalidArgumentException('Nieprawidłowy typ operacji generowania.');
    }
    if ($postId !== null && find_post($postId, true) === null) {
        throw new RuntimeException('Nie znaleziono powiązanego posta.');
    }
    if ($topicId !== null && find_editorial_topic($topicId) === null) {
        throw new RuntimeException('Nie znaleziono powiązanego tematu.');
    }
    validate_generation_schema($outputSchema);
    $inputJson = generation_json($input);
    $schemaJson = generation_json($outputSchema);
    $mode = generation_mode();
    $prompt = build_generation_prompt($operationType, $input, $outputSchema);
    $provider = $mode === 'api' ? (string) app_config('generation_provider') : 'manual-json';
    $model = $mode === 'api'
        ? (string) app_config($provider === 'gemini' ? 'gemini_model' : 'openai_model')
        : '';
    $stableOperationKey = hash('sha256', generation_json([
        'v' => 1, 'type' => $operationType, 'input' => $inputJson, 'schema' => $schemaJson,
        'mode' => $mode, 'provider' => $provider, 'model' => $model,
    ]));
    $operationKey = in_array($operationType, ['field_text_repair'], true)
        ? $stableOperationKey : bin2hex(random_bytes(16));
    $statement = bueno_database()->prepare(
        'INSERT INTO generation_operations (
            operation_key, post_id, topic_id, operation_type, execution_mode,
            status, prompt_text, input_json, output_schema_json, input_hash,
            provider, model
         ) VALUES (
            :operation_key, :post_id, :topic_id, :operation_type, :execution_mode,
            "prepared", :prompt_text, :input_json, :output_schema_json, :input_hash,
            :provider, :model
         ) ON CONFLICT(operation_key) DO NOTHING'
    );
    $statement->execute([
        ':operation_key' => $operationKey,
        ':post_id' => $postId,
        ':topic_id' => $topicId,
        ':operation_type' => $operationType,
        ':execution_mode' => $mode,
        ':prompt_text' => $prompt,
        ':input_json' => $inputJson,
        ':output_schema_json' => $schemaJson,
        ':input_hash' => hash('sha256', $inputJson),
        ':provider' => $provider,
        ':model' => $model,
    ]);
    if ($statement->rowCount() === 1) return (int) bueno_database()->lastInsertId();
    $existing = bueno_database()->prepare('SELECT id FROM generation_operations WHERE operation_key=:key');
    $existing->execute([':key'=>$operationKey]);
    return (int) $existing->fetchColumn();
}

function find_generation_operation(int $operationId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM generation_operations WHERE id = :id'
    );
    $statement->execute([':id' => $operationId]);
    $operation = $statement->fetch();

    return is_array($operation) ? $operation : null;
}

function list_generation_operations(int $limit = 100): array
{
    $statement = bueno_database()->prepare(
        'SELECT generation_operations.*, editorial_topics.title AS topic_title
         FROM generation_operations
         LEFT JOIN editorial_topics ON editorial_topics.id = generation_operations.topic_id
         ORDER BY generation_operations.id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function generation_value_matches_type(mixed $value, string $type): bool
{
    return match ($type) {
        'object' => is_array($value) && !array_is_list($value),
        'array' => is_array($value) && array_is_list($value),
        'string' => is_string($value),
        'integer' => is_int($value),
        'number' => is_int($value) || is_float($value),
        'boolean' => is_bool($value),
        'null' => $value === null,
        default => false,
    };
}

function validate_generation_value(mixed $value, array $schema, string $path = '$'): void
{
    $type = (string) ($schema['type'] ?? '');
    if (!generation_value_matches_type($value, $type)) {
        throw new InvalidArgumentException("Pole {$path} ma nieprawidłowy typ.");
    }
    if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
        throw new InvalidArgumentException("Pole {$path} ma wartość spoza dozwolonej listy.");
    }
    if ($type === 'string') {
        $length = mb_strlen($value);
        $minimum = isset($schema['minLength']) ? (int) $schema['minLength'] : null;
        $maximum = isset($schema['maxLength']) ? (int) $schema['maxLength'] : null;
        if (($minimum !== null && $length < $minimum) || ($maximum !== null && $length > $maximum)) {
            throw new GenerationFieldConstraintException($path, $length, $minimum, $maximum);
        }
    }
    if (in_array($type, ['integer', 'number'], true)) {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new InvalidArgumentException(
                "Pole {$path}: otrzymano {$value}; dozwolony zakres {$schema['minimum']}–"
                . ($schema['maximum'] ?? '∞') . '.'
            );
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            throw new InvalidArgumentException(
                "Pole {$path}: otrzymano {$value}; dozwolony zakres "
                . ($schema['minimum'] ?? '-∞') . "–{$schema['maximum']}."
            );
        }
    }
    if ($type === 'object') {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (!array_key_exists((string) $required, $value)) {
                throw new InvalidArgumentException("Brakuje wymaganego pola {$path}.{$required}.");
            }
        }
        if (($schema['additionalProperties'] ?? true) === false) {
            $extra = array_diff(array_keys($value), array_keys($properties));
            if ($extra !== []) {
                throw new InvalidArgumentException("Odpowiedź zawiera niedozwolone pole {$path}." . reset($extra) . '.');
            }
        }
        foreach ($value as $key => $child) {
            if (isset($properties[$key]) && is_array($properties[$key])) {
                validate_generation_value($child, $properties[$key], $path . '.' . $key);
            }
        }
    } elseif ($type === 'array') {
        $itemSchema = $schema['items'] ?? null;
        if (!is_array($itemSchema)) {
            throw new InvalidArgumentException("Schemat tablicy {$path} nie definiuje elementów.");
        }
        $count = count($value);
        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            throw new InvalidArgumentException(
                "Pole {$path} musi zawierać co najmniej " . (int) $schema['minItems'] . ' elementów.'
            );
        }
        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            throw new InvalidArgumentException(
                "Pole {$path} może zawierać maksymalnie " . (int) $schema['maxItems'] . ' elementów.'
            );
        }
        foreach ($value as $index => $child) {
            validate_generation_value($child, $itemSchema, $path . '[' . $index . ']');
        }
    }
}

function decode_generation_response(string $response): array
{
    if (strlen($response) > GENERATION_RESPONSE_MAX_BYTES) {
        throw new InvalidArgumentException('Odpowiedź przekracza limit 2 MB.');
    }
    $response = trim($response);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $response, $match) === 1) {
        $response = trim($match[1]);
    }
    try {
        $decoded = json_decode($response, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new InvalidArgumentException('Odpowiedź nie jest poprawnym JSON-em.', 0, $exception);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException('Odpowiedź musi być obiektem JSON.');
    }

    return $decoded;
}

function complete_generation_operation(
    int $operationId,
    string $rawResponse,
    string $executionMode,
    array $providerMetadata = []
): array {
    $operation = find_generation_operation($operationId);
    if ($operation === null) {
        throw new RuntimeException('Nie znaleziono operacji generowania.');
    }
    if ($operation['execution_mode'] !== $executionMode) {
        throw new InvalidArgumentException('Sposób wykonania nie odpowiada przygotowanej operacji.');
    }
    if ($operation['status'] === 'completed') {
        return json_decode((string) $operation['output_json'], true, 128, JSON_THROW_ON_ERROR);
    }
    $output = decode_generation_response($rawResponse);
    $schema = json_decode((string) $operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
    validate_generation_value($output, $schema);

    $specialValidation = null;
    if ($operation['operation_type'] === 'research_package') {
        $specialValidation = validate_research_operation_output($operation, $output);
    } elseif ($operation['operation_type'] === 'article_draft') {
        $specialValidation = validate_article_draft_output($operation, $output);
    } elseif ($operation['operation_type'] === 'quality_check') {
        $specialValidation = validate_quality_check_output($operation, $output);
    }

    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'UPDATE generation_operations
             SET status = "completed", output_json = :output_json,
                 provider_response_id = :provider_response_id,
                 usage_json = :usage_json, error_message = "",
                 completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            ':output_json' => generation_json($output),
            ':provider_response_id' => mb_substr(trim((string) ($providerMetadata['response_id'] ?? '')), 0, 200),
            ':usage_json' => generation_json(is_array($providerMetadata['usage'] ?? null) ? $providerMetadata['usage'] : []),
            ':id' => $operationId,
        ]);
        if ($operation['operation_type'] === 'research_package' && $specialValidation !== null) {
            persist_completed_research_package($operationId, $output, $specialValidation);
        } elseif ($operation['operation_type'] === 'article_draft' && $specialValidation !== null) {
            persist_completed_article_draft($operationId, $output, $specialValidation);
        } elseif ($operation['operation_type'] === 'quality_check' && $specialValidation !== null) {
            persist_completed_quality_check($operationId, $output, $specialValidation);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return $output;
}

function import_manual_generation_response(int $operationId, string $rawResponse): array
{
    $operation = find_generation_operation($operationId);
    if ($operation === null) {
        throw new RuntimeException('Nie znaleziono operacji generowania.');
    }
    if ($operation['execution_mode'] === 'manual') {
        return complete_generation_operation($operationId, $rawResponse, 'manual');
    }
    if ($operation['execution_mode'] === 'api'
        && in_array((string) $operation['status'], ['prepared', 'running', 'failed'], true)) {
        return complete_generation_operation($operationId, $rawResponse, 'api', [
            'response_id' => 'manual-fallback',
            'usage' => ['manual_fallback' => true],
        ]);
    }
    throw new InvalidArgumentException('Ta operacja nie może być kontynuowana importem ręcznym.');
}

function generation_field_repair_audit(array $parent, string $result, GenerationFieldConstraintException $error, array $details = []): void
{
    $statement = bueno_database()->prepare(
        'SELECT id,batch_id FROM generation_batch_items
         WHERE research_operation_id=:id OR draft_operation_id=:id OR quality_operation_id=:id
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':id' => (int) $parent['id']]);
    $item = $statement->fetch();
    if (!is_array($item)) return;
    $field = ltrim($error->jsonPath, '$.');
    if ($result === 'targeted_repair_started') {
        generation_batch_update_item((int) $item['id'], [
            'wait_reason' => "Automatycznie dopasowuję długość pola {$field} (obecnie {$error->actualLength}, wymagane "
                . ($error->minimumLength ?? 0) . '–' . ($error->maximumLength ?? '∞') . ').',
        ]);
    }
    generation_batch_audit((int) $item['batch_id'], (int) $item['id'], 'field_constraint_repair', 'worker', [
        'operation_id' => (int) $parent['id'], 'json_path' => $error->jsonPath,
        'actual_length' => $error->actualLength, 'min_length' => $error->minimumLength,
        'max_length' => $error->maximumLength, 'result' => $result, ...$details,
    ]);
}

function complete_generation_with_field_repair(
    int $operationId,
    string $rawResponse,
    string $executionMode,
    array $providerMetadata,
    callable $executeRepair
): array {
    try {
        return complete_generation_operation($operationId, $rawResponse, $executionMode, $providerMetadata);
    } catch (GenerationFieldConstraintException $error) {
        $parent = find_generation_operation($operationId);
        if (!is_array($parent)) throw $error;
        if ((string) $parent['operation_type'] === 'field_text_repair') throw $error;
        $original = decode_generation_response($rawResponse);
        $current = generation_value_at_path($original, $error->jsonPath);
        if (!is_string($current)) throw $error;
        $safeContext = generation_field_safe_context($parent, $error->jsonPath);
        $repairInput = [
            'repair_type' => 'field_constraint', 'json_path' => $error->jsonPath,
            'current_value' => $current, 'actual_length' => $error->actualLength,
            'minLength' => $error->minimumLength, 'maxLength' => $error->maximumLength,
            'safe_context' => $safeContext,
            'instruction' => 'Zwróć tylko poprawioną wartość pola. Zachowaj znaczenie; nie dodawaj twierdzeń spoza bezpiecznego kontekstu.',
        ];
        generation_field_repair_audit($parent, 'targeted_repair_started', $error);
        $repairOperationId = prepare_generation_operation(
            'field_text_repair', $repairInput, generation_field_repair_schema($error),
            isset($parent['post_id']) ? (int) $parent['post_id'] : null,
            isset($parent['topic_id']) ? (int) $parent['topic_id'] : null
        );
        try {
            $repair = $executeRepair($repairOperationId);
            $value = trim((string) ($repair['value'] ?? ''));
            validate_generation_value($value, generation_field_repair_schema($error)['properties']['value'], '$.value');
            $candidate = generation_set_value_at_path($original, $error->jsonPath, $value);
            $completed = complete_generation_operation($operationId, generation_json($candidate), $executionMode, $providerMetadata);
            generation_field_repair_audit($parent, 'targeted_repair_succeeded', $error, ['repair_operation_id' => $repairOperationId]);
            return $completed;
        } catch (ArticleTitleRepairException $titleError) {
            throw $titleError;
        } catch (Throwable $repairError) {
            $minimum = max(1, (int) ($error->minimumLength ?? 1));
            $maximum = max($minimum, (int) ($error->maximumLength ?? max($minimum, 500)));
            $fallback = generation_deterministic_text_fit($current, $minimum, $maximum, $safeContext);
            $candidate = generation_set_value_at_path($original, $error->jsonPath, $fallback);
            $completed = complete_generation_operation($operationId, generation_json($candidate), $executionMode, $providerMetadata);
            generation_field_repair_audit($parent, 'deterministic_fallback_succeeded', $error, [
                'repair_operation_id' => $repairOperationId, 'repair_error' => mb_substr($repairError->getMessage(), 0, 500),
            ]);
            return $completed;
        }
    }
}

function persist_rejected_title_candidate(int $operationId, array $draft, array $diagnostics): void
{
    bueno_database()->prepare(
        'UPDATE generation_operations SET output_json = :output, error_message = :error, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':output'=>generation_json($draft), ':error'=>generation_json($diagnostics), ':id'=>$operationId]);
    bueno_database()->prepare(
        'UPDATE article_draft_versions SET draft_json = :draft, validation_json = :validation, status = "repairing", updated_at = CURRENT_TIMESTAMP WHERE generation_operation_id = :id'
    )->execute([':draft'=>generation_json($draft), ':validation'=>generation_json($diagnostics), ':id'=>$operationId]);
}

function generation_title_repair_audit(array $parent, int $repairOperationId, int $attempt, array $details): void
{
    $statement = bueno_database()->prepare('SELECT id,batch_id FROM generation_batch_items WHERE draft_operation_id = :id ORDER BY id DESC LIMIT 1');
    $statement->execute([':id'=>(int)$parent['id']]);
    $item = $statement->fetch();
    if (is_array($item)) generation_batch_audit((int)$item['batch_id'], (int)$item['id'], 'title_repair_attempt', 'worker', [
        'parent_operation_id'=>(int)$parent['id'], 'repair_operation_id'=>$repairOperationId,
        'repair_scope'=>'titles', 'attempt'=>$attempt, ...$details,
    ]);
}

function annotate_completed_title_repair(int $operationId, array $metadata): void
{
    $draft = find_article_draft_by_operation($operationId);
    if (!is_array($draft)) return;
    $validation = json_decode((string)$draft['validation_json'], true) ?: [];
    bueno_database()->prepare('UPDATE article_draft_versions SET validation_json = :validation WHERE generation_operation_id = :id')
        ->execute([':validation'=>generation_json([...$validation, ...$metadata]), ':id'=>$operationId]);
}

function complete_generation_with_title_repair(
    int $operationId,
    string $rawResponse,
    string $executionMode,
    array $providerMetadata,
    callable $executeRepair
): array {
    try {
        return complete_generation_with_field_repair(
            $operationId, $rawResponse, $executionMode, $providerMetadata, $executeRepair
        );
    } catch (ArticleTitleRepairException $exception) {
        $parent = find_generation_operation($operationId);
        if (!is_array($parent) || (string)$parent['operation_type'] !== 'article_draft') throw $exception;
        $draft = decode_generation_response($rawResponse);
        $diagnostics = $exception->diagnostics;
        persist_rejected_title_candidate($operationId, $draft, $diagnostics);
        $knownClaims = [];
        $parentInput = json_decode((string)$parent['input_json'], true, 128, JSON_THROW_ON_ERROR);
        foreach ((array)($parentInput['research_package']['claims'] ?? []) as $claim) $knownClaims[(string)$claim['claim_id']] = $claim;
        $maximum = (int)app_config('title_repair_max_attempts');
        $repairUsage = [];
        for ($attempt=1; $attempt<=$maximum; $attempt++) {
            $repairOperationId = prepare_generation_operation('article_title_repair', article_title_repair_input($parent, $draft, $diagnostics, $attempt), article_title_repair_schema(), (int)$parent['post_id'], (int)$parent['topic_id']);
            $repair = [];
            try {
                $repair = $executeRepair($repairOperationId);
                $candidate = merge_article_title_repair($draft, $repair);
                $result = validate_article_title_strategy($candidate, $knownClaims);
                $repairOperation = find_generation_operation($repairOperationId);
                $repairUsage[] = ['operation_id'=>$repairOperationId,'attempt'=>$attempt,'usage'=>json_decode((string)($repairOperation['usage_json'] ?? '{}'),true) ?: []];
                generation_title_repair_audit($parent, $repairOperationId, $attempt, ['old_title'=>$draft['title'] ?? '', 'new_title'=>$candidate['title'] ?? '', 'reason'=>$diagnostics['message'] ?? '', 'validation_result'=>$result]);
                $providerMetadata['usage'] = ['operation_kind'=>'title_only_repair','parent_usage'=>$providerMetadata['usage'] ?? [],'repair_calls'=>$repairUsage];
                $completed = complete_generation_with_field_repair(
                    $operationId, generation_json($candidate), $executionMode, $providerMetadata, $executeRepair
                );
                annotate_completed_title_repair($operationId, ['repair_scope'=>'titles','repair_status'=>'succeeded','repair_attempt'=>$attempt,'old_title'=>$draft['title'] ?? '','new_title'=>$candidate['title'] ?? '']);
                return $completed;
            } catch (ArticleTitleRepairException|InvalidArgumentException $repairError) {
                $newDiagnostics = $repairError instanceof ArticleTitleRepairException ? $repairError->diagnostics : ['code'=>'invalid_title_repair','repair_scope'=>'titles','message'=>$repairError->getMessage()];
                generation_title_repair_audit($parent, $repairOperationId, $attempt, ['old_title'=>$draft['title'] ?? '', 'new_title'=>$repair['title'] ?? '', 'reason'=>$diagnostics['message'] ?? '', 'validation_result'=>$newDiagnostics]);
                $diagnostics = $newDiagnostics;
            }
        }
        $fallback = article_title_deterministic_fallback($draft, $knownClaims);
        $candidate = merge_article_title_repair($draft, $fallback);
        try {
            validate_article_title_strategy($candidate, $knownClaims);
            $providerMetadata['usage'] = ['operation_kind'=>'title_only_repair_fallback','repair_calls'=>$repairUsage];
            generation_title_repair_audit($parent, 0, $maximum + 1, ['old_title'=>$draft['title'] ?? '', 'new_title'=>$candidate['title'] ?? '', 'reason'=>'Wyczerpano próby modelu.', 'validation_result'=>'fallback_passed']);
            $completed = complete_generation_with_field_repair(
                $operationId, generation_json($candidate), $executionMode, $providerMetadata, $executeRepair
            );
            annotate_completed_title_repair($operationId, ['repair_scope'=>'titles','repair_status'=>'fallback_succeeded','repair_attempt'=>$maximum + 1,'old_title'=>$draft['title'] ?? '','new_title'=>$candidate['title'] ?? '']);
            return $completed;
        } catch (Throwable $fallbackError) {
            persist_rejected_title_candidate($operationId, $candidate, ['code'=>'title_repair_review_required','repair_scope'=>'titles','message'=>$fallbackError->getMessage(),'proposals'=>$fallback['title_variants']]);
            throw new RuntimeException('Naprawa tytułu wymaga review; tekst artykułu i propozycje zostały zachowane.');
        }
    }
}

function resume_saved_article_title_repair(int $operationId, ?callable $transport = null): array
{
    $operation = find_generation_operation($operationId);
    $record = find_article_draft_by_operation($operationId);
    $draft = is_array($record) ? json_decode((string)$record['draft_json'], true) : null;
    $validation = is_array($record) ? (json_decode((string)$record['validation_json'], true) ?: []) : [];
    if (!is_array($operation) || !is_array($draft) || $draft === [] || ($validation['repair_scope'] ?? '') !== 'titles') {
        throw new RuntimeException('Brak zachowanego szkicu do naprawy samego tytułu. Wymagane jest review; pełna regeneracja została zablokowana.');
    }
    $usage = json_decode((string)$operation['usage_json'], true) ?: [];
    return complete_generation_with_title_repair($operationId, generation_json($draft), (string)$operation['execution_mode'], ['response_id'=>'saved-title-repair','usage'=>$usage], static fn(int $repairId): array => execute_generation_operation($repairId, $transport));
}

function generation_mock_value(array $schema): mixed
{
    if (isset($schema['enum'][0])) {
        return $schema['enum'][0];
    }

    return match ((string) ($schema['type'] ?? '')) {
        'object' => array_reduce(
            array_keys((array) ($schema['properties'] ?? [])),
            static function (array $result, string $key) use ($schema): array {
                $result[$key] = generation_mock_value($schema['properties'][$key]);
                return $result;
            },
            []
        ),
        'array' => array_map(
            static fn (mixed $_): mixed => generation_mock_value((array) ($schema['items'] ?? [])),
            array_fill(0, max(0, (int) ($schema['minItems'] ?? 0)), null)
        ),
        'string' => 'Lokalna odpowiedź testowa.',
        'integer' => 1,
        'number' => 1.0,
        'boolean' => true,
        default => null,
    };
}

function openai_extract_output_text(array $response): string
{
    if (is_string($response['output_text'] ?? null) && trim($response['output_text']) !== '') {
        return $response['output_text'];
    }
    foreach ((array) ($response['output'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach ((array) ($item['content'] ?? []) as $content) {
            if (!is_array($content)) {
                continue;
            }
            if (($content['type'] ?? '') === 'refusal') {
                throw new RuntimeException('Model odmówił wykonania operacji: ' . (string) ($content['refusal'] ?? 'brak szczegółów'));
            }
            if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                return $content['text'];
            }
        }
    }
    throw new RuntimeException('Odpowiedź API nie zawiera tekstowego wyniku.');
}

function openai_curl_transport(array $payload, string $apiKey, string $operationKey): array
{
    $baseUrl = rtrim((string) app_config('openai_api_base_url'), '/');
    if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
        throw new RuntimeException('OPENAI_API_BASE_URL musi być poprawnym adresem HTTPS.');
    }
    $body = '';
    $tooLarge = false;
    $headers = [];
    $curl = curl_init($baseUrl . '/responses');
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić klienta OpenAI API.');
    }
    $options = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => (int) app_config('openai_timeout_seconds'),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Idempotency-Key: ' . $operationKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > GENERATION_RESPONSE_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    $caBundle = trim((string) app_config('openai_ca_bundle'));
    if ($caBundle !== '') {
        if (!is_file($caBundle)) {
            curl_close($curl);
            throw new RuntimeException('OPENAI_CA_BUNDLE nie wskazuje istniejącego pliku.');
        }
        $options[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($curl, $options);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($tooLarge) {
        return ['status' => 0, 'body' => '', 'headers' => $headers, 'network_error' => 'Odpowiedź API przekracza limit 2 MB.'];
    }
    if ($success === false) {
        return ['status' => 0, 'body' => '', 'headers' => $headers, 'network_error' => $error !== '' ? $error : 'Nieznany błąd sieci.'];
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'network_error' => ''];
}

function openai_error_details(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

    return [
        'code' => (string) ($error['code'] ?? ''),
        'message' => mb_substr(trim((string) ($error['message'] ?? '')), 0, 1000),
    ];
}

function gemini_curl_transport(array $payload, string $apiKey, string $operationKey, string $model): array
{
    $baseUrl = rtrim((string) app_config('gemini_api_base_url'), '/');
    if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
        throw new RuntimeException('GEMINI_API_BASE_URL musi być poprawnym adresem HTTPS.');
    }
    if (preg_match('/^[a-zA-Z0-9._-]{2,100}$/', $model) !== 1) {
        throw new RuntimeException('GEMINI_MODEL zawiera niedozwolone znaki.');
    }
    $body = '';
    $tooLarge = false;
    $headers = [];
    $curl = curl_init($baseUrl . '/models/' . rawurlencode($model) . ':generateContent');
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić klienta Gemini API.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => (int) app_config('gemini_timeout_seconds'),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'x-goog-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'X-Mamona-Operation: ' . $operationKey,
        ],
        CURLOPT_POSTFIELDS => generation_json($payload),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > GENERATION_RESPONSE_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    return [
        'status' => $tooLarge ? 0 : $status,
        'body' => $tooLarge ? '' : $body,
        'headers' => $headers,
        'network_error' => $tooLarge
            ? 'Odpowiedź Gemini przekracza limit 2 MB.'
            : ($success === false ? ($error !== '' ? $error : 'Nieznany błąd sieci Gemini API.') : ''),
    ];
}

function gemini_error_details(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

    return [
        'code' => (string) ($error['status'] ?? $error['code'] ?? ''),
        'message' => mb_substr(trim((string) ($error['message'] ?? '')), 0, 1000),
    ];
}

function gemini_extract_output(array $response): array
{
    $text = '';
    foreach ((array) ($response['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (is_array($part) && is_string($part['text'] ?? null)) {
            $text .= $part['text'];
        }
    }
    if (trim($text) === '') {
        $reason = trim((string) ($response['candidates'][0]['finishReason'] ?? ''));
        throw new RuntimeException(
            'Gemini API nie zwróciło wyniku JSON' . ($reason !== '' ? ' (finishReason: ' . $reason . ')' : '') . '.'
        );
    }

    return [
        'text' => $text,
        'response_id' => (string) ($response['responseId'] ?? ''),
        'usage' => is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [],
    ];
}

function execute_openai_generation_operation(
    int $operationId,
    ?callable $transport = null,
    ?string $apiKey = null
): array {
    $operation = find_generation_operation($operationId);
    if ($operation === null) {
        throw new RuntimeException('Nie znaleziono operacji generowania.');
    }
    if ($operation['execution_mode'] !== 'api') {
        throw new InvalidArgumentException('Ta operacja została przygotowana w trybie manual.');
    }
    if ($operation['status'] === 'completed') {
        return json_decode((string) $operation['output_json'], true, 128, JSON_THROW_ON_ERROR);
    }

    $schema = json_decode((string) $operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
    $useBuiltInMock = (bool) app_config('openai_mock') && $transport === null;
    if (!$useBuiltInMock && $transport === null) {
        $apiKey = $apiKey ?? app_environment_value('OPENAI_API_KEY');
        if ($apiKey === null) {
            throw new RuntimeException('Brakuje OPENAI_API_KEY. Ustaw klucz w środowisku albo przełącz tryb na manual.');
        }
    }
    $payload = [
        'model' => (string) $operation['model'],
        'input' => (string) $operation['prompt_text'],
        'store' => false,
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => preg_replace('/[^a-z0-9_]+/', '_', (string) $operation['operation_type']) ?: 'mamona_output',
                'strict' => true,
                'schema' => $schema,
            ],
        ],
    ];
    if ($useBuiltInMock) {
        $mockValue = match ($operation['operation_type']) {
            'research_package' => research_mock_generation_value($operation),
            'article_draft' => article_draft_mock_generation_value($operation),
            'quality_check' => quality_check_mock_generation_value(),
            default => generation_mock_value($schema),
        };
        $mockOutput = generation_json($mockValue);
        $transport = static fn (): array => [
            'status' => 200,
            'body' => generation_json([
                'id' => 'resp_local_mock',
                'output_text' => $mockOutput,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            ]),
            'headers' => [],
            'network_error' => '',
        ];
        $apiKey = 'local-mock-not-a-secret';
    }
    $transport ??= 'openai_curl_transport';
    $maximumAttempts = (int) app_config('openai_max_attempts');
    $lastError = 'Nieznany błąd OpenAI API.';

    for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
        bueno_database()->prepare(
            'UPDATE generation_operations
             SET status = "running", attempt_count = :attempt, error_message = "",
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':attempt' => $attempt, ':id' => $operationId]);
        $response = $transport($payload, (string) $apiKey, (string) $operation['operation_key']);
        $status = (int) ($response['status'] ?? 0);
        $details = openai_error_details($response);
        $isQuotaError = $details['code'] === 'insufficient_quota';
        $transient = !$isQuotaError && (
            $status === 0
            || in_array($status, [408, 409, 429, 500, 502, 503, 504], true)
        );
        if ($status >= 200 && $status < 300) {
            try {
                $decoded = json_decode((string) $response['body'], true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Odpowiedź API nie jest obiektem.');
                }
                $outputText = openai_extract_output_text($decoded);
                $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
                if (isset($decoded['cost']) && (is_int($decoded['cost']) || is_float($decoded['cost']))) {
                    $usage['reported_cost'] = $decoded['cost'];
                }
                return complete_generation_with_title_repair($operationId, $outputText, 'api', [
                    'response_id' => (string) ($decoded['id'] ?? ''),
                    'usage' => $usage,
                ], static fn(int $repairId): array => execute_generation_operation($repairId, $transport, $apiKey));
            } catch (Throwable $exception) {
                $lastError = 'Nieprawidłowa odpowiedź OpenAI API: ' . $exception->getMessage();
                $transient = false;
            }
        } else {
            $lastError = (string) ($response['network_error'] ?? '');
            if ($lastError === '') {
                $lastError = $details['message'] !== ''
                    ? 'OpenAI API: ' . $details['message']
                    : 'OpenAI API zwróciło HTTP ' . $status . '.';
            }
        }
        if (!$transient || $attempt >= $maximumAttempts) {
            break;
        }
        usleep(250000 * $attempt);
    }

    bueno_database()->prepare(
        'UPDATE generation_operations
         SET status = "failed", error_message = :error_message,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':error_message' => mb_substr($lastError, 0, 2000), ':id' => $operationId]);
    if ($operation['operation_type'] === 'research_package') {
        mark_research_package_failed($operationId, $lastError);
    } elseif ($operation['operation_type'] === 'article_draft') {
        mark_article_draft_failed($operationId, $lastError);
    } elseif ($operation['operation_type'] === 'quality_check') {
        mark_quality_check_failed($operationId, $lastError);
    }
    throw new RuntimeException($lastError);
}

function execute_generation_operation(
    int $operationId,
    ?callable $transport = null,
    ?string $apiKey = null
): array {
    $operation = find_generation_operation($operationId);
    if ($operation === null) {
        throw new RuntimeException('Nie znaleziono operacji generowania.');
    }
    if ((string) $operation['provider'] !== 'gemini') {
        return execute_openai_generation_operation($operationId, $transport, $apiKey);
    }
    if ($operation['execution_mode'] !== 'api') {
        throw new InvalidArgumentException('Ta operacja została przygotowana w trybie manual.');
    }
    if ($operation['status'] === 'completed') {
        return json_decode((string) $operation['output_json'], true, 128, JSON_THROW_ON_ERROR);
    }

    $schema = json_decode((string) $operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
    $useBuiltInMock = (bool) app_config('gemini_mock') && $transport === null;
    $providedTransport = $transport !== null;
    $isLiveRequest = !$useBuiltInMock && !$providedTransport;
    if (!$useBuiltInMock && $transport === null) {
        $apiKey = $apiKey ?? app_environment_value('GEMINI_API_KEY');
        if ($apiKey === null) {
            throw new RuntimeException(
                'Brakuje GEMINI_API_KEY. Ustaw klucz w środowisku albo przełącz tryb na manual.'
            );
        }
    }
    if ($isLiveRequest && PHP_SAPI === 'cli' && trim((string) getenv('CMS_TEST_DATABASE_FILE')) !== ''
        && !(bool) app_config('allow_live_gemini_test')) {
        throw new RuntimeException('Testy nie moga laczyc sie z Gemini bez CMS_ALLOW_LIVE_GEMINI_TEST=1.');
    }
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => (string) $operation['prompt_text']]],
        ]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => $schema,
            'temperature' => 0.2,
        ],
    ];
    if ($useBuiltInMock) {
        $mockValue = match ($operation['operation_type']) {
            'research_package' => research_mock_generation_value($operation),
            'article_draft' => article_draft_mock_generation_value($operation),
            'quality_check' => quality_check_mock_generation_value(),
            default => generation_mock_value($schema),
        };
        $transport = static fn (): array => [
            'status' => 200,
            'body' => generation_json([
                'responseId' => 'gemini_local_mock',
                'candidates' => [[
                    'content' => ['parts' => [['text' => generation_json($mockValue)]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 0,
                    'candidatesTokenCount' => 0,
                    'totalTokenCount' => 0,
                ],
            ]),
            'headers' => [],
            'network_error' => '',
        ];
        $apiKey = 'local-mock-not-a-secret';
    }
    $transport ??= 'gemini_curl_transport';
    $savedConstraint = generation_saved_field_constraint($operation);
    if ($savedConstraint !== null) {
        return complete_generation_with_field_repair(
            $operationId,
            (string) $operation['output_json'],
            'api',
            ['response_id' => 'saved-field-constraint', 'usage' => ['resumed_saved_output' => true]],
            static fn (int $repairId): array => execute_generation_operation($repairId, $useBuiltInMock ? null : $transport, $apiKey)
        );
    }
    $maximumAttempts = (int) app_config('gemini_max_attempts');
    $project = gemini_quota_project_identity((string) $apiKey);
    $models = gemini_configured_models((string) $operation['model']);
    $maximumAttempts += max(0, count($models) - 1);
    $modelIndex = 0;
    $activeModel = $models[0] ?? (string) $operation['model'];
    $callReason = gemini_call_reason($operation);
    $quotaException = null;
    $lastError = 'Nieznany błąd Gemini API.';

    $lastRetryAfterSeconds = 0;
    $contractRepairCount = 0;
    for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
        bueno_database()->prepare(
            'UPDATE generation_operations
             SET status = "running", attempt_count = :attempt, error_message = "",
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':attempt' => $attempt, ':id' => $operationId]);
        $admission = null;
        $fingerprint = gemini_call_fingerprint($operation, $activeModel);
        if ($isLiveRequest) {
            $cached = gemini_cached_call(bueno_database(), $project, $activeModel, $fingerprint);
            if (is_array($cached)) {
                $completedOutput = complete_generation_with_title_repair($operationId, (string) $cached['output_json'], 'api', [
                    'response_id' => (string) $cached['provider_response_id'],
                    'usage' => [...(json_decode((string) $cached['usage_json'], true) ?: []), 'cache_hit' => true],
                ], static fn(int $repairId): array => execute_generation_operation($repairId, null, $apiKey));
                bueno_database()->prepare('UPDATE generation_operations SET model_used=:model,call_reason=:reason,call_fingerprint=:fingerprint WHERE id=:id')->execute([':model'=>$activeModel,':reason'=>$callReason,':fingerprint'=>$fingerprint,':id'=>$operationId]);
                return $completedOutput;
            }
        }
        try {
            if ($isLiveRequest) {
                $admission = gemini_quota_acquire(bueno_database(), $project, $activeModel, $operationId, $callReason, $fingerprint, gemini_estimated_tokens($payload));
            }
            $response = $transport($payload, (string) $apiKey, (string) $operation['operation_key'], $activeModel);
        } catch (GeminiTopicBudgetException $exception) {
            throw $exception;
        } catch (GeminiQuotaWaitException $exception) {
            $quotaException = $exception;
            $response = ['status'=>429,'body'=>'','headers'=>[],'network_error'=>$exception->getMessage()];
        } catch (Throwable $exception) {
            $response = ['status' => 0, 'body' => '', 'headers' => [], 'network_error' => $exception->getMessage()];
        }
        $status = (int) ($response['status'] ?? 0);
        if (is_array($admission)) {
            $probe = json_decode((string) ($response['body'] ?? ''), true) ?: [];
            gemini_quota_release(bueno_database(), $project, $activeModel, $admission, $status >= 200 && $status < 300 ? 'completed' : 'failed', (int) ($probe['usageMetadata']['totalTokenCount'] ?? 0));
        }
        if ($quotaException instanceof GeminiQuotaWaitException) {
            $lastError = $quotaException->getMessage();
            break;
        }
        $details = gemini_error_details($response);
        $transient = $status === 0 || $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
        $failureDiagnostics = ['class' => $transient ? 'retryable_transport' : 'non_retryable', 'retryable' => $transient, 'http_status' => $status];
        if ($status >= 200 && $status < 300) {
            try {
                $decoded = json_decode((string) $response['body'], true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Odpowiedź API nie jest obiektem.');
                }
                $providerOutput = gemini_extract_output($decoded);

                $completedOutput = complete_generation_with_title_repair($operationId, (string) $providerOutput['text'], 'api', [
                    'response_id' => (string) $providerOutput['response_id'],
                    'usage' => (array) $providerOutput['usage'],
                ], static fn(int $repairId): array => execute_generation_operation($repairId, $useBuiltInMock ? null : $transport, $apiKey));
                bueno_database()->prepare('UPDATE generation_operations SET model_used=:model,call_reason=:reason,call_fingerprint=:fingerprint,live_request_count=live_request_count+:live WHERE id=:id')->execute([':model'=>$activeModel,':reason'=>$callReason,':fingerprint'=>$fingerprint,':live'=>$isLiveRequest ? 1 : 0,':id'=>$operationId]);
                if ($isLiveRequest) {
                    gemini_store_cached_call(bueno_database(), $project, $activeModel, $fingerprint, (string) $providerOutput['text'], (string) $providerOutput['response_id'], (array) $providerOutput['usage']);
                }
                if ($contractRepairCount > 0) generation_contract_repair_audit($operation, 'succeeded', $contractRepairCount);
                return $completedOutput;
            } catch (Throwable $exception) {
                if (isset($providerOutput['text'])) {
                    try { $output = decode_generation_response((string) $providerOutput['text']); } catch (Throwable) { $output = []; }
                }
                $lastError = 'Nieprawidłowa odpowiedź Gemini API: ' . $exception->getMessage();
                $failureDiagnostics = generation_error_classification($exception, $status);
                $failureDiagnostics['http_status'] = $status;
                $transient = (bool) $failureDiagnostics['retryable'];
                if ($contractRepairCount < 1
                    && in_array((string) $operation['operation_type'], ['research_package', 'article_draft'], true)
                    && ($failureDiagnostics['class'] ?? '') === 'validation_contract'
                    && $attempt < $maximumAttempts) {
                    $contractRepairCount++;
                    $repairMessage = generation_validation_repair_message($operation, $exception, (array) ($output ?? []));
                    if (isset($providerOutput['text'])) {
                        $repairMessage = generation_validation_repair_message($operation, $exception, (array) $output);
                    }
                    $payload['contents'][] = ['role' => 'model', 'parts' => [['text' => (string) ($providerOutput['text'] ?? '')]]];
                    $payload['contents'][] = ['role' => 'user', 'parts' => [['text' => generation_json($repairMessage)]]];
                    $failureDiagnostics['repair_attempt'] = $contractRepairCount;
                    generation_contract_repair_audit($operation, 'queued', $contractRepairCount, [
                        'json_path' => $repairMessage['json_path'], 'rule' => $repairMessage['rule'],
                        'allowed_values' => $repairMessage['allowed_values'],
                    ]);
                    $transient = true;
                }
            }
        } else {
            $lastError = trim((string) ($response['network_error'] ?? ''));
            if ($lastError === '') {
                $lastError = $details['message'] !== ''
                    ? 'Gemini API: ' . $details['message']
                    : 'Gemini API zwróciło HTTP ' . $status . '.';
            }
            if ($status === 429) {
                $quota = gemini_quota_response_details($response, $activeModel);
                if ($isLiveRequest) {
                    gemini_mark_quota_state(bueno_database(), $project, $activeModel, (string) $quota['dimension'], (string) $quota['next_retry_at'], 429, ['message' => (string) $quota['message']]);
                }
                if ((string) $quota['dimension'] === 'RPD' && isset($models[$modelIndex + 1])) {
                    $activeModel = $models[++$modelIndex];
                    $transient = true;
                    continue;
                }
                $quotaException = new GeminiQuotaWaitException((string) $quota['dimension'], $activeModel, (string) $quota['next_retry_at'], gemini_quota_wait_message((string) $quota['dimension'], $activeModel));
                $transient = false;
                $lastRetryAfterSeconds = max(0, min(86400, (int) ($response['headers']['retry-after'] ?? 0)));
                $lastError .= ' Limit Free Tier został osiągnięty; operację można zaimportować ręcznie lub ponowić później.';
            }
        }
        if (!$transient || $attempt >= $maximumAttempts) {
            break;
        }
        $retryAfterSeconds = max(0, min(10, (int) ($response['headers']['retry-after'] ?? 0)));
        $backoffMs = min(
            10000,
            max(
                $retryAfterSeconds * 1000,
                (int) app_config('gemini_initial_backoff_ms') * (2 ** ($attempt - 1))
            )
        );
        usleep($backoffMs * 1000);
    }

    if ($lastRetryAfterSeconds > 0) {
        $lastError .= ' Retry-After: ' . $lastRetryAfterSeconds . ' s.';
    }
    if ($contractRepairCount > 0) generation_contract_repair_audit($operation, 'exhausted', $contractRepairCount, [
        'classification' => $failureDiagnostics['class'] ?? 'validation_contract',
    ]);
    if ($quotaException instanceof GeminiQuotaWaitException) {
        bueno_database()->prepare('UPDATE generation_operations SET status="prepared",error_message=:error,next_retry_at=:retry,quota_dimension=:dimension,model_used=:model,call_reason=:reason,call_fingerprint=:fingerprint,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([
            ':error' => $quotaException->getMessage(), ':retry' => gmdate('Y-m-d H:i:s', strtotime($quotaException->nextRetryAt)),
            ':dimension' => $quotaException->quotaDimension, ':model' => $quotaException->quotaModel,
            ':reason' => $callReason, ':fingerprint' => gemini_call_fingerprint($operation, $quotaException->quotaModel), ':id' => $operationId,
        ]);
        throw $quotaException;
    }
    $failedOutput = isset($output) && is_array($output) && $output !== [] ? generation_json($output) : null;
    bueno_database()->prepare(
        'UPDATE generation_operations
         SET status = "failed", error_message = :error_message,
             output_json = COALESCE(:output_json, output_json), updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':error_message' => mb_substr($lastError, 0, 2000), ':output_json' => $failedOutput, ':id' => $operationId]);
    if ($operation['operation_type'] === 'research_package') {
        mark_research_package_failed($operationId, $lastError);
    } elseif ($operation['operation_type'] === 'article_draft') {
        mark_article_draft_failed($operationId, $lastError);
    } elseif ($operation['operation_type'] === 'quality_check') {
        mark_quality_check_failed($operationId, $lastError, $failureDiagnostics ?? ['class' => 'non_retryable', 'retryable' => false]);
    }
    throw new RuntimeException($lastError);
}

function prepare_topic_foundation_operation(int $topicId): int
{
    $topic = find_editorial_topic($topicId);
    if ($topic === null) {
        throw new RuntimeException('Nie znaleziono tematu.');
    }
    $sources = array_map(
        static fn (array $item): array => [
            'title' => (string) $item['title'],
            'publisher' => (string) $item['source_name'],
            'url' => (string) $item['source_url'],
            'published_at' => $item['published_at'],
            'feed_summary' => (string) $item['summary'],
        ],
        topic_feed_items($topicId)
    );
    $input = [
        'topic_id' => $topicId,
        'topic_title' => (string) $topic['title'],
        'topic_score' => $topic['score'] !== null ? (int) $topic['score'] : null,
        'sources' => $sources,
        'instruction' => 'Potwierdź gotowość wspólnego formatu przed wdrożeniem właściwego researchu w TASK-16.',
    ];
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

    return prepare_generation_operation(
        'foundation_test',
        $input,
        $schema,
        (int) $topic['primary_post_id'],
        $topicId
    );
}
