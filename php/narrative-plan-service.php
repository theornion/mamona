<?php

declare(strict_types=1);

/**
 * NarrativePlan service — generates and persists narrative plans between research and draft.
 *
 * Contract:
 * - article_id, promise_to_reader, main_thesis, narrative_arc, arc_justification
 * - sections_json, transitions_json, rhythm_notes, visual_slots_planned
 * - hero_topic_ref DEFAULT 'A', ending_type, supplemental_topics_json
 * - target_length, status DEFAULT 'planned', batch_stage_ref
 */

const NARRATIVE_PLAN_STATUSES = ['planned', 'generated', 'accepted', 'frozen', 'rejected', 'manual_review'];
const NARRATIVE_ARC_TYPES = [
    'chronology',
    'problem_solution',
    'leading_question',
    'scene_analysis',
    'comparison',
    'myth_evidence',
    'case_study',
];

const NARRATIVE_PLAN_TARGET_LENGTH_MIN = 5000;

function editorial_v2_required_image_count(int $articleCharacters): int
{
    return max(3, min(4, 1 + (int) floor(max(0, $articleCharacters) / 2000)));
}

function editorial_v2_publication_image_floor(int $visualTarget): int
{
    $visualTarget = max(3, min(4, $visualTarget));
    return $visualTarget === 3 ? 3 : $visualTarget - 1;
}

function editorial_v2_visual_target_state(int $articleCharacters, int $currentSlotCount): array
{
    $target = editorial_v2_required_image_count($articleCharacters);
    return [
        'final_article_length'=>$articleCharacters,
        'visual_target'=>$target,
        'visual_slot_count'=>max(0, $currentSlotCount),
        'visual_deficit'=>max(0, $target - max(0, $currentSlotCount)),
        'publication_visual_floor'=>editorial_v2_publication_image_floor($target),
    ];
}
/**
 * Bump this only when the persisted NarrativePlan/VisualPlan contract changes.
 * It is part of the operation input hash, so a stricter contract never reuses
 * a completed operation whose output was produced against an older schema.
 */
const NARRATIVE_PLAN_VISUAL_PLAN_CONTRACT_VERSION = 'editorial-engine-v3-visual-floor-aligned';

