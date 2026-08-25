<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_DRAFT_VISUAL_PLAN_SCHEMA_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_DRAFT_VISUAL_PLAN_SCHEMA_SMOKE=1, aby uruchomić test.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/php/admin-database.php';

function visual_plan_schema_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function visual_plan_schema_slot(string $id, string $role, string $anchor, string $need, array $queries): array
{
    return [
        'slot_id' => $id,
        'role' => $role,
        'section_anchor' => $anchor,
        'visual_need' => $need,
        'required' => true,
        'must_be_direct' => $role === 'hero',
        'acceptable_related' => false,
        'search_queries_direct' => $queries,
        'search_queries_related' => [],
    ];
}

$plan = [
    'id' => 991,
    'visual_plan_json' => generation_json([
        'hero_slot' => visual_plan_schema_slot(
            'hero-main',
            'hero',
            'article',
            'Teleskop obserwujący dysk pyłowy wokół czarnej dziury',
            ['black hole accretion disk telescope documentary photograph']
        ),
        'inline_slots' => [
            visual_plan_schema_slot('inline-lead', 'inline', 'lead', 'Obserwatorium i instrument pomiarowy', ['space observatory instrument']),
            visual_plan_schema_slot('inline-why', 'inline', 'why-important', 'Pył i para wodna w danych widmowych', ['infrared spectrum dust water vapor']),
            visual_plan_schema_slot('inline-fact', 'inline', 'fact-1', 'Schemat analizy widma astronomicznego', ['astronomy spectrum analysis diagram']),
        ],
    ]),
];

$schema = article_draft_schema_from_plan($plan, ['S1'], ['C1'], 'informational', []);
$schema = article_draft_schema_lock_narrative_visual_projection($schema, $plan);
$contract = narrative_plan_draft_illustration_contract($plan);
$illustrationSchema = $schema['properties']['illustration_plan'];
$validIllustrationPlan = $contract['illustration_plan'];

$oldContractInput = ['narrative_plan' => $plan, 'draft_visual_plan_contract_version' => 1];
$currentContractInput = [
    'narrative_plan' => $plan,
    'draft_visual_plan_contract_version' => ARTICLE_DRAFT_VISUAL_PLAN_CONTRACT_VERSION,
];
visual_plan_schema_assert(
    hash('sha256', generation_json($oldContractInput)) !== hash('sha256', generation_json($currentContractInput)),
    'Nowa wersja kontraktu VisualPlan nie zmienia identyfikatora wejścia operacji.'
);
visual_plan_schema_assert(
    hash('sha256', generation_json($currentContractInput)) === hash('sha256', generation_json($currentContractInput)),
    'To samo wejście wersjonowanego kontraktu nie jest idempotentne.'
);

validate_generation_value($validIllustrationPlan, $illustrationSchema);

visual_plan_schema_assert(
    $validIllustrationPlan['hero']['slot_id'] === 'hero-main'
    && $validIllustrationPlan['inline'][0]['slot_id'] === 'inline-lead'
    && $validIllustrationPlan['inline'][0]['section_id'] === 'lead',
    'Projekcja VisualPlan nie zachowuje stabilnych slot_id oraz anchorÃ³w.'
);

$heroOnlyPlan = $plan;
$heroOnlyVisual = json_decode((string) $heroOnlyPlan['visual_plan_json'], true, 128, JSON_THROW_ON_ERROR);
$heroOnlyVisual['inline_slots'] = [];
$heroOnlyPlan['visual_plan_json'] = generation_json($heroOnlyVisual);
$heroOnlySchema = article_draft_schema_from_plan($heroOnlyPlan, ['S1'], ['C1'], 'informational', []);
$heroOnlySchema = article_draft_schema_lock_narrative_visual_projection($heroOnlySchema, $heroOnlyPlan);
$heroOnlyContract = narrative_plan_draft_illustration_contract($heroOnlyPlan);
validate_generation_value($heroOnlyContract['illustration_plan'], $heroOnlySchema['properties']['illustration_plan']);
visual_plan_schema_assert($heroOnlyContract['illustration_plan']['inline'] === [], 'Hero-only VisualPlan nie zachowuje pustej listy inline.');

