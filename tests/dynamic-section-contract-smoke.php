<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function dynamic_section_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$planned = [
    ['section_id'=>'A1','topic_role'=>'A','content_type'=>'prose','heading'=>'Plan A','content_brief'=>'A'],
    ['section_id'=>'B1','topic_role'=>'B','content_type'=>'explainer','heading'=>'Plan B','content_brief'=>'B'],
];
$operation = ['input_json'=>generation_json(['narrative_plan'=>['sections_json'=>generation_json($planned)]])];
dynamic_section_assert(count(article_draft_narrative_sections($operation)) === 2,
    'Runtime-shaped NarrativePlan sections were not loaded from narrative_plan.sections_json.');
$arrayOperation = ['input_json'=>generation_json(['narrative_plan'=>['sections_json'=>$planned]])];
dynamic_section_assert(count(article_draft_narrative_sections($arrayOperation)) === 2,
    'Array-form narrative_plan.sections_json was not accepted.');
$malformedRejected = false;
try { article_draft_narrative_sections(['input_json'=>generation_json(['narrative_plan'=>['sections_json'=>'{']])]); }
catch (InvalidArgumentException $exception) { $malformedRejected = str_contains($exception->getMessage(), 'narrative_sections_contract_error'); }
dynamic_section_assert($malformedRejected, 'Malformed narrative_plan.sections_json was not rejected deterministically.');

$differentWording = ['sections'=>[
    ['section_id'=>'A1','topic_role'=>'A','content_type'=>'prose','heading'=>'Nowy tytuł A','body'=>'Treść napisana przez draft.'],
    ['section_id'=>'B1','topic_role'=>'B','content_type'=>'explainer','heading'=>'Nowy tytuł B','body'=>'Inna redakcyjna treść.'],
]];
dynamic_section_assert(article_draft_normalize_narrative_sections($operation, $differentWording) === [],
    'Different heading/body wording was treated as a NarrativePlan conflict.');
article_draft_assert_narrative_sections($operation, $differentWording['sections']);

$legacyOmission = ['sections'=>[
    ['heading'=>'Sekcja A','body'=>'Treść A'],
    ['heading'=>'Sekcja B','body'=>'Treść B'],
]];
$normalized = article_draft_normalize_narrative_sections($operation, $legacyOmission);
dynamic_section_assert($legacyOmission['sections'][0]['section_id'] === 'A1'
    && $legacyOmission['sections'][1]['section_id'] === 'B1'
    && $legacyOmission['sections'][1]['topic_role'] === 'B'
    && $legacyOmission['sections'][1]['content_type'] === 'explainer'
    && count($normalized) === 6,
    'Unambiguous positional mapping did not restore canonical structural fields.');

$roleConflict = ['sections'=>[
    ['section_id'=>'A1','topic_role'=>'A','content_type'=>'prose','heading'=>'A','body'=>'A'],
    ['section_id'=>'B1','topic_role'=>'C','content_type'=>'explainer','heading'=>'B','body'=>'B'],
]];
$conflictRejected = false;
try { article_draft_normalize_narrative_sections($operation, $roleConflict); }
catch (InvalidArgumentException $exception) { $conflictRejected = str_contains($exception->getMessage(), 'narrative_section_contract_conflict'); }
dynamic_section_assert($conflictRejected, 'Explicit B-to-C topic_role conflict was not rejected.');

$legacyOperation = ['input_json'=>generation_json(['editorial_plan'=>['sections'=>$planned]])];
dynamic_section_assert(count(article_draft_narrative_sections($legacyOperation)) === 2,
    'Legacy editorial_plan.sections fallback no longer works.');

foreach ([
    ['sections'=>[['section_id'=>'A1'],['section_id'=>'A1']]],
    ['sections'=>[['section_id'=>'A1']]],
    ['sections'=>[['section_id'=>'A1'],['section_id'=>'B1'],['section_id'=>'C1']]],
] as $invalid) {
    $rejected = false;
    try { article_draft_normalize_narrative_sections($operation, $invalid); }
    catch (InvalidArgumentException $exception) { $rejected = str_contains($exception->getMessage(), 'narrative_section_contract_conflict'); }
    dynamic_section_assert($rejected, 'Duplicate/missing/extra section contract was not rejected.');
}

$generationSource = (string)file_get_contents(dirname(__DIR__) . '/php/generation-service.php');
$normalizationPosition = strpos($generationSource, 'article_draft_normalize_narrative_sections($operation, $output)');
$schemaPosition = $normalizationPosition === false ? false : strpos($generationSource, 'validate_generation_value($output, $schema)', $normalizationPosition);
dynamic_section_assert($normalizationPosition !== false && $schemaPosition !== false && $normalizationPosition < $schemaPosition,
    'Central section normalization does not run before provider-output schema validation.');
$draftSource = (string)file_get_contents(dirname(__DIR__) . '/php/article-draft-service.php');
dynamic_section_assert(substr_count($draftSource, 'article_draft_narrative_sections($operation)') >= 3
    && str_contains($draftSource, 'article_draft_assert_narrative_sections($operation, $draft[\'sections\'])'),
    'Normalizer, final validator and draft fixture do not share the canonical NarrativePlan accessor.');

echo "DYNAMIC_SECTION_CONTRACT_SMOKE_OK\n";