function prepare_narrative_plan_operation(int $topicId, int $researchPackageId): int
{
    $package = find_research_package($researchPackageId);
    if ($package === null || $package['status'] !== 'approved') {
        throw new RuntimeException('NarrativePlan można utworzyć wyłącznie z zatwierdzonej paczki researchowej.');
    }
    $research = json_decode((string) $package['package_json'], true, 128, JSON_THROW_ON_ERROR);
    $researchInput = json_decode((string) $package['research_input_json'], true, 128, JSON_THROW_ON_ERROR);

    $sourceIds = array_values(array_map(
        static fn (array $source): string => (string) $source['source_id'],
        (array) ($researchInput['numbered_sources'] ?? [])
    ));
    $claimIds = array_values(array_map(
        static fn (array $claim): string => (string) $claim['claim_id'],
        (array) ($research['claims'] ?? [])
    ));

    if ($sourceIds === [] || $claimIds === []) {
        throw new RuntimeException('Zatwierdzony research nie zawiera źródeł i twierdzeń potrzebnych do planu narracyjnego.');
    }

    $input = [
        'topic_id' => $topicId,
        'research_package_id' => $researchPackageId,
        'visual_plan_contract_version' => NARRATIVE_PLAN_VISUAL_PLAN_CONTRACT_VERSION,
        'visual_phase' => 'preliminary_directions',
        'output_language' => [
            'code' => 'pl-PL',
            'name' => 'język polski',
            'rule' => 'Cała treść planu narracyjnego musi być napisana naturalnym językiem polskim.',
        ],
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'workflow_version' => 2,
        'instructions' => [
            'Wybierz z source-backed researchu najbardziej wartościową, naturalną kompozycję A+B+C; nie maksymalizuj liczby sekcji.',
            'A pozostaje główną historią. B ma pomagać zrozumieć A. Preferuj osobne, source-backed C jako ciekawość, historię lub zaskakujący kontekst; jeśli research nie daje wartościowego C, zwróć curiosity_omitted_reason.',
            'Jeżeli selected_curiosity_topics nie jest puste, curiosity_omitted_reason musi być pustym stringiem. Wypełnij reason wyłącznie przy rzeczywistym pominięciu C.',
            'Projektuj jeden spójny artykuł o preferowanej długości 6000–8500 znaków, w twardym zakresie 5000–10000.',
            'Zdefiniuj zmienną kolejność dynamicznych sections; nie narzucaj legacy lead/facts/unknowns shape.',
            'VisualPlan jest na tym etapie wyłącznie preliminary visual directions dla A+B+C. Pomaga zaplanować narrację, ale nie jest finalnym źródłem wymaganej liczby grafik; FinalVisualPlan powstanie dopiero po QC i core locku.',
            'visual_slots_planned musi dokładnie odpowiadać liczbie slotów: jeden hero plus wszystkie inline_slots. Dla target_length 5000–5999 zaplanuj dokładnie 3 sloty, a dla 6000–10000 dokładnie 4 (jeden hero i trzy inline). Każda sekcja z visual_slot_required=true musi mieć odpowiadający inline_slot z tym samym section_anchor i topic_source.',
            'Hero musi reprezentować sedno primary story A: konkretny obiekt, metodę, zjawisko albo mierzoną aktywność opisaną w researchu. Nie zastępuj sedna ogólnym obrazem dziedziny ani dekoracyjnym schematem.',
            'For each visual slot, PREFER 3–5 diverse direct search queries when useful, but provide at least 1.',
            'For every slot with acceptable_related=false, return search_queries_related as an empty array.',
        ],
    ];

    $schema = narrative_plan_schema($sourceIds, $claimIds);

    // NarrativePlan is idempotent per research package and explicit contract.
    // Older completed operations did not contain this key, therefore cannot be
    // selected here after a schema upgrade.
    $inputHash = hash('sha256', generation_json($input));
    $existing = bueno_database()->prepare(
        'SELECT id FROM generation_operations
         WHERE operation_type = "narrative_plan" AND topic_id = :topic_id
           AND input_hash = :input_hash
         ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([':topic_id' => $topicId, ':input_hash' => $inputHash]);
    $existingOperationId = (int) $existing->fetchColumn();
    if ($existingOperationId > 0) {
        return $existingOperationId;
    }

    return prepare_generation_operation(
        'narrative_plan',
        $input,
        $schema,
        (int) ($package['post_id'] ?? 0),
        $topicId
    );
}

function narrative_plan_schema(array $sourceIds, array $claimIds): array
{
    $slotSchema = [
        'type' => 'object',
        'properties' => [
            'slot_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'role' => ['type' => 'string', 'enum' => ['hero', 'inline']],
            'section_anchor' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'visual_need' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
            'must_be_direct' => ['type' => 'boolean'],
            'acceptable_related' => ['type' => 'boolean'],
            'search_queries_direct' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2], 'minItems' => 1, 'maxItems' => 5],
            'search_queries_related' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2], 'minItems' => 0, 'maxItems' => 6],
            'required' => ['type' => 'boolean'],
            'topic_source' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
        ],
        'required' => ['slot_id', 'role', 'section_anchor', 'topic_source', 'visual_need', 'must_be_direct', 'acceptable_related', 'search_queries_direct', 'search_queries_related', 'required'],
        'additionalProperties' => false,
    ];
    $heroSlotSchema = $slotSchema;
    $heroSlotSchema['properties']['role'] = ['type' => 'string', 'enum' => ['hero']];
    $heroSlotSchema['properties']['section_anchor'] = ['type' => 'string', 'enum' => ['article']];
    $heroSlotSchema['properties']['must_be_direct'] = ['type' => 'boolean', 'enum' => [true]];
    $heroSlotSchema['properties']['required'] = ['type' => 'boolean', 'enum' => [true]];
    $moduleSchema = [
        'type' => 'object', 'properties' => [
            'module_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'topic' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 300],
            'purpose' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
            'suitable_visual_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 5],
            'preferred_placement' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'source_claim_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $claimIds], 'minItems' => 1, 'maxItems' => 12],
        ], 'required' => ['module_id', 'topic', 'purpose', 'suitable_visual_types', 'preferred_placement', 'source_claim_ids'], 'additionalProperties' => false,
    ];
    $sectionSchema = [
        'type' => 'object',
        'properties' => [
            'section_id' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 81],
            'topic_role' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'content_type' => ['type' => 'string', 'enum' => ['prose', 'explainer', 'curiosity', 'history', 'comparison', 'short_callout', 'unknowns', 'takeaway']],
            'heading' => ['type' => 'string', 'maxLength' => 180],
            'content_brief' => ['type' => 'string'],
            'visual_slot_required' => ['type' => 'boolean'],
            'estimated_length' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 3000],
        ],
        'required' => ['section_id', 'topic_role', 'content_type', 'heading', 'content_brief', 'visual_slot_required', 'estimated_length'],
        'additionalProperties' => false,
    ];
    $transitionSchema = [
        'type' => 'object',
        'properties' => [
            'from_section' => ['type' => 'string'],
            'to_section' => ['type' => 'string'],
            'device' => ['type' => 'string'],
            'text_hint' => ['type' => 'string'],
        ],
        'required' => ['from_section', 'to_section', 'device'],
        'additionalProperties' => false,
    ];

    $supplementalSchema = [
        'type' => 'object',
        'properties' => [
            'topic_id' => ['type' => 'string', 'enum' => ['B', 'C']],
            'relation_to_A' => ['type' => 'string'],
            'brief' => ['type' => 'string'],
            'visual_slots' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2],
        ],
        'required' => ['topic_id', 'relation_to_A', 'brief', 'visual_slots'],
        'additionalProperties' => false,
    ];
    $selectedTopicSchema = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'minLength' => 1],
            'title' => ['type' => 'string', 'minLength' => 5],
            'claim_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $claimIds], 'minItems' => 1],
            'selection_reason' => ['type' => 'string', 'minLength' => 10],
        ],
        'required' => ['id', 'title', 'claim_ids', 'selection_reason'],
        'additionalProperties' => false,
    ];

    return [
        'type' => 'object',
        'properties' => [
            'promise_to_reader' => ['type' => 'string', 'minLength' => 30, 'maxLength' => 300],
            'main_thesis' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 500],
            'selected_primary' => $selectedTopicSchema,
            'selected_context_topics' => ['type' => 'array', 'items' => $selectedTopicSchema, 'maxItems' => 4],
            'selected_curiosity_topics' => ['type' => 'array', 'items' => $selectedTopicSchema, 'maxItems' => 4],
            'curiosity_omitted_reason' => ['type' => 'string', 'maxLength' => 500],
            'editorial_thesis' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 500],
            'reader_journey' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 1000],
            'narrative_arc' => ['type' => 'string', 'enum' => NARRATIVE_ARC_TYPES],
            'arc_justification' => ['type' => 'string', 'minLength' => 30, 'maxLength' => 400],
            'sections' => [
                'type' => 'array',
                'items' => $sectionSchema,
                'minItems' => 3,
                'maxItems' => 12,
            ],
            'transitions' => [
                'type' => 'array',
                'items' => $transitionSchema,
                'minItems' => 2,
                'maxItems' => 11,
            ],
            'rhythm_notes' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 500],
            'visual_slots_planned' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 4],
            'hero_topic_ref' => ['type' => 'string', 'enum' => ['A']],
            'ending_type' => ['type' => 'string', 'enum' => ['conclusion', 'open_question', 'call_to_action', 'scene_return', 'summary']],
            'supplemental_topics' => [
                'type' => 'array',
                'items' => $supplementalSchema,
                'minItems' => 0,
                'maxItems' => 2,
            ],
            'target_length' => ['type' => 'integer', 'minimum' => NARRATIVE_PLAN_TARGET_LENGTH_MIN, 'maximum' => ARTICLE_MAIN_CONTENT_MAX_LENGTH],
            'used_source_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $sourceIds],
            ],
            'visual_plan' => ['type' => 'object', 'properties' => [
                'hero_slot' => $heroSlotSchema,
            'inline_slots' => ['type' => 'array', 'items' => $slotSchema, 'minItems' => 2, 'maxItems' => 3],
            ], 'required' => ['hero_slot', 'inline_slots'], 'additionalProperties' => false],
            'expansion_modules' => ['type' => 'array', 'items' => $moduleSchema, 'minItems' => 0, 'maxItems' => 4],
        ],
        'required' => [
            'promise_to_reader',
            'main_thesis',
            'selected_primary',
            'selected_context_topics',
            'selected_curiosity_topics',
            'curiosity_omitted_reason',
            'editorial_thesis',
            'reader_journey',
            'narrative_arc',
            'arc_justification',
            'sections',
            'transitions',
            'rhythm_notes',
            'visual_slots_planned',
            'hero_topic_ref',
            'ending_type',
            'supplemental_topics',
            'target_length',
            'used_source_ids',
            'visual_plan',
            'expansion_modules',
        ],
        'additionalProperties' => false,
    ];
}

