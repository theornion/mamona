<?php
declare(strict_types=1);

$drafts = [1 => ['id'=>1, 'status'=>'frozen', 'updated_at'=>'2026-08-11 10:00:00', 'draft_json'=>'{"lead":{"text":"core"}}'], 2 => ['id'=>2, 'status'=>'completed', 'updated_at'=>'', 'draft_json'=>'{"lead":{"text":"core"}}']];
function find_article_draft_by_id(int $id): ?array { global $drafts; return $drafts[$id] ?? null; }
require_once __DIR__ . '/../php/quality-check-service.php';
function p3_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$locked = core_text_lock_state(1);
p3_assert($locked['core_text_locked'] === true && is_string($locked['core_hash']) && strlen($locked['core_hash']) === 64, 'QC PASS frozen draft does not produce auditable core lock.');
p3_assert(core_text_lock_state(2)['core_text_locked'] === false, 'Non-frozen draft was locked.');
p3_assert(core_text_operation_allowed('caption') && core_text_operation_allowed('additive_related_module'), 'Allowed additive operations were rejected.');
p3_assert(!core_text_operation_allowed('fresh_conservative_rewrite') && !core_text_operation_allowed('full_rewrite'), 'Full rewrite was allowed after lock.');
$before = $locked['core_hash'];
$after = core_text_lock_state(1)['core_hash'];
p3_assert($before === $after, 'Image-side operation changed locked core canonical representation.');
$source = file_get_contents(__DIR__ . '/../php/article-draft-service.php');
p3_assert(str_contains((string)$source, 'Core text jest locked; pełny rewrite'), 'Frozen core rewrite guard is missing from repair routing.');
echo "P3_CORE_TEXT_LOCK_SMOKE_OK\n";
