<?php

declare(strict_types=1);

function research_confidence_schema(): array
{
    return ['type' => 'string', 'enum' => ['high', 'medium', 'low']];
}

function research_evidence_schema(array $sourceIds): array
{
    return [
        'type' => 'object',
        'properties' => [
            'source_id' => ['type' => 'string', 'enum' => $sourceIds],
            'excerpt' => ['type' => 'string'],
        ],
        'required' => ['source_id', 'excerpt'],
        'additionalProperties' => false,
    ];
}

function research_package_schema(array $sourceIds): array
{
    $sourceIdSchema = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $sourceIds]];
    $sharedSourceIdSchema = [
        'type' => 'array',
        'items' => ['type' => 'string', 'enum' => $sourceIds],
        'minItems' => 2,
    ];
    $evidenceListSchema = ['type' => 'array', 'items' => research_evidence_schema($sourceIds)];
    $requiresMultipleSources = count(array_unique($sourceIds)) >= 2;
    $storySchema = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string', 'minLength' => 1],
            'title' => ['type' => 'string', 'minLength' => 5],
            'main_question' => ['type' => 'string', 'minLength' => 10],
            'why_now' => ['type' => 'string', 'minLength' => 10],
            'reader_value' => ['type' => 'string', 'minLength' => 10],
            'claim_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
            'visual_directions' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
        ],
        'required' => ['id', 'title', 'main_question', 'why_now', 'reader_value', 'claim_ids', 'visual_directions'],
        'additionalProperties' => false,
    ];
    $topicSchema = static function (string $hookField): array {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'minLength' => 1],
                'title' => ['type' => 'string', 'minLength' => 5],
                'connection_to_primary' => ['type' => 'string', 'minLength' => 10],
                $hookField => ['type' => 'string', 'minLength' => 10],
                'editorial_value' => ['type' => 'string', 'minLength' => 10],
                'claim_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                'visual_potential' => ['type' => 'string', 'minLength' => 5],
                'suggested_visual_queries' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
            ],
            'required' => ['id', 'title', 'connection_to_primary', $hookField, 'editorial_value', 'claim_ids', 'visual_potential', 'suggested_visual_queries'],
            'additionalProperties' => false,
        ];
    };

    return [
        'type' => 'object',
        'properties' => [
            'event_summary' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string'],
                    'source_ids' => $sourceIdSchema,
                ],
                'required' => ['text', 'source_ids'],
                'additionalProperties' => false,
            ],
            'claims' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'claim_id' => ['type' => 'string'],
                        'claim' => ['type' => 'string'],
                        'source_ids' => $sourceIdSchema,
                        'evidence' => $evidenceListSchema,
                        'confidence' => research_confidence_schema(),
                    ],
                    'required' => ['claim_id', 'claim', 'source_ids', 'evidence', 'confidence'],
                    'additionalProperties' => false,
                ],
            ],
            'primary_story' => $storySchema,
            'context_topics' => ['type' => 'array', 'items' => $topicSchema('reader_question_answered'), 'maxItems' => 6],
            'curiosity_topics' => ['type' => 'array', 'items' => $topicSchema('curiosity_hook'), 'maxItems' => 6],
            'source_claims' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
            'source_map' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'claim_id' => ['type' => 'string'],
                        'source_ids' => $sourceIdSchema,
                    ],
                    'required' => ['claim_id', 'source_ids'],
                    'additionalProperties' => false,
                ],
                'minItems' => 1,
            ],
            'shared_facts' => [
                'type' => 'array',
                ...($requiresMultipleSources ? [] : ['maxItems' => 0]),
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'fact' => ['type' => 'string'],
                        'source_ids' => $sharedSourceIdSchema,
                        'evidence' => $evidenceListSchema,
                        'confidence' => research_confidence_schema(),
                    ],
                    'required' => ['fact', 'source_ids', 'evidence', 'confidence'],
                    'additionalProperties' => false,
                ],
            ],
            'contradictions' => [
                'type' => 'array',
                ...($requiresMultipleSources ? [] : ['maxItems' => 0]),
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => 'string'],
                        'positions' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'source_id' => ['type' => 'string', 'enum' => $sourceIds],
                                    'statement' => ['type' => 'string'],
                                    'evidence' => ['type' => 'string'],
                                ],
                                'required' => ['source_id', 'statement', 'evidence'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'status' => ['type' => 'string', 'enum' => ['unresolved', 'partially_resolved']],
                    ],
                    'required' => ['description', 'positions', 'status'],
                    'additionalProperties' => false,
                ],
            ],
            'unknowns' => ['type' => 'array', 'items' => ['type' => 'string']],
            'polish_context' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'context' => ['type' => 'string'],
                        'basis_source_ids' => $sourceIdSchema,
                        'confidence' => research_confidence_schema(),
                    ],
                    'required' => ['context', 'basis_source_ids', 'confidence'],
                    'additionalProperties' => false,
                ],
            ],
            'comparisons' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'comparison' => ['type' => 'string'],
                        'basis_source_ids' => $sourceIdSchema,
                        'confidence' => research_confidence_schema(),
                    ],
                    'required' => ['comparison', 'basis_source_ids', 'confidence'],
                    'additionalProperties' => false,
                ],
            ],
            'recommendation' => [
                'type' => 'object',
                'properties' => [
                    'decision' => ['type' => 'string', 'enum' => ['continue', 'reject']],
                    'reason' => ['type' => 'string'],
                    'source_coverage' => ['type' => 'string', 'enum' => ['sufficient', 'insufficient']],
                ],
                'required' => ['decision', 'reason', 'source_coverage'],
                'additionalProperties' => false,
            ],
        ],
        'required' => [
            'event_summary',
            'claims',
            'primary_story',
            'context_topics',
            'curiosity_topics',
            'source_claims',
            'source_map',
            'shared_facts',
            'contradictions',
            'unknowns',
            'polish_context',
            'comparisons',
            'recommendation',
        ],
        'additionalProperties' => false,
    ];
}

