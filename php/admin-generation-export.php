<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';

require_admin_login();

$operationId = filter_input(INPUT_GET, 'operation', FILTER_VALIDATE_INT) ?: 0;
$format = strtolower(trim((string) ($_GET['format'] ?? 'txt')));
$operation = find_generation_operation($operationId);
if ($operation === null || !in_array($format, ['txt', 'json'], true)) {
    http_response_code(404);
    exit('Nie znaleziono eksportu.');
}

$filename = 'mamona-operation-' . $operationId . '.' . $format;
header('Content-Type: ' . ($format === 'json' ? 'application/json' : 'text/plain') . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

if ($format === 'txt') {
    echo (string) $operation['prompt_text'];
    exit;
}

echo json_encode([
    'operation_id' => (int) $operation['id'],
    'operation_key' => (string) $operation['operation_key'],
    'operation_type' => (string) $operation['operation_type'],
    'execution_mode' => (string) $operation['execution_mode'],
    'prompt' => (string) $operation['prompt_text'],
    'input' => json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR),
    'output_schema' => json_decode((string) $operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
