<?php

declare(strict_types=1);

const GENERATION_RESPONSE_MAX_BYTES = 2097152;

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
    $statement = bueno_database()->prepare(
        'INSERT INTO generation_operations (
            operation_key, post_id, topic_id, operation_type, execution_mode,
            status, prompt_text, input_json, output_schema_json, input_hash,
            provider, model
         ) VALUES (
            :operation_key, :post_id, :topic_id, :operation_type, :execution_mode,
            "prepared", :prompt_text, :input_json, :output_schema_json, :input_hash,
            :provider, :model
         )'
    );
    $statement->execute([
        ':operation_key' => bin2hex(random_bytes(16)),
        ':post_id' => $postId,
        ':topic_id' => $topicId,
        ':operation_type' => $operationType,
        ':execution_mode' => $mode,
        ':prompt_text' => $prompt,
        ':input_json' => $inputJson,
        ':output_schema_json' => $schemaJson,
        ':input_hash' => hash('sha256', $inputJson),
        ':provider' => $mode === 'api' ? (string) app_config('generation_provider') : 'manual-json',
        ':model' => $mode === 'api'
            ? (string) app_config((string) app_config('generation_provider') === 'gemini' ? 'gemini_model' : 'openai_model')
            : '',
    ]);

    return (int) bueno_database()->lastInsertId();
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
        return complete_generation_operation($operationId, $rawResponse, $executionMode, $providerMetadata);
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
                $completed = complete_generation_operation($operationId, generation_json($candidate), $executionMode, $providerMetadata);
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
            $completed = complete_generation_operation($operationId, generation_json($candidate), $executionMode, $providerMetadata);
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
        'array' => [],
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
    if (!$useBuiltInMock && $transport === null) {
        $apiKey = $apiKey ?? app_environment_value('GEMINI_API_KEY');
        if ($apiKey === null) {
            throw new RuntimeException(
                'Brakuje GEMINI_API_KEY. Ustaw klucz w środowisku albo przełącz tryb na manual.'
            );
        }
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
    $maximumAttempts = (int) app_config('gemini_max_attempts');
    $lastError = 'Nieznany błąd Gemini API.';

    $lastRetryAfterSeconds = 0;
    for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
        bueno_database()->prepare(
            'UPDATE generation_operations
             SET status = "running", attempt_count = :attempt, error_message = "",
                 updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':attempt' => $attempt, ':id' => $operationId]);
        try {
            $response = $transport(
                $payload,
                (string) $apiKey,
                (string) $operation['operation_key'],
                (string) $operation['model']
            );
        } catch (Throwable $exception) {
            $response = ['status' => 0, 'body' => '', 'headers' => [], 'network_error' => $exception->getMessage()];
        }
        $status = (int) ($response['status'] ?? 0);
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

                return complete_generation_with_title_repair($operationId, (string) $providerOutput['text'], 'api', [
                    'response_id' => (string) $providerOutput['response_id'],
                    'usage' => (array) $providerOutput['usage'],
                ], static fn(int $repairId): array => execute_generation_operation($repairId, $transport, $apiKey));
            } catch (Throwable $exception) {
                $lastError = 'Nieprawidłowa odpowiedź Gemini API: ' . $exception->getMessage();
                $failureDiagnostics = generation_error_classification($exception, $status);
                $failureDiagnostics['http_status'] = $status;
                $transient = (bool) $failureDiagnostics['retryable'];
            }
        } else {
            $lastError = trim((string) ($response['network_error'] ?? ''));
            if ($lastError === '') {
                $lastError = $details['message'] !== ''
                    ? 'Gemini API: ' . $details['message']
                    : 'Gemini API zwróciło HTTP ' . $status . '.';
            }
            if ($status === 429) {
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