/** Deterministic fixture used only when GEMINI_API_MOCK is enabled. */
function narrative_plan_mock_generation_value(array $operation): array
{
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true);
    $sourceIds = array_values(array_filter(array_map(
        static fn (array $source): string => (string) ($source['source_id'] ?? ''),
        (array) ($input['numbered_sources'] ?? [])
    )));
    $claimIds = array_values(array_filter(array_map(
        static fn (array $claim): string => (string) ($claim['claim_id'] ?? ''),
        (array) ($input['research_package']['claims'] ?? [])
    )));
    $moduleClaims = array_slice($claimIds, 0, 1);

    return [
        'promise_to_reader' => 'Wyjaśniamy, co oznacza odkrycie, jak je zbadano i dlaczego ma znaczenie.',
        'main_thesis' => 'Kontrolowane dane pozwalają jasno wyjaśnić znaczenie badanego odkrycia naukowego.',
        'selected_primary' => ['id'=>'A','title'=>'Główna historia','claim_ids'=>$moduleClaims,'selection_reason'=>'To centralny, najlepiej udokumentowany temat materiału.'],
        'selected_context_topics' => [],
        'selected_curiosity_topics' => [],
        'curiosity_omitted_reason' => 'Fixture research nie zawiera osobnego, source-backed kandydata C.',
        'editorial_thesis' => 'Główna historia prowadzi artykuł, a wyłącznie udokumentowany kontekst pomaga czytelnikowi zrozumieć jej znaczenie.',
        'reader_journey' => 'Czytelnik poznaje wydarzenie, rozumie dowody i mechanizm, a następnie wraca do znaczenia głównej historii.',
        'narrative_arc' => 'leading_question',
        'arc_justification' => 'Plan zaczyna od pytania, następnie przedstawia dowody i kończy praktycznym znaczeniem wyniku.',
        'sections' => [
            ['section_id' => 'opening', 'topic_role' => 'A', 'content_type' => 'prose', 'heading' => '', 'content_brief' => 'Otwarcie i najważniejszy kontekst odkrycia.', 'visual_slot_required' => true, 'estimated_length' => 1200],
            ['section_id' => 'mechanism', 'topic_role' => 'A', 'content_type' => 'explainer', 'heading' => 'Jak działa badany mechanizm', 'content_brief' => 'Metoda, dane oraz ograniczenia przedstawione na podstawie źródeł.', 'visual_slot_required' => true, 'estimated_length' => 1800],
            ['section_id' => 'meaning', 'topic_role' => 'A', 'content_type' => 'takeaway', 'heading' => 'Co z tego wynika', 'content_brief' => 'Znaczenie wyniku i ostrożny wniosek dla czytelnika.', 'visual_slot_required' => true, 'estimated_length' => 1500],
        ],
        'transitions' => [
            ['from_section' => 'opening', 'to_section' => 'mechanism', 'device' => 'question_to_evidence', 'text_hint' => 'Przejście od pytania do danych.'],
            ['from_section' => 'mechanism', 'to_section' => 'meaning', 'device' => 'evidence_to_implication', 'text_hint' => 'Przejście od dowodów do znaczenia.'],
        ],
        'rhythm_notes' => 'Krótki lead, rzeczowe wyjaśnienie metody i spokojne podsumowanie bez przesadnych obietnic.',
        'visual_slots_planned' => 4,
        'hero_topic_ref' => 'A',
        'ending_type' => 'summary',
        'supplemental_topics' => [],
        'target_length' => 6500,
        'used_source_ids' => array_slice($sourceIds, 0, 1),
        'visual_plan' => [
            'hero_slot' => ['slot_id'=>'hero-main','role'=>'hero','section_anchor'=>'article','topic_source'=>'A','visual_need'=>'Bezpośredni obraz głównego tematu artykułu.','must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['scientific subject documentary photograph','primary subject research image','documentary science feature image'],'search_queries_related'=>[],'required'=>true],
            'inline_slots' => [
                ['slot_id'=>'inline-opening','role'=>'inline','section_anchor'=>'opening','topic_source'=>'A','visual_need'=>'Obraz głównej historii opisanej w otwarciu.','must_be_direct'=>false,'acceptable_related'=>true,'search_queries_direct'=>['scientific evidence research','primary research subject','science documentary evidence'],'search_queries_related'=>['research context'],'required'=>true],
                ['slot_id'=>'inline-mechanism','role'=>'inline','section_anchor'=>'mechanism','topic_source'=>'A','visual_need'=>'Obraz wyjaśniający mechanizm lub metodę.','must_be_direct'=>false,'acceptable_related'=>true,'search_queries_direct'=>['scientific mechanism explanation','research apparatus mechanism','technology process diagram'],'search_queries_related'=>['research laboratory context'],'required'=>true],
                ['slot_id'=>'inline-meaning','role'=>'inline','section_anchor'=>'meaning','topic_source'=>'A','visual_need'=>'Obraz wspierający znaczenie wyniku.','must_be_direct'=>false,'acceptable_related'=>true,'search_queries_direct'=>['scientific result context','research impact evidence','science comparison visual'],'search_queries_related'=>['research impact context'],'required'=>true],
            ],
        ],
        'expansion_modules' => [
            ['module_id'=>'module-method','topic'=>'Metoda badawcza','purpose'=>'Dodatkowy kontekst metody wynikający z zatwierdzonego researchu.','suitable_visual_types'=>['diagram','laboratory photograph'],'preferred_placement'=>'after-evidence','source_claim_ids'=>$moduleClaims],
            ['module_id'=>'module-context','topic'=>'Kontekst naukowy','purpose'=>'Ostrożne wyjaśnienie znaczenia odkrycia bez zmiany głównej tezy.','suitable_visual_types'=>['context photograph','comparison graphic'],'preferred_placement'=>'before-takeaway','source_claim_ids'=>$moduleClaims],
        ],
    ];
}

function persist_narrative_plan(int $operationId, array $plan): int
{
    $database = bueno_database();
    $existing = $database->prepare('SELECT id FROM narrative_plans WHERE batch_stage_ref=:operation_id ORDER BY id DESC LIMIT 1');
    $existing->execute([':operation_id' => $operationId]);
    if (($existingId = $existing->fetchColumn()) !== false) {
        return (int) $existingId;
    }
    $topicId = (int) ($plan['topic_id'] ?? 0);
    $postId = (int) ($plan['post_id'] ?? 0);

    if ($topicId <= 0) {
        $operation = find_generation_operation($operationId);
        if (!is_array($operation)) {
            throw new RuntimeException('Nie znaleziono operacji planu narracyjnego.');
        }
        $topicId = (int) ($operation['topic_id'] ?? 0);
    }

    $sectionsJson = generation_json((array) ($plan['sections'] ?? []));
    $transitionsJson = generation_json((array) ($plan['transitions'] ?? []));
    $supplementalJson = generation_json((array) ($plan['supplemental_topics'] ?? []));
    $visualPlanJson = generation_json((array) ($plan['visual_plan'] ?? []));
    $expansionModulesJson = generation_json((array) ($plan['expansion_modules'] ?? []));

    $database->prepare(
        'INSERT INTO narrative_plans (' .
            'article_id, promise_to_reader, main_thesis, narrative_arc, arc_justification,' .
            'sections_json, transitions_json, rhythm_notes, visual_slots_planned,' .
            'hero_topic_ref, ending_type, supplemental_topics_json, visual_plan_json, expansion_modules_json, target_length,' .
            'status, batch_stage_ref, created_at, updated_at' .
        ') VALUES (' .
            ':article_id, :promise, :thesis, :arc, :justification,' .
            ':sections, :transitions, :rhythm, :visual_slots,' .
            ':hero_ref, :ending, :supplemental, :visual_plan, :expansion_modules, :target_length,' .
            ':status, :batch_stage, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP' .
        ')'
    )->execute([
        ':article_id' => $postId > 0 ? $postId : $topicId,
        ':promise' => mb_substr((string) ($plan['promise_to_reader'] ?? ''), 0, 300),
        ':thesis' => mb_substr((string) ($plan['main_thesis'] ?? ''), 0, 500),
        ':arc' => mb_substr((string) ($plan['narrative_arc'] ?? ''), 0, 100),
        ':justification' => mb_substr((string) ($plan['arc_justification'] ?? ''), 0, 400),
        ':sections' => $sectionsJson,
        ':transitions' => $transitionsJson,
        ':rhythm' => mb_substr((string) ($plan['rhythm_notes'] ?? ''), 0, 500),
        ':visual_slots' => max(1, min(4, (int) ($plan['visual_slots_planned'] ?? 1))),
        ':hero_ref' => 'A',
        ':ending' => mb_substr((string) ($plan['ending_type'] ?? ''), 0, 50),
        ':supplemental' => $supplementalJson,
        ':visual_plan' => $visualPlanJson,
        ':expansion_modules' => $expansionModulesJson,
        ':target_length' => max(NARRATIVE_PLAN_TARGET_LENGTH_MIN, min(ARTICLE_MAIN_CONTENT_MAX_LENGTH, (int) ($plan['target_length'] ?? 6500))),
        ':status' => 'generated',
        ':batch_stage' => $operationId,
    ]);

    return (int) $database->lastInsertId();
}

