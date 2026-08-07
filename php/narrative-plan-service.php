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

const NARRATIVE_PLAN_TARGET_LENGTH_MIN = 2500;

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
        'output_language' => [
            'code' => 'pl-PL',
            'name' => 'język polski',
            'rule' => 'Cała treść planu narracyjnego musi być napisana naturalnym językiem polskim.',
        ],
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
    ];

    $schema = narrative_plan_schema($sourceIds, $claimIds);

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
    $sectionSchema = [
        'type' => 'object',
        'properties' => [
            'section_id' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['lead', 'body', 'analysis', 'comparison', 'scene', 'facts', 'takeaway', 'ending']],
            'topic_ref' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'content_brief' => ['type' => 'string'],
            'visual_slot_required' => ['type' => 'boolean'],
            'estimated_length' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 3000],
        ],
        'required' => ['section_id', 'type', 'topic_ref', 'content_brief', 'visual_slot_required', 'estimated_length'],
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

    return [
        'type' => 'object',
        'properties' => [
            'promise_to_reader' => ['type' => 'string', 'minLength' => 30, 'maxLength' => 300],
            'main_thesis' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 500],
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
            'visual_slots_planned' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
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
        ],
        'required' => [
            'promise_to_reader',
            'main_thesis',
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
        ],
        'additionalProperties' => false,
    ];
}

function persist_narrative_plan(int $operationId, array $plan): int
{
    $database = bueno_database();
    $topicId = (int) $plan['topic_id'] ?? 0;
    $postId = (int) $plan['post_id'] ?? 0;

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

    $database->prepare(
        'INSERT INTO narrative_plans (' .
            'article_id, promise_to_reader, main_thesis, narrative_arc, arc_justification,' .
            'sections_json, transitions_json, rhythm_notes, visual_slots_planned,' .
            'hero_topic_ref, ending_type, supplemental_topics_json, target_length,' .
            'status, batch_stage_ref, created_at, updated_at' .
        ') VALUES (' .
            ':article_id, :promise, :thesis, :arc, :justification,' .
            ':sections, :transitions, :rhythm, :visual_slots,' .
            ':hero_ref, :ending, :supplemental, :target_length,' .
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
        ':visual_slots' => max(1, min(5, (int) ($plan['visual_slots_planned'] ?? 1))),
        ':hero_ref' => 'A',
        ':ending' => mb_substr((string) ($plan['ending_type'] ?? ''), 0, 50),
        ':supplemental' => $supplementalJson,
        ':target_length' => max(NARRATIVE_PLAN_TARGET_LENGTH_MIN, min(ARTICLE_MAIN_CONTENT_MAX_LENGTH, (int) ($plan['target_length'] ?? 4000))),
        ':status' => 'generated',
        ':batch_stage' => null,
    ]);

    return (int) $database->lastInsertId();
}