function research_numbered_sources(int $topicId): array
{
    $sources = [];
    $verified = list_verified_research_sources($topicId);
    if ($verified !== []) {
        foreach ($verified as $index => $item) {
            $sources[] = [
                'source_id' => 'S' . ($index + 1),
                'verified_source_id' => (int) $item['id'],
                'publisher' => (string) $item['publisher'],
                'title' => trim((string) $item['title']),
                'url' => (string) $item['canonical_url'],
                'published_at' => $item['published_at'],
                'material' => trim((string) $item['content_excerpt']),
                'source_kind' => (string) $item['source_kind'],
                'is_primary' => (bool) $item['is_primary'],
                'peer_reviewed' => (bool) $item['is_peer_reviewed'],
                'identifier' => trim((string) $item['identifier_type'] . ':' . (string) $item['identifier_value'], ':'),
                'verification_method' => (string) $item['verification_method'],
                'completeness' => (string) $item['completeness'],
                'verification_evidence' => json_decode((string) $item['evidence_json'], true) ?: [],
            ];
        }
        return $sources;
    }
    foreach (topic_feed_items($topicId) as $index => $item) {
        $sources[] = [
            'source_id' => 'S' . ($index + 1),
            'feed_item_id' => (int) $item['id'],
            'publisher' => (string) $item['source_name'],
            'title' => trim((string) $item['title']),
            'url' => (string) $item['source_url'],
            'published_at' => $item['published_at'],
            'material' => trim((string) $item['summary']),
            'source_kind' => 'rss_discovery',
            'is_primary' => false,
            'peer_reviewed' => false,
            'verification_method' => 'unverified_feed_metadata',
            'completeness' => 'excerpt_only',
        ];
    }

    return $sources;
}

