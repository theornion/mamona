<?php

declare(strict_types=1);

const QUALITY_PASS_SCORE = 75;
const QUALITY_SCORE_TOTAL = 100;
const QUALITY_CHECK_INPUT_CONTRACT_VERSION = 3;

/** Hard gates block every further step. */
const QC_HARD_GATES = [
    'char_count_range',
    'gemini_budget_limit',
    'max_5_images',
    'required_slots_filled',
    'assets_exist',
    'rights_license_ok',
    'metadata_consistent',
    'publication_safe',
    'no_fallback_images',
];

/** Soft gates generate feedback only. */
const QC_SOFT_GATES = [
    'narrative_coherence',
    'transitions_smooth',
    'no_redundancy',
    'rhythm_varied',
    'engagement_level',
    'not_monotonic_matrix',
];

function quality_score_rubric(): array
{
    $rubric = [
        'fact_source_alignment' => 25, 'completeness' => 10, 'primary_source' => 10,
        'original_value' => 10, 'originality' => 10, 'title_quality' => 10,
        'language_readability' => 10, 'seo' => 10, 'risk_handling' => 5,
    ];
    if (array_sum($rubric) !== QUALITY_SCORE_TOTAL) {
        throw new LogicException('Rubryka kontroli jakości musi sumować się do 100 punktów.');
    }
    return $rubric;
}

function quality_score_instructions(): array
{
    $ranges = [];
    foreach (quality_score_rubric() as $name => $maximum) $ranges[] = "{$name}: 0–{$maximum}";
    return [
        'Każde z dziewięciu pól scores jest liczbą całkowitą w dokładnym zakresie: ' . implode('; ', $ranges) . '.',
        'risk_handling ma zakres 0–5, nie 0–10.',
        'total_score musi być dokładną sumą dziewięciu pól scores (0–100).',
        'Nie normalizuj kategorii do wspólnej skali 0–10.',
    ];
}

function quality_check_schema(): array
{
    $scores = quality_score_rubric();
    $scoreProperties = [];
    foreach ($scores as $name => $maximum) {
        $scoreProperties[$name] = ['type' => 'integer', 'minimum' => 0, 'maximum' => $maximum];
    }
    $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

    return [
        'type' => 'object',
        'properties' => [
            'scores' => [
                'type' => 'object',
                'properties' => $scoreProperties,
                'required' => array_keys($scoreProperties),
                'additionalProperties' => false,
            ],
            'total_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => QUALITY_SCORE_TOTAL],
            'title_supported' => ['type' => 'boolean'],
            'has_primary_source' => ['type' => 'boolean'],
            'unsupported_claims' => $stringList,
            'false_quotes' => $stringList,
            'unsupported_tests' => $stringList,
            'clickbait_phrases' => $stringList,
            'similarity' => [
                'type' => 'object',
                'properties' => [
                    'level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    'explanation' => ['type' => 'string'],
                ],
                'required' => ['level', 'explanation'],
                'additionalProperties' => false,
            ],
            'risk_flags' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['legal', 'financial', 'medical', 'security']],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['type', 'description'],
                    'additionalProperties' => false,
                ],
            ],
            'missing_elements' => $stringList,
            'language_issues' => $stringList,
            'original_value' => ['type' => 'string'],
            'justification' => ['type' => 'string'],
            'recommendation' => ['type' => 'string', 'enum' => ['pass', 'revise', 'block']],
        ],
        'required' => [
            'scores',
            'total_score',
            'title_supported',
            'has_primary_source',
            'unsupported_claims',
            'false_quotes',
            'unsupported_tests',
            'clickbait_phrases',
            'similarity',
            'risk_flags',
            'missing_elements',
            'language_issues',
            'original_value',
            'justification',
            'recommendation',
        ],
        'additionalProperties' => false,
    ];
}

function find_quality_draft_context(int $draftVersionId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT drafts.*, packages.package_json AS research_json,
                research_operations.input_json AS research_input_json
         FROM article_draft_versions AS drafts
         INNER JOIN research_packages AS packages ON packages.id = drafts.research_package_id
         INNER JOIN generation_operations AS research_operations
            ON research_operations.id = packages.generation_operation_id
         WHERE drafts.id = :id'
    );
    $statement->execute([':id' => $draftVersionId]);
    $draft = $statement->fetch();

    return is_array($draft) ? $draft : null;
}