/** Project the persisted P02 VisualPlan to the established draft/image plan without losing slot provenance. */
function narrative_visual_plan_to_illustration_plan(array $visualPlan): array
{
    $project = static function (array $slot, string $layout): array {
        return ['role'=>(string)$slot['role'], 'section_id'=>(string)$slot['section_anchor'],
            'visual_intent'=>(string)$slot['visual_need'], 'search_queries'=>(array)$slot['search_queries_direct'],
            'expected_content'=>(string)$slot['visual_need'], 'source_page_url'=>'','source_file_url'=>'','local_path'=>'',
            'author'=>'','license'=>'','license_url'=>'','attribution'=>'','alt'=>(string)$slot['visual_need'],
            'caption'=>(string)$slot['visual_need'],'layout'=>$layout,'status'=>'planned',
            'slot_id'=>(string)$slot['slot_id'],'must_be_direct'=>(bool)$slot['must_be_direct'],
            'acceptable_related'=>(bool)$slot['acceptable_related'],'search_queries_related'=>(array)$slot['search_queries_related']];
    };
    $hero = $project((array)($visualPlan['hero_slot'] ?? []), 'full');
    $inline = [];
    foreach (array_slice((array)($visualPlan['inline_slots'] ?? []), 0, 3) as $index => $slot) $inline[] = $project((array)$slot, ['full','left','right','breakout'][$index % 4]);
    return ['hero'=>$hero, 'inline'=>$inline];
}