function prepare_research_package_operation(int $topicId): int
{
    $topic = find_editorial_topic($topicId);
    if ($topic === null || $topic['primary_post_status'] === 'rejected') {
        throw new RuntimeException('Research można przygotować wyłącznie dla aktywnego tematu.');
    }
    $sources = research_numbered_sources($topicId);
    if ($sources === []) {
        throw new RuntimeException('Temat nie zawiera materiału źródłowego.');
    }
    $sourceIds = array_column($sources, 'source_id');
    $policy = research_policy_for_topic(
        $topicId,
        (string) ($topic['risk_level'] ?? 'low'),
        !empty($topic['is_controversial'])
    );
    $input = [
        'topic_id' => $topicId,
        'topic_title' => (string) $topic['title'],
        'topic_score' => $topic['score'] !== null ? (int) $topic['score'] : null,
        'numbered_sources' => $sources,
        'research_policy' => $policy,
        'workflow_version' => 2,
        'instructions' => [
            'Używaj wyłącznie tytułów i materiałów przekazanych w numbered_sources.',
            'Każde twierdzenie musi wskazywać source_ids i zawierać krótki, dosłowny excerpt z odpowiedniego materiału.',
            'Excerpt przepisz znak w znak z pola title albo material tego samego numbered_sources.source_id. Nie parafrazuj cytatu, nie tłumacz go i nie dodawaj wielokropka.',
            'shared_facts zawiera wyłącznie fakty potwierdzone przez co najmniej dwa różne source_ids; przy jednym źródle zwróć pustą tablicę.',
            'contradictions porównuje co najmniej dwa różne źródła; przy jednym źródle zwróć pustą tablicę.',
            'Nie uzupełniaj wiedzy z pamięci ani z innych stron.',
            'Sprzeczności pozostaw jako unresolved lub partially_resolved; nie przedstawiaj ich jako pewników.',
            'Jeżeli materiał nie wystarcza do rzetelnego artykułu, ustaw recommendation.decision na reject.',
            'Polski kontekst i porównania dodawaj tylko wtedy, gdy mają podstawę w przekazanych źródłach; w przeciwnym razie zwróć puste tablice.',
            'Odkryj primary_story A oraz kandydatów context_topics B i curiosity_topics C. B/C nie są fillerem: muszą zwiększać wartość artykułu, być powiązane z A i wskazywać claim_ids z claims.',
            'source_claims zawiera identyfikatory wszystkich claims użytych przez A/B/C, a source_map jest listą rekordów {claim_id, source_ids[]} pokrywającą każdy z tych claims.',
            'Kandydat B/C bez source-backed claim_ids może zostać pominięty; nie wolno przedstawiać wiedzy spoza numbered_sources.',
            ...(($policy['material_scope'] ?? '') === 'feed_excerpt_only' ? [
                'Materiał ma zakres feed_excerpt_only: wolno używać wyłącznie dosłownego tytułu i opisu z feedu. Nie zakładaj treści pełnej strony.',
                'Pewność claims opartych wyłącznie na feedzie nie może przekroczyć medium; brakujące szczegóły zapisz w unknowns.',
            ] : []),
        ],
    ];
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $operationId = prepare_generation_operation(
            'research_package',
            $input,
            research_package_schema($sourceIds),
            (int) $topic['primary_post_id'],
            $topicId
        );
        $database->prepare(
            'INSERT INTO research_packages (
                topic_id, post_id, generation_operation_id, execution_mode
             ) VALUES (
                :topic_id, :post_id, :operation_id, :execution_mode
             )'
        )->execute([
            ':topic_id' => $topicId,
            ':post_id' => (int) $topic['primary_post_id'],
            ':operation_id' => $operationId,
            ':execution_mode' => generation_mode(),
        ]);
        $packageId = (int) $database->lastInsertId();
        $database->prepare('UPDATE research_packages SET policy_json = :policy WHERE id = :id')
            ->execute([':policy' => generation_json($policy), ':id' => $packageId]);
        $database->prepare('INSERT INTO research_policy_audit (topic_id,research_package_id,decision,reason,policy_json) VALUES (:topic,:package,:decision,:reason,:policy)')
            ->execute([':topic'=>$topicId, ':package'=>$packageId, ':decision'=>$policy['decision'], ':reason'=>$policy['reason'], ':policy'=>generation_json($policy)]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return $operationId;
}