function list_completed_article_drafts(int $limit = 500): array
{
    $statement = bueno_database()->prepare(
        'SELECT drafts.*, topics.title AS topic_title,
                (SELECT COUNT(*) FROM quality_check_runs AS checks
                 WHERE checks.draft_version_id = drafts.id) AS check_count
         FROM article_draft_versions AS drafts
         INNER JOIN editorial_topics AS topics ON topics.id = drafts.topic_id
         WHERE drafts.status = "completed"
         ORDER BY drafts.completed_at DESC, drafts.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function prepare_quality_check_operation(int $draftVersionId): int
{
    $context = find_quality_draft_context($draftVersionId);
    if ($context === null || !in_array((string)$context['status'], ['completed', 'frozen'], true)) {
        throw new RuntimeException('Kontrolę jakości można uruchomić wyłącznie dla ukończonego szkicu.');
    }
    $draft = json_decode((string) $context['draft_json'], true, 128, JSON_THROW_ON_ERROR);
    $research = json_decode((string) $context['research_json'], true, 128, JSON_THROW_ON_ERROR);
    $researchInput = json_decode((string) $context['research_input_json'], true, 128, JSON_THROW_ON_ERROR);
    $postSources = array_map(
        static fn (array $source): array => [
            'url' => (string) $source['source_url'],
            'title' => (string) $source['source_title'],
            'publisher' => (string) $source['publisher_name'],
            'is_primary' => (int) $source['is_primary'] === 1,
        ],
        list_post_sources((int) $context['post_id'])
    );
    $input = [
        'input_contract_version' => QUALITY_CHECK_INPUT_CONTRACT_VERSION,
        'draft_version_id' => $draftVersionId,
        'draft' => $draft,
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'registered_post_sources' => $postSources,
        'workflow_version' => 2,
        'editorial_research' => array_intersect_key($research, array_flip(['primary_story','context_topics','curiosity_topics','source_claims','source_map'])),
        'instructions' => [
            'Oceń wyłącznie przekazany szkic względem paczki researchowej i źródeł.',
            ...quality_score_instructions(),
            'Zgłoś każdy fakt bez podstawy, fałszywy cytat, deklarowany test bez dowodu, clickbait i ryzyko.',
            'Wysokie podobieństwo oznacza kopiowanie lub bardzo bliską parafrazę cudzej publikacji.',
            'Sprawdź, czy długość treści głównej jest zgodna z polityką szkicu oraz czy tekst nie osiąga jej przez lanie wody, powtórzenia lub sztuczne rozwlekanie.',
            '5000 znaków jest twardą dolną granicą, nie celem; preferuj 6000–8500 i nie przekraczaj 10000 znaków.',
            'Sprawdź, czy A pozostaje głównym tematem, B faktycznie pomaga zrozumieć A, a C jest powiązane i wnosi wartość zamiast filleru.',
            'Sprawdź naturalność przejść A–B–C, source grounding wszystkich claims oraz twardy zakres 5000–10000 znaków bez powtórzeń.',
            'Nie ukrywaj problemu tylko po to, aby wynik przekroczył próg.',
        ],
    ];
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $numberStatement = $database->prepare(
            'SELECT COALESCE(MAX(check_number), 0) + 1
             FROM quality_check_runs WHERE draft_version_id = :draft_id'
        );
        $numberStatement->execute([':draft_id' => $draftVersionId]);
        $checkNumber = (int) $numberStatement->fetchColumn();
        $operationId = prepare_generation_operation(
            'quality_check',
            $input,
            quality_check_schema(),
            (int) $context['post_id'],
            (int) $context['topic_id']
        );
        $database->prepare(
            'INSERT INTO quality_check_runs (
                draft_version_id, post_id, generation_operation_id,
                check_number, execution_mode
             ) VALUES (
                :draft_id, :post_id, :operation_id, :check_number, :execution_mode
             )'
        )->execute([
            ':draft_id' => $draftVersionId,
            ':post_id' => (int) $context['post_id'],
            ':operation_id' => $operationId,
            ':check_number' => $checkNumber,
            ':execution_mode' => generation_mode(),
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return $operationId;
}

function quality_texts_from_draft(array $draft): array
{
    $texts = [];
    $collect = static function (mixed $value) use (&$collect, &$texts): void {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if (is_string($child) && in_array($key, ['title', 'text', 'seo_description', 'image_alt'], true)) {
                $text = trim(strip_tags($child));
                if ($text !== '') {
                    $texts[] = $text;
                }
            } elseif (is_array($child)) {
                $collect($child);
            }
        }
    };
    $collect($draft);

    return array_values(array_unique($texts));
}

function quality_tokens(string $text): array
{
    $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = ['oraz', 'który', 'która', 'które', 'jest', 'tego', 'dla', 'przez', 'jako', 'się', 'nie', 'ale', 'czy', 'ten', 'the', 'and', 'with', 'from'];

    return array_values(array_filter(
        array_map(static fn (string $part): string => mb_substr($part, 0, min(6, mb_strlen($part))), $parts),
        static fn (string $part): bool => mb_strlen($part) >= 3 && !in_array($part, $stop, true)
    ));
}

function quality_has_shared_phrase(string $first, string $second, int $length = 12): bool
{
    $a = quality_tokens($first);
    $b = quality_tokens($second);
    if (count($a) < $length || count($b) < $length) {
        return false;
    }
    $phrases = [];
    for ($index = 0; $index <= count($a) - $length; $index++) {
        $phrases[implode(' ', array_slice($a, $index, $length))] = true;
    }
    for ($index = 0; $index <= count($b) - $length; $index++) {
        if (isset($phrases[implode(' ', array_slice($b, $index, $length))])) {
            return true;
        }
    }

    return false;
}

/**
 * A translated Polish title cannot be compared lexically with an English
 * research package. It is grounded only by body text that cites valid source
 * evidence registered both in the section and in the draft source list.
 */
function quality_title_has_cited_section_support(array $draft, array $sources): bool
{
    $titleTokens = article_title_normalized_tokens((string) ($draft['title'] ?? ''));
    if ($titleTokens === []) {
        return false;
    }
    $usedSourceIds = array_fill_keys(array_map('strval', (array) ($draft['used_source_ids'] ?? [])), true);
    $validSourceIds = [];
    foreach ($sources as $sourceId => $source) {
        if (trim((string) ($source['url'] ?? '')) !== ''
            && trim((string) ($source['title'] ?? '') . ' ' . (string) ($source['material'] ?? '')) !== '') {
            $validSourceIds[(string) $sourceId] = true;
        }
    }

    $sections = [];
    foreach ((array) ($draft['sections'] ?? []) as $section) {
        $sections[] = [...(array) $section, 'text'=>(string) ($section['body'] ?? '')];
    }
    foreach (['lead', 'why_important', 'comparison_context', 'practical_takeaway'] as $field) {
        $sections[] = (array) ($draft[$field] ?? []);
    }
    foreach (['key_facts', 'unknowns', 'narrative'] as $field) {
        foreach ((array) ($draft[$field] ?? []) as $section) {
            $sections[] = (array) $section;
        }
    }

    $citedText = [];
    foreach ($sections as $section) {
        $sourceIds = array_values(array_filter(array_map('strval', (array) ($section['source_ids'] ?? []))));
        if ($sourceIds === []
            || array_diff($sourceIds, array_keys($validSourceIds)) !== []
            || array_diff($sourceIds, array_keys($usedSourceIds)) !== []) {
            continue;
        }
        $text = trim((string) ($section['text'] ?? ''));
        if ($text !== '') {
            $citedText[] = $text;
        }
    }
    if ($citedText === []) {
        return false;
    }
    $supported = array_intersect($titleTokens, article_title_normalized_tokens(implode(' ', $citedText)));
    return count($supported) >= max(2, (int) ceil(count($titleTokens) * 0.45));
}

function deterministic_quality_checks(array $operation): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $draft = (array) $input['draft'];
    $sources = [];
    foreach ((array) $input['numbered_sources'] as $source) {
        $sources[(string) $source['source_id']] = $source;
    }
    $blocks = [];
    $warnings = [];
    $deduction = 0;
    $usedSourceIds = array_values(array_unique((array) ($draft['used_source_ids'] ?? [])));
    $compositionMode = (string) ($draft['composition_mode'] ?? 'informational');
    $lengthPolicy = article_draft_length_policy($compositionMode);
    $contentLength = article_draft_main_content_length($draft);
    if ($contentLength < $lengthPolicy['minimum_characters'] || $contentLength > $lengthPolicy['maximum_characters']) {
        $blocks['invalid_content_length'] = [
            'code' => 'invalid_content_length',
            'message' => 'Treść główna ma ' . $contentLength . ' znaków; wymagany zakres to '
                . $lengthPolicy['minimum_characters'] . '–' . $lengthPolicy['maximum_characters'] . '.',
            'reviewable' => false,
        ];
    }
    if (article_draft_repeated_sentence($draft) !== null) {
        $blocks['repeated_content'] = [
            'code' => 'repeated_content',
            'message' => 'Treść główna zawiera powtórzone długie zdanie.',
            'reviewable' => false,
        ];
    }
    if ($usedSourceIds === []) {
        $blocks['missing_sources'] = [
            'code' => 'missing_sources',
            'message' => 'Szkic nie wskazuje żadnego źródła.',
            'reviewable' => false,
        ];
    }
    foreach ($usedSourceIds as $sourceId) {
        if (!isset($sources[$sourceId])) {
            $blocks['missing_sources'] = [
                'code' => 'missing_sources',
                'message' => 'Szkic wskazuje źródło nieobecne w zatwierdzonym researchu.',
                'reviewable' => false,
            ];
        }
    }

    if (!quality_title_has_cited_section_support($draft, $sources)) {
        $blocks['unsupported_title_fact'] = [
            'code' => 'unsupported_title_fact',
            'message' => 'Tytuł nie ma rozpoznawalnej podstawy w twierdzeniach researchu.',
            'reviewable' => false,
        ];
    }

    if (($titleError = article_title_surface_error((string) ($draft['title'] ?? ''))) !== null) {
        $blocks['title_language'] = [
            'code' => 'title_language',
            'message' => $titleError,
            'reviewable' => false,
        ];
    }

    $allText = implode("\n", quality_texts_from_draft($draft));
    if (preg_match_all('/[„“"«](.{8,240}?)[”"»]/u', $allText, $matches)) {
        foreach ($matches[1] as $quote) {
            $found = false;
            foreach ($sources as $source) {
                if (str_contains(
                    research_normalize_evidence((string) $source['title'] . ' ' . (string) $source['material']),
                    research_normalize_evidence((string) $quote)
                )) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $blocks['false_quote'] = [
                    'code' => 'false_quote',
                    'message' => 'Szkic zawiera cytat, którego nie ma w przekazanych materiałach.',
                    'reviewable' => false,
                ];
                break;
            }
        }
    }
    if (preg_match('/\b(przetestowali(?:śmy|śmy)|sprawdziliśmy|nasz(?:e|ych)?\s+test|w\s+naszych\s+testach|we\s+tested|our\s+tests?)\b/iu', $allText)) {
        $blocks['unsupported_test_claim'] = [
            'code' => 'unsupported_test_claim',
            'message' => 'Szkic sugeruje wykonanie własnego testu bez dowodu.',
            'reviewable' => false,
        ];
    }

    foreach ($sources as $source) {
        if (quality_has_shared_phrase($allText, (string) $source['material'])) {
            $blocks['high_similarity'] = [
                'code' => 'high_similarity',
                'message' => 'Szkic zawiera długi fragment bardzo podobny do materiału źródłowego.',
                'reviewable' => false,
            ];
            break;
        }
    }
    if (!isset($blocks['high_similarity'])) {
        foreach (list_posts(null, true) as $published) {
            if ((int) $published['id'] === (int) $operation['post_id']) {
                continue;
            }
            if (quality_has_shared_phrase($allText, (string) $published['title'] . ' ' . (string) $published['content'])) {
                $blocks['high_similarity'] = [
                    'code' => 'high_similarity',
                    'message' => 'Szkic jest bardzo podobny do istniejącego opublikowanego materiału.',
                    'reviewable' => false,
                ];
                break;
            }
        }
    }

    $riskPatterns = [
        'medical' => '/\b(dawkowan|diagnoz|wylecz|odstaw\s+lek|zamiast\s+lekarz)\w*/iu',
        'financial' => '/\b(zainwestuj|kup\s+akcje|gwarantowan\w*\s+zysk|pewn\w*\s+zarob)\b/iu',
        'legal' => '/\b(porada\s+prawn|na\s+pewno\s+legaln|unikn\w*\s+odpowiedzialno)\w*/iu',
        'security' => '/\b(obej(?:dź|scie)\s+zabezpiec|wykrad\w*\s+hasł|włam\s+się)\b/iu',
    ];
    foreach ($riskPatterns as $type => $pattern) {
        if (preg_match($pattern, $allText)) {
            $blocks['high_risk_without_human_approval'] = [
                'code' => 'high_risk_without_human_approval',
                'message' => 'Treść wysokiego ryzyka (' . $type . ') wymaga udokumentowanej akceptacji człowieka.',
                'reviewable' => true,
            ];
            break;
        }
    }

    $clickbait = preg_match('/\b(szok|nie uwierzysz|zmieni wszystko|musisz to zobaczyć|koniec świata|przełom stulecia)\b/iu', (string) $draft['title']) === 1;
    if ($clickbait) {
        $warnings[] = 'Tytuł zawiera potencjalnie clickbaitowe sformułowanie.';
        $deduction += 15;
    }
    if (mb_strlen(trim((string) ($draft['seo_description'] ?? ''))) < 70) {
        $warnings[] = 'Opis SEO jest zbyt krótki.';
        $deduction += 5;
    }
    if (mb_strlen((string) ($draft['title'] ?? '')) > 100) {
        $warnings[] = 'Tytuł jest dłuższy niż 100 znaków.';
        $deduction += 5;
    }
    $v2Sections = (array) ($draft['sections'] ?? []);
    if ($v2Sections === [] && (((array) ($draft['key_facts'] ?? [])) === [] || trim((string) ($draft['why_important']['text'] ?? '')) === '')) {
        $warnings[] = 'Szkic jest niekompletny.';
        $deduction += 20;
    }

    $hasPrimary = false;
    $usedUrls = [];
    foreach ($usedSourceIds as $sourceId) {
        if (isset($sources[$sourceId])) {
            $usedUrls[(string) $sources[$sourceId]['url']] = true;
        }
    }
    foreach ((array) ($input['registered_post_sources'] ?? []) as $source) {
        if (!empty($source['is_primary']) && isset($usedUrls[(string) $source['url']])) {
            $hasPrimary = true;
            break;
        }
    }
    if (!$hasPrimary) {
        $warnings[] = 'Szkic nie wykorzystuje zarejestrowanego źródła pierwotnego.';
        $deduction += 10;
    }

    return [
        'hard_blocks' => array_values($blocks),
        'warnings' => $warnings,
        'deduction' => min(100, $deduction),
        'has_primary_source' => $hasPrimary,
        'used_source_count' => count($usedSourceIds),
        'main_content_character_count' => $contentLength,
        'main_content_minimum' => $lengthPolicy['minimum_characters'],
        'main_content_maximum' => $lengthPolicy['maximum_characters'],
    ];
}

function validate_quality_check_output(array $operation, array $result): array
{
    $maximums = quality_score_rubric();
    $sum = 0;
    foreach ($maximums as $name => $maximum) {
        $score = $result['scores'][$name] ?? null;
        if (!is_int($score) || $score < 0 || $score > $maximum) {
            throw new InvalidArgumentException("Punktacja {$name} wykracza poza zakres 0–{$maximum}.");
        }
        $sum += $score;
    }
    if (($result['total_score'] ?? null) !== $sum || $sum > QUALITY_SCORE_TOTAL) {
        throw new InvalidArgumentException('total_score nie jest sumą punktów składowych.');
    }
    foreach (['justification', 'original_value'] as $field) {
        if (trim((string) ($result[$field] ?? '')) === '') {
            throw new InvalidArgumentException("Pole {$field} wymaga uzasadnienia.");
        }
    }
    if (trim((string) ($result['similarity']['explanation'] ?? '')) === '') {
        throw new InvalidArgumentException('Ocena podobieństwa wymaga uzasadnienia.');
    }
    foreach ((array) ($result['risk_flags'] ?? []) as $index => $risk) {
        if (trim((string) ($risk['description'] ?? '')) === '') {
            throw new InvalidArgumentException("$.risk_flags[{$index}] wymaga opisu.");
        }
    }

    $deterministic = deterministic_quality_checks($operation);
    $blocks = [];
    foreach ($deterministic['hard_blocks'] as $block) {
        $blocks[$block['code']] = $block;
    }
    $modelBlocks = [
        'false_quote' => ((array) ($result['false_quotes'] ?? [])) !== [],
        'unsupported_test_claim' => ((array) ($result['unsupported_tests'] ?? [])) !== [],
        'high_similarity' => ($result['similarity']['level'] ?? '') === 'high',
        'high_risk_without_human_approval' => ((array) ($result['risk_flags'] ?? [])) !== [],
    ];
    foreach ($modelBlocks as $code => $present) {
        if ($present && !isset($blocks[$code])) {
            $reviewable = $code === 'high_risk_without_human_approval';
            $blocks[$code] = [
                'code' => $code,
                'message' => 'Kontrola modelowa zgłosiła blokadę: ' . str_replace('_', ' ', $code) . '.',
                'reviewable' => $reviewable,
            ];
        }
    }
    $finalScore = min($sum, max(0, 100 - (int) $deterministic['deduction']));
    $passed = $finalScore >= QUALITY_PASS_SCORE
        && $blocks === []
        && ($result['recommendation'] ?? '') === 'pass';

    return [
        'valid' => true,
        'model_score' => $sum,
        'final_score' => $finalScore,
        'passed' => $passed,
        'hard_blocks' => array_values($blocks),
        'deterministic' => $deterministic,
    ];
}

function persist_completed_quality_check(int $operationId, array $result, array $validation): void
{
    $statement = bueno_database()->prepare(
        'UPDATE quality_check_runs
         SET status = "completed", model_score = :model_score,
             final_score = :final_score, passed = :passed,
             model_result_json = :model_result_json,
             deterministic_json = :deterministic_json,
             hard_blocks_json = :hard_blocks_json,
             validation_json = :validation_json,
             completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([
        ':model_score' => (int) $validation['model_score'],
        ':final_score' => (int) $validation['final_score'],
        ':passed' => $validation['passed'] ? 1 : 0,
        ':model_result_json' => generation_json($result),
        ':deterministic_json' => generation_json($validation['deterministic']),
        ':hard_blocks_json' => generation_json($validation['hard_blocks']),
        ':validation_json' => generation_json($validation),
        ':operation_id' => $operationId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Nie znaleziono kontroli jakości.');
    }
    $check = find_quality_check_by_operation($operationId);
    bueno_database()->prepare(
        'UPDATE posts SET quality_score = :score, updated_at = CURRENT_TIMESTAMP WHERE id = :post_id'
    )->execute([':score' => (int) $validation['final_score'], ':post_id' => (int) $check['post_id']]);
    if (!empty($validation['passed'])) {
        qc_freeze_accepted_artifacts((int) $check['draft_version_id']);
    }
}

function mark_quality_check_failed(int $operationId, string $errorMessage, array $diagnostics = []): void
{
    bueno_database()->prepare(
        'UPDATE quality_check_runs
         SET status = "failed", validation_json = :validation_json,
             updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    )->execute([
        ':validation_json' => generation_json(['valid' => false, 'error' => mb_substr($errorMessage, 0, 2000), 'operation_id' => $operationId, ...$diagnostics]),
        ':operation_id' => $operationId,
    ]);
}

function find_quality_check_by_operation(int $operationId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM quality_check_runs WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([':operation_id' => $operationId]);
    $check = $statement->fetch();

    return is_array($check) ? $check : null;
}

function list_quality_checks(int $limit = 500): array
{
    $statement = bueno_database()->prepare(
        'SELECT checks.*, drafts.version_number AS draft_version_number,
                drafts.composition_mode, topics.title AS topic_title
         FROM quality_check_runs AS checks
         INNER JOIN article_draft_versions AS drafts ON drafts.id = checks.draft_version_id
         INNER JOIN editorial_topics AS topics ON topics.id = drafts.topic_id
         ORDER BY checks.id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function quality_active_hard_blocks(array $check): array
{
    $blocks = json_decode((string) ($check['hard_blocks_json'] ?? '[]'), true);
    $blocks = is_array($blocks) ? $blocks : [];
    if (($check['human_review_status'] ?? '') === 'approved') {
        $blocks = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['code'] ?? '') !== 'high_risk_without_human_approval'
        ));
    }

    return $blocks;
}

function review_quality_risk(int $checkId, string $decision, string $reason): void
{
    $decision = strtolower(trim($decision));
    $reason = trim($reason);
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Nieprawidłowa decyzja kontroli człowieka.');
    }
    if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Decyzja człowieka wymaga uzasadnienia od 10 do 1000 znaków.');
    }
    $statement = bueno_database()->prepare('SELECT * FROM quality_check_runs WHERE id = :id');
    $statement->execute([':id' => $checkId]);
    $check = $statement->fetch();
    if (!is_array($check) || $check['status'] !== 'completed') {
        throw new RuntimeException('Nie znaleziono ukończonej kontroli jakości.');
    }
    if (!in_array(trim((string) ($check['human_review_status'] ?? '')), ['', 'pending'], true)) {
        if ((string) $check['human_review_status'] === $decision) return;
        throw new RuntimeException('Decyzja QC została już zapisana. Odśwież stronę przed kolejną zmianą.');
    }
    $reviewable = false;
    foreach (json_decode((string) $check['hard_blocks_json'], true) ?: [] as $block) {
        if (($block['code'] ?? '') === 'high_risk_without_human_approval') {
            $reviewable = true;
        }
    }
    if (!$reviewable) {
        throw new RuntimeException('Ta kontrola nie zawiera ryzyka wymagającego decyzji człowieka.');
    }
    $check['human_review_status'] = $decision;
    $check['hard_blocks_json'] = (string) $check['hard_blocks_json'];
    $modelResult = json_decode((string) $check['model_result_json'], true);
    $passed = $decision === 'approved'
        && (int) $check['final_score'] >= QUALITY_PASS_SCORE
        && quality_active_hard_blocks($check) === []
        && is_array($modelResult)
        && ($modelResult['recommendation'] ?? '') === 'pass';
    bueno_database()->prepare(
        'UPDATE quality_check_runs
         SET human_review_status = :decision, human_review_reason = :reason,
             human_reviewed_at = CURRENT_TIMESTAMP, passed = :passed,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([
        ':decision' => $decision,
        ':reason' => $reason,
        ':passed' => $passed ? 1 : 0,
        ':id' => $checkId,
    ]);
}

/** Routes QC failures to a bounded automatic repair stage without weakening any hard block. */
function quality_check_auto_repair_decision(array $check, bool $convergenceActive = false): array
{
    $model = json_decode((string) ($check['model_result_json'] ?? '{}'), true) ?: [];
    $deterministic = json_decode((string) ($check['deterministic_json'] ?? '{}'), true) ?: [];
    $blocks = quality_active_hard_blocks($check);
    $blockCodes = array_values(array_filter(array_map(
        static fn (array $block): string => (string) ($block['code'] ?? ''),
        $blocks
    )));
    $context = find_quality_draft_context((int) ($check['draft_version_id'] ?? 0));
    $research = is_array($context) ? (json_decode((string) ($context['research_json'] ?? '{}'), true) ?: []) : [];

    $humanReasons = [];
    if ((array) ($model['risk_flags'] ?? []) !== [] || in_array('high_risk_without_human_approval', $blockCodes, true)) {
        $humanReasons[] = 'Treść ma ryzyko prawne, medyczne, finansowe lub bezpieczeństwa.';
    }
    if ($humanReasons !== []) {
        return ['repairable' => false, 'human_required' => true, 'target_stage' => null, 'categories' => [], 'feedback' => [], 'reasons' => array_values(array_unique($humanReasons))];
    }

    $researchReasons = [];
    if ((array) ($research['contradictions'] ?? []) !== []) $researchReasons[] = 'Research zawiera nierozstrzygnięte sprzeczności źródeł.';
    if (($research['recommendation']['source_coverage'] ?? '') !== 'sufficient'
        || ($model['has_primary_source'] ?? true) === false
        || in_array('missing_sources', $blockCodes, true)) {
        $researchReasons[] = 'Źródła są niewystarczające albo brak wymaganego źródła pierwotnego.';
    }
    if ($researchReasons !== []) {
        return [
            'repairable' => true, 'human_required' => false, 'target_stage' => 'research',
            'categories' => ['sources'],
            'feedback' => array_map(static fn (string $reason): string => 'Ponów enrichment/research: ' . $reason, array_values(array_unique($researchReasons))),
            'reasons' => [],
        ];
    }

    $categories = [];
    $feedback = [];
    $append = static function (string $category, string $message) use (&$categories, &$feedback): void {
        $categories[$category] = true;
        if ($message !== '') $feedback[] = mb_substr($message, 0, 700);
    };
    foreach ((array) ($model['language_issues'] ?? []) as $issue) $append('language', 'Popraw język i czytelność: ' . trim((string) $issue));
    foreach ((array) ($model['missing_elements'] ?? []) as $issue) $append('completeness', 'Uzupełnij element wyłącznie na podstawie researchu: ' . trim((string) $issue));
    foreach ((array) ($model['clickbait_phrases'] ?? []) as $issue) $append('clickbait', 'Usuń clickbait bez osłabiania faktów: ' . trim((string) $issue));
    foreach ((array) ($model['unsupported_claims'] ?? []) as $issue) $append('fact_grounding', 'Usuń albo doprecyzuj przez wspierane claim_ids/source_ids: ' . trim((string) $issue));
    foreach ((array) ($model['unsupported_tests'] ?? []) as $issue) $append('fact_grounding', 'Usuń deklarację testu bez dowodu: ' . trim((string) $issue));
    foreach ((array) ($model['false_quotes'] ?? []) as $quote) {
        $append('false_quote', 'Napraw podejrzany cytat: ' . trim((string) $quote)
            . '. Użyj wyłącznie zweryfikowanego dosłownego fragmentu numbered_sources albo usuń cudzysłów i bezpiecznie sparafrazuj wsparty fakt z zachowaniem claim_ids/source_ids.');
    }
    foreach ((array) ($deterministic['warnings'] ?? []) as $warning) {
        $text = (string) $warning;
        $category = str_contains(mb_strtolower($text), 'seo') ? 'seo'
            : (str_contains(mb_strtolower($text), 'clickbait') ? 'clickbait' : 'structure');
        $append($category, $text);
    }
    foreach ($blocks as $block) {
        $code = (string) ($block['code'] ?? '');
        if (in_array($code, ['invalid_content_length', 'repeated_content'], true)) $append('structure', (string) ($block['message'] ?? $code));
        if ($code === 'unsupported_title_fact') $append('fact_grounding', (string) ($block['message'] ?? $code));
        if ($code === 'unsupported_test_claim') $append('fact_grounding', (string) ($block['message'] ?? $code));
        if ($code === 'high_similarity') $append('originality', (string) ($block['message'] ?? $code));
        if ($code === 'false_quote' && (array) ($model['false_quotes'] ?? []) === []) {
            $append('false_quote', (string) ($block['message'] ?? $code)
                . ' Użyj zweryfikowanego dosłownego fragmentu źródła albo usuń cudzysłów i parafrazuj wyłącznie wsparty fakt.');
        }
    }
    if (($model['recommendation'] ?? '') !== 'pass' || (int) ($check['final_score'] ?? 0) < QUALITY_PASS_SCORE) {
        $append('completeness', 'Zastosuj uzasadnienie QC i popraw ocenione elementy bez dodawania nowych faktów: ' . trim((string) ($model['justification'] ?? 'wynik poniżej progu')));
    }

    /** In convergence mode: never lower thresholds; force targeted_repair strategy. */
    $strategy = 'targeted_repair';
    if (!$convergenceActive) {
        /* Outside convergence, allow the router to pick a broader strategy if needed. */
        $strategy = 'auto';
    }

    return [
        'repairable' => true,
        'human_required' => false,
        'target_stage' => 'draft',
        'categories' => array_keys($categories),
        'feedback' => array_values(array_unique(array_filter($feedback))),
        'reasons' => [],
        'convergence_active' => $convergenceActive,
        'repair_strategy' => $strategy,
    ];
}

/**
 * Derive deterministic, slot-level image coverage from the persisted visual plan.
 * A related image becomes final coverage only after a later stage records
 * source-backed related support in its multimodal assessment.
 */
function article_image_coverage_state(int $postId, ?int $topicId = null, bool $requireLocalAsset = true): array
{
    $plan = find_narrative_plan_for_post($postId, $topicId);
    $workflowVersion = 1;
    if (is_array($plan) && (int) ($plan['batch_stage_ref'] ?? 0) > 0) {
        $planOperation = find_generation_operation((int) $plan['batch_stage_ref']);
        $planInput = is_array($planOperation) ? (json_decode((string) ($planOperation['input_json'] ?? '{}'), true) ?: []) : [];
        $workflowVersion = (int) ($planInput['workflow_version'] ?? 1);
    }
    $visualPlan = is_array($plan) && function_exists('article_image_effective_visual_plan')
        ? article_image_effective_visual_plan($postId, $topicId, $plan)
        : (is_array($plan) ? (json_decode((string) ($plan['visual_plan_json'] ?? '{}'), true) ?: []) : []);
    $slots = [];
    if (is_array($visualPlan['hero_slot'] ?? null)) {
        $slots[] = $visualPlan['hero_slot'];
    }
    foreach ((array) ($visualPlan['inline_slots'] ?? []) as $slot) {
        if (is_array($slot) && !empty($slot['required'])) $slots[] = $slot;
    }
    if ($slots === []) {
        $legacyCount = max(1, min(6, (int) (is_array($plan) ? ($plan['visual_slots_planned'] ?? 1) : 1)));
        $slots[] = ['slot_id' => 'hero-main', 'role' => 'hero', 'section_anchor' => 'article', 'must_be_direct' => true, 'acceptable_related' => false, 'required' => true];
        for ($index = 1; $index < $legacyCount; $index++) {
            $slots[] = ['slot_id' => 'inline-' . $index, 'role' => 'inline', 'section_anchor' => '', 'must_be_direct' => false, 'acceptable_related' => false, 'required' => true];
        }
    }

    $imagesStatement = bueno_database()->prepare('SELECT * FROM article_images WHERE post_id = :post_id ORDER BY id DESC');
    $imagesStatement->execute([':post_id' => $postId]);
    $images = $imagesStatement->fetchAll();
    $filled = [];
    $missing = [];
    $heroStatus = 'missing';
    foreach ($slots as $slot) {
        $role = (string) ($slot['role'] ?? 'inline');
        $anchor = (string) ($slot['section_anchor'] ?? '');
        $image = null;
        foreach ($images as $candidate) {
            if ((string) ($candidate['role'] ?? '') !== $role) continue;
            if ($role === 'hero' || $anchor === '' || (string) ($candidate['section_id'] ?? '') === $anchor) {
                $image = $candidate;
                break;
            }
        }
        $status = 'missing';
        if (is_array($image)) {
            $assetExists = !$requireLocalAsset || (trim((string) ($image['local_path'] ?? '')) !== '' && is_file(app_path((string) $image['local_path'])));
            $usable = (string) ($image['status'] ?? '') === 'downloaded'
                && (int) ($image['is_fallback'] ?? 0) === 0
                && (int) ($image['editorial_rejected'] ?? 0) === 0
                && (int) ($image['multimodal_accepted'] ?? 0) === 1
                && $assetExists;
            if ((int) ($image['is_fallback'] ?? 0) === 1) {
                $status = 'fallback';
            } elseif ((int) ($image['editorial_rejected'] ?? 0) === 1 || (string) ($image['status'] ?? '') === 'rejected') {
                $status = 'rejected';
            } elseif ($usable && (string) ($image['relationship'] ?? 'exact_subject') === 'exact_subject') {
                $searchAudit = json_decode((string) ($image['search_audit_json'] ?? '[]'), true) ?: [];
                $selectedAudit = array_values(array_filter($searchAudit, static fn (array $entry): bool => (string) ($entry['result'] ?? '') === 'selected'));
                $selectedLevel = $selectedAudit === [] ? 'exact_direct' : (string) ($selectedAudit[array_key_last($selectedAudit)]['level'] ?? 'exact_direct');
                $status = $selectedLevel === 'broader_direct' ? 'broader_direct_ok' : 'direct_ok';
            } elseif ($usable && $role === 'hero') {
                $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true) ?: [];
                $heroRecovery = (array) ($assessment['hero_recovery'] ?? []);
                $vision = (array) ($heroRecovery['final_vision'] ?? []);
                $blockId = (int) ($heroRecovery['context_block_id'] ?? 0);
                $block = bueno_database()->prepare('SELECT COUNT(*) FROM article_related_context_blocks WHERE id=:id AND post_id=:post AND image_id=:image AND status="approved" AND source_claim_ids_json<>"[]"');
                $block->execute([':id' => $blockId, ':post' => $postId, ':image' => (int) ($image['id'] ?? 0)]);
                $wwContextual = (string) ($heroRecovery['policy'] ?? '') === 'ww_contextual_v1'
                    && (string) ($heroRecovery['status'] ?? '') === 'validated'
                    && !empty($assessment['related_supported'])
                    && trim((string) ($image['caption'] ?? '')) !== '';
                $heroAllowed = $wwContextual || ((string) ($heroRecovery['policy'] ?? '') === 'source_backed_related_hero_v1'
                    && (string) ($heroRecovery['status'] ?? '') === 'validated'
                    && in_array((string) ($image['relationship'] ?? ''), ['mechanism', 'related_context'], true)
                    && in_array((string) ($vision['relationship_level'] ?? ''), ['direct','broader_direct','strong_related','contextual_related','domain_related'], true)
                    && !empty($vision['contextual_useful']) && !empty($vision['honest_caption_possible'])
                    && empty($vision['misleading']) && empty($vision['inappropriate'])
                    && (string) ($vision['decision'] ?? '') === 'accept'
                    && (int) $block->fetchColumn() === 1);
                $status = $heroAllowed ? 'controlled_related_supported' : 'related_candidate';
            } elseif ($usable && $role === 'inline') {
                $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true) ?: [];
                $block = bueno_database()->prepare('SELECT COUNT(*) FROM article_related_context_blocks WHERE post_id=:post AND image_id=:image AND status="approved" AND source_claim_ids_json<>"[]"');
                $block->execute([':post'=>$postId, ':image'=>(int) ($image['id'] ?? 0)]);
                $wwContextual = (string) ($assessment['contextual_policy'] ?? '') === 'ww_contextual_v1'
                    && !empty($assessment['related_supported'])
                    && article_image_license_is_auto_safe((string) ($image['license'] ?? ''))
                    && trim((string) ($image['source_page_url'] ?? '')) !== ''
                    && trim((string) ($image['caption'] ?? '')) !== '';
                $legacyRelated = empty($slot['must_be_direct']) && !empty($slot['acceptable_related'])
                    && !empty($assessment['related_supported'])
                    && trim((string) ($image['source_page_url'] ?? '')) !== ''
                    && (int) $block->fetchColumn() > 0;
                $status = $wwContextual || $legacyRelated
                    ? 'related_supported' : 'related_candidate';
            }
        }
        $entry = ['slot_id' => (string) ($slot['slot_id'] ?? ''), 'role' => $role, 'section_anchor' => $anchor, 'status' => $status];
        if (in_array($status, ['direct_ok', 'broader_direct_ok', 'related_supported', 'controlled_related_supported'], true)) $filled[] = $entry;
        else $missing[] = $entry;
        if ($role === 'hero') $heroStatus = $status;
    }
    $draftStatement = bueno_database()->prepare(
        'SELECT draft_json FROM article_draft_versions WHERE post_id=:post AND status IN ("completed","frozen") ORDER BY is_active DESC,id DESC LIMIT 1'
    );
    $draftStatement->execute([':post'=>$postId]);
    $draftJson = json_decode((string) ($draftStatement->fetchColumn() ?: '{}'), true) ?: [];
    $finalArticleLength = $draftJson === [] ? 0 : article_draft_main_content_length($draftJson);
    $targetState = $workflowVersion >= 2 && $finalArticleLength > 0
        ? editorial_v2_visual_target_state($finalArticleLength, count($slots))
        : ['final_article_length'=>$finalArticleLength, 'visual_target'=>count($slots), 'visual_slot_count'=>count($slots),
            'visual_deficit'=>0, 'publication_visual_floor'=>count($slots)];
    $heroIsAllowed = in_array($heroStatus, ['direct_ok', 'broader_direct_ok', 'controlled_related_supported'], true);
    $coverageComplete = count($slots) > 0 && count($filled) === count($slots)
        && (int) $targetState['visual_deficit'] === 0
        && $heroIsAllowed
        && !array_filter($missing, static fn (array $slot): bool => $slot['status'] === 'fallback');
    $visualTarget = (int) $targetState['visual_target'];
    $publicationFloor = (int) $targetState['publication_visual_floor'];
    $publicationFloorMet = $heroIsAllowed && count($filled) >= $publicationFloor;
    return ['required_slots' => $slots, 'filled_slots' => $filled, 'missing_slots' => $missing,
        'hero_status' => $heroStatus, 'hero_present' => $heroIsAllowed, 'hero_is_allowed' => $heroIsAllowed,
        'coverage_complete' => $coverageComplete, 'workflow_version'=>$workflowVersion, 'visual_target'=>$visualTarget,
        'final_article_length'=>(int) $targetState['final_article_length'], 'visual_slot_count'=>(int) $targetState['visual_slot_count'],
        'visual_deficit'=>(int) $targetState['visual_deficit'], 'image_plan_expansion_required'=>(int) $targetState['visual_deficit'] > 0,
        'publication_visual_floor'=>$publicationFloor, 'publication_floor_met'=>$publicationFloorMet,
        'narrative_plan_id' => is_array($plan) ? (int) ($plan['id'] ?? 0) : null];
}

