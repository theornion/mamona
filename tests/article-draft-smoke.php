<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_ARTICLE_DRAFT_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_ARTICLE_DRAFT_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function draft_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function draft_smoke_section(string $text, string $claimId, string $sourceId): array
{
    return ['text' => $text, 'claim_ids' => [$claimId], 'source_ids' => [$sourceId]];
}

function draft_smoke_replace_selected_title(array $draft, string $title): array
{
    $draft['title'] = $title;
    foreach ($draft['title_variants'] as $index => $variant) {
        $draft['title_variants'][$index]['selected'] = $index === 0;
    }
    $draft['title_variants'][0]['title'] = $title;

    return $draft;
}

function draft_smoke_output(string $mode, string $claimId, string $sourceId, string $suffix): array
{
    $section = static fn (string $text): array => draft_smoke_section($text . ' ' . $suffix, $claimId, $sourceId);
    $empty = ['text' => '', 'claim_ids' => [], 'source_ids' => []];
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
            : $section('Część narracji ' . $key . ' oparta na zatwierdzonym fakcie:');
    }

    $draft = [
        'composition_mode' => $mode,
        'title' => 'Kontrolowany pomiar ujawnia znaczenie i ograniczenia danych',
        'brief' => 'Za kontrolowanym wynikiem kryje się szerszy mechanizm, którego znaczenie pokaże dopiero uporządkowanie dostępnych danych.',
        'lead' => $section('Lead od razu opisuje kontrolowane wydarzenie.'),
        'why_important' => $section('Znaczenie wynika z udokumentowanego pomiaru.'),
        'key_facts' => [
            $section('Pierwszy najważniejszy fakt ma przypisane źródło.'),
            $section('Drugi fakt rozwija udokumentowane znaczenie wyniku.'),
            $section('Trzeci fakt porządkuje ograniczenia dostępnego materiału.'),
        ],
        'comparison_context' => $empty,
        'unknowns' => [[
            'text' => 'Nie jest jeszcze znany pełny zestaw danych. ' . $suffix,
            'research_unknown_indexes' => [0],
        ]],
        'practical_takeaway' => $section('Czytelnik powinien poczekać na pełne dane.'),
        'seo_description' => 'Opis SEO kontrolowanego szkicu o udokumentowanym wydarzeniu i jego znaczeniu. ' . $suffix,
        'category' => 'how-it-works',
        'image_alt' => 'Schemat kontrolowanego pomiaru w laboratorium ' . $suffix,
        'used_source_ids' => [$sourceId],
        'narrative' => $narrative,
    ];
    $draft = [...$draft, ...build_article_title_strategy_fixture((string) $draft['title'])];
    $policy = article_draft_length_policy($mode);
    $index = 1;
    while (article_draft_main_content_length($draft) < $policy['minimum_characters']) {
        $draft['practical_takeaway']['text'] .= ' Kontekst testowy ' . $index
            . ' porządkuje znaczenie wyniku, zakres dostępnych danych, ograniczenia interpretacji i praktyczne konsekwencje opisane w źródle.';
        $index++;
    }
    $draft['illustration_plan'] = build_planned_illustration_fixture($draft);

    return $draft;
}