function research_normalize_evidence(string $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
}

function research_assert_source_ids(array $sourceIds, array $knownSources, string $path, int $minimum = 1): void
{
    $sourceIds = array_values(array_unique($sourceIds));
    if (count($sourceIds) < $minimum) {
        throw new InvalidArgumentException("{$path} musi wskazywać co najmniej {$minimum} źródło/źródła.");
    }
    foreach ($sourceIds as $sourceId) {
        if (!is_string($sourceId) || !isset($knownSources[$sourceId])) {
            throw new InvalidArgumentException("{$path} zawiera nieznany identyfikator źródła.");
        }
    }
}

function research_assert_evidence(array $evidence, array $knownSources, array $requiredSourceIds, string $path): void
{
    $covered = [];
    foreach ($evidence as $index => $entry) {
        $sourceId = (string) ($entry['source_id'] ?? '');
        if (!isset($knownSources[$sourceId])) {
            throw new InvalidArgumentException("{$path}[{$index}] wskazuje nieznane źródło.");
        }
        $excerpt = research_normalize_evidence((string) ($entry['excerpt'] ?? ''));
        if (mb_strlen($excerpt) < 8) {
            throw new InvalidArgumentException("{$path}[{$index}] zawiera zbyt krótki dowód.");
        }
        $haystack = research_normalize_evidence(
            $knownSources[$sourceId]['title'] . ' ' . $knownSources[$sourceId]['material']
        );
        if (!str_contains($haystack, $excerpt)) {
            throw new InvalidArgumentException("{$path}[{$index}] nie jest dosłownym fragmentem źródła {$sourceId}.");
        }
        $covered[$sourceId] = true;
    }
    foreach ($requiredSourceIds as $sourceId) {
        if (!isset($covered[$sourceId])) {
            throw new InvalidArgumentException("{$path} nie zawiera dowodu dla źródła {$sourceId}.");
        }
    }
}