/** A completed QC from a local fixture may exercise tests, never production readiness. */
function quality_check_is_production_eligible(int $qualityCheckId): bool
{
    if ($qualityCheckId <= 0) return false;
    // The disposable test DB intentionally uses the deterministic transport to
    // exercise the P08/P09 sequence. It is never publishable outside this
    // process, while production still requires a real provider response below.
    if (generation_explicit_test_mode()) return true;
    $statement = bueno_database()->prepare(
        'SELECT checks.execution_mode, operations.live_request_count, operations.provider_response_id
         FROM quality_check_runs checks
         INNER JOIN generation_operations operations ON operations.id = checks.generation_operation_id
         WHERE checks.id = :id'
    );
    $statement->execute([':id' => $qualityCheckId]);
    $row = $statement->fetch();
    if (!is_array($row) || (string) ($row['execution_mode'] ?? '') !== 'api') return false;
    $responseId = (string) ($row['provider_response_id'] ?? '');
    return (int) ($row['live_request_count'] ?? 0) > 0
        && !str_starts_with($responseId, 'deterministic-')
        && !str_starts_with($responseId, 'resp_local_mock');
}

function assert_post_quality_allows_publication(int $postId): void
{
    $draftStatement = bueno_database()->prepare(
        'SELECT * FROM article_draft_versions
         WHERE post_id = :post_id AND status IN ("completed", "frozen")
         ORDER BY is_active DESC, id DESC LIMIT 1'
    );
    $draftStatement->execute([':post_id' => $postId]);
    $draft = $draftStatement->fetch();
    if (!is_array($draft)) {
        return;
    }
    $checkStatement = bueno_database()->prepare(
        'SELECT * FROM quality_check_runs
         WHERE draft_version_id = :draft_id AND status = "completed"
         ORDER BY id DESC LIMIT 1'
    );
    $checkStatement->execute([':draft_id' => (int) $draft['id']]);
    $check = $checkStatement->fetch();
    if (is_array($check) && !quality_check_is_production_eligible((int) ($check['id'] ?? 0))) {
        throw new RuntimeException('Publikacja zablokowana: wynik QC nie pochodzi z rzeczywistego wywołania modelu.');
    }
    if (!is_array($check)) {
        throw new RuntimeException('Najnowsza wersja szkicu nie ma ukończonej kontroli jakości.');
    }
    $blocks = quality_active_hard_blocks($check);
    if ($blocks !== []) {
        throw new RuntimeException('Publikację blokuje kontrola jakości: ' . (string) $blocks[0]['message']);
    }
    if ((int) $check['passed'] !== 1 || (int) $check['final_score'] < QUALITY_PASS_SCORE) {
        throw new RuntimeException('Szkic nie osiągnął progu jakości ' . QUALITY_PASS_SCORE . '/100.');
    }
    /* P2-D: block publication if any fallback image exists. */
    $lock = core_text_lock_state((int) $draft['id']);
    if (empty($lock['core_text_locked'])) {
        throw new RuntimeException('Publikacja zablokowana: core text nie jest locked po kontroli jakości.');
    }
    $coverage = article_image_coverage_state($postId);
    if ($coverage['hero_status'] === 'fallback') {
        throw new RuntimeException('Publikacja zablokowana: hero jest fallbackiem technicznym.');
    }
    if (!$coverage['hero_present']) {
        throw new RuntimeException('Publikacja zablokowana: brak prawidłowego hero.');
    }
    if (empty($coverage['coverage_complete'])) {
        throw new RuntimeException('Publikacja zablokowana: wymagane jest pełne coverage ' . count($coverage['filled_slots'])
            . '/' . count($coverage['required_slots']) . ' grafik z FinalVisualPlan.');
    }
    $draftJson = json_decode((string) ($draft['draft_json'] ?? '{}'), true) ?: [];
    $contentLength = article_draft_main_content_length($draftJson);
    $minimumInline = editorial_v2_required_image_count($contentLength) - 1;
    $requiredInline = count(array_filter((array) $coverage['required_slots'], static fn (array $slot): bool => (string) ($slot['role'] ?? '') === 'inline'));
    if ($requiredInline < $minimumInline) {
        throw new RuntimeException('Publikacja zablokowana: VisualPlan nie spełnia floor hero + ' . $minimumInline . ' inline dla długości ' . $contentLength . '.');
    }
    $fallbackStmt = bueno_database()->prepare(
        'SELECT COUNT(*) AS cnt FROM article_images WHERE post_id = :post_id AND is_fallback = 1'
    );
    $fallbackStmt->execute([':post_id' => $postId]);
    $fallbackCount = (int) $fallbackStmt->fetchColumn();
    if ($fallbackCount > 0) {
        throw new RuntimeException('Publikacja zablokowana: artykuł zawiera ' . $fallbackCount . ' grafikę/fallback, która nie może być publikowana.');
    }

    /* Enforce actual files for every canonical FinalVisualPlan slot. */
    $validStmt = bueno_database()->prepare(
        'SELECT local_path FROM article_images' .
        ' WHERE post_id = :post_id AND status = "downloaded" AND is_fallback = 0' .
        ' AND editorial_rejected = 0 AND multimodal_accepted = 1'
    );
    $validStmt->execute([':post_id' => $postId]);
    $validCount = count(array_filter(
        $validStmt->fetchAll(),
        static fn (array $image): bool => trim((string) ($image['local_path'] ?? '')) !== ''
            && is_file(app_path((string) $image['local_path']))
    ));
    $requiredSlots = count((array) ($coverage['required_slots'] ?? []));
    if ($validCount < $requiredSlots) {
        throw new RuntimeException(
            'Publikacja zablokowana: FinalVisualPlan wymaga ' . $requiredSlots . ' prawidłowych grafik; znaleziono ' . $validCount . '.'
        );
    }

    /* P2-F: block publication if the batch item is in manual_review state. */
    $batchStmt = bueno_database()->prepare(
        'SELECT status FROM generation_batch_items WHERE post_id = :post_id ORDER BY id DESC LIMIT 1'
    );
    $batchStmt->execute([':post_id' => $postId]);
    $batchItem = $batchStmt->fetch();
    if (is_array($batchItem) && (string) $batchItem['status'] === 'manual_review') {
        throw new RuntimeException(
            'Publikacja zablokowana: artykuł wymaga przeglądu redakcyjnego (budżet Gemini wyczerpany).'
        );
    }

    $layout = bueno_database()->prepare(
        'SELECT id FROM generation_operations
         WHERE post_id=:post AND operation_type="layout_plan" AND status="completed"
         ORDER BY completed_at DESC,id DESC LIMIT 1'
    );
    $layout->execute([':post' => $postId]);
    if ($layout->fetchColumn() === false) {
        throw new RuntimeException('Publikacja zablokowana: brak utrwalonego LayoutPlan.');
    }

    $final = bueno_database()->prepare('SELECT decision FROM final_multimodal_qc_runs WHERE post_id=:post AND draft_version_id=:draft AND status="completed" ORDER BY id DESC LIMIT 1');
    $final->execute([':post'=>$postId, ':draft'=>(int)$draft['id']]);
    $decision = (string) $final->fetchColumn();
    if (!in_array($decision, ['PASS', 'PASS_WITH_MINOR_NOTES'], true)) {
        throw new RuntimeException('Publikacja zablokowana: brak pozytywnego finalnego QC multimodalnego.');
    }
    $budget = gemini_article_budget_state($postId);
    if ((int) ($budget['used_calls'] ?? 0) > 30 || (int) ($budget['max_calls'] ?? 30) > 30) {
        throw new RuntimeException('Publikacja zablokowana: przekroczono GeminiBudget 30.');
    }
}