$hero287Plan = $heroOnlyPlan;
$hero287Visual = json_decode((string) $hero287Plan['visual_plan_json'], true, 128, JSON_THROW_ON_ERROR);
$hero287Visual['hero_slot']['slot_id'] = 'hero_image_eclipse';
$hero287Visual['hero_slot']['must_be_direct'] = true;
$hero287Visual['hero_slot']['acceptable_related'] = false;
$hero287Visual['hero_slot']['search_queries_direct'] = ['total solar eclipse documentary photograph'];
$hero287Visual['hero_slot']['search_queries_related'] = [];
$hero287Plan['visual_plan_json'] = generation_json($hero287Visual);
$hero287Contract = narrative_plan_draft_illustration_contract($hero287Plan);
$legacyHeroDraft = ['illustration_plan' => $hero287Contract['illustration_plan']];
foreach (['slot_id', 'must_be_direct', 'acceptable_related', 'search_queries', 'search_queries_related'] as $field) unset($legacyHeroDraft['illustration_plan']['hero'][$field]);
article_draft_normalize_narrative_visual_slot_identity($hero287Plan, $legacyHeroDraft);
visual_plan_schema_assert(
    $legacyHeroDraft['illustration_plan']['hero']['slot_id'] === 'hero_image_eclipse'
    && $legacyHeroDraft['illustration_plan']['hero']['must_be_direct'] === true
    && $legacyHeroDraft['illustration_plan']['hero']['acceptable_related'] === false
    && $legacyHeroDraft['illustration_plan']['hero']['search_queries'] === ['total solar eclipse documentary photograph']
    && $legacyHeroDraft['illustration_plan']['hero']['search_queries_related'] === [],
    'Normalizer nie przywraca canonical identity/policy hero z persisted VisualPlan.'
);
$hero287Schema = article_draft_schema_from_plan($hero287Plan, ['S1'], ['C1'], 'informational', []);
$hero287Schema = article_draft_schema_lock_narrative_visual_projection($hero287Schema, $hero287Plan);
validate_generation_value($legacyHeroDraft['illustration_plan'], $hero287Schema['properties']['illustration_plan']);

$unchangedHeroDraft = ['illustration_plan' => $hero287Contract['illustration_plan']];
$beforeNormalization = serialize($unchangedHeroDraft);
article_draft_normalize_narrative_visual_slot_identity($hero287Plan, $unchangedHeroDraft);
visual_plan_schema_assert(serialize($unchangedHeroDraft) === $beforeNormalization, 'Canonical slot został niepotrzebnie zmieniony.');

$subsetQueryPlan = $hero287Plan;
$subsetQueryVisual = json_decode((string) $subsetQueryPlan['visual_plan_json'], true, 128, JSON_THROW_ON_ERROR);
$subsetQueryVisual['hero_slot']['search_queries_direct'][] = 'solar corona close-up photograph';
$subsetQueryPlan['visual_plan_json'] = generation_json($subsetQueryVisual);
$subsetQueryDraft = ['illustration_plan' => $hero287Contract['illustration_plan']];
article_draft_normalize_narrative_visual_slot_identity($subsetQueryPlan, $subsetQueryDraft);
visual_plan_schema_assert(
    $subsetQueryDraft['illustration_plan']['hero']['search_queries'] === $subsetQueryVisual['hero_slot']['search_queries_direct'],
    'Bezpieczny podzbiór canonical search queries nie został uzupełniony z VisualPlan.'
);

$foreignQueryDraft = ['illustration_plan' => $hero287Contract['illustration_plan']];
$foreignQueryDraft['illustration_plan']['hero']['search_queries'] = ['unapproved invented query'];
try {
    article_draft_normalize_narrative_visual_slot_identity($hero287Plan, $foreignQueryDraft);
    throw new RuntimeException('Obce zapytanie spoza VisualPlan przeszło normalizację.');
} catch (InvalidArgumentException $exception) {
    visual_plan_schema_assert(str_contains($exception->getMessage(), 'visual_plan_policy_conflict'), 'Obce zapytanie nie zostało odrzucone jako policy conflict.');
}