function validate_research_package(array $package, array $input): array
{
    $knownSources = [];
    foreach ((array) ($input['numbered_sources'] ?? []) as $source) {
        if (is_array($source) && is_string($source['source_id'] ?? null)) {
            $knownSources[$source['source_id']] = $source;
        }
    }
    if ($knownSources === []) {
        throw new RuntimeException('Operacja researchowa nie zawiera źródeł wejściowych.');
    }
    if (trim((string) ($package['event_summary']['text'] ?? '')) === '') {
        throw new InvalidArgumentException('Podsumowanie wydarzenia nie może być puste.');
    }

    research_assert_source_ids(
        (array) ($package['event_summary']['source_ids'] ?? []),
        $knownSources,
        '$.event_summary.source_ids'
    );
    $claimIds = [];
    $citedSources = [];
    foreach ((array) ($package['claims'] ?? []) as $index => $claim) {
        $claimId = trim((string) ($claim['claim_id'] ?? ''));
        if ($claimId === '' || isset($claimIds[$claimId])) {
            throw new InvalidArgumentException('Każde twierdzenie musi mieć unikalny claim_id.');
        }
        if (trim((string) ($claim['claim'] ?? '')) === '') {
            throw new InvalidArgumentException("$.claims[{$index}].claim nie może być pusty.");
        }
        $claimIds[$claimId] = true;
        $sourceIds = (array) ($claim['source_ids'] ?? []);
        research_assert_source_ids($sourceIds, $knownSources, "$.claims[{$index}].source_ids");
        research_assert_evidence(
            (array) ($claim['evidence'] ?? []),
            $knownSources,
            $sourceIds,
            "$.claims[{$index}].evidence"
        );
        if (($input['research_policy']['confidence_cap'] ?? '') === 'medium'
            && (string) ($claim['confidence'] ?? '') === 'high') {
            throw new InvalidArgumentException("$.claims[{$index}].confidence przekracza limit medium dla materiału feedowego.");
        }
        foreach ($sourceIds as $sourceId) {
            $citedSources[$sourceId] = true;
        }
    }
    $assertTopicClaims = static function (array $topic, string $path) use (&$claimIds): void {
        $ids = array_values(array_unique(array_map('strval', (array) ($topic['claim_ids'] ?? []))));
        if ($ids === [] || array_diff($ids, array_keys($claimIds)) !== []) {
            throw new InvalidArgumentException("{$path}.claim_ids muszą wskazywać zatwierdzone claims.");
        }
    };
    $assertTopicClaims((array) ($package['primary_story'] ?? []), '$.primary_story');
    foreach (['context_topics', 'curiosity_topics'] as $topicType) {
        $topicIds = [];
        foreach ((array) ($package[$topicType] ?? []) as $index => $topic) {
            $topicId = trim((string) ($topic['id'] ?? ''));
            if ($topicId === '' || isset($topicIds[$topicId])) throw new InvalidArgumentException("$.{$topicType}[{$index}].id musi być unikalne.");
            $topicIds[$topicId] = true;
            $assertTopicClaims((array) $topic, "$.{$topicType}[{$index}]");
        }
    }
    $sourceClaims = array_values(array_unique(array_map('strval', (array) ($package['source_claims'] ?? []))));
    if ($sourceClaims === [] || array_diff($sourceClaims, array_keys($claimIds)) !== []) {
        throw new InvalidArgumentException('$.source_claims musi wskazywać zatwierdzone claims.');
    }
    $sourceMap = [];
    foreach ((array) ($package['source_map'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        if (array_key_exists('claim_id', $entry)) {
            $claimId = trim((string) ($entry['claim_id'] ?? ''));
            if ($claimId === '' || isset($sourceMap[$claimId])) {
                throw new InvalidArgumentException('$.source_map zawiera pusty lub zduplikowany claim_id.');
            }
            $sourceMap[$claimId] = (array) ($entry['source_ids'] ?? []);
            continue;
        }
        foreach ($entry as $claimId => $sourceIds) $sourceMap[(string) $claimId] = (array) $sourceIds;
    }
    foreach ($sourceClaims as $claimId) {
        if (!isset($sourceMap[$claimId]) || array_diff((array) $sourceMap[$claimId], array_keys($knownSources)) !== []) {
            throw new InvalidArgumentException('$.source_map nie pokrywa claim ' . $claimId . '.');
        }
    }
    foreach ((array) ($package['shared_facts'] ?? []) as $index => $fact) {
        if (trim((string) ($fact['fact'] ?? '')) === '') {
            throw new InvalidArgumentException("$.shared_facts[{$index}].fact nie może być pusty.");
        }
        $sourceIds = (array) ($fact['source_ids'] ?? []);
        research_assert_source_ids($sourceIds, $knownSources, "$.shared_facts[{$index}].source_ids", 2);
        research_assert_evidence(
            (array) ($fact['evidence'] ?? []),
            $knownSources,
            $sourceIds,
            "$.shared_facts[{$index}].evidence"
        );
    }
    foreach ((array) ($package['contradictions'] ?? []) as $index => $contradiction) {
        if (trim((string) ($contradiction['description'] ?? '')) === '') {
            throw new InvalidArgumentException("$.contradictions[{$index}].description nie może być pusty.");
        }
        $positions = (array) ($contradiction['positions'] ?? []);
        if (count($positions) < 2) {
            throw new InvalidArgumentException("$.contradictions[{$index}] musi zawierać co najmniej dwie pozycje.");
        }
        $positionSources = [];
        foreach ($positions as $positionIndex => $position) {
            $sourceId = (string) ($position['source_id'] ?? '');
            research_assert_source_ids([$sourceId], $knownSources, "$.contradictions[{$index}].positions");
            $positionSources[$sourceId] = true;
            research_assert_evidence(
                [['source_id' => $sourceId, 'excerpt' => (string) ($position['evidence'] ?? '')]],
                $knownSources,
                [$sourceId],
                "$.contradictions[{$index}].positions[{$positionIndex}].evidence"
            );
        }
        if (count($positionSources) < 2) {
            throw new InvalidArgumentException("$.contradictions[{$index}] nie porównuje różnych źródeł.");
        }
    }
    foreach (['polish_context', 'comparisons'] as $section) {
        foreach ((array) ($package[$section] ?? []) as $index => $entry) {
            $textKey = $section === 'polish_context' ? 'context' : 'comparison';
            if (trim((string) ($entry[$textKey] ?? '')) === '') {
                throw new InvalidArgumentException("$.{$section}[{$index}].{$textKey} nie może być pusty.");
            }
            research_assert_source_ids(
                (array) ($entry['basis_source_ids'] ?? []),
                $knownSources,
                "$.{$section}[{$index}].basis_source_ids"
            );
        }
    }
    $decision = (string) ($package['recommendation']['decision'] ?? '');
    $coverage = (string) ($package['recommendation']['source_coverage'] ?? '');
    if (trim((string) ($package['recommendation']['reason'] ?? '')) === '') {
        throw new InvalidArgumentException('Rekomendacja musi zawierać uzasadnienie.');
    }
    foreach ((array) ($package['unknowns'] ?? []) as $index => $unknown) {
        if (trim((string) $unknown) === '') {
            throw new InvalidArgumentException("$.unknowns[{$index}] nie może być pusty.");
        }
    }
    if ($decision === 'continue' && ((array) ($package['claims'] ?? [])) === []) {
        throw new InvalidArgumentException('Rekomendacja continue wymaga co najmniej jednego udokumentowanego twierdzenia.');
    }
    if ($coverage === 'insufficient' && $decision !== 'reject') {
        throw new InvalidArgumentException('Niewystarczające źródła muszą prowadzić do rekomendacji reject.');
    }

    return [
        'valid' => true,
        'known_source_count' => count($knownSources),
        'cited_source_count' => count($citedSources),
        'claim_count' => count((array) ($package['claims'] ?? [])),
        'shared_fact_count' => count((array) ($package['shared_facts'] ?? [])),
        'contradiction_count' => count((array) ($package['contradictions'] ?? [])),
        'decision' => $decision,
        'policy' => (array) ($input['research_policy'] ?? []),
    ];
}

function validate_research_operation_output(array $operation, array $output): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);

    return validate_research_package($output, $input);
}