function final_multimodal_qc_schema(): array
{
    $score = ['type'=>'integer','minimum'=>0,'maximum'=>10];
    $scores = array_fill_keys(['text_quality','factual_consistency','source_coverage','hero_fit','image_section_alignment','visual_completeness','related_module_naturalness','layout_coherence','reader_flow','editorial_value'], $score);
    return ['type'=>'object','properties'=>[
        'scores'=>['type'=>'object','properties'=>$scores,'required'=>array_keys($scores),'additionalProperties'=>false],
        'decision'=>['type'=>'string','enum'=>['PASS','PASS_WITH_MINOR_NOTES','FAIL']],
        'notes'=>['type'=>'array','items'=>['type'=>'string']],
        'allowed_repair_operations'=>['type'=>'array','items'=>['type'=>'string']],
        'justification'=>['type'=>'string'],
    ],'required'=>['scores','decision','notes','allowed_repair_operations','justification'],'additionalProperties'=>false];
}

/** Deterministic pre-gates run before the final model and cannot be overridden by it. */
function final_multimodal_qc_preflight(int $postId, int $draftVersionId): array
{
    $reasons = [];
    if (!function_exists('core_text_lock_state') || empty(core_text_lock_state($draftVersionId)['core_text_locked'])) $reasons[] = 'core_text_not_locked';
    try { assert_post_quality_allows_publication($postId); }
    catch (Throwable $exception) {
        $message = $exception->getMessage();
        if (!str_contains($message, 'finalnego QC multimodalnego')) $reasons[] = $message;
    }
    $orphan = bueno_database()->prepare('SELECT COUNT(*) FROM article_related_context_blocks blocks LEFT JOIN article_images images ON images.id=blocks.image_id AND images.post_id=blocks.post_id WHERE blocks.post_id=:post AND (images.id IS NULL OR blocks.source_claim_ids_json="[]")');
    $orphan->execute([':post'=>$postId]);
    if ((int)$orphan->fetchColumn() > 0) $reasons[] = 'orphaned_or_unsourced_related_context';
    return ['passed'=>$reasons===[], 'reasons'=>$reasons];
}