function find_narrative_plan_for_topic(int $topicId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT plans.* FROM narrative_plans AS plans
         INNER JOIN generation_operations AS operations
            ON operations.id = CAST(plans.batch_stage_ref AS INTEGER)
           AND operations.operation_type = "narrative_plan"
         WHERE operations.topic_id = :id
           AND plans.article_id = operations.post_id
           AND plans.status IN ("generated", "accepted", "frozen")
         ORDER BY plans.id DESC LIMIT 1'
    );
    $statement->execute([':id' => $topicId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

/** Resolve a post's plan without assuming that post_id and topic_id share a value. */
function find_narrative_plan_for_post(int $postId, ?int $topicId = null): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM narrative_plans
         WHERE article_id = :post_id AND status IN ("generated", "accepted", "frozen")
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':post_id' => $postId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function find_narrative_plan(int $planId): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM narrative_plans WHERE id = :id');
    $statement->execute([':id' => $planId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

/** Full V2 selection remains auditable in the generating operation output. */
function narrative_plan_editorial_payload(array $narrativePlan): array
{
    $operationId = (int) ($narrativePlan['batch_stage_ref'] ?? 0);
    $operation = $operationId > 0 ? find_generation_operation($operationId) : null;
    $output = is_array($operation) ? json_decode((string) ($operation['output_json'] ?? '{}'), true) : null;
    if (!is_array($output)) return [];
    return array_intersect_key($output, array_flip([
        'selected_primary', 'selected_context_topics', 'selected_curiosity_topics',
        'curiosity_omitted_reason', 'editorial_thesis', 'reader_journey', 'sections', 'transitions', 'target_length',
    ]));
}

function accept_narrative_plan(int $planId): void
{
    bueno_database()->prepare(
        'UPDATE narrative_plans SET status = "accepted", updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':id' => $planId]);
}

function freeze_narrative_plan(int $planId): void
{
    bueno_database()->prepare(
        'UPDATE narrative_plans SET status = "frozen", updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':id' => $planId]);
}

/**
 * Project a P02 VisualPlan to the persisted draft illustration contract.
 * V2 anchors are dynamic section IDs; legacy plans retain their old anchors.
 */
function narrative_plan_draft_illustration_contract(array $narrativePlan): array
{
    $visualPlan = json_decode((string) ($narrativePlan['visual_plan_json'] ?? '{}'), true);
    if (!is_array($visualPlan)) {
        throw new RuntimeException('NarrativePlan nie zawiera poprawnego VisualPlan dla szkicu.');
    }
    $hero = (array) ($visualPlan['hero_slot'] ?? []);
    $inline = array_slice(array_values((array) ($visualPlan['inline_slots'] ?? [])), 0, 3);
    if (($hero['role'] ?? '') !== 'hero' || ($hero['section_anchor'] ?? '') !== 'article'
        || empty($hero['required']) || empty($hero['must_be_direct'])
        || (array) ($hero['search_queries_direct'] ?? []) === []) {
        throw new RuntimeException('NarrativePlan nie ma wymaganego hero zgodnego z kontraktem szkicu.');
    }
    $slotIds = [];
    foreach ([$hero, ...$inline] as $slot) {
        $slotId = trim((string) ($slot['slot_id'] ?? ''));
        $anchor = trim((string) ($slot['section_anchor'] ?? ''));
        if ($slotId === '' || isset($slotIds[$slotId]) || (array) ($slot['search_queries_direct'] ?? []) === []) {
            throw new RuntimeException('NarrativePlan ma niejednoznaczny slot wizualny dla szkicu.');
        }
        if (!empty($slot['acceptable_related']) && (array) ($slot['search_queries_related'] ?? []) === []) {
            throw new RuntimeException('NarrativePlan dopuszcza related bez wymaganych zapytań related.');
        }
        if (($slot['role'] ?? '') === 'inline' && $anchor === '') throw new RuntimeException('NarrativePlan wskazuje pusty anchor inline.');
        $slotIds[$slotId] = true;
    }
    foreach ($inline as $slot) {
        if (($slot['role'] ?? '') !== 'inline' || trim((string) ($slot['section_anchor'] ?? '')) === '') {
            return null;
        }
    }
    $project = static function (array $slot, string $layout): array {
        $need = trim((string) ($slot['visual_need'] ?? ''));
        if ($need === '') throw new RuntimeException('NarrativePlan slot nie ma visual_need.');
        return [
            'role' => (string) $slot['role'], 'section_id' => (string) $slot['section_anchor'],
            'visual_intent' => $need, 'search_queries' => array_values((array) $slot['search_queries_direct']),
            'expected_content' => $need, 'source_page_url' => '', 'source_file_url' => '', 'local_path' => '',
            'author' => '', 'license' => '', 'license_url' => '', 'attribution' => '',
            'alt' => $need, 'caption' => $need, 'layout' => $layout, 'status' => 'planned',
            'slot_id' => (string) $slot['slot_id'],
            'must_be_direct' => (bool) $slot['must_be_direct'],
            'acceptable_related' => (bool) $slot['acceptable_related'],
            'search_queries_related' => !empty($slot['acceptable_related'])
                ? array_values((array) $slot['search_queries_related'])
                : [],
        ];
    };
    $projectedInline = [];
    $usedSections = [];
    foreach ($inline as $index => $slot) {
        if (($slot['role'] ?? '') !== 'inline') {
            throw new RuntimeException('NarrativePlan ma slot niebędący inline w tablicy inline_slots.');
        }
        $slot = (array) $slot;
        $sectionId = (string) $slot['section_anchor'];
        if (isset($usedSections[$sectionId])) {
            throw new RuntimeException('NarrativePlan mapuje więcej niż jeden wymagany slot do sekcji szkicu ' . $sectionId . '.');
        }
        $usedSections[$sectionId] = true;
        $projectedInline[] = $project($slot, ['full', 'left', 'right', 'breakout'][$index % 4]);
    }
    return [
        'plan_id' => (int) ($narrativePlan['id'] ?? 0),
        'illustration_plan' => ['hero' => $project($hero, 'full'), 'inline' => $projectedInline],
        'slot_ids' => array_keys($slotIds),
    ];
}

/** Build the stable persisted draft schema with plan-authoritative visual slots. */
function article_draft_schema_from_plan(
    ?array $narrativePlan,
    array $sourceIds,
    array $claimIds,
    string $compositionMode,
    array $unknownIds = []
): array {
    if ($narrativePlan === null) {
        return article_draft_schema($sourceIds, $claimIds, $compositionMode, $unknownIds);
    }

    $contract = narrative_plan_draft_illustration_contract($narrativePlan);
    $schema = article_draft_v2_schema($sourceIds, $claimIds, $compositionMode, (array) (narrative_plan_editorial_payload($narrativePlan)['sections'] ?? []));
    $inline = (array) ($contract['illustration_plan']['inline'] ?? []);
    $schema['properties']['illustration_plan'] = article_illustration_plan_schema(
        count($inline),
        $inline === [] ? null : array_column($inline, 'section_id')
    );
    return $schema;
}

/** Validate a completed narrative plan output. Returns validation array or null on failure. */
function validate_narrative_plan_output(array $operation, array $output): ?array
{
    $visualPlan = (array) ($output['visual_plan'] ?? []);
    $hero = (array) ($visualPlan['hero_slot'] ?? []);
    $inline = (array) ($visualPlan['inline_slots'] ?? []);
    if (($hero['role'] ?? '') !== 'hero' || ($hero['required'] ?? false) !== true
        || ($hero['must_be_direct'] ?? false) !== true || ($hero['section_anchor'] ?? '') !== 'article') return null;
    $slotIds = [];
    foreach ([$hero, ...$inline] as $slot) {
        $slotId = trim((string) ($slot['slot_id'] ?? ''));
        if ($slotId === '' || isset($slotIds[$slotId]) || trim((string) ($slot['section_anchor'] ?? '')) === ''
            || (array) ($slot['search_queries_direct'] ?? []) === []
            || !in_array((string) ($slot['topic_source'] ?? ''), ['A', 'B', 'C'], true)
            || (!empty($slot['acceptable_related']) && (array) ($slot['search_queries_related'] ?? []) === [])) return null;
        $slotIds[$slotId] = true;
    }
    foreach ($inline as $slot) {
        if (($slot['role'] ?? '') !== 'inline' || trim((string) ($slot['section_anchor'] ?? '')) === '') {
            return null;
        }
    }
    $modules = (array) ($output['expansion_modules'] ?? []);
    foreach ($modules as $module) {
        if (trim((string) ($module['module_id'] ?? '')) === '' || trim((string) ($module['topic'] ?? '')) === ''
            || trim((string) ($module['purpose'] ?? '')) === '' || (array) ($module['suitable_visual_types'] ?? []) === []
            || trim((string) ($module['preferred_placement'] ?? '')) === ''
            || (array) ($module['source_claim_ids'] ?? []) === []) return null;
    }
    $arc = (string) ($output['narrative_arc'] ?? '');
    if (!in_array($arc, NARRATIVE_ARC_TYPES, true)) {
        return null;
    }

    $sections = (array) ($output['sections'] ?? []);
    if (count($sections) < 3 || count($sections) > 12) {
        return null;
    }

    $visualSlots = (int) ($output['visual_slots_planned'] ?? 0);
    if ($visualSlots < 3 || $visualSlots > 4) {
        return null;
    }

    $targetLength = (int) ($output['target_length'] ?? 0);
    if ($targetLength < NARRATIVE_PLAN_TARGET_LENGTH_MIN || $targetLength > ARTICLE_MAIN_CONTENT_MAX_LENGTH) {
        return null;
    }
    $minimumInline = editorial_v2_required_image_count($targetLength) - 1;
    if (count(array_filter($inline, static fn (array $slot): bool => !empty($slot['required']))) < $minimumInline
        || $visualSlots !== 1 + count($inline)) {
        return null;
    }

    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    $research = (array) ($input['research_package'] ?? []);
    $claimIds = array_fill_keys(array_map(static fn (array $claim): string => (string) ($claim['claim_id'] ?? ''), (array) ($research['claims'] ?? [])), true);
    $primary = (array) ($research['primary_story'] ?? []);
    $candidateIds = ['A' => [(string) ($primary['id'] ?? '') => array_map('strval', (array) ($primary['claim_ids'] ?? []))], 'B' => [], 'C' => []];
    foreach ((array) ($research['context_topics'] ?? []) as $topic) $candidateIds['B'][(string) ($topic['id'] ?? '')] = array_map('strval', (array) ($topic['claim_ids'] ?? []));
    foreach ((array) ($research['curiosity_topics'] ?? []) as $topic) $candidateIds['C'][(string) ($topic['id'] ?? '')] = array_map('strval', (array) ($topic['claim_ids'] ?? []));
    $selectedSources = ['A' => false, 'B' => false, 'C' => false];
    foreach (['selected_primary'=>['A', false], 'selected_context_topics'=>['B', true], 'selected_curiosity_topics'=>['C', true]] as $field => [$type, $many]) {
        $selected = $many ? (array) ($output[$field] ?? []) : [(array) ($output[$field] ?? [])];
        foreach ($selected as $topic) {
            $topicId = (string) ($topic['id'] ?? '');
            $selectedClaims = array_map('strval', (array) ($topic['claim_ids'] ?? []));
            if (!isset($candidateIds[$type][$topicId]) || $selectedClaims === []
                || array_diff($selectedClaims, $candidateIds[$type][$topicId]) !== []
                || array_diff($selectedClaims, array_keys($claimIds)) !== []) return null;
            $selectedSources[$type] = true;
        }
    }

    foreach ([$hero, ...$inline] as $slot) {
        if (empty($selectedSources[(string) ($slot['topic_source'] ?? '')])) return null;
    }

    $sectionIds = [];
    $hasPrimaryProse = false;
    foreach ($sections as $sec) {
        $sectionId = trim((string) ($sec['section_id'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $sectionId) !== 1 || isset($sectionIds[$sectionId])) return null;
        $sectionIds[$sectionId] = true;
        $topicRole = (string) ($sec['topic_role'] ?? '');
        if (!in_array($topicRole, ['A', 'B', 'C'], true)) {
            return null;
        }
        if (empty($selectedSources[$topicRole])) return null;
        if ($topicRole === 'A' && in_array((string) ($sec['content_type'] ?? ''), ['prose', 'explainer'], true)) $hasPrimaryProse = true;
    }
    if (!$hasPrimaryProse) return null;
    foreach ($inline as $slot) if (!isset($sectionIds[(string) ($slot['section_anchor'] ?? '')])) return null;
    $selectedCuriosity = (array) ($output['selected_curiosity_topics'] ?? []);
    $omittedReason = trim((string) ($output['curiosity_omitted_reason'] ?? ''));
    if ($selectedCuriosity === [] && $omittedReason === '') return null;
    if ($selectedCuriosity !== [] && $omittedReason !== '') return null;
    if ((array) ($research['curiosity_topics'] ?? []) !== [] && $selectedCuriosity === []) return null;

    return [
        'valid' => true,
        'narrative_arc' => $arc,
        'section_count' => count($sections),
        'visual_slots_planned' => $visualSlots,
        'target_length' => $targetLength,
    ];
}

/** Canonicalize a harmless provider ambiguity without inventing or deleting any researched C angle. */
function narrative_plan_normalize_curiosity_omission(array &$output): bool
{
    if ((array) ($output['selected_curiosity_topics'] ?? []) === []
        || trim((string) ($output['curiosity_omitted_reason'] ?? '')) === '') {
        return false;
    }
    $output['curiosity_omitted_reason'] = '';
    return true;
}

/** A slot that disallows related media must not retain inert related queries. */
function narrative_plan_normalize_visual_related_query_policy(array &$output): array
{
    $visual =& $output['visual_plan'];
    if (!is_array($visual)) return [];
    $normalized = [];
    foreach (['hero_slot'] as $field) {
        $slot = (array) ($visual[$field] ?? []);
        if ($slot !== [] && empty($slot['acceptable_related']) && (array) ($slot['search_queries_related'] ?? []) !== []) {
            $visual[$field]['search_queries_related'] = [];
            $normalized[] = (string) ($slot['slot_id'] ?? $field);
        }
    }
    foreach ((array) ($visual['inline_slots'] ?? []) as $index => $slot) {
        $slot = (array) $slot;
        if (empty($slot['acceptable_related']) && (array) ($slot['search_queries_related'] ?? []) !== []) {
            $visual['inline_slots'][$index]['search_queries_related'] = [];
            $normalized[] = (string) ($slot['slot_id'] ?? ('inline-' . $index));
        }
    }
    return $normalized;
}

function narrative_plan_record_visual_related_query_policy_normalization(array $operation, array $slotIds): void
{
    $usage = json_decode((string) ($operation['usage_json'] ?? '{}'), true) ?: [];
    $usage['visual_related_query_policy_normalization'] = [
        'applied' => true, 'slot_ids' => array_values($slotIds), 'canonical_value' => [], 'at' => gmdate('c'),
    ];
    bueno_database()->prepare('UPDATE generation_operations SET usage_json=:usage, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
        ->execute([':usage' => generation_json($usage), ':id' => (int) $operation['id']]);
}

/** Normalize provider-friendly section identifiers to renderer-safe kebab-case and update every reference atomically. */
function narrative_plan_normalize_section_ids(array &$output): array
{
    $mapping = []; $seen = [];
    foreach ((array) ($output['sections'] ?? []) as $index => $section) {
        $old = trim((string) ($section['section_id'] ?? ''));
        $new = trim((string) preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower($old)), '-');
        if ($old === '' || $new === '' || isset($seen[$new])) return [];
        $seen[$new] = true;
        if ($new !== $old) $mapping[$old] = $new;
        $output['sections'][$index]['section_id'] = $new;
    }
    if ($mapping === []) return [];
    foreach ((array) ($output['transitions'] ?? []) as $index => $transition) {
        foreach (['from_section','to_section'] as $field) {
            $value = (string) ($transition[$field] ?? '');
            if (isset($mapping[$value])) $output['transitions'][$index][$field] = $mapping[$value];
        }
    }
    foreach ((array) (($output['visual_plan'] ?? [])['inline_slots'] ?? []) as $index => $slot) {
        $anchor = (string) ($slot['section_anchor'] ?? '');
        if (isset($mapping[$anchor])) $output['visual_plan']['inline_slots'][$index]['section_anchor'] = $mapping[$anchor];
    }
    return $mapping;
}

function narrative_plan_record_curiosity_omission_normalization(array $operation): void
{
    $usage = json_decode((string) ($operation['usage_json'] ?? '{}'), true) ?: [];
    $usage['curiosity_omission_normalization'] = [
        'applied'=>true, 'reason'=>'selected_curiosity_topics_present', 'canonical_value'=>'', 'at'=>gmdate('c'),
    ];
    bueno_database()->prepare('UPDATE generation_operations SET usage_json=:usage, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
        ->execute([':usage'=>generation_json($usage), ':id'=>(int) $operation['id']]);
}

/** Keep a provider overrun within the fixed one-hero plus three-inline contract. */
function narrative_plan_normalize_visual_ceiling(array &$output): bool
{
    $visual =& $output['visual_plan'];
    if (!is_array($visual) || !is_array($visual['inline_slots'] ?? null)) return false;
    $inline = array_values($visual['inline_slots']);
    if (count($inline) <= 3) return false;
    $kept = [];
    $anchors = [];
    foreach ($inline as $slot) {
        if (!is_array($slot)) continue;
        $anchor = trim((string) ($slot['section_anchor'] ?? ''));
        if ($anchor === '' || isset($anchors[$anchor])) continue;
        $anchors[$anchor] = true;
        $kept[] = $slot;
        if (count($kept) === 3) break;
    }
    if (count($kept) !== 3) return false;
    $visual['inline_slots'] = $kept;
    $output['visual_slots_planned'] = 4;
    return true;
}

/** Return source-backed direct queries for a selected non-primary topic without inventing a visual direction. */
function narrative_plan_selected_topic_queries(array $operation, array $output, string $topicSource): array
{
    if (!in_array($topicSource, ['B', 'C'], true)) return [];
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    $research = (array) ($input['research_package'] ?? []);
    $selectedField = $topicSource === 'B' ? 'selected_context_topics' : 'selected_curiosity_topics';
    $researchField = $topicSource === 'B' ? 'context_topics' : 'curiosity_topics';
    $selectedIds = array_fill_keys(array_filter(array_map(
        static fn (array $topic): string => trim((string) ($topic['id'] ?? '')),
        (array) ($output[$selectedField] ?? [])
    )), true);
    if ($selectedIds === []) return [];
    foreach ((array) ($research[$researchField] ?? []) as $topic) {
        if (!isset($selectedIds[(string) ($topic['id'] ?? '')])) continue;
        $queries = array_values(array_unique(array_filter(array_map(
            static fn (mixed $query): string => trim((string) $query),
            (array) ($topic['suggested_visual_queries'] ?? [])
        ), static fn (string $query): bool => mb_strlen($query) >= 2)));
        if ($queries !== []) return array_slice($queries, 0, 5);
    }
    return [];
}

/** Fill only an explicitly planned visual section omitted from VisualPlan, using existing plan text and source-backed queries. */
function narrative_plan_normalize_visual_floor(array &$output, ?array $operation = null, array &$researchQuerySlotIds = []): array
{
    $visual =& $output['visual_plan'];
    if (!is_array($visual) || !is_array($visual['hero_slot'] ?? null) || !is_array($visual['inline_slots'] ?? null)) return [];
    $target = editorial_v2_required_image_count((int) ($output['target_length'] ?? 0));
    $actual = 1 + count($visual['inline_slots']);
    $added = [];
    if ($actual < $target) {
        $used = array_fill_keys(array_map(static fn (array $slot): string => (string) ($slot['section_anchor'] ?? ''), $visual['inline_slots']), true);
        $donors = [$visual['hero_slot'], ...$visual['inline_slots']];
        foreach ((array) ($output['sections'] ?? []) as $section) {
            if ($actual >= $target) break;
            $anchor = (string) ($section['section_id'] ?? '');
            $topic = (string) ($section['topic_role'] ?? '');
            if (empty($section['visual_slot_required']) || $anchor === '' || isset($used[$anchor])) continue;
            $donor = null;
            foreach ($donors as $candidate) if ((string) ($candidate['topic_source'] ?? '') === $topic) { $donor = $candidate; break; }
            $queries = is_array($donor)
                ? array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($donor['search_queries_direct'] ?? []))))), 0, 5)
                : ($operation === null ? [] : narrative_plan_selected_topic_queries($operation, $output, $topic));
            $need = trim((string) ($section['content_brief'] ?? $section['heading'] ?? ''));
            if ($queries === [] || mb_strlen($need) < 10) continue;
            $slotId = 'inline-' . $anchor;
            $visual['inline_slots'][] = [
                'slot_id'=>$slotId,'role'=>'inline','section_anchor'=>$anchor,'topic_source'=>$topic,
                'visual_need'=>mb_substr($need, 0, 500),'must_be_direct'=>false,'acceptable_related'=>false,
                'search_queries_direct'=>$queries,'search_queries_related'=>[],'required'=>true,
            ];
            $used[$anchor] = true; $actual++; $added[] = $slotId;
            if (!is_array($donor)) $researchQuerySlotIds[] = $slotId;
        }
    }
    if ($actual >= $target && (int) ($output['visual_slots_planned'] ?? 0) !== $actual) $output['visual_slots_planned'] = $actual;
    return $added;
}

/** Strict P02 compatibility bridge derived solely from the same post's frozen draft. */
function narrative_plan_adapt_legacy_visual_plan(array $operation, array $output): ?array
{
    $postId = (int) ($operation['post_id'] ?? 0);
    if ($postId <= 0) return null;
    $statement = bueno_database()->prepare('SELECT draft_json FROM article_draft_versions WHERE post_id=:post_id AND status="frozen" ORDER BY is_active DESC, id DESC LIMIT 1');
    $statement->execute([':post_id' => $postId]);
    $row = $statement->fetch();
    if (!is_array($row)) return null;
    try { $draft = json_decode((string) $row['draft_json'], true, 128, JSON_THROW_ON_ERROR); } catch (Throwable) { return null; }
    $plan = is_array($draft) ? (array) ($draft['illustration_plan'] ?? []) : [];
    $hero = (array) ($plan['hero'] ?? []);
    if (($hero['role'] ?? '') !== 'hero' || (string) ($hero['section_id'] ?? '') !== 'article') return null;
    $slotFromDraft = static function (array $image, string $role, string $slotId, string $anchor): ?array {
        $queries = array_values(array_filter(array_map(static fn (mixed $query): string => trim((string) $query), (array) ($image['search_queries'] ?? [])), static fn (string $query): bool => mb_strlen($query) >= 2));
        $need = trim(implode(' ', array_filter([(string) ($image['visual_intent'] ?? ''), (string) ($image['expected_content'] ?? ''), (string) ($image['alt'] ?? '')], static fn (string $value): bool => $value !== '')));
        if ($queries === [] || mb_strlen($need) < 10) return null;
        return ['slot_id'=>$slotId, 'role'=>$role, 'section_anchor'=>$anchor, 'topic_source'=>'A', 'visual_need'=>mb_substr($need, 0, 500),
            'must_be_direct'=>true, 'acceptable_related'=>false, 'search_queries_direct'=>array_slice($queries, 0, 6),
            'search_queries_related'=>[], 'required'=>true];
    };
    $heroSlot = $slotFromDraft($hero, 'hero', 'legacy-hero-article', 'article');
    if ($heroSlot === null) return null;
    $inlineSlots = [];
    foreach ((array) ($plan['inline'] ?? []) as $index => $image) {
        $image = (array) $image;
        $anchor = trim((string) ($image['section_id'] ?? ''));
        if (($image['role'] ?? '') !== 'inline' || $anchor === '') return null;
        $slot = $slotFromDraft($image, 'inline', 'legacy-inline-' . ($index + 1), $anchor);
        if ($slot === null) return null;
        $inlineSlots[] = $slot;
    }
    if (count($inlineSlots) > 4) return null;
    $output['visual_plan'] = ['hero_slot'=>$heroSlot, 'inline_slots'=>$inlineSlots];
    $output['visual_slots_planned'] = 1 + count($inlineSlots);
    return $output;
}

function narrative_plan_record_legacy_visual_adapter(array $operation, int $inlineCount): void
{
    $usage = json_decode((string) ($operation['usage_json'] ?? '{}'), true) ?: [];
    $usage['legacy_visual_plan_adapter'] = ['applied'=>true, 'source'=>'frozen_draft_illustration_plan', 'hero_direct_required'=>true, 'inline_slots'=>$inlineCount, 'at'=>gmdate('c')];
    bueno_database()->prepare('UPDATE generation_operations SET usage_json=:usage, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
        ->execute([':usage'=>generation_json($usage), ':id'=>(int) $operation['id']]);
}

/** Normalize only relaxed fields of a completed pre-P02 hero without inventing evidence. */
function narrative_plan_normalize_relaxed_completed_hero(array $output): ?array
{
    $visualPlan = (array) ($output['visual_plan'] ?? []);
    $hero = (array) ($visualPlan['hero_slot'] ?? []);
    $queries = array_values(array_filter(array_map(
        static fn (mixed $query): string => trim((string) $query),
        (array) ($hero['search_queries_direct'] ?? [])
    ), static fn (string $query): bool => mb_strlen($query) >= 2));
    if (trim((string) ($hero['slot_id'] ?? '')) === ''
        || mb_strlen(trim((string) ($hero['visual_need'] ?? ''))) < 10
        || $queries === []) {
        return null;
    }

    $hero['role'] = 'hero';
    $hero['required'] = true;
    $hero['must_be_direct'] = true;
    $hero['section_anchor'] = 'article';
    $hero['search_queries_direct'] = $queries;
    $visualPlan['hero_slot'] = $hero;
    $output['visual_plan'] = $visualPlan;
    return $output;
}

function narrative_plan_record_completed_hero_normalization(array $operation): void
{
    $usage = json_decode((string) ($operation['usage_json'] ?? '{}'), true) ?: [];
    $usage['completed_hero_contract_normalization'] = [
        'applied' => true,
        'source' => 'stored_completed_narrative_plan',
        'preserved_fields' => ['slot_id', 'visual_need', 'search_queries_direct'],
        'normalized_fields' => ['role', 'required', 'must_be_direct', 'section_anchor'],
        'at' => gmdate('c'),
    ];
    bueno_database()->prepare('UPDATE generation_operations SET usage_json=:usage, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
        ->execute([':usage'=>generation_json($usage), ':id'=>(int) $operation['id']]);
}

function complete_narrative_plan_operation(int $operationId, string $rawResponse, string $executionMode, array $providerMetadata = []): array
{
    $operation = find_generation_operation($operationId);
    if ($operation === null) {
        throw new RuntimeException('Nie znaleziono operacji planu narracyjnego.');
    }
    if ($operation['status'] === 'completed') {
        try {
            $output = json_decode((string) $operation['output_json'], true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($output)) {
                throw new RuntimeException('Output nie jest obiektem JSON.');
            }
            $schema = json_decode((string) $operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
            validate_generation_value($output, $schema);
            $curiosityNormalized = narrative_plan_normalize_curiosity_omission($output);
            $relatedQueryPolicyNormalized = narrative_plan_normalize_visual_related_query_policy($output);
            $sectionIdsNormalized = narrative_plan_normalize_section_ids($output);
            $visualCeilingNormalized = narrative_plan_normalize_visual_ceiling($output);
            $visualFloorResearchSlotIds = [];
            $visualFloorNormalized = narrative_plan_normalize_visual_floor($output, $operation, $visualFloorResearchSlotIds);
            if ($curiosityNormalized || $relatedQueryPolicyNormalized !== [] || $sectionIdsNormalized !== [] || $visualCeilingNormalized || $visualFloorNormalized !== []) {
                bueno_database()->prepare('UPDATE generation_operations SET output_json=:output,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                    ->execute([':output'=>generation_json($output), ':id'=>$operationId]);
            }
            if ($curiosityNormalized) narrative_plan_record_curiosity_omission_normalization($operation);
            if ($relatedQueryPolicyNormalized !== []) narrative_plan_record_visual_related_query_policy_normalization($operation, $relatedQueryPolicyNormalized);
            if ($visualCeilingNormalized || $visualFloorNormalized !== []) {
                $currentOperation = find_generation_operation($operationId);
                $usage = is_array($currentOperation) ? (json_decode((string) ($currentOperation['usage_json'] ?? '{}'), true) ?: []) : [];
                $usage['visual_floor_normalization'] = ['applied'=>true,'added_slot_ids'=>$visualFloorNormalized,
                    'research_query_slot_ids'=>$visualFloorResearchSlotIds,'at'=>gmdate('c')];
                bueno_database()->prepare('UPDATE generation_operations SET usage_json=:usage,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':usage'=>generation_json($usage),':id'=>$operationId]);
            }
            if ($sectionIdsNormalized !== []) {
                $currentOperation = find_generation_operation($operationId);
                $usage = is_array($currentOperation) ? (json_decode((string) ($currentOperation['usage_json'] ?? '{}'), true) ?: []) : [];
                $usage['section_id_normalization'] = ['applied'=>true,'mapping'=>$sectionIdsNormalized,'at'=>gmdate('c')];
                bueno_database()->prepare('UPDATE generation_operations SET usage_json=:usage,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':usage'=>generation_json($usage),':id'=>$operationId]);
            }
            if (validate_narrative_plan_output($operation, $output) === null) {
                $adapted = narrative_plan_adapt_legacy_visual_plan($operation, $output);
                if ($adapted !== null && validate_narrative_plan_output($operation, $adapted) !== null) {
                    $output = $adapted;
                    narrative_plan_record_legacy_visual_adapter($operation, count((array) ($output['visual_plan']['inline_slots'] ?? [])));
                } else {
                $normalized = narrative_plan_normalize_relaxed_completed_hero($output);
                if ($normalized !== null && validate_narrative_plan_output($operation, $normalized) !== null) {
                    $output = $normalized;
                    narrative_plan_record_completed_hero_normalization($operation);
                } else {
                    $adapted = narrative_plan_adapt_legacy_visual_plan($operation, $output);
                if ($adapted === null || validate_narrative_plan_output($operation, $adapted) === null) {
                    throw new RuntimeException('Plan nie przeszedł walidacji specjalnej i nie ma bezpiecznej zgodności legacy z zamrożonym szkicem.');
                }
                $output = $adapted;
                narrative_plan_record_legacy_visual_adapter($operation, count((array) ($output['visual_plan']['inline_slots'] ?? [])));
                }
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Ukończona operacja NarrativePlan ma nieprawidłowy zapisany output: ' . $exception->getMessage(), 0, $exception);
        }
        $planId = persist_narrative_plan($operationId, [...$output,
            'topic_id' => (int) ($operation['topic_id'] ?? 0),
            'post_id' => (int) ($operation['post_id'] ?? 0),
        ]);
        return [...$output, 'plan_id' => $planId];
    }

    $output = decode_generation_response($rawResponse);
    $schema = json_decode((string) $operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
    validate_generation_value($output, $schema);
    $curiosityNormalized = narrative_plan_normalize_curiosity_omission($output);
    $relatedQueryPolicyNormalized = narrative_plan_normalize_visual_related_query_policy($output);
    $sectionIdsNormalized = narrative_plan_normalize_section_ids($output);
    $visualCeilingNormalized = narrative_plan_normalize_visual_ceiling($output);
    $visualFloorResearchSlotIds = [];
    $visualFloorNormalized = narrative_plan_normalize_visual_floor($output, $operation, $visualFloorResearchSlotIds);

    $specialValidation = validate_narrative_plan_output($operation, $output);
    if ($specialValidation === null) {
        throw new RuntimeException('Plan narracyjny nie przeszedł walidacji.');
    }

    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'UPDATE generation_operations' .
            ' SET status = "completed", output_json = :output_json,' .
            ' provider_response_id = :provider_response_id,' .
            ' usage_json = :usage_json, error_message = "",' .
            ' completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP' .
            ' WHERE id = :id'
        );
        $statement->execute([
            ':output_json' => generation_json($output),
            ':provider_response_id' => mb_substr(trim((string) ($providerMetadata['response_id'] ?? '')), 0, 200),
            ':usage_json' => generation_json([
                ...(is_array($providerMetadata['usage'] ?? null) ? $providerMetadata['usage'] : []),
                ...($curiosityNormalized ? ['curiosity_omission_normalization'=>[
                    'applied'=>true,'reason'=>'selected_curiosity_topics_present','canonical_value'=>'','at'=>gmdate('c'),
                ]] : []),
                ...($relatedQueryPolicyNormalized !== [] ? ['visual_related_query_policy_normalization'=>[
                    'applied'=>true,'slot_ids'=>array_values($relatedQueryPolicyNormalized),'canonical_value'=>[],'at'=>gmdate('c'),
                ]] : []),
                ...($visualFloorNormalized !== [] ? ['visual_floor_normalization'=>[
                    'applied'=>true,'added_slot_ids'=>$visualFloorNormalized,
                    'research_query_slot_ids'=>$visualFloorResearchSlotIds,'at'=>gmdate('c'),
                ]] : []),
                ...($sectionIdsNormalized !== [] ? ['section_id_normalization'=>[
                    'applied'=>true,'mapping'=>$sectionIdsNormalized,'at'=>gmdate('c'),
                ]] : []),
            ]),
            ':id' => $operationId,
        ]);

        $input = json_decode((string) $operation['input_json'], true) ?: [];
        $outputWithMeta = [...$output, 'topic_id' => (int) ($operation['topic_id'] ?? 0), 'post_id' => (int) ($operation['post_id'] ?? 0)];
        $planId = persist_narrative_plan($operationId, $outputWithMeta);

        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return [...$output, 'plan_id' => $planId];
}

/**
 * End-to-end NarrativePlan generator.
 * Prepares the operation, sends it through the transport (Gemini), and persists the result.
 * Returns plan array with plan_id or throws on failure.
 */
function generate_narrative_plan(int $topicId, array $research, ?callable $transport = null): array
{
    $package = find_latest_approved_research_package_for_topic($topicId);
    if ($package === null) {
        throw new RuntimeException('Brak zatwierdzonej paczki researchowej dla tematu.');
    }

    $operationId = prepare_narrative_plan_operation($topicId, (int) $package['id']);
    execute_generation_operation($operationId, $transport);
    return complete_narrative_plan_operation(
        $operationId,
        '', // rawResponse is empty; complete reads from operation output_json
        generation_mode()
    );
}

/** Find the latest approved research package for a topic. */
function find_latest_approved_research_package_for_topic(int $topicId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM research_packages WHERE topic_id = :topic_id AND status = "approved"' .
        ' ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':topic_id' => $topicId]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}
