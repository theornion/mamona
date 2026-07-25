<?php

declare(strict_types=1);

const ARTICLE_COMPOSITION_MODES = ['informational', 'problem_discovery_return'];
const ARTICLE_MAIN_CONTENT_MIN_LENGTH = 2000;
const ARTICLE_COMPLEX_MAIN_CONTENT_MIN_LENGTH = 3000;
const ARTICLE_MAIN_CONTENT_MAX_LENGTH = 4000;

function article_draft_length_policy(string $compositionMode): array
{
    if (!in_array($compositionMode, ARTICLE_COMPOSITION_MODES, true)) {
        throw new InvalidArgumentException('Nieprawidłowy tryb kompozycji szkicu.');
    }
    $complex = $compositionMode === 'problem_discovery_return';

    return [
        'scope' => 'main_content',
        'complex' => $complex,
        'minimum_characters' => $complex
            ? ARTICLE_COMPLEX_MAIN_CONTENT_MIN_LENGTH
            : ARTICLE_MAIN_CONTENT_MIN_LENGTH,
        'maximum_characters' => ARTICLE_MAIN_CONTENT_MAX_LENGTH,
    ];
}

function article_draft_main_content_texts(array $draft): array
{
    $texts = [];
    $append = static function (mixed $value) use (&$texts): void {
        $text = trim(strip_tags((string) $value));
        if ($text !== '') {
            $texts[] = $text;
        }
    };
    $append($draft['lead']['text'] ?? '');
    $append($draft['why_important']['text'] ?? '');
    foreach ((array) ($draft['key_facts'] ?? []) as $fact) {
        $append($fact['text'] ?? '');
    }
    $append($draft['comparison_context']['text'] ?? '');
    foreach ((array) ($draft['unknowns'] ?? []) as $unknown) {
        $append($unknown['text'] ?? '');
    }
    $append($draft['practical_takeaway']['text'] ?? '');
    foreach ((array) ($draft['narrative'] ?? []) as $section) {
        $append($section['text'] ?? '');
    }

    return $texts;
}

function article_draft_main_content_length(array $draft): int
{
    return mb_strlen(implode("\n\n", article_draft_main_content_texts($draft)));
}

function article_draft_repeated_sentence(array $draft): ?string
{
    $seen = [];
    foreach (article_draft_main_content_texts($draft) as $text) {
        $sentences = preg_split('/(?<=[.!?])\s+|\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($sentences as $sentence) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence));
            if (mb_strlen($normalized) < 40) {
                continue;
            }
            if (isset($seen[$normalized])) {
                return $sentence;
            }
            $seen[$normalized] = true;
        }
    }

    return null;
}

function article_draft_reference_schema(array $sourceIds, array $claimIds): array
{
    return [
        'type' => 'object',
        'properties' => [
            'text' => ['type' => 'string'],
            'claim_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $claimIds],
            ],
            'source_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $sourceIds],
            ],
        ],
        'required' => ['text', 'claim_ids', 'source_ids'],
        'additionalProperties' => false,
    ];
}

function article_draft_schema(array $sourceIds, array $claimIds, string $compositionMode): array
{
    $section = article_draft_reference_schema($sourceIds, $claimIds);
    $narrativeProperties = [];
    foreach ([
        'opening_question',
        'pursuit',
        'topic_b',
        'apparent_dead_end',
        'return_to_topic_a',
        'close_topic_b',
        'answer_and_punchline',
    ] as $key) {
        $narrativeProperties[$key] = $section;
    }

    return [
        'type' => 'object',
        'properties' => [
            'composition_mode' => ['type' => 'string', 'enum' => [$compositionMode]],
            'title' => ['type' => 'string'],
            'lead' => $section,
            'why_important' => $section,
            'key_facts' => ['type' => 'array', 'items' => $section],
            'comparison_context' => $section,
            'unknowns' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'research_unknown_indexes' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required' => ['text', 'research_unknown_indexes'],
                    'additionalProperties' => false,
                ],
            ],
            'practical_takeaway' => $section,
            'seo_description' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'image_alt' => ['type' => 'string'],
            'used_source_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $sourceIds],
            ],
            'narrative' => [
                'type' => 'object',
                'properties' => $narrativeProperties,
                'required' => array_keys($narrativeProperties),
                'additionalProperties' => false,
            ],
        ],
        'required' => [
            'composition_mode',
            'title',
            'lead',
            'why_important',
            'key_facts',
            'comparison_context',
            'unknowns',
            'practical_takeaway',
            'seo_description',
            'category',
            'image_alt',
            'used_source_ids',
            'narrative',
        ],
        'additionalProperties' => false,
    ];
}