function prepare_final_multimodal_qc_operation(int $postId, int $topicId, int $draftVersionId): int
{
    $preflight = final_multimodal_qc_preflight($postId, $draftVersionId);
    if (!$preflight['passed']) throw new RuntimeException('Final QC zablokowane przez deterministic pre-gates: '.implode('; ', $preflight['reasons']));
    $layoutAudit = [];
    $plan = find_narrative_plan_for_post($postId, $topicId);
    $package = find_latest_approved_research_package_for_topic($topicId);
    $research = is_array($package) ? (json_decode((string) ($package['package_json'] ?? '{}'), true) ?: []) : [];
    $draftRow = find_article_draft_by_id($draftVersionId);
    $draftJson = is_array($draftRow) ? (json_decode((string) ($draftRow['draft_json'] ?? '{}'), true) ?: []) : [];
    $input = ['post_id'=>$postId,'draft_version_id'=>$draftVersionId,'workflow_version'=>2,'locked_core'=>core_text_lock_state($draftVersionId),
        'editorial_research'=>array_intersect_key($research, array_flip(['primary_story','context_topics','curiosity_topics','source_claims','source_map'])),
        'narrative_selection'=>is_array($plan) ? narrative_plan_editorial_payload($plan) : [],
        'images'=>list_article_images($postId),'related_context_blocks'=>article_related_context_blocks_for_post($postId),
        'layout_plan'=>article_layout_plan_for_post($postId, $layoutAudit),'layout_audit'=>$layoutAudit,
        'coverage'=>article_image_coverage_state($postId, $topicId),
        'dynamic_sections'=>(array) ($draftJson['sections'] ?? []),
        'composition_contract'=>['card_max_characters'=>500,'max_consecutive_cards'=>2,'preferred_image_interval_characters'=>[1500,2500]],
        'instruction'=>'Assess whether A leads, B/C are distinct and source-backed, long prose is not presented as callout, no card wall exists, images are distributed with editorial rhythm, and the package is ready for manual publication. Do not propose or perform a core-article rewrite.'];
    $operation = prepare_generation_operation('final_multimodal_qc', $input, final_multimodal_qc_schema(), $postId, $topicId);
    bueno_database()->prepare('INSERT INTO final_multimodal_qc_runs (post_id,draft_version_id,generation_operation_id,deterministic_gates_json) VALUES (:post,:draft,:operation,:gates)')->execute([':post'=>$postId,':draft'=>$draftVersionId,':operation'=>$operation,':gates'=>generation_json($preflight)]);
    return $operation;
}

