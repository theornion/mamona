<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_QUALITY_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_QUALITY_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function quality_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function quality_smoke_expect(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        quality_smoke_assert(
            str_contains($exception->getMessage(), $messagePart),
            'Nieoczekiwany komunikat: ' . $exception->getMessage()
        );
        return;
    }
    throw new RuntimeException('Oczekiwany wyjątek nie został zgłoszony.');
}

function quality_smoke_section(string $text): array
{
    return ['text' => $text, 'claim_ids' => ['C1'], 'source_ids' => ['S1']];
}

function quality_smoke_draft(string $suffix, string $practicalText, string $leadText = ''): array
{
    $empty = ['text' => '', 'claim_ids' => [], 'source_ids' => []];

    $draft = [
        'composition_mode' => 'informational',
        'title' => 'Laboratorium opisuje kontrolowany pomiar ' . $suffix,
        'lead' => quality_smoke_section(
            $leadText !== '' ? $leadText : 'Laboratorium przedstawiło kontrolowany wynik pomiaru ' . $suffix . '.'
        ),
        'why_important' => quality_smoke_section('Pomiar pokazuje znaczenie kontrolowanej metody ' . $suffix . '.'),
        'key_facts' => [quality_smoke_section('Wynik został opisany przez źródło pierwotne ' . $suffix . '.')],
        'comparison_context' => $empty,
        'unknowns' => [[
            'text' => 'Pełny zestaw danych nadal nie jest dostępny ' . $suffix . '.',
            'research_unknown_indexes' => [0],
        ]],
        'practical_takeaway' => quality_smoke_section($practicalText),
        'seo_description' => 'Kontrolowany opis wyniku laboratoryjnego, jego znaczenia, ograniczeń oraz dostępnych materiałów źródłowych ' . $suffix . '.',
        'category' => 'how-it-works',
        'image_alt' => 'Aparatura wykorzystana do kontrolowanego pomiaru ' . $suffix,
        'used_source_ids' => ['S1'],
        'narrative' => [
            'opening_question' => $empty,
            'pursuit' => $empty,
            'topic_b' => $empty,
            'apparent_dead_end' => $empty,
            'return_to_topic_a' => $empty,
            'close_topic_b' => $empty,
            'answer_and_punchline' => $empty,
        ],
    ];
    $index = 1;
    while (article_draft_main_content_length($draft) < ARTICLE_MAIN_CONTENT_MIN_LENGTH) {
        $draft['practical_takeaway']['text'] .= ' Kontekst jakościowy ' . $index
            . ' wyjaśnia znaczenie pomiaru, ograniczenia danych, ostrożność interpretacji oraz praktyczny sens informacji dla czytelnika.';
        $index++;
    }
    $draft['illustration_plan'] = build_planned_illustration_fixture($draft);

    return $draft;
}

