<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_ARTICLE_DRAFT_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_ARTICLE_DRAFT_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

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
        'title' => 'Precyzyjny tytuł kontrolowanego szkicu ' . $suffix,
        'lead' => $section('Lead od razu opisuje kontrolowane wydarzenie.'),
        'why_important' => $section('Znaczenie wynika z udokumentowanego pomiaru.'),
        'key_facts' => [$section('Najważniejszy fakt ma przypisane źródło.')],
        'comparison_context' => $empty,
        'unknowns' => [[
            'text' => 'Nie jest jeszcze znany pełny zestaw danych. ' . $suffix,
            'research_unknown_indexes' => [0],
        ]],
        'practical_takeaway' => $section('Czytelnik powinien poczekać na pełne dane.'),
        'seo_description' => 'Opis SEO kontrolowanego szkicu o udokumentowanym wydarzeniu. ' . $suffix,
        'category' => 'how-it-works',
        'image_alt' => 'Schemat kontrolowanego pomiaru w laboratorium ' . $suffix,
        'used_source_ids' => [$sourceId],
        'narrative' => $narrative,
    ];
    $policy = article_draft_length_policy($mode);
    $index = 1;
    while (article_draft_main_content_length($draft) < $policy['minimum_characters']) {
        $draft['practical_takeaway']['text'] .= ' Kontekst testowy ' . $index
            . ' porządkuje znaczenie wyniku, zakres dostępnych danych, ograniczenia interpretacji i praktyczne konsekwencje opisane w źródle.';
        $index++;
    }

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
        str_contains((string) $manualOperation['prompt_text'], '"minimum_characters":2000')
        && str_contains((string) $manualOperation['prompt_text'], '"maximum_characters":4000')
        && str_contains((string) $manualOperation['prompt_text'], 'lania wody'),
        'Prompt prostego szkicu nie zawiera kompletnej polityki długości i jakości.'
    );
    $manualOutput = draft_smoke_output('informational', 'C1', 'S1', 'manual-v1');
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
        $shortRejected = str_contains($exception->getMessage(), 'wymagany jest zakres 2000–4000');
    }
    draft_smoke_assert($shortRejected, 'Szkic krótszy niż 2000 znaków nie został odrzucony.');
    $longOutput = $manualOutput;
    $longOutput['practical_takeaway']['text'] .= str_repeat(' Nadmiarowa treść testowa.', 200);
    $longRejected = false;
    try {
        import_manual_generation_response($manualId, generation_json($longOutput));
    } catch (Throwable $exception) {
        $longRejected = str_contains($exception->getMessage(), 'wymagany jest zakres 2000–4000');
    }
    draft_smoke_assert($longRejected, 'Szkic dłuższy niż 4000 znaków nie został odrzucony.');
    $repeatedOutput = $manualOutput;
    $repeatedOutput['why_important']['text'] = $repeatedOutput['lead']['text'];
    $repetitionRejected = false;
    try {
        import_manual_generation_response($manualId, generation_json($repeatedOutput));
    } catch (Throwable $exception) {
        $repetitionRejected = str_contains($exception->getMessage(), 'powtarza to samo zdanie');
    }
    draft_smoke_assert($repetitionRejected, 'Powtórzone długie zdanie nie zostało odrzucone.');
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
        str_contains((string) $narrativeOperation['prompt_text'], '"minimum_characters":3000')
        && str_contains((string) $narrativeOperation['prompt_text'], 'dolna granica'),
        'Prompt złożonego szkicu nie podkreśla progu 3000 i pełnego rozwinięcia.'
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
                'id' => 'resp_draft_smoke',
                'output_text' => generation_json($apiOutput),
                'usage' => ['input_tokens' => 70, 'output_tokens' => 60, 'total_tokens' => 130],
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
    $postAfter = find_post($postId, true);
    foreach (['title', 'content', 'status', 'is_published'] as $field) {
        draft_smoke_assert($postAfter[$field] === $postBefore[$field], 'Generowanie zmieniło pole posta: ' . $field);
    }

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