$conflictingHeroDraft = ['illustration_plan' => $hero287Contract['illustration_plan']];
$conflictingHeroDraft['illustration_plan']['hero']['must_be_direct'] = false;
try {
    article_draft_normalize_narrative_visual_slot_identity($hero287Plan, $conflictingHeroDraft);
    throw new RuntimeException('Jawnie sprzeczna policy hero przeszła normalizację.');
} catch (InvalidArgumentException $exception) {
    visual_plan_schema_assert(str_contains($exception->getMessage(), 'visual_plan_policy_conflict'), 'Conflict policy nie ma deterministycznej klasyfikacji.');
}

$ambiguousHeroDraft = ['illustration_plan' => $heroOnlyContract['illustration_plan']];
unset($ambiguousHeroDraft['illustration_plan']['hero']['slot_id']);
$ambiguousHeroDraft['illustration_plan']['hero']['section_id'] = 'lead';
$geminiTransportCalls = 0;
try {
    article_draft_normalize_narrative_visual_slot_identity($heroOnlyPlan, $ambiguousHeroDraft);
    throw new RuntimeException('Niejednoznaczny hero otrzymał wymyślony slot_id.');
} catch (InvalidArgumentException $exception) {
    visual_plan_schema_assert(
        str_contains($exception->getMessage(), 'visual_plan_slot_mapping_ambiguous'),
        'Niejednoznaczny hero nie daje deterministycznej diagnozy.'
    );
}
visual_plan_schema_assert($geminiTransportCalls === 0, 'Lokalna normalizacja nie może uruchamiać Gemini.');

$missingHeroPlan = $plan;
$missingHeroVisual = json_decode((string) $missingHeroPlan['visual_plan_json'], true, 128, JSON_THROW_ON_ERROR);
unset($missingHeroVisual['hero_slot']);
$missingHeroPlan['visual_plan_json'] = generation_json($missingHeroVisual);
try {
    narrative_plan_draft_illustration_contract($missingHeroPlan);
    throw new RuntimeException('Brak wymaganego hero przeszedÅ‚ kontrakt szkicu.');
} catch (RuntimeException $exception) {
    visual_plan_schema_assert(str_contains($exception->getMessage(), 'wymaganego hero'), 'Brak hero nie daje deterministycznej diagnozy.');
}

$heroSchema = $illustrationSchema['properties']['hero']['properties'];
visual_plan_schema_assert(
    ($heroSchema['expected_content']['enum'] ?? []) === [$validIllustrationPlan['hero']['expected_content']],
    'Provider schema nie blokuje exact expected_content hero.'
);
visual_plan_schema_assert(
    ($heroSchema['search_queries']['items']['enum'] ?? []) === $validIllustrationPlan['hero']['search_queries'],
    'Provider schema nie blokuje zapytań hero z VisualPlan.'
);

$modified = $validIllustrationPlan;
$modified['hero']['expected_content'] = 'Dowolna niepowiązana grafika zastępcza';
try {
    validate_generation_value($modified, $illustrationSchema);
    throw new RuntimeException('Zmiana expected_content hero przeszła walidację provider schema.');
} catch (InvalidArgumentException $exception) {
    visual_plan_schema_assert(
        str_contains($exception->getMessage(), '$.hero.expected_content'),
        'Odrzucenie zmienionego expected_content nie wskazuje pola provider schema.'
    );
}

$modified = $validIllustrationPlan;
$modified['inline'][1]['search_queries'] = ['unrelated stock photo'];
try {
    validate_generation_value($modified, $illustrationSchema);
    throw new RuntimeException('Zmiana zapytania inline przeszła walidację provider schema.');
} catch (InvalidArgumentException $exception) {
    visual_plan_schema_assert(
        str_contains($exception->getMessage(), '$.inline[1].search_queries[0]'),
        'Odrzucenie zmienionego zapytania inline nie wskazuje pola provider schema.'
    );
}

echo "draft-visual-plan-schema-smoke: OK\n";
