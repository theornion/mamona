<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-mock-guard-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Nie utworzono katalogu testowego.');
$databaseFile = $directory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_GENERATION_MODE=api');
putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_API_MOCK=true');
require_once dirname(__DIR__) . '/php/admin-database.php';

function mock_guard_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    mock_guard_assert(generation_explicit_test_mode(), 'Izolowana baza testowa nie aktywowała jawnego trybu testowego.');
    mock_guard_assert(article_title_surface_error('New research from Emory University introduces a model that could change how blood clots are treated.') !== null,
        'Angielski tytuł nie został odrzucony przed zapisem szkicu.');

    putenv('CMS_TEST_DATABASE_FILE=');
    mock_guard_assert(!generation_explicit_test_mode(), 'Pusty CMS_TEST_DATABASE_FILE nadal aktywuje mock.');
    try {
        salvage_execute_safe_composer([]);
        throw new RuntimeException('Safe composer uruchomił fixture poza trybem testowym.');
    } catch (RuntimeException $exception) {
        mock_guard_assert(str_contains($exception->getMessage(), 'nie może działać w normalnym pipeline'), 'Safe composer zwrócił nieprawidłowy błąd guardu.');
    }

    $operationId = prepare_generation_operation('mock_guard', ['value' => 'x'], [
        'type' => 'object', 'properties' => ['value' => ['type' => 'string']],
        'required' => ['value'], 'additionalProperties' => false,
    ]);
    try {
        execute_generation_operation($operationId);
        throw new RuntimeException('Wbudowany mock Gemini uruchomił się poza trybem testowym.');
    } catch (RuntimeException $exception) {
        mock_guard_assert(str_contains($exception->getMessage(), 'wyłącznie z CMS_TEST_DATABASE_FILE'), 'Gemini mock zwrócił nieprawidłowy błąd guardu.');
    }

    echo "NORMAL_PIPELINE_MOCK_GUARD_SMOKE_OK\n";
} finally {
    foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm'] as $file) if (is_file($file)) @unlink($file);
    if (is_dir($directory)) @rmdir($directory);
}
