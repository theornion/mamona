<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/admin-database.php';
bueno_database();

$drain = in_array('--drain', $argv, true);
$guardToken = '';
foreach ($argv as $argument) if (str_starts_with((string)$argument, '--guard=')) $guardToken = substr((string)$argument, 8);
try {
    generation_batch_backfill_research_sources();
    do {
        $claims = generation_batch_claim_items(1);
        foreach ($claims as $claim) {
            generation_batch_process_item((int) $claim['id'], (string) $claim['lease_token']);
        }
        if (!$drain || $claims === []) break;
    } while (true);
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . gmdate('c') . '] batch worker failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($guardToken !== '') bueno_database()->prepare('DELETE FROM generation_worker_guard WHERE guard_key=1 AND lease_token=:token')->execute([':token'=>$guardToken]);
}