/** Canonical provider-safe source map; safely derivable from already returned claims. */
function research_normalize_source_map(array &$output): ?array
{
    $original = $output['source_map'] ?? null;
    $normalized = [];
    foreach ((array) $original as $key => $entry) {
        if (is_array($entry) && array_key_exists('claim_id', $entry)) {
            $claimId = trim((string) ($entry['claim_id'] ?? ''));
            $sourceIds = array_values(array_unique(array_filter(array_map('strval', (array) ($entry['source_ids'] ?? [])))));
        } else {
            $claimId = is_string($key) ? trim($key) : '';
            $sourceIds = array_values(array_unique(array_filter(array_map('strval', (array) $entry))));
        }
        if ($claimId !== '' && $sourceIds !== []) $normalized[$claimId] = ['claim_id'=>$claimId, 'source_ids'=>$sourceIds];
    }
    foreach ((array) ($output['claims'] ?? []) as $claim) {
        $claimId = trim((string) ($claim['claim_id'] ?? ''));
        $sourceIds = array_values(array_unique(array_filter(array_map('strval', (array) ($claim['source_ids'] ?? [])))));
        if ($claimId !== '' && $sourceIds !== [] && !isset($normalized[$claimId])) {
            $normalized[$claimId] = ['claim_id'=>$claimId, 'source_ids'=>$sourceIds];
        }
    }
    $value = array_values($normalized);
    $output['source_map'] = $value;
    return $original === $value ? null : ['field'=>'source_map', 'strategy'=>'derived_from_claim_source_ids'];
}

