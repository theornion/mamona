<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function visual_query_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$schema = narrative_plan_schema(['S1'], ['C1']);
$slotSchema = $schema['properties']['visual_plan']['properties']['inline_slots']['items'];
$base = [
    'slot_id'=>'inline-one', 'role'=>'inline', 'section_anchor'=>'opening', 'topic_source'=>'A',
    'visual_need'=>'Bezpośrednia ilustracja głównego tematu sekcji.',
    'must_be_direct'=>false, 'acceptable_related'=>false,
    'search_queries_direct'=>['query one'], 'search_queries_related'=>[], 'required'=>true,
];

foreach ([
    ['query one'],
    ['query one', 'query two'],
    ['query one', 'query two', 'query three'],
    ['query one', 'query two', 'query three', 'query four', 'query five'],
] as $queries) {
    validate_generation_value([...$base, 'search_queries_direct'=>$queries], $slotSchema);
}

$emptyRejected = false;
try {
    validate_generation_value([...$base, 'search_queries_direct'=>[]], $slotSchema);
} catch (GenerationFieldConstraintException|InvalidArgumentException $exception) {
    $emptyRejected = true;
}
visual_query_assert($emptyRejected, 'Empty search_queries_direct unexpectedly passed validation.');

validate_generation_value($base, $slotSchema);
visual_query_assert(
    (int) $slotSchema['properties']['search_queries_direct']['minItems'] === 1
    && (int) $slotSchema['properties']['search_queries_direct']['maxItems'] === 5,
    'Canonical direct-query bounds are not 1–5.'
);
visual_query_assert(
    (int) $slotSchema['properties']['search_queries_related']['minItems'] === 0
    && (int) $slotSchema['properties']['search_queries_related']['maxItems'] === 6,
    'Related-query optional semantics changed unexpectedly.'
);
visual_query_assert(
    array_column(article_image_direct_queries([
        'search_queries'=>['query one', 'query two'],
        'expected_content'=>'fallback visual intent',
    ], 1), 'query') === ['query one', 'query two'],
    'Retrieval did not preserve every available direct query.'
);

echo "VISUAL_QUERY_CONTRACT_SMOKE_OK\n";
