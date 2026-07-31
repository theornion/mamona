<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/admin-database.php';
bueno_database();

$drain = in_array('--drain', $argv, true);
generation_batch_backfill_research_sources();
do {
    $claims = generation_batch_claim_items(1);
    foreach ($claims as $claim) {
        generation_batch_process_item((int) $claim['id'], (string) $claim['lease_token']);
    }
    if (!$drain || $claims === []) break;
} while (true);