function research_mock_generation_value(array $operation): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $sources = (array) ($input['numbered_sources'] ?? []);
    $sourceId = (string) ($sources[0]['source_id'] ?? '');
    $sourceTitle = trim((string) ($sources[0]['title'] ?? ''));
    if ($sourceId === '') {
        throw new RuntimeException('Lokalna atrapa researchu nie otrzymała źródła.');
    }
    if ($sourceTitle === '') {
        throw new RuntimeException('Lokalna atrapa researchu nie otrzymała tytułu źródła.');
    }

    return [
        'event_summary' => [
            'text' => 'Lokalna atrapa potwierdza techniczny przepływ dla materiału: ' . $sourceTitle,
            'source_ids' => [$sourceId],
        ],
        'claims' => [[
            'claim_id' => 'C1',
            'claim' => $sourceTitle,
            'source_ids' => [$sourceId],
            'evidence' => [[
                'source_id' => $sourceId,
                'excerpt' => $sourceTitle,
            ]],
            'confidence' => ($input['research_policy']['confidence_cap'] ?? '') === 'medium' ? 'medium' : 'high',
        ]],
        'primary_story' => ['id'=>'A','title'=>$sourceTitle,'main_question'=>'Co dokładnie wydarzyło się w opisanym materiale?','why_now'=>'Materiał źródłowy opisuje aktualne wydarzenie.','reader_value'=>'Czytelnik otrzymuje wyjaśnienie znaczenia wydarzenia.','claim_ids'=>['C1'],'visual_directions'=>['bezpośrednia fotografia głównego tematu']],
        'context_topics' => [],
        'curiosity_topics' => [],
        'source_claims' => ['C1'],
        'source_map' => [['claim_id'=>'C1','source_ids'=>[$sourceId]]],
        'shared_facts' => [],
        'contradictions' => [],
        'unknowns' => ['Atrapa nie ocenia prawdziwości ani kompletności materiału.'],
        'polish_context' => [],
        'comparisons' => [],
        'recommendation' => [
            'decision' => 'continue',
            'reason' => 'Kontynuacja służy wyłącznie testowi technicznemu pełnego pipeline’u.',
            'source_coverage' => 'sufficient',
        ],
    ];
}

function persist_completed_research_package(int $operationId, array $package, array $validation): void
{
    $statement = bueno_database()->prepare(
        'UPDATE research_packages
         SET status = "completed", package_json = :package_json,
             validation_json = :validation_json,
             completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([
        ':package_json' => generation_json($package),
        ':validation_json' => generation_json($validation),
        ':operation_id' => $operationId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Nie znaleziono rekordu paczki researchowej.');
    }
}

function mark_research_package_failed(int $operationId, string $errorMessage): void
{
    bueno_database()->prepare(
        'UPDATE research_packages
         SET status = "failed",
             validation_json = :validation_json,
             updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    )->execute([
        ':validation_json' => generation_json([
            'valid' => false,
            'error' => mb_substr($errorMessage, 0, 2000),
        ]),
        ':operation_id' => $operationId,
    ]);
}

function find_research_package_by_operation(int $operationId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM research_packages WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([':operation_id' => $operationId]);
    $package = $statement->fetch();

    return is_array($package) ? $package : null;
}

function list_research_packages(int $limit = 100): array
{
    $statement = bueno_database()->prepare(
        'SELECT packages.*, topics.title AS topic_title
         FROM research_packages AS packages
         INNER JOIN editorial_topics AS topics ON topics.id = packages.topic_id
         ORDER BY packages.id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}
