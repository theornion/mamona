<?php

declare(strict_types=1);

function post_publication_status(bool $isPublished): string
{
    return $isPublished ? 'published' : 'draft';
}

function post_is_public(array $post): bool
{
    return (string) ($post['status'] ?? '') === 'published'
        && empty($post['deleted_at']);
}

function post_legacy_publication_flag(string $status, mixed $deletedAt = null): int
{
    return $status === 'published' && empty($deletedAt) ? 1 : 0;
}

function remove_public_file(string $path): void
{
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Nie można usunąć wycofanego pliku publicznego.');
    }
}

function write_public_file_atomically(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        throw new RuntimeException('Katalog publicznego pliku nie istnieje.');
    }

    $temporaryPath = tempnam($directory, basename($path) . '.tmp-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Nie można utworzyć pliku tymczasowego publikacji.');
    }

    try {
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Nie można zapisać pliku tymczasowego publikacji.');
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('Nie można atomowo opublikować pliku.');
            }
            return;
        }

        // Windows does not reliably replace an existing file with rename().
        $backupPath = null;
        if (is_file($path)) {
            $backupPath = $path . '.replace-' . bin2hex(random_bytes(6));
            if (!rename($path, $backupPath)) {
                throw new RuntimeException('Nie można przygotować poprzedniej wersji pliku do podmiany.');
            }
        }

        if (!rename($temporaryPath, $path)) {
            if ($backupPath !== null && is_file($backupPath)) {
                @rename($backupPath, $path);
            }
            throw new RuntimeException('Nie można opublikować nowej wersji pliku.');
        }

        if ($backupPath !== null && is_file($backupPath)) {
            @unlink($backupPath);
        }
    } finally {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
}