function quality_smoke_result(int $score = 90): array
{
    $scores = [
        'fact_source_alignment' => min(25, $score),
        'completeness' => min(10, max(0, $score - 25)),
        'primary_source' => min(10, max(0, $score - 35)),
        'original_value' => min(10, max(0, $score - 45)),
        'originality' => min(10, max(0, $score - 55)),
        'title_quality' => min(10, max(0, $score - 65)),
        'language_readability' => min(10, max(0, $score - 75)),
        'seo' => min(10, max(0, $score - 85)),
        'risk_handling' => min(5, max(0, $score - 95)),
    ];

    return [
        'scores' => $scores,
        'total_score' => array_sum($scores),
        'title_supported' => true,
        'has_primary_source' => true,
        'unsupported_claims' => [],
        'false_quotes' => [],
        'unsupported_tests' => [],
        'clickbait_phrases' => [],
        'similarity' => ['level' => 'low', 'explanation' => 'Nie wykryto wysokiego podobieństwa.'],
        'risk_flags' => [],
        'missing_elements' => [],
        'language_issues' => [],
        'original_value' => 'Szkic porządkuje znaczenie i ograniczenia wyniku.',
        'justification' => 'Fakty mają źródła, a wymagane elementy redakcyjne są obecne.',
        'recommendation' => 'pass',
    ];
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
        'name' => 'Quality source ' . $token,
        'website_url' => 'https://quality-' . $token . '.example.org/',
        'feed_url' => 'https://quality-' . $token . '.example.org/feed.xml',
        'source_type' => 'rss',
        'topic_category' => 'physics',
        'language' => 'en',
        'credibility_level' => 5,
        'is_primary' => 1,
        'is_active' => 0,
    ]);
    $source = find_technical_source($sourceId);
    $postId = persist_discovered_feed_item($source, [
        'external_id' => 'quality-' . $token,
        'source_url' => 'https://quality-' . $token . '.example.org/result',
        'title' => 'Laboratory describes controlled measurement ' . $token,
        'source_name' => $source['name'],
        'published_at' => gmdate('Y-m-d H:i:s'),
        'summary' => 'Laboratorium opisało kontrolowany pomiar oraz ograniczenia dostępnego zestawu danych.',
        'category' => 'physics',
        'content_hash' => hash('sha256', $token),
    ]);
    $topicStatement = $database->prepare(
        'SELECT memberships.topic_id
         FROM discovered_feed_items AS items
         INNER JOIN feed_topic_memberships AS memberships ON memberships.feed_item_id = items.id
         WHERE items.post_id = :post_id'
    );
    $topicStatement->execute([':post_id' => $postId]);
    $topicId = (int) $topicStatement->fetchColumn();

    update_generation_mode('manual');
    $researchOperationId = prepare_research_package_operation($topicId);
    $operationIds[] = $researchOperationId;
    $numberedSource = research_numbered_sources($topicId)[0];
    import_manual_generation_response($researchOperationId, generation_json([
        'event_summary' => ['text' => 'Laboratorium opisało kontrolowany pomiar.', 'source_ids' => ['S1']],
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
        'comparisons' => [],
        'recommendation' => [
            'decision' => 'continue',
            'reason' => 'Źródło pierwotne opisuje wynik.',
            'source_coverage' => 'sufficient',
        ],
    ]));
    $researchPackage = find_research_package_by_operation($researchOperationId);
    approve_research_package((int) $researchPackage['id']);

    $cleanDraftOperationId = prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    $operationIds[] = $cleanDraftOperationId;
    import_manual_generation_response(
        $cleanDraftOperationId,
        generation_json(quality_smoke_draft('wersja czysta', 'Czytelnik powinien poczekać na pełne dane.'))
    );
    $cleanDraft = find_article_draft_by_operation($cleanDraftOperationId);

    $manualCheckOperationId = prepare_quality_check_operation((int) $cleanDraft['id']);
    $operationIds[] = $manualCheckOperationId;
    $manualResult = quality_smoke_result(90);
    import_manual_generation_response($manualCheckOperationId, generation_json($manualResult));
    $manualCheck = find_quality_check_by_operation($manualCheckOperationId);
    $manualDeterministic = json_decode((string) $manualCheck['deterministic_json'], true);
    quality_smoke_assert(
        $manualCheck['status'] === 'completed'
        && (int) $manualCheck['final_score'] === 90
        && (int) $manualCheck['passed'] === 1
        && $manualCheck['execution_mode'] === 'manual'
        && ($manualDeterministic['main_content_character_count'] ?? 0) >= ARTICLE_MAIN_CONTENT_MIN_LENGTH
        && ($manualDeterministic['main_content_maximum'] ?? 0) === ARTICLE_MAIN_CONTENT_MAX_LENGTH,
        'Poprawna manualna kontrola jakości nie została zaliczona.'
    );
    assert_post_quality_allows_publication($postId);

    $invalidCheckOperationId = prepare_quality_check_operation((int) $cleanDraft['id']);
    $operationIds[] = $invalidCheckOperationId;
    $invalidResult = $manualResult;
    $invalidResult['total_score'] = 100;
    quality_smoke_expect(
        static fn () => import_manual_generation_response($invalidCheckOperationId, generation_json($invalidResult)),
        'nie jest sumą'
    );
    quality_smoke_assert(
        find_generation_operation($invalidCheckOperationId)['status'] === 'prepared',
        'Niepoprawny import manualny został zapisany.'
    );

    update_generation_mode('api');
    $apiCheckOperationId = prepare_quality_check_operation((int) $cleanDraft['id']);
    $operationIds[] = $apiCheckOperationId;
    quality_smoke_assert(
        find_generation_operation($apiCheckOperationId)['output_schema_json']
            === find_generation_operation($manualCheckOperationId)['output_schema_json'],
        'Tryb manual i API używają różnych schematów kontroli.'
    );
    execute_generation_operation(
        $apiCheckOperationId,
        static fn (): array => [
            'status' => 200,
            'body' => generation_json([
                'responseId' => 'resp_quality_smoke',
                'candidates' => [[
                    'content' => ['parts' => [['text' => generation_json($manualResult)]]],
                    'finishReason' => 'STOP',
                ]],
                'usage' => ['input_tokens' => 90, 'output_tokens' => 40, 'total_tokens' => 130],
            ]),
            'headers' => [],
            'network_error' => '',
        ],
        'smoke-secret-key'
    );
    $apiCheck = find_quality_check_by_operation($apiCheckOperationId);
    quality_smoke_assert(
        (int) $apiCheck['check_number'] === 3 && $apiCheck['execution_mode'] === 'api',
        'Kolejna kontrola API nie została zachowana osobno.'
    );

    update_generation_mode('manual');
    $riskDraftOperationId = prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    $operationIds[] = $riskDraftOperationId;
    import_manual_generation_response(
        $riskDraftOperationId,
        generation_json(quality_smoke_draft(
            'wersja ryzykowna',
            'Zainwestuj wszystkie oszczędności, bo to gwarantowany zysk.'
        ))
    );
    $riskDraft = find_article_draft_by_operation($riskDraftOperationId);
    $riskCheckOperationId = prepare_quality_check_operation((int) $riskDraft['id']);
    $operationIds[] = $riskCheckOperationId;
    import_manual_generation_response($riskCheckOperationId, generation_json($manualResult));
    $riskCheck = find_quality_check_by_operation($riskCheckOperationId);
    quality_smoke_assert(
        (int) $riskCheck['passed'] === 0
        && (quality_active_hard_blocks($riskCheck)[0]['code'] ?? '') === 'high_risk_without_human_approval',
        'Aplikacja zaufała modelowi i pominęła deterministyczne ryzyko.'
    );
    quality_smoke_expect(
        static fn () => review_quality_risk((int) $riskCheck['id'], 'approved', 'krótko'),
        'wymaga uzasadnienia'
    );
    review_quality_risk(
        (int) $riskCheck['id'],
        'approved',
        'Redaktor zweryfikował charakter materiału i akceptuje opisane ryzyko testowe.'
    );
    $reviewedRiskCheck = find_quality_check_by_operation($riskCheckOperationId);
    quality_smoke_assert(
        quality_active_hard_blocks($reviewedRiskCheck) === []
        && (int) $reviewedRiskCheck['passed'] === 1,
        'Uzasadniona decyzja człowieka nie rozwiązała wyłącznie blokady ryzyka.'
    );

    $quoteDraftOperationId = prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    $operationIds[] = $quoteDraftOperationId;
    import_manual_generation_response(
        $quoteDraftOperationId,
        generation_json(quality_smoke_draft(
            'wersja z cytatem',
            'Czytelnik powinien poczekać na pełne dane.',
            'Laboratorium podało: „To jest całkowicie zmyślony cytat bez źródła”.'
        ))
    );
    $quoteDraft = find_article_draft_by_operation($quoteDraftOperationId);
    $quoteCheckOperationId = prepare_quality_check_operation((int) $quoteDraft['id']);
    $operationIds[] = $quoteCheckOperationId;
    import_manual_generation_response($quoteCheckOperationId, generation_json($manualResult));
    $quoteCheck = find_quality_check_by_operation($quoteCheckOperationId);
    quality_smoke_assert(
        (quality_active_hard_blocks($quoteCheck)[0]['code'] ?? '') === 'false_quote',
        'Fałszywy cytat nie utworzył twardej blokady.'
    );
    quality_smoke_expect(
        static fn () => review_quality_risk(
            (int) $quoteCheck['id'],
            'approved',
            'Próba niedozwolonego ukrycia fałszywego cytatu z uzasadnieniem.'
        ),
        'nie zawiera ryzyka'
    );
    quality_smoke_expect(
        static fn () => change_post_editorial_status($postId, 'scheduled', 'Test blokady jakości'),
        'blokuje kontrola jakości'
    );
    quality_smoke_assert(find_post($postId, true)['status'] === 'idea', 'Blokada nie zatrzymała zmiany statusu.');

    $checkCount = $database->prepare('SELECT COUNT(*) FROM quality_check_runs WHERE post_id = :post_id');
    $checkCount->execute([':post_id' => $postId]);
    quality_smoke_assert((int) $checkCount->fetchColumn() === 5, 'Nie zachowano wszystkich kolejnych kontroli.');

    echo "QUALITY_CHECK_SMOKE_OK\n";
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
    quality_smoke_assert(
        (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItems,
        'Test pozostawił wpis źródłowy.'
    );
}
