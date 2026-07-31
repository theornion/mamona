<?php

declare(strict_types=1);

const QUALITY_PASS_SCORE = 75;
const QUALITY_SCORE_TOTAL = 100;

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
    if ($context === null || $context['status'] !== 'completed') {
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
        'draft_version_id' => $draftVersionId,
        'draft' => $draft,
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'registered_post_sources' => $postSources,
        'instructions' => [
            'Oceń wyłącznie przekazany szkic względem paczki researchowej i źródeł.',
            ...quality_score_instructions(),
            'Zgłoś każdy fakt bez podstawy, fałszywy cytat, deklarowany test bez dowodu, clickbait i ryzyko.',
            'Wysokie podobieństwo oznacza kopiowanie lub bardzo bliską parafrazę cudzej publikacji.',
            'Sprawdź, czy długość treści głównej jest zgodna z polityką szkicu oraz czy tekst nie osiąga jej przez lanie wody, powtórzenia lub sztuczne rozwlekanie.',
            'W złożonym trybie 4000 znaków jest dolną granicą, nie celem; pełne, wartościowe wyjaśnienie może i powinno być dłuższe.',
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

function deterministic_quality_checks(array $operation): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $draft = (array) $input['draft'];
    $research = (array) $input['research_package'];
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

    $claimText = (string) ($research['event_summary']['text'] ?? '');
    foreach ((array) ($research['claims'] ?? []) as $claim) {
        $claimText .= ' ' . (string) ($claim['claim'] ?? '');
    }
    $titleTokens = array_unique(quality_tokens((string) ($draft['title'] ?? '')));
    $claimTokens = array_unique(quality_tokens($claimText));
    if ($titleTokens === [] || count(array_intersect($titleTokens, $claimTokens)) === 0) {
        $blocks['unsupported_title_fact'] = [
            'code' => 'unsupported_title_fact',
            'message' => 'Tytuł nie ma rozpoznawalnej podstawy w twierdzeniach researchu.',
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
    if (((array) ($draft['key_facts'] ?? [])) === [] || trim((string) ($draft['why_important']['text'] ?? '')) === '') {
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
        'unsupported_title_fact' => ($result['title_supported'] ?? true) === false,
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

function assert_post_quality_allows_publication(int $postId): void
{
    $draftStatement = bueno_database()->prepare(
        'SELECT * FROM article_draft_versions
         WHERE post_id = :post_id AND status = "completed"
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