$database = bueno_database();
$originalMode = generation_mode();
$baselineFeedItems = (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn();
$token = bin2hex(random_bytes(6));
$sourceId = 0;
$postId = 0;
$operationIds = [];

try {
    $sourceId = save_technical_source([
        'name' => 'Draft source ' . $token,
        'website_url' => 'https://draft-' . $token . '.example.org/',
        'feed_url' => 'https://draft-' . $token . '.example.org/feed.xml',
        'source_type' => 'rss',
        'topic_category' => 'physics',
        'language' => 'en',
        'credibility_level' => 5,
        'is_primary' => 1,
        'is_active' => 0,
    ]);
    $source = find_technical_source($sourceId);
    $postId = persist_discovered_feed_item($source, [
        'external_id' => 'draft-' . $token,
        'source_url' => 'https://draft-' . $token . '.example.org/result',
        'title' => 'Laboratory reports a controlled measurement ' . $token,
        'source_name' => $source['name'],
        'published_at' => gmdate('Y-m-d H:i:s'),
        'summary' => 'Badacze opisali kontrolowany pomiar i ograniczenia dostępnego zestawu danych.',
        'category' => 'physics',
        'content_hash' => hash('sha256', $token),
    ]);
    draft_smoke_assert(is_int($postId) && $postId > 0, 'Nie utworzono testowego pomysłu.');
    $membership = $database->prepare(
        'SELECT memberships.topic_id
         FROM discovered_feed_items AS items
         INNER JOIN feed_topic_memberships AS memberships ON memberships.feed_item_id = items.id
         WHERE items.post_id = :post_id'
    );
    $membership->execute([':post_id' => $postId]);
    $topicId = (int) $membership->fetchColumn();
    $postBefore = find_post($postId, true);

    update_generation_mode('manual');
    $researchOperationId = prepare_research_package_operation($topicId);
    $operationIds[] = $researchOperationId;
    $numberedSource = research_numbered_sources($topicId)[0];
    $researchOutput = [
        'event_summary' => ['text' => 'Źródło opisuje kontrolowany pomiar.', 'source_ids' => ['S1']],
        'claims' => [[
            'claim_id' => 'C1',
            'claim' => 'Laboratorium opisało kontrolowany pomiar.',
            'source_ids' => ['S1'],
            'evidence' => [['source_id' => 'S1', 'excerpt' => $numberedSource['title']]],
            'confidence' => 'high',
        ]],
        'shared_facts' => [],
        'contradictions' => [],
        'unknowns' => ['Nie jest znany pełny zestaw danych.'],
        'polish_context' => [],
        'comparisons' => [[
            'comparison' => 'Materiał pozwala zestawić wynik z ograniczeniami pomiaru.',
            'basis_source_ids' => ['S1'],
            'confidence' => 'medium',
        ]],
        'recommendation' => [
            'decision' => 'continue',
            'reason' => 'Źródło pierwotne zawiera fakt i materiał dowodowy.',
            'source_coverage' => 'sufficient',
        ],
    ];
    import_manual_generation_response($researchOperationId, generation_json($researchOutput));
    $researchPackage = find_research_package_by_operation($researchOperationId);
    $blocked = false;
    try {
        prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    } catch (Throwable $exception) {
        $blocked = str_contains($exception->getMessage(), 'zatwierdzonej');
    }
    draft_smoke_assert($blocked, 'Niezatwierdzony research pozwolił utworzyć szkic.');
    approve_research_package((int) $researchPackage['id']);

    $manualId = prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    $operationIds[] = $manualId;
    $manualOperation = find_generation_operation($manualId);
    draft_smoke_assert(
        str_contains((string) $manualOperation['prompt_text'], '"minimum_characters":3000')
        && str_contains((string) $manualOperation['prompt_text'], '"maximum_characters":7000')
        && str_contains((string) $manualOperation['prompt_text'], '"code":"pl-PL"')
        && str_contains((string) $manualOperation['prompt_text'], '"target_characters":"3400–3500"')
        && str_contains((string) $manualOperation['prompt_text'], '"required_inline_illustrations":3')
        && str_contains((string) $manualOperation['prompt_text'], '"required_inline_section_ids":["lead","why-important","fact-1"]')
        && str_contains((string) $manualOperation['prompt_text'], 'jedną ilustrację inline na 950–1050 znaków')
        && str_contains((string) $manualOperation['prompt_text'], 'Brief to jedno naturalne zdanie')
        && str_contains((string) $manualOperation['prompt_text'], 'title_variants')
        && str_contains((string) $manualOperation['prompt_text'], 'Nie uwierzysz')
        && str_contains((string) $manualOperation['prompt_text'], 'total_score')
        && str_contains((string) $manualOperation['prompt_text'], 'allowed_research_unknowns')
        && str_contains((string) $manualOperation['prompt_text'], 'research_unknown_indexes')
        && str_contains((string) $manualOperation['prompt_text'], 'lania wody'),
        'Prompt prostego szkicu nie zawiera języka oraz kompletnej polityki długości i jakości.'
    );
    $manualSchema = json_decode((string) $manualOperation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
    $titleVariantsSchema = (array) (($manualSchema['properties'] ?? [])['title_variants'] ?? []);
    $unknownIdSchema = (array) ($manualSchema['properties']['unknowns']['items']['properties']['research_unknown_indexes']['items'] ?? []);
    draft_smoke_assert(
        ($titleVariantsSchema['minItems'] ?? null) === 5
        && ($titleVariantsSchema['maxItems'] ?? null) === 8
        && in_array('title_selection_reason', (array) ($manualSchema['required'] ?? []), true),
        'Formalny schemat JSON nie wymusza kompletnej strategii wyboru tytułu.'
    );
    draft_smoke_assert(($unknownIdSchema['enum'] ?? null) === [0], 'Schemat nie ogranicza unknown_id do identyfikatorów przekazanych przez research.');
    $invalidUnknownSchemaValue = draft_smoke_output('informational', 'C1', 'S1', 'invalid-unknown');
    $invalidUnknownSchemaValue['unknowns'][0]['research_unknown_indexes'] = [99];
    try {
        validate_generation_value($invalidUnknownSchemaValue, $manualSchema);
        throw new RuntimeException('Nieznany unknown_id przeszedł walidację schematu.');
    } catch (InvalidArgumentException $exception) {
        draft_smoke_assert(str_contains($exception->getMessage(), '$.unknowns[0].research_unknown_indexes[0]'), 'Błąd unknown_id nie wskazuje JSONPath.');
    }
    $manualOutput = draft_smoke_output('informational', 'C1', 'S1', 'manual-v1');
    $titleValidation = validate_article_title_strategy($manualOutput, [
        'C1' => ['claim' => 'Laboratorium opisało kontrolowany pomiar.'],
    ]);
    draft_smoke_assert(
        $titleValidation['variant_count'] === 5
        && $titleValidation['selected_score'] === 46
        && $titleValidation['supported_title_tokens'] >= 2,
        'Tytuł zgodny z faktami i treścią nie przeszedł strategii wyboru.'
    );
    $preliminaryHeroMismatch = $manualOutput;
    foreach (['visual_intent', 'expected_content', 'alt', 'caption'] as $field) {
        $preliminaryHeroMismatch['illustration_plan']['hero'][$field] = 'Dokumentalna fotografia obiektu badawczego.';
    }
    $preliminaryHeroMismatch['illustration_plan']['hero']['search_queries'] = ['documentary research object photograph'];
    draft_smoke_assert(
        validate_article_title_strategy($preliminaryHeroMismatch, [
            'C1' => ['claim' => 'Laboratorium opisało kontrolowany pomiar.'],
        ])['variant_count'] === 5,
        'Preliminary hero został błędnie potraktowany jak finalny title/hero gate przed FinalVisualPlan.'
    );
    $forbiddenTitle = draft_smoke_replace_selected_title(
        $manualOutput,
        'Nie uwierzysz, co ujawnił kontrolowany pomiar'
    );
    try {
        validate_article_title_strategy($forbiddenTitle, [
            'C1' => ['claim' => 'Laboratorium opisało kontrolowany pomiar.'],
        ]);
        throw new RuntimeException('Zakazana pusta formuła clickbaitowa nie została odrzucona.');
    } catch (InvalidArgumentException $exception) {
        draft_smoke_assert(str_contains($exception->getMessage(), 'clickbait'), 'Nieoczekiwany błąd clickbaitu.');
    }
    $capsTitle = draft_smoke_replace_selected_title(
        $manualOutput,
        'KONTROLOWANY POMIAR UJAWNIA ZNACZENIE DANYCH'
    );
    try {
        validate_article_title_strategy($capsTitle, [
            'C1' => ['claim' => 'Laboratorium opisało kontrolowany pomiar.'],
        ]);
        throw new RuntimeException('Tytuł ALL CAPS nie został odrzucony.');
    } catch (InvalidArgumentException $exception) {
        draft_smoke_assert(str_contains($exception->getMessage(), 'wersalikami'), 'Nieoczekiwany błąd ALL CAPS.');
    }
    $punctuationTitle = draft_smoke_replace_selected_title(
        $manualOutput,
        'Kontrolowany pomiar ujawnia znaczenie dostępnych danych!!!'
    );
    try {
        validate_article_title_strategy($punctuationTitle, [
            'C1' => ['claim' => 'Laboratorium opisało kontrolowany pomiar.'],
        ]);
        throw new RuntimeException('Nadmiar interpunkcji nie został odrzucony.');
    } catch (InvalidArgumentException $exception) {
        draft_smoke_assert(str_contains($exception->getMessage(), 'interpunkcję'), 'Nieoczekiwany błąd interpunkcji.');
    }
    $weakerSelection = $manualOutput;
    $weakerSelection['title_variants'][0]['selected'] = false;
    $weakerSelection['title_variants'][1]['selected'] = true;
    $weakerSelection['title'] = $weakerSelection['title_variants'][1]['title'];
    try {
        validate_article_title_strategy($weakerSelection, [
            'C1' => ['claim' => 'Laboratorium opisało kontrolowany pomiar.'],
        ]);
        throw new RuntimeException('Słabszy wariant został wybrany mimo niższego wyniku.');
    } catch (InvalidArgumentException $exception) {
        draft_smoke_assert(str_contains($exception->getMessage(), 'najmocniejszym'), 'Nieoczekiwany błąd wyboru wariantu.');
    }
    $unsupportedSensational = draft_smoke_replace_selected_title(
        $manualOutput,
        'Kontrolowany pomiar dowodzi, że wynik zmieni życie każdego człowieka!'
    );
    try {
        validate_article_title_strategy($unsupportedSensational, [
            'C1' => ['claim_id'=>'C1','claim' => 'Laboratorium opisało kontrolowany pomiar.','source_ids'=>['S1']],
        ]);
        throw new RuntimeException('Nieuzasadniony sensacyjny tytuł nie został odrzucony.');
    } catch (InvalidArgumentException $exception) {
        draft_smoke_assert(
            str_contains($exception->getMessage(), 'więcej')
            || str_contains($exception->getMessage(), 'mocniejsze'),
            'Nieoczekiwany błąd sensacyjnej obietnicy.'
        );
        draft_smoke_assert(
            $exception instanceof ArticleTitleRepairException
            && $exception->diagnostics['code'] === 'unsupported_title_promise'
            && $exception->diagnostics['repair_scope'] === 'titles'
            && $exception->diagnostics['allowed_claim_ids'] === ['C1']
            && in_array('lead', $exception->diagnostics['preserved_fields'], true),
            'Błąd obietnicy tytułu nie ma strukturalnego kontraktu title-only repair.'
        );
    }
    $repair = $manualOutput;
    $repair['title_selection_reason'] = 'Nowe uzasadnienie pozostaje ściśle oparte na kontrolowanym pomiarze opisanym w tekście.';
    $repair['seo_description'] = 'Zmienione SEO bez fałszywej obietnicy.';
    $mergedRepair = merge_article_title_repair($unsupportedSensational, [
        'title'=>$manualOutput['title'], 'title_variants'=>$manualOutput['title_variants'],
        'title_selection_reason'=>$repair['title_selection_reason'], 'seo_title'=>$manualOutput['title'],
        'seo_description'=>$repair['seo_description'],
    ]);
    foreach (array_diff(array_keys($manualOutput), ['title','title_variants','title_selection_reason','seo_title','seo_description']) as $field) {
        draft_smoke_assert(serialize($mergedRepair[$field]) === serialize($unsupportedSensational[$field]), "Title repair zmienił zachowane pole {$field}.");
    }
    draft_smoke_assert($mergedRepair['illustration_plan'] === $unsupportedSensational['illustration_plan'], 'Title repair zmienił plan ilustracji.');
    $shortOutput = $manualOutput;
    $shortOutput['practical_takeaway']['text'] = 'Zbyt krótki wniosek.';
    draft_smoke_assert(
        article_draft_main_content_length($shortOutput) < ARTICLE_MAIN_CONTENT_MIN_LENGTH,
        'Fixture krótkiego szkicu nie jest krótsza od minimum.'
    );
    $shortRejected = false;
    try {
        import_manual_generation_response($manualId, generation_json($shortOutput));
    } catch (Throwable $exception) {
        $shortRejected = str_contains($exception->getMessage(), 'wymagany jest zakres 3000–7000');
    }
    draft_smoke_assert($shortRejected, 'Szkic krótszy niż 3000 znaków nie został odrzucony.');
    $longOutput = $manualOutput;
    $longOutput['practical_takeaway']['text'] .= str_repeat(' Nadmiarowa treść testowa.', 200);
    $longRejected = false;
    try {
        import_manual_generation_response($manualId, generation_json($longOutput));
    } catch (Throwable $exception) {
        $longRejected = str_contains($exception->getMessage(), 'wymagany jest zakres 3000–7000');
    }
    draft_smoke_assert($longRejected, 'Szkic dłuższy niż 7000 znaków nie został odrzucony.');
    $repeatedOutput = $manualOutput;
    $repeatedOutput['why_important']['text'] = $repeatedOutput['lead']['text'];
    $repetitionRejected = false;
    try {
        import_manual_generation_response($manualId, generation_json($repeatedOutput));
    } catch (Throwable $exception) {
        $repetitionRejected = str_contains($exception->getMessage(), 'powtarza to samo zdanie');
    }
    draft_smoke_assert($repetitionRejected, 'Powtórzone długie zdanie nie zostało odrzucone.');
    $englishRejected = false;
    try {
        article_draft_assert_polish_language([
            'lead' => ['text' => str_repeat('The source describes a controlled scientific result. ', 20)],
            'why_important' => ['text' => str_repeat('Researchers explain the result and its importance. ', 20)],
            'key_facts' => [['text' => str_repeat('Measurements support the reported conclusion. ', 20)]],
            'comparison_context' => ['text' => 'The available material provides context.'],
            'unknowns' => [['text' => 'Further measurements remain necessary.']],
            'practical_takeaway' => ['text' => str_repeat('Readers should consider the limitations of the evidence. ', 20)],
            'narrative' => [],
        ]);
    } catch (Throwable $exception) {
        $englishRejected = str_contains($exception->getMessage(), 'język polski');
    }
    draft_smoke_assert($englishRejected, 'Angielska treść nie została odrzucona.');
    draft_smoke_assert(
        find_generation_operation($manualId)['status'] === 'prepared',
        'Odrzucona odpowiedź zmieniła stan operacji przed poprawną regeneracją.'
    );
    import_manual_generation_response($manualId, generation_json($manualOutput));
    $manualDraft = find_article_draft_by_operation($manualId);
    draft_smoke_assert(
        $manualDraft['status'] === 'completed'
        && (int) $manualDraft['version_number'] === 1
        && $manualDraft['composition_mode'] === 'informational'
        && $manualDraft['execution_mode'] === 'manual',
        'Nie zapisano pierwszej manualnej wersji szkicu.'
    );

    $narrativeId = prepare_article_draft_operation((int) $researchPackage['id'], 'problem_discovery_return');
    $operationIds[] = $narrativeId;
    $narrativeOutput = draft_smoke_output('problem_discovery_return', 'C1', 'S1', 'manual-v2');
    $narrativeOperation = find_generation_operation($narrativeId);
    draft_smoke_assert(
        str_contains((string) $narrativeOperation['prompt_text'], '"minimum_characters":4000')
        && str_contains((string) $narrativeOperation['prompt_text'], 'dolna granica'),
        'Prompt złożonego szkicu nie podkreśla progu 4000 i pełnego rozwinięcia.'
    );
    import_manual_generation_response($narrativeId, generation_json($narrativeOutput));
    $narrativeDraft = find_article_draft_by_operation($narrativeId);
    draft_smoke_assert(
        (int) $narrativeDraft['version_number'] === 2
        && $narrativeDraft['composition_mode'] === 'problem_discovery_return'
        && article_draft_main_content_length($narrativeOutput) >= ARTICLE_COMPLEX_MAIN_CONTENT_MIN_LENGTH,
        'Nie zapisano narracyjnej wersji szkicu.'
    );

    update_generation_mode('api');
    $apiId = prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    $operationIds[] = $apiId;
    $apiOperation = find_generation_operation($apiId);
    draft_smoke_assert(
        $apiOperation['output_schema_json'] === $manualOperation['output_schema_json'],
        'Manual i API używają różnych schematów dla tego samego trybu.'
    );
    $apiOutput = draft_smoke_output('informational', 'C1', 'S1', 'api-v3');
    execute_generation_operation(
        $apiId,
        static fn (): array => [
            'status' => 200,
            'body' => generation_json([
                'responseId' => 'resp_draft_smoke',
                'candidates' => [[
                    'content' => ['parts' => [['text' => generation_json($apiOutput)]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 70, 'candidatesTokenCount' => 60, 'totalTokenCount' => 130],
            ]),
            'headers' => [],
            'network_error' => '',
        ],
        'smoke-secret-key'
    );
    $apiDraft = find_article_draft_by_operation($apiId);
    draft_smoke_assert(
        (int) $apiDraft['version_number'] === 3 && $apiDraft['execution_mode'] === 'api',
        'Regeneracja nie utworzyła trzeciej wersji API.'
    );
    draft_smoke_assert(
        json_decode((string) $manualDraft['draft_json'], true) === $manualOutput,
        'Regeneracja nadpisała wcześniejszą wersję.'
    );
    $activeBeforeOversizedRepair = $database->query(
        'SELECT id FROM article_draft_versions WHERE post_id=' . (int) $postId . ' AND is_active=1 ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN);
    $qcRepairId = prepare_article_qc_repair_operation(
        (int) $apiDraft['id'],
        ['id' => 987654],
        ['categories' => ['structure'], 'feedback' => ['Zachowaj strukturę i popraw pojedynczą uwagę.']],
        1
    );
    $operationIds[] = $qcRepairId;
    $qcRepairOperation = find_generation_operation($qcRepairId);
    $qcRepairInput = json_decode((string) $qcRepairOperation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $qcRepairPrompt = (string) $qcRepairOperation['prompt_text'];
    draft_smoke_assert(
        ($qcRepairInput['length_requirements']['minimum_characters'] ?? null) === 3000
        && ($qcRepairInput['length_requirements']['maximum_characters'] ?? null) === 7000
        && ($qcRepairInput['length_requirements']['target_characters'] ?? null) === '3400–3500'
        && isset($qcRepairInput['length_requirements']['measurement'], $qcRepairInput['length_requirements']['final_check'])
        && str_contains($qcRepairPrompt, 'minimum_characters')
        && str_contains($qcRepairPrompt, 'maximum_characters'),
        'Targeted QC repair nie dostał pełnego kontraktu 3000–7000 dla łącznej treści.'
    );
    draft_smoke_assert(
        ($qcRepairInput['qc_repair_contract_version'] ?? null) === ARTICLE_DRAFT_QC_REPAIR_CONTRACT_VERSION
        && prepare_article_qc_repair_operation(
            (int) $apiDraft['id'],
            ['id' => 987654],
            ['categories' => ['structure'], 'feedback' => ['Zachowaj strukturę i popraw pojedynczą uwagę.']],
            1
        ) === $qcRepairId,
        'Ten sam kontrakt targeted QC repair nie jest idempotentny.'
    );
    $repairVisualPlan = [
        'id' => 991,
        'visual_plan_json' => generation_json([
            'hero_slot' => [
                'slot_id' => 'hero-main', 'role' => 'hero', 'section_anchor' => 'article',
                'visual_need' => 'Teleskop i obserwowany obiekt', 'required' => true,
                'must_be_direct' => true, 'acceptable_related' => false,
                'search_queries_direct' => ['space telescope documentary photograph'],
                'search_queries_related' => [],
            ],
            'inline_slots' => [
                ['slot_id'=>'inline-lead','role'=>'inline','section_anchor'=>'lead','visual_need'=>'Instrument obserwacyjny','required'=>true,'must_be_direct'=>false,'acceptable_related'=>false,'search_queries_direct'=>['space observatory instrument'],'search_queries_related'=>[]],
                ['slot_id'=>'inline-why','role'=>'inline','section_anchor'=>'why-important','visual_need'=>'Dane pomiarowe','required'=>true,'must_be_direct'=>false,'acceptable_related'=>false,'search_queries_direct'=>['scientific measurement data'],'search_queries_related'=>[]],
                ['slot_id'=>'inline-fact','role'=>'inline','section_anchor'=>'fact-1','visual_need'=>'Schemat analizy','required'=>true,'must_be_direct'=>false,'acceptable_related'=>false,'search_queries_direct'=>['analysis diagram'],'search_queries_related'=>[]],
            ],
        ]),
    ];
    $apiInput = json_decode((string) $apiOperation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $apiInput['narrative_plan'] = $repairVisualPlan;
    $apiInput['draft_visual_plan_contract_version'] = ARTICLE_DRAFT_VISUAL_PLAN_CONTRACT_VERSION;
    $database->prepare('UPDATE generation_operations SET input_json=:input WHERE id=:id')->execute([
        ':input' => generation_json($apiInput), ':id' => $apiId,
    ]);
    $planRepairId = prepare_article_qc_repair_operation(
        (int) $apiDraft['id'], ['id' => 987655],
        ['categories' => ['structure'], 'feedback' => ['Zachowaj strukturę.']], 1
    );
    $operationIds[] = $planRepairId;
    $planRepairOperation = find_generation_operation($planRepairId);
    $planRepairInput = json_decode((string) $planRepairOperation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $planRepairSchema = json_decode((string) $planRepairOperation['output_schema_json'], true, 128, JSON_THROW_ON_ERROR);
    $expectedIllustrations = narrative_plan_draft_illustration_contract($repairVisualPlan)['illustration_plan'];
    draft_smoke_assert(
        ($planRepairInput['narrative_plan']['id'] ?? null) === 991
        && ($planRepairInput['draft_visual_plan_contract_version'] ?? null) === ARTICLE_DRAFT_VISUAL_PLAN_CONTRACT_VERSION
        && count((array) ($expectedIllustrations['inline'] ?? [])) === 3,
        'Korekta QC nie odziedziczyła VisualPlan źródłowego szkicu.'
    );
    validate_generation_value($expectedIllustrations, $planRepairSchema['properties']['illustration_plan']);
    $changedHero = $expectedIllustrations;
    $changedHero['hero']['search_queries'] = ['unrelated stock photo'];
    $visualRepairRejected = false;
    try {
        validate_generation_value($changedHero, $planRepairSchema['properties']['illustration_plan']);
    } catch (InvalidArgumentException) {
        $visualRepairRejected = true;
    }
    draft_smoke_assert($visualRepairRejected, 'Schema korekty QC dopuścił zmianę hero VisualPlan przed transportem.');
    draft_smoke_assert(
        prepare_article_qc_repair_operation((int) $apiDraft['id'], ['id' => 987655], ['categories' => ['structure'], 'feedback' => ['Zachowaj strukturę.']], 1) === $planRepairId,
        'Wersjonowany kontrakt VisualPlan korekty QC nie jest idempotentny.'
    );
    $qcRepairDraft = find_article_draft_by_operation($qcRepairId);
    $oversizedRepair = $apiOutput;
    $oversizedRepair['practical_takeaway']['text'] .= str_repeat(' Nadmiarowa treść poprawki QC.', 250);
    draft_smoke_assert(article_draft_main_content_length($oversizedRepair) > ARTICLE_MAIN_CONTENT_MAX_LENGTH, 'Fixture zbyt długiej poprawki QC nie przekracza maksimum.');
    $database->prepare(
        'UPDATE article_draft_versions
         SET status="completed", draft_json=:json, validation_json=:validation
         WHERE id=:id'
    )->execute([
        ':json' => generation_json($oversizedRepair),
        ':validation' => generation_json(['valid' => true]),
        ':id' => (int) $qcRepairDraft['id'],
    ]);
    $oversizedActivationRejected = false;
    try {
        activate_completed_article_qc_repair((int) $qcRepairDraft['id']);
    } catch (RuntimeException) {
        $oversizedActivationRejected = true;
    }
    draft_smoke_assert($oversizedActivationRejected, 'Zbyt długa poprawka QC została dopuszczona do aktywacji.');
    $activeAfterOversizedRepair = $database->query(
        'SELECT id FROM article_draft_versions WHERE post_id=' . (int) $postId . ' AND is_active=1 ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN);
    draft_smoke_assert($activeAfterOversizedRepair === $activeBeforeOversizedRepair, 'Odrzucona zbyt długa poprawka QC zmieniła aktywną wersję.');
    $repairParentId = prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    $operationIds[] = $repairParentId;
    $badDraft = draft_smoke_replace_selected_title($apiOutput, 'Kontrolowany pomiar to przełom, który zmieni życie każdego człowieka!');
    $goodRepair = ['title'=>$apiOutput['title'],'title_variants'=>$apiOutput['title_variants'],'title_selection_reason'=>'Wybór pozostaje atrakcyjny, ale opiera się wyłącznie na kontrolowanym pomiarze i jego przypisanym claimie.','seo_title'=>$apiOutput['title'],'seo_description'=>$apiOutput['seo_description']];
    $badRepair = $goodRepair;
    $badRepair['title'] = $badDraft['title'];
    $badRepair['title_variants'] = $badDraft['title_variants'];
    $repairCalls = 0;
    $beforeRepairOperation = (int)$database->query('SELECT COALESCE(MAX(id),0) FROM generation_operations')->fetchColumn();
    execute_generation_operation($repairParentId, static function () use (&$repairCalls, $badDraft, $badRepair, $goodRepair): array {
        $value = $repairCalls++ === 0 ? $badDraft : ($repairCalls === 2 ? $badRepair : $goodRepair);
        return ['status'=>200,'body'=>generation_json(['responseId'=>'resp_title_repair_'.$repairCalls,'candidates'=>[['content'=>['parts'=>[['text'=>generation_json($value)]]],'finishReason'=>'STOP']],'usageMetadata'=>['promptTokenCount'=>10,'candidatesTokenCount'=>5,'totalTokenCount'=>15]]),'headers'=>[],'network_error'=>''];
    }, 'smoke-secret-key');
    foreach ($database->query('SELECT id FROM generation_operations WHERE id > '.(int)$beforeRepairOperation.' AND id <> '.(int)$repairParentId)->fetchAll(PDO::FETCH_COLUMN) as $childId) $operationIds[] = (int)$childId;
    $repairedRecord = find_article_draft_by_operation($repairParentId);
    $repairedDraft = json_decode((string)$repairedRecord['draft_json'], true, 128, JSON_THROW_ON_ERROR);
    $repairedValidation = json_decode((string)$repairedRecord['validation_json'], true, 128, JSON_THROW_ON_ERROR);
    foreach (array_diff(array_keys($apiOutput), ['title','title_variants','title_selection_reason','seo_title','seo_description']) as $field) draft_smoke_assert(serialize($repairedDraft[$field]) === serialize($badDraft[$field]), "Automatyczny title repair zmienił zachowane pole {$field}.");
    draft_smoke_assert($repairCalls === 3 && $repairedValidation['repair_attempt'] === 2 && $repairedValidation['repair_scope'] === 'titles', 'Druga próba title repair nie zakończyła operacji rodzica.');
    $repairUsage = json_decode((string)find_generation_operation($repairParentId)['usage_json'], true);
    draft_smoke_assert(($repairUsage['operation_kind'] ?? '') === 'title_only_repair', 'Usage nie oznacza małej naprawy tytułu.');
    $postAfter = find_post($postId, true);
    foreach (['title', 'content', 'status', 'is_published'] as $field) {
        draft_smoke_assert($postAfter[$field] === $postBefore[$field], 'Generowanie zmieniło pole posta: ' . $field);
    }
    $promotedPostId = promote_article_draft_to_post((int) $apiDraft['id']);
    $promotedPost = find_post($promotedPostId);
    $promotedBlocks = json_decode((string) ($promotedPost['content_blocks'] ?? '[]'), true);
    draft_smoke_assert(
        $promotedPostId === $postId
        && is_array($promotedPost)
        && $promotedPost['status'] === 'draft'
        && (int) $promotedPost['is_published'] === 0
        && $promotedPost['title'] === $apiOutput['title']
        && is_array($promotedBlocks)
        && $promotedBlocks !== [],
        'Ukończony szkic nie trafił do edytora jako nieopublikowany post.'
    );
    draft_smoke_assert(
        count(list_article_images($promotedPostId)) === 1 + count($apiOutput['illustration_plan']['inline']),
        'Promocja szkicu nie zapisała planu hero i ilustracji inline.'
    );
    draft_smoke_assert($promotedPost['excerpt'] === $apiOutput['brief'], 'Brief nie trafił pod tytuł jako osobne pole.');
    draft_smoke_assert(
        !str_contains((string) $promotedPost['content'], '<h2>Najważniejsze fakty</h2>')
        && !str_contains((string) $promotedPost['content'], '<h2>Kontekst</h2>'),
        'Kompozytor nadal pokazuje techniczne etykiety sekcji.'
    );
    draft_smoke_assert(
        str_contains((string) $promotedPost['content'], 'article-section--facts')
        && str_contains((string) $promotedPost['content'], 'article-section--importance'),
        'Kompozytor nie rozróżnia sekcji kontrolowanymi stylami.'
    );

    echo "ARTICLE_DRAFT_SMOKE_OK\n";
} finally {
    foreach (array_reverse($operationIds) as $operationId) {
        $database->prepare('DELETE FROM generation_operations WHERE id = :id')->execute([':id' => $operationId]);
    }
    if ($postId > 0) {
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }
    if ($sourceId > 0) {
        $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
    }
    update_generation_mode($originalMode);
    draft_smoke_assert(
        (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItems,
        'Test pozostawił wpis źródłowy.'
    );
}
