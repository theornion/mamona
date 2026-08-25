<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

$floorOnly = ['publication_floor_met' => true, 'coverage_complete' => false];
if (generation_batch_image_coverage_allows_finalization($floorOnly)) {
    throw new RuntimeException('Floor-only incomplete coverage must not invoke LayoutPlan or Final Multimodal QC.');
}

$complete = ['publication_floor_met' => true, 'coverage_complete' => true];
if (!generation_batch_image_coverage_allows_finalization($complete)) {
    throw new RuntimeException('Complete W/W coverage must allow LayoutPlan and Final Multimodal QC.');
}

echo "GENERATION_BATCH_FINALIZATION_GATE_SMOKE_OK\n";