function complete_final_multimodal_qc_operation(int $operationId): array
{
    $operation = find_generation_operation($operationId);
    if (!is_array($operation) || (string)$operation['operation_type'] !== 'final_multimodal_qc' || (string)$operation['status'] !== 'completed') throw new RuntimeException('Brak ukończonego finalnego QC.');
    $input = json_decode((string)$operation['input_json'], true) ?: [];
    $result = json_decode((string)$operation['output_json'], true) ?: [];
    validate_generation_value($result, final_multimodal_qc_schema());
    $preflight = final_multimodal_qc_preflight((int)$operation['post_id'], (int)($input['draft_version_id'] ?? 0));
    $decision = $preflight['passed'] ? (string)$result['decision'] : 'FAIL';
    $allowed = ['caption','heading','transition_paragraph','placement','context_block','additive_related_module','targeted_correction'];
    foreach ((array)$result['allowed_repair_operations'] as $repair) if (!in_array((string)$repair, $allowed, true)) throw new RuntimeException('Final QC zaproponowało niedozwoloną naprawę.');
    bueno_database()->prepare('UPDATE final_multimodal_qc_runs SET status="completed",decision=:decision,result_json=:result,deterministic_gates_json=:gates,completed_at=CURRENT_TIMESTAMP WHERE generation_operation_id=:operation')->execute([':decision'=>$decision,':result'=>generation_json($result),':gates'=>generation_json($preflight),':operation'=>$operationId]);
    return ['decision'=>$decision,'preflight'=>$preflight,'result'=>$result];
}