function find_narrative_plan_for_topic(int $topicId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM narrative_plans WHERE article_id = :id AND status IN ("generated", "accepted", "frozen")' .
        ' ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':id' => $topicId]);
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

/** Build draft schema from a NarrativePlan. Falls back to the legacy 7-section schema when no plan exists. */
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

    $sections = json_decode((string) ($narrativePlan['sections_json'] ?? '[]'), true) ?: [];
    $transitions = json_decode((string) ($narrativePlan['transitions_json'] ?? '[]'), true) ?: [];
    $visualSlots = max(1, min(5, (int) ($narrativePlan['visual_slots_planned'] ?? 1)));
    $targetLength = max(NARRATIVE_PLAN_TARGET_LENGTH_MIN, min(ARTICLE_MAIN_CONTENT_MAX_LENGTH, (int) ($narrativePlan['target_length'] ?? 4000)));

    if ($sections === []) {
        return article_draft_schema($sourceIds, $claimIds, $compositionMode, $unknownIds);
    }

    $section = article_draft_reference_schema($sourceIds, $claimIds);
    $requiredSection = $section;
    $requiredSection['properties']['text']['minLength'] = 1;
    $requiredSection['properties']['text']['maxLength'] = 10000;

    $narrativeProperties = [];
    foreach ($sections as $idx => $sec) {
        $secId = (string) ($sec['section_id'] ?? "section_{$idx}");
        $narrativeProperties[$secId] = [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'minLength' => 50, 'maxLength' => 10000],
                'heading' => ['type' => 'string'],
                'topic_ref' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
                'claim_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $claimIds],
                ],
                'source_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $sourceIds],
                ],
            ],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }

    $inlineIllustrations = min($visualSlots - 1, count($sections));
    $alwaysAvailableSections = array_slice(
        ARTICLE_IMAGE_ALWAYS_AVAILABLE_SECTION_IDS ?? ['lead', 'why-important', 'fact-1', 'takeaway'],
        0,
        max(1, $inlineIllustrations)
    );

    return [
        'type' => 'object',
        'properties' => [
            'composition_mode' => ['type' => 'string', 'enum' => [$compositionMode]],
            'title' => ['type' => 'string'],
            'title_variants' => [
                'type' => 'array',
                'items' => article_title_variant_schema(),
                'minItems' => 5,
                'maxItems' => 8,
            ],
            'title_selection_reason' => ['type' => 'string'],
            'brief' => ['type' => 'string', 'minLength' => 80, 'maxLength' => 220],
            'lead' => $requiredSection,
            'narrative_plan_ref' => ['type' => 'integer'],
            'narrative_arc_used' => ['type' => 'string'],
            'sections' => [
                'type' => 'object',
                'properties' => $narrativeProperties,
                'required' => array_keys($narrativeProperties),
                'additionalProperties' => false,
            ],
            'transitions_applied' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
            'unknowns' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'research_unknown_indexes' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'integer',
                                ...($unknownIds === [] ? [] : ['enum' => array_values($unknownIds)]),
                            ],
                        ],
                    ],
                    'required' => ['text', 'research_unknown_indexes'],
                    'additionalProperties' => false,
                ],
            ],
            'practical_takeaway' => $requiredSection,
            'seo_description' => ['type' => 'string', 'minLength' => 70, 'maxLength' => 160],
            'category' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
            'image_alt' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 250],
            'illustration_plan' => article_illustration_plan_schema(
                max(1, $inlineIllustrations),
                $alwaysAvailableSections
            ),
            'used_source_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $sourceIds],
            ],
        ],
        'required' => [
            'composition_mode',
            'title',
            'title_variants',
            'title_selection_reason',
            'brief',
            'lead',
            'narrative_plan_ref',
            'narrative_arc_used',
            'sections',
            'transitions_applied',
            'unknowns',
            'practical_takeaway',
            'seo_description',
            'category',
            'image_alt',
            'illustration_plan',
            'used_source_ids',
        ],
        'additionalProperties' => false,
    ];
}

/** Validate a completed narrative plan output. Returns validation array or null on failure. */
function validate_narrative_plan_output(array $operation, array $output): ?array
{
    $arc = (string) ($output['narrative_arc'] ?? '');
    if (!in_array($arc, NARRATIVE_ARC_TYPES, true)) {
        return null;
    }

    $sections = (array) ($output['sections'] ?? []);
    if (count($sections) < 3 || count($sections) > 12) {
        return null;
    }

    $visualSlots = (int) ($output['visual_slots_planned'] ?? 0);
    if ($visualSlots < 1 || $visualSlots > 5) {
        return null;
    }

    $targetLength = (int) ($output['target_length'] ?? 0);
    if ($targetLength < NARRATIVE_PLAN_TARGET_LENGTH_MIN || $targetLength > ARTICLE_MAIN_CONTENT_MAX_LENGTH) {
        return null;
    }

    $hasLead = false;
    foreach ($sections as $sec) {
        if ((string) ($sec['type'] ?? '') === 'lead') {
            $hasLead = true;
        }
        if ((string) ($sec['topic_ref'] ?? '') !== '' && !in_array((string) $sec['topic_ref'], ['A', 'B', 'C'], true)) {
            return null;
        }
    }

    if (!$hasLead) {
        return null;
    }

    return [
        'valid' => true,
        'narrative_arc' => $arc,
        'section_count' => count($sections),
        'visual_slots_planned' => $visualSlots,
        'target_length' => $targetLength,
    ];
}

function complete_narrative_plan_operation(int $operationId, string $rawResponse, string $executionMode, array $providerMetadata = []): array
{
    $operation = find_generation_operation($operationId);
    if ($operation === null) {
        throw new RuntimeException('Nie znaleziono operacji planu narracyjnego.');
    }
    if ($operation['status'] === 'completed') {
        return json_decode((string) $operation['output_json'], true, 128, JSON_THROW_ON_ERROR);
    }

    $output = decode_generation_response($rawResponse);
    $schema = json_decode((string) $operation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
    validate_generation_value($output, $schema);

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
            ':usage_json' => generation_json(is_array($providerMetadata['usage'] ?? null) ? $providerMetadata['usage'] : []),
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
function generate_narrative_plan(int $topicId, array $research, callable $transport): array
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