function find_research_package(int $packageId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT packages.*, operations.input_json AS research_input_json
         FROM research_packages AS packages
         INNER JOIN generation_operations AS operations
            ON operations.id = packages.generation_operation_id
         WHERE packages.id = :id'
    );
    $statement->execute([':id' => $packageId]);
    $package = $statement->fetch();

    return is_array($package) ? $package : null;
}

function list_approved_research_packages(int $limit = 500): array
{
    $statement = bueno_database()->prepare(
        'SELECT packages.*, topics.title AS topic_title
         FROM research_packages AS packages
         INNER JOIN editorial_topics AS topics ON topics.id = packages.topic_id
         WHERE packages.status = "approved"
         ORDER BY packages.approved_at DESC, packages.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function approve_research_package(int $packageId): void
{
    $package = find_research_package($packageId);
    if ($package === null || $package['status'] !== 'completed') {
        throw new RuntimeException('Do zatwierdzenia wymagana jest ukończona paczka researchowa.');
    }
    $validation = json_decode((string) $package['validation_json'], true, 128, JSON_THROW_ON_ERROR);
    $research = json_decode((string) $package['package_json'], true, 128, JSON_THROW_ON_ERROR);
    if (($validation['valid'] ?? false) !== true || ($research['recommendation']['decision'] ?? '') !== 'continue') {
        throw new RuntimeException('Nie można zatwierdzić researchu odrzuconego lub niepoprawnego.');
    }
    bueno_database()->prepare(
        'UPDATE research_packages
         SET status = "approved", approved_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = "completed"'
    )->execute([':id' => $packageId]);
}

function resolve_article_composition_mode(array $research, string $requestedMode): string
{
    if (!in_array($requestedMode, ARTICLE_COMPOSITION_MODES, true)) {
        throw new InvalidArgumentException('Nieprawidłowy tryb kompozycji szkicu.');
    }
    if (
        $requestedMode === 'problem_discovery_return'
        && (array) ($research['comparisons'] ?? []) === []
        && count((array) ($research['claims'] ?? [])) < 2
    ) {
        return 'informational';
    }

    return $requestedMode;
}

function prepare_article_draft_operation(int $researchPackageId, string $compositionMode): int
{
    $package = find_research_package($researchPackageId);
    if ($package === null || $package['status'] !== 'approved') {
        throw new RuntimeException('Szkic można utworzyć wyłącznie z zatwierdzonej paczki researchowej.');
    }
    $research = json_decode((string) $package['package_json'], true, 128, JSON_THROW_ON_ERROR);
    $researchInput = json_decode((string) $package['research_input_json'], true, 128, JSON_THROW_ON_ERROR);
    if (($research['recommendation']['decision'] ?? '') !== 'continue') {
        throw new RuntimeException('Odrzucona paczka researchowa nie może być podstawą szkicu.');
    }
    $compositionMode = resolve_article_composition_mode($research, $compositionMode);
    $lengthPolicy = article_draft_length_policy($compositionMode);
    $sourceIds = array_values(array_map(
        static fn (array $source): string => (string) $source['source_id'],
        (array) ($researchInput['numbered_sources'] ?? [])
    ));
    $claimIds = array_values(array_map(
        static fn (array $claim): string => (string) $claim['claim_id'],
        (array) ($research['claims'] ?? [])
    ));
    if ($sourceIds === [] || $claimIds === []) {
        throw new RuntimeException('Zatwierdzony research nie zawiera źródeł i twierdzeń potrzebnych do szkicu.');
    }
    $input = [
        'research_package_id' => $researchPackageId,
        'composition_mode' => $compositionMode,
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'length_requirements' => [
            ...$lengthPolicy,
            'measurement' => 'Liczba znaków tekstu głównego: lead, znaczenie, fakty, kontekst porównawczy, niewiadome, wniosek praktyczny i — jeśli używana — narracja. Bez tytułu, SEO, kategorii, altu i metadanych.',
            'quality' => 'Osiągnij zakres konkretnym, logicznie uporządkowanym wyjaśnieniem. Nie używaj powtórzeń, lania wody ani sztucznego wydłużania.',
            'complex_guidance' => $lengthPolicy['complex']
                ? '3000 znaków to wyłącznie dolna granica. Gdy research dostarcza wartościowego materiału, wyjaśnij temat szerzej i naturalnie przekrocz minimum; nie skracaj mechanicznie do 3000 znaków.'
                : 'Nie rozciągaj prostego tematu ponad ilość wartościowego materiału, ale przedstaw go kompletnie w wymaganym zakresie.',
        ],
        'editorial_requirements' => [
            'Napisz naturalny tekst po polsku, zoptymalizowany do czytania na telefonie.',
            'Nie kopiuj zdań ze źródeł. Parafrazuj, zachowując znaczenie i przypisania.',
            'Nie dodawaj testów, cytatów, plotek ani osobistych doświadczeń, których nie ma w researchu.',
            'Lead od razu odpowiada, co się wydarzyło; nie ukrywaj podstawowej informacji dla napięcia.',
            'Nie używaj sensacyjnych obietnic nieobecnych w paczce.',
            'Każda sekcja faktograficzna wskazuje claim_ids i source_ids z paczki.',
            'used_source_ids zawiera dokładnie wszystkie źródła wykorzystane w szkicu.',
        ],
        'composition_requirements' => $compositionMode === 'problem_discovery_return'
            ? [
                'Wypełnij siedem pól narrative w kolejności: pytanie, dążenie, temat B, ślepa uliczka, powrót do A, domknięcie B, odpowiedź i puenta.',
                'Pytanie, temat B oraz puenta muszą wynikać z przypisanych twierdzeń i źródeł.',
                'Temat B ma pomagać zrozumieć A, nie dominować tekstu i zostać domknięty.',
                'Rozwiń wszystkie wartościowe zależności obecne w researchu; minimum 3000 znaków nie jest docelową długością ani powodem do skracania pełnego wyjaśnienia.',
            ]
            : [
                'Przekaż najważniejszą odpowiedź od razu, a dalszą treść uporządkuj bez powtórzeń i pustych zdań.',
                'Wszystkie pola narrative pozostaw puste: text jako pusty string, claim_ids i source_ids jako puste tablice.',
            ],
    ];
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $versionStatement = $database->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1
             FROM article_draft_versions WHERE research_package_id = :package_id'
        );
        $versionStatement->execute([':package_id' => $researchPackageId]);
        $versionNumber = (int) $versionStatement->fetchColumn();
        $operationId = prepare_generation_operation(
            'article_draft',
            $input,
            article_draft_schema($sourceIds, $claimIds, $compositionMode),
            (int) $package['post_id'],
            (int) $package['topic_id']
        );
        $database->prepare(
            'INSERT INTO article_draft_versions (
                research_package_id, topic_id, post_id, generation_operation_id,
                version_number, composition_mode, execution_mode
             ) VALUES (
                :package_id, :topic_id, :post_id, :operation_id,
                :version_number, :composition_mode, :execution_mode
             )'
        )->execute([
            ':package_id' => $researchPackageId,
            ':topic_id' => (int) $package['topic_id'],
            ':post_id' => (int) $package['post_id'],
            ':operation_id' => $operationId,
            ':version_number' => $versionNumber,
            ':composition_mode' => $compositionMode,
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

function article_draft_assert_references(
    array $section,
    array $knownClaims,
    array $knownSources,
    string $path,
    bool $allowEmpty = false,
    bool $requireClaim = true
): array {
    $text = trim((string) ($section['text'] ?? ''));
    $claimIds = array_values(array_unique((array) ($section['claim_ids'] ?? [])));
    $sourceIds = array_values(array_unique((array) ($section['source_ids'] ?? [])));
    if ($text === '') {
        if (!$allowEmpty || $claimIds !== [] || $sourceIds !== []) {
            throw new InvalidArgumentException("{$path} nie może być pusty.");
        }
        return [];
    }
    if ($sourceIds === []) {
        throw new InvalidArgumentException("{$path} nie wskazuje źródła.");
    }
    if ($requireClaim && $claimIds === []) {
        throw new InvalidArgumentException("{$path} nie wskazuje twierdzenia z researchu.");
    }
    foreach ($claimIds as $claimId) {
        if (!is_string($claimId) || !isset($knownClaims[$claimId])) {
            throw new InvalidArgumentException("{$path} wskazuje nieznane twierdzenie.");
        }
    }
    foreach ($sourceIds as $sourceId) {
        if (!is_string($sourceId) || !isset($knownSources[$sourceId])) {
            throw new InvalidArgumentException("{$path} wskazuje nieznane źródło.");
        }
    }
    if ($claimIds !== []) {
        $claimSources = [];
        foreach ($claimIds as $claimId) {
            foreach ((array) $knownClaims[$claimId]['source_ids'] as $sourceId) {
                $claimSources[$sourceId] = true;
            }
        }
        foreach ($sourceIds as $sourceId) {
            if (!isset($claimSources[$sourceId])) {
                throw new InvalidArgumentException("{$path} przypisuje twierdzenie do niewłaściwego źródła.");
            }
        }
    }

    return $sourceIds;
}

function article_draft_assert_not_copied(string $text, array $knownSources, string $path): void
{
    $normalized = research_normalize_evidence($text);
    if (mb_strlen($normalized) < 120) {
        return;
    }
    foreach ($knownSources as $source) {
        $material = research_normalize_evidence((string) $source['material']);
        if ($material !== '' && str_contains($material, $normalized)) {
            throw new InvalidArgumentException("{$path} kopiuje długi fragment materiału źródłowego.");
        }
    }
}

function validate_article_draft_output(array $operation, array $draft): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $research = (array) ($input['research_package'] ?? []);
    $knownClaims = [];
    foreach ((array) ($research['claims'] ?? []) as $claim) {
        $knownClaims[(string) $claim['claim_id']] = $claim;
    }
    $knownSources = [];
    foreach ((array) ($input['numbered_sources'] ?? []) as $source) {
        $knownSources[(string) $source['source_id']] = $source;
    }
    $mode = (string) ($input['composition_mode'] ?? '');
    if (($draft['composition_mode'] ?? '') !== $mode || !in_array($mode, ARTICLE_COMPOSITION_MODES, true)) {
        throw new InvalidArgumentException('Wynik zmienił wybrany tryb kompozycji.');
    }
    $lengthPolicy = article_draft_length_policy($mode);
    $contentLength = article_draft_main_content_length($draft);
    if ($contentLength < $lengthPolicy['minimum_characters'] || $contentLength > $lengthPolicy['maximum_characters']) {
        throw new InvalidArgumentException(
            'Treść główna szkicu ma ' . $contentLength . ' znaków; dla trybu ' . $mode
            . ' wymagany jest zakres ' . $lengthPolicy['minimum_characters']
            . '–' . $lengthPolicy['maximum_characters'] . ' znaków.'
        );
    }
    $repeatedSentence = article_draft_repeated_sentence($draft);
    if ($repeatedSentence !== null) {
        throw new InvalidArgumentException(
            'Treść główna powtarza to samo zdanie i nie może osiągać wymaganej długości przez duplikowanie treści.'
        );
    }
    foreach (['title', 'seo_description', 'category', 'image_alt'] as $field) {
        if (trim((string) ($draft[$field] ?? '')) === '') {
            throw new InvalidArgumentException("Pole {$field} nie może być puste.");
        }
    }
    $usedSources = [];
    foreach (['lead', 'why_important', 'comparison_context', 'practical_takeaway'] as $field) {
        $allowEmpty = $field === 'comparison_context';
        foreach (article_draft_assert_references(
            (array) ($draft[$field] ?? []),
            $knownClaims,
            $knownSources,
            '$.' . $field,
            $allowEmpty,
            $field !== 'comparison_context'
        ) as $sourceId) {
            $usedSources[$sourceId] = true;
        }
        article_draft_assert_not_copied((string) ($draft[$field]['text'] ?? ''), $knownSources, '$.' . $field);
    }
    if (((array) ($draft['key_facts'] ?? [])) === []) {
        throw new InvalidArgumentException('Szkic musi zawierać co najmniej jeden najważniejszy fakt.');
    }
    foreach ((array) $draft['key_facts'] as $index => $fact) {
        foreach (article_draft_assert_references(
            (array) $fact,
            $knownClaims,
            $knownSources,
            "$.key_facts[{$index}]"
        ) as $sourceId) {
            $usedSources[$sourceId] = true;
        }
        article_draft_assert_not_copied((string) ($fact['text'] ?? ''), $knownSources, "$.key_facts[{$index}]");
    }
    $researchUnknowns = (array) ($research['unknowns'] ?? []);
    foreach ((array) ($draft['unknowns'] ?? []) as $index => $unknown) {
        if (trim((string) ($unknown['text'] ?? '')) === '') {
            throw new InvalidArgumentException("$.unknowns[{$index}] nie może być pusty.");
        }
        $indexes = array_values(array_unique((array) ($unknown['research_unknown_indexes'] ?? [])));
        if ($indexes === []) {
            throw new InvalidArgumentException("$.unknowns[{$index}] nie wskazuje niewiadomej z researchu.");
        }
        foreach ($indexes as $unknownIndex) {
            if (!is_int($unknownIndex) || !array_key_exists($unknownIndex, $researchUnknowns)) {
                throw new InvalidArgumentException("$.unknowns[{$index}] wskazuje nieznaną niewiadomą.");
            }
        }
    }
    $narrativeKeys = [
        'opening_question',
        'pursuit',
        'topic_b',
        'apparent_dead_end',
        'return_to_topic_a',
        'close_topic_b',
        'answer_and_punchline',
    ];
    foreach ($narrativeKeys as $key) {
        $sectionSources = article_draft_assert_references(
            (array) ($draft['narrative'][$key] ?? []),
            $knownClaims,
            $knownSources,
            '$.narrative.' . $key,
            $mode === 'informational'
        );
        foreach ($sectionSources as $sourceId) {
            $usedSources[$sourceId] = true;
        }
    }
    $declaredSources = array_values(array_unique((array) ($draft['used_source_ids'] ?? [])));
    sort($declaredSources);
    $actualSources = array_keys($usedSources);
    sort($actualSources);
    if ($declaredSources === [] || $declaredSources !== $actualSources) {
        throw new InvalidArgumentException('used_source_ids nie odpowiada dokładnie źródłom wykorzystanym w szkicu.');
    }
    article_draft_assert_not_copied((string) $draft['title'], $knownSources, '$.title');

    return [
        'valid' => true,
        'composition_mode' => $mode,
        'claim_reference_count' => count($knownClaims),
        'used_source_count' => count($usedSources),
        'key_fact_count' => count((array) $draft['key_facts']),
        'unknown_count' => count((array) $draft['unknowns']),
        'main_content_character_count' => $contentLength,
        'main_content_minimum' => $lengthPolicy['minimum_characters'],
        'main_content_maximum' => $lengthPolicy['maximum_characters'],
    ];
}

function persist_completed_article_draft(int $operationId, array $draft, array $validation): void
{
    $statement = bueno_database()->prepare(
        'UPDATE article_draft_versions
         SET status = "completed", draft_json = :draft_json,
             validation_json = :validation_json,
             completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([
        ':draft_json' => generation_json($draft),
        ':validation_json' => generation_json($validation),
        ':operation_id' => $operationId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Nie znaleziono wersji szkicu.');
    }
}

function mark_article_draft_failed(int $operationId, string $errorMessage): void
{
    bueno_database()->prepare(
        'UPDATE article_draft_versions
         SET status = "failed",
             validation_json = :validation_json,
             updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    )->execute([
        ':validation_json' => generation_json(['valid' => false, 'error' => mb_substr($errorMessage, 0, 2000)]),
        ':operation_id' => $operationId,
    ]);
}

function find_article_draft_by_operation(int $operationId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_draft_versions WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([':operation_id' => $operationId]);
    $draft = $statement->fetch();

    return is_array($draft) ? $draft : null;
}

function list_article_draft_versions(int $limit = 500): array
{
    $statement = bueno_database()->prepare(
        'SELECT drafts.*, topics.title AS topic_title
         FROM article_draft_versions AS drafts
         INNER JOIN editorial_topics AS topics ON topics.id = drafts.topic_id
         ORDER BY drafts.id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function article_draft_mock_generation_value(array $operation): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $research = (array) $input['research_package'];
    $claim = (array) $research['claims'][0];
    $claimId = (string) $claim['claim_id'];
    $sourceId = (string) $claim['source_ids'][0];
    $section = static fn (string $text): array => [
        'text' => $text,
        'claim_ids' => [$claimId],
        'source_ids' => [$sourceId],
    ];
    $empty = ['text' => '', 'claim_ids' => [], 'source_ids' => []];
    $mode = (string) $input['composition_mode'];
    $narrative = [];
    foreach ([
        'opening_question',
        'pursuit',
        'topic_b',
        'apparent_dead_end',
        'return_to_topic_a',
        'close_topic_b',
        'answer_and_punchline',
    ] as $key) {
        $narrative[$key] = $mode === 'informational'
            ? $empty
            : $section('Kontrolowana część narracji ' . $key . ' oparta na twierdzeniu ' . $claimId . '.');
    }

    $draft = [
        'composition_mode' => $mode,
        'title' => mb_substr((string) $claim['claim'], 0, 100),
        'lead' => $section('To lokalny szkic służący do sprawdzenia przepływu technicznego.'),
        'why_important' => $section('Znaczenie wynika z zatwierdzonego twierdzenia researchowego.'),
        'key_facts' => [$section('Najważniejszy fakt pochodzi z przypisanego źródła.')],
        'comparison_context' => $empty,
        'unknowns' => isset($research['unknowns'][0])
            ? [['text' => (string) $research['unknowns'][0], 'research_unknown_indexes' => [0]]]
            : [],
        'practical_takeaway' => $section('Czytelnik powinien traktować ten wynik jako test techniczny.'),
        'seo_description' => 'Lokalny szkic testowy systemu generowania artykułu z zatwierdzonego researchu.',
        'category' => 'how-it-works',
        'image_alt' => 'Ilustracja lokalnego testu generowania szkicu',
        'used_source_ids' => [$sourceId],
        'narrative' => $narrative,
    ];
    $policy = article_draft_length_policy($mode);
    $index = 1;
    while (article_draft_main_content_length($draft) < $policy['minimum_characters']) {
        $draft['practical_takeaway']['text'] .= ' Techniczny kontekst ' . $index
            . ' opisuje zakres danych, ich znaczenie, ograniczenia interpretacji oraz sposób zachowania przypisań do zatwierdzonego twierdzenia.';
        $index++;
    }

    return $draft;
}