/** Non-public readiness outcome; publication remains a separate explicit admin action. */
function final_multimodal_qc_readiness(int $postId): string
{
    try { assert_post_quality_allows_publication($postId); }
    catch (Throwable) { return 'manual_review'; }
    return 'ready_for_manual_publish';
}

/** Collect structured diagnostics for a budget-exhausted article. No secrets are included. */
function gemini_budget_exhaustion_diagnostics(int $postId): array
{
    $diagnostics = [
        'article_id' => $postId,
        'timestamp' => gmdate(DATE_ATOM),
        'block_reason' => 'gemini_article_budget_exhausted',
        'budget' => [],
        'artifacts' => [],
        'images' => [],
        'qc' => [],
    ];

    /* Budget state */
    $budget = gemini_article_budget_state($postId);
    $diagnostics['budget'] = [
        'used_calls' => (int) ($budget['used_calls'] ?? 0),
        'max_calls' => (int) ($budget['max_calls'] ?? 30),
        'convergence_active' => (bool) ($budget['convergence_active'] ?? false),
        'is_exhausted' => (bool) ($budget['is_exhausted'] ?? false),
    ];

    /* Draft state */
    $draftStmt = bueno_database()->prepare(
        'SELECT id, status, version_number, composition_mode FROM article_draft_versions
         WHERE post_id = :post_id AND status IN ("completed", "frozen")
         ORDER BY is_active DESC, id DESC LIMIT 1'
    );
    $draftStmt->execute([':post_id' => $postId]);
    $draft = $draftStmt->fetch();
    if (is_array($draft)) {
        $diagnostics['artifacts']['draft_version_id'] = (int) $draft['id'];
        $diagnostics['artifacts']['draft_status'] = (string) $draft['status'];
        $diagnostics['artifacts']['version_number'] = (int) $draft['version_number'];
    }

    /* QC state */
    if (isset($diagnostics['artifacts']['draft_version_id'])) {
        $qcStmt = bueno_database()->prepare(
            'SELECT id, status, final_score, passed FROM quality_check_runs
             WHERE draft_version_id = :draft_id AND status = "completed"
             ORDER BY id DESC LIMIT 1'
        );
        $qcStmt->execute([':draft_id' => $diagnostics['artifacts']['draft_version_id']]);
        $qc = $qcStmt->fetch();
        if (is_array($qc)) {
            $diagnostics['qc'] = [
                'check_id' => (int) $qc['id'],
                'final_score' => (int) ($qc['final_score'] ?? 0),
                'passed' => (bool) ($qc['passed'] ?? false),
            ];
        }
    }

    /* Image state */
    $imgStmt = bueno_database()->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN status="downloaded" AND is_fallback=0 AND editorial_rejected=0 AND multimodal_accepted=1 THEN 1 ELSE 0 END) AS valid,
                SUM(CASE WHEN is_fallback=1 THEN 1 ELSE 0 END) AS fallbacks,
                SUM(CASE WHEN status IN ("missing","manual_review","planned") THEN 1 ELSE 0 END) AS pending
         FROM article_images WHERE post_id = :post_id'
    );
    $imgStmt->execute([':post_id' => $postId]);
    $imgRow = $imgStmt->fetch();
    if (is_array($imgRow)) {
        $diagnostics['images'] = [
            'total' => (int) ($imgRow['total'] ?? 0),
            'valid' => (int) ($imgRow['valid'] ?? 0),
            'fallbacks' => (int) ($imgRow['fallbacks'] ?? 0),
            'pending' => (int) ($imgRow['pending'] ?? 0),
        ];
    }

    /* Narrative plan */
    $plan = find_narrative_plan_for_topic($postId);
    if (is_array($plan)) {
        $diagnostics['artifacts']['narrative_plan_status'] = (string) ($plan['status'] ?? '');
        $diagnostics['artifacts']['visual_slots_planned'] = (int) ($plan['visual_slots_planned'] ?? 0);
    }

    return $diagnostics;
}

/** Classify a QC block code as hard or soft gate. */
function qc_gate_type(string $code): string
{
    if (in_array($code, QC_HARD_GATES, true)) {
        return 'hard';
    }
    if (in_array($code, QC_SOFT_GATES, true)) {
        return 'soft';
    }
    /* Unknown codes default to hard for safety. */
    return 'hard';
}

/** Build structured QcReport with hard_gates and soft_gates arrays. */
function qc_structured_report(array $check, array $validation): array
{
    $deterministic = (array) ($validation['deterministic'] ?? []);
    $blocks = (array) ($validation['hard_blocks'] ?? []);
    $model = json_decode((string) ($check['model_result_json'] ?? '{}'), true) ?: [];

    $hardGates = [];
    foreach (QC_HARD_GATES as $gateName) {
        $passed = true;
        $detail = '';
        /* Map deterministic block codes to hard gates. */
        foreach ($blocks as $block) {
            $code = (string) ($block['code'] ?? '');
            if ($code === $gateName || qc_gate_type($code) === 'hard') {
                if ($gateName === 'char_count_range' && $code === 'invalid_content_length') {
                    $passed = false;
                    $detail = (string) ($block['message'] ?? $code);
                } elseif ($gateName === 'publication_safe' && in_array($code, ['false_quote', 'unsupported_test_claim', 'high_similarity'], true)) {
                    $passed = false;
                    $detail = (string) ($block['message'] ?? $code);
                } elseif ($gateName === 'metadata_consistent' && $code === 'missing_sources') {
                    $passed = false;
                    $detail = (string) ($block['message'] ?? $code);
                } elseif ($gateName === 'publication_safe' && $code === 'unsupported_title_fact') {
                    $passed = false;
                    $detail = (string) ($block['message'] ?? $code);
                } elseif ($gateName === 'publication_safe' && $code === 'high_risk_without_human_approval') {
                    $passed = false;
                    $detail = (string) ($block['message'] ?? $code);
                }
            }
        }
        /* Score-based hard gate. */
        if ($gateName === 'publication_safe' && (int) ($check['final_score'] ?? 0) < QUALITY_PASS_SCORE) {
            $passed = false;
            $detail = sprintf('Wynik QC %d poniżej progu %d.', (int) ($check['final_score'] ?? 0), QUALITY_PASS_SCORE);
        }
        $hardGates[] = [
            'gate_name' => $gateName,
            'passed' => $passed,
            'detail' => $detail,
            'severity' => 'blocker',
        ];
    }

    $softGates = [];
    foreach (QC_SOFT_GATES as $gateName) {
        $score = 100;
        $detail = '';
        $suggestedFixScope = '';
        /* Derive soft-gate signals from model feedback. */
        if ($gateName === 'narrative_coherence') {
            foreach ((array) ($model['missing_elements'] ?? []) as $issue) {
                $score = max(0, $score - 15);
                $detail .= (string) $issue . '; ';
                $suggestedFixScope = 'draft_section';
            }
        } elseif ($gateName === 'transitions_smooth') {
            foreach ((array) ($model['language_issues'] ?? []) as $issue) {
                if (str_contains(mb_strtolower((string) $issue), 'przejście') || str_contains(mb_strtolower((string) $issue), 'spójność')) {
                    $score = max(0, $score - 10);
                    $detail .= (string) $issue . '; ';
                    $suggestedFixScope = 'transition';
                }
            }
        } elseif ($gateName === 'no_redundancy') {
            foreach ((array) ($model['language_issues'] ?? []) as $issue) {
                if (str_contains(mb_strtolower((string) $issue), 'powtó') || str_contains(mb_strtolower((string) $issue), 'redundan')) {
                    $score = max(0, $score - 15);
                    $detail .= (string) $issue . '; ';
                    $suggestedFixScope = 'draft_section';
                }
            }
        } elseif ($gateName === 'rhythm_varied') {
            foreach ((array) ($model['language_issues'] ?? []) as $issue) {
                if (str_contains(mb_strtolower((string) $issue), 'rytm') || str_contains(mb_strtolower((string) $issue), 'monoton')) {
                    $score = max(0, $score - 10);
                    $detail .= (string) $issue . '; ';
                    $suggestedFixScope = 'draft_section';
                }
            }
        } elseif ($gateName === 'engagement_level') {
            foreach ((array) ($model['clickbait_phrases'] ?? []) as $issue) {
                $score = max(0, $score - 10);
                $detail .= (string) $issue . '; ';
                $suggestedFixScope = 'title';
            }
        } elseif ($gateName === 'not_monotonic_matrix') {
            foreach ((array) ($model['language_issues'] ?? []) as $issue) {
                if (str_contains(mb_strtolower((string) $issue), 'matryca') || str_contains(mb_strtolower((string) $issue), 'schemat')) {
                    $score = max(0, $score - 15);
                    $detail .= (string) $issue . '; ';
                    $suggestedFixScope = 'narrative_plan';
                }
            }
        }
        $softGates[] = [
            'gate_name' => $gateName,
            'score' => $score,
            'detail' => rtrim($detail, '; '),
            'suggested_fix_scope' => $suggestedFixScope,
        ];
    }

    return [
        'qc_id' => (int) ($check['id'] ?? 0),
        'article_id' => (int) ($check['post_id'] ?? 0),
        'iteration' => (int) ($check['check_number'] ?? 1),
        'hard_gates' => $hardGates,
        'soft_gates' => $softGates,
        'model_score' => (int) ($validation['model_score'] ?? 0),
        'final_score' => (int) ($validation['final_score'] ?? 0),
        'passed' => (bool) ($validation['passed'] ?? false),
        'convergence_check' => (bool) ($check['convergence_active'] ?? false),
    ];
}

/** Freeze accepted artifacts after a successful QC iteration. */
function qc_freeze_accepted_artifacts(int $draftVersionId, bool $convergenceActive = false): void
{
    $database = bueno_database();
    /* In convergence mode, all 'accepted' become 'frozen'. */
    if ($convergenceActive) {
        $database->prepare(
            'UPDATE article_draft_versions SET status = "frozen", updated_at = CURRENT_TIMESTAMP
             WHERE post_id = (SELECT post_id FROM article_draft_versions WHERE id = :draft_id)
             AND status = "accepted"'
        )->execute([':draft_id' => $draftVersionId]);
    } else {
        /* Normal mode: only the current draft version becomes frozen after passing QC. */
        $database->prepare(
            'UPDATE article_draft_versions SET status = "frozen", updated_at = CURRENT_TIMESTAMP
             WHERE id = :draft_id AND status IN ("accepted", "completed")'
        )->execute([':draft_id' => $draftVersionId]);
    }
}

/** Check whether an artifact is frozen and must not be modified. */
function qc_is_artifact_frozen(int $draftVersionId): bool
{
    $statement = bueno_database()->prepare(
        'SELECT status FROM article_draft_versions WHERE id = :id'
    );
    $statement->execute([':id' => $draftVersionId]);
    $row = $statement->fetch();

    return is_array($row) && (string) ($row['status'] ?? '') === 'frozen';
}

/** Auditable P03 lock: the frozen accepted version and its canonical core hash are the source of truth. */
function core_text_lock_state(int $draftVersionId): array
{
    $draft = find_article_draft_by_id($draftVersionId);
    $locked = is_array($draft) && (string) ($draft['status'] ?? '') === 'frozen';
    return ['core_text_locked' => $locked, 'draft_version_id' => $draftVersionId,
        'locked_at' => $locked ? (string) ($draft['updated_at'] ?? '') : null,
        'core_hash' => $locked ? hash('sha256', (string) ($draft['draft_json'] ?? '')) : null];
}

function core_text_operation_allowed(string $operation): bool
{
    return in_array($operation, ['caption', 'sidebar', 'context_block', 'explainer', 'comparison_block', 'reader_attention_note', 'additive_related_module', 'transition_paragraph', 'targeted_correction'], true);
}

function final_multimodal_qc_mock_generation_value(): array
{
    return ['scores'=>array_fill_keys(['text_quality','factual_consistency','source_coverage','hero_fit','image_section_alignment','visual_completeness','related_module_naturalness','layout_coherence','reader_flow','editorial_value'], 9),
        'decision'=>'PASS','notes'=>[],'allowed_repair_operations'=>[],'justification'=>'Deterministyczna atrapa potwierdza kompletny pakiet redakcyjny.'];
}

function quality_check_mock_generation_value(): array
{
    $scores = quality_score_rubric();
    return [
        'scores' => $scores,
        'total_score' => array_sum($scores),
        'title_supported' => true,
        'has_primary_source' => true,
        'unsupported_claims' => [],
        'false_quotes' => [],
        'unsupported_tests' => [],
        'clickbait_phrases' => [],
        'similarity' => ['level' => 'low', 'explanation' => 'Brak wysokiego podobieństwa w lokalnej atrapie.'],
        'risk_flags' => [],
        'missing_elements' => [],
        'language_issues' => [],
        'original_value' => 'Szkic porządkuje fakty z zatwierdzonego researchu.',
        'justification' => 'Lokalna atrapa sprawdza przepływ techniczny kontroli jakości.',
        'recommendation' => 'pass',
    ];
}
