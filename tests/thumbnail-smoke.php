<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_THUMBNAIL_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_THUMBNAIL_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function thumbnail_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function thumbnail_smoke_expect(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        thumbnail_smoke_assert(
            str_contains($exception->getMessage(), $messagePart),
            'Nieoczekiwany komunikat: ' . $exception->getMessage()
        );
        return;
    }
    throw new RuntimeException('Oczekiwany wyjątek nie został zgłoszony.');
}

function thumbnail_smoke_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function thumbnail_smoke_png(int $width, int $height, array $rgb): string
{
    $pixel = chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
    $row = "\x00" . str_repeat($pixel, $width);
    $raw = str_repeat($row, $height);

    return "\x89PNG\r\n\x1a\n"
        . thumbnail_smoke_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . thumbnail_smoke_png_chunk('IDAT', gzcompress($raw, 9))
        . thumbnail_smoke_png_chunk('IEND', '');
}

function thumbnail_smoke_section(string $text): array
{
    return ['text' => $text, 'claim_ids' => ['C1'], 'source_ids' => ['S1']];
}

function thumbnail_smoke_draft(string $suffix): array
{
    $empty = ['text' => '', 'claim_ids' => [], 'source_ids' => []];

    $draft = [
        'composition_mode' => 'informational',
        'title' => 'Laboratorium opisuje kontrolowany pomiar ' . $suffix,
        'lead' => thumbnail_smoke_section('Laboratorium przedstawiło kontrolowany wynik ' . $suffix . '.'),
        'why_important' => thumbnail_smoke_section('Metoda pozwala lepiej zrozumieć pomiar ' . $suffix . '.'),
        'key_facts' => [thumbnail_smoke_section('Źródło pierwotne opisuje wynik ' . $suffix . '.')],
        'comparison_context' => $empty,
        'unknowns' => [[
            'text' => 'Nie jest znany pełny zestaw danych ' . $suffix . '.',
            'research_unknown_indexes' => [0],
        ]],
        'practical_takeaway' => thumbnail_smoke_section('Warto poczekać na pełne dane ' . $suffix . '.'),
        'seo_description' => 'Kontrolowany wynik laboratoryjny wraz ze znaczeniem, ograniczeniami oraz kompletem materiałów źródłowych ' . $suffix . '.',
        'category' => 'how-it-works',
        'image_alt' => 'Centralnie ustawiona aparatura do kontrolowanego pomiaru ' . $suffix,
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
        $draft['practical_takeaway']['text'] .= ' Kontekst miniatury ' . $index
            . ' opisuje znaczenie wyniku, ograniczenia materiału, sposób ostrożnej interpretacji oraz elementy istotne dla czytelnika.';
        $index++;
    }
    $draft['illustration_plan'] = build_planned_illustration_fixture($draft);

    return $draft;
}

function thumbnail_smoke_quality_result(): array
{
    return [
        'scores' => [
            'fact_source_alignment' => 25,
            'completeness' => 10,
            'primary_source' => 10,
            'original_value' => 10,
            'originality' => 10,
            'title_quality' => 10,
            'language_readability' => 10,
            'seo' => 10,
            'risk_handling' => 5,
        ],
        'total_score' => 100,
        'title_supported' => true,
        'has_primary_source' => true,
        'unsupported_claims' => [],
        'false_quotes' => [],
        'unsupported_tests' => [],
        'clickbait_phrases' => [],
        'similarity' => ['level' => 'low', 'explanation' => 'Szkic ma własną strukturę i język.'],
        'risk_flags' => [],
        'missing_elements' => [],
        'language_issues' => [],
        'original_value' => 'Szkic wyjaśnia znaczenie i ograniczenia wyniku.',
        'justification' => 'Wszystkie wymagane elementy mają podstawę w researchu.',
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
$createdFiles = [];

try {
    $sourceId = save_technical_source([
        'name' => 'Thumbnail source ' . $token,
        'website_url' => 'https://thumbnail-' . $token . '.example.org/',
        'feed_url' => 'https://thumbnail-' . $token . '.example.org/feed.xml',
        'source_type' => 'rss',
        'topic_category' => 'physics',
        'language' => 'en',
        'credibility_level' => 5,
        'is_primary' => 1,
        'is_active' => 0,
    ]);
    $source = find_technical_source($sourceId);
    $postId = persist_discovered_feed_item($source, [
        'external_id' => 'thumbnail-' . $token,
        'source_url' => 'https://thumbnail-' . $token . '.example.org/result',
        'title' => 'Laboratory describes controlled measurement ' . $token,
        'source_name' => $source['name'],
        'published_at' => gmdate('Y-m-d H:i:s'),
        'summary' => 'Laboratorium opisało kontrolowany pomiar i ograniczenia dostępnych danych.',
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
    $draftOperationId = prepare_article_draft_operation((int) $researchPackage['id'], 'informational');
    $operationIds[] = $draftOperationId;
    import_manual_generation_response($draftOperationId, generation_json(thumbnail_smoke_draft('test')));
    $draft = find_article_draft_by_operation($draftOperationId);

    thumbnail_smoke_expect(
        static fn () => prepare_thumbnail_version((int) $draft['id']),
        'nie ma zaliczonej kontroli'
    );

    $qualityOperationId = prepare_quality_check_operation((int) $draft['id']);
    $operationIds[] = $qualityOperationId;
    import_manual_generation_response($qualityOperationId, generation_json(thumbnail_smoke_quality_result()));

    $manualThumbnailId = prepare_thumbnail_version((int) $draft['id']);
    $manualThumbnail = find_thumbnail_version($manualThumbnailId);
    thumbnail_smoke_assert(
        $manualThumbnail['execution_mode'] === 'manual'
        && str_contains((string) $manualThumbnail['prompt_text'], 'bez tekstu i liter')
        && str_contains((string) $manualThumbnail['prompt_text'], 'centralnych 60%'),
        'Prompt manualny nie zawiera ograniczeń kompozycji i bezpieczeństwa.'
    );
    thumbnail_smoke_expect(
        static fn () => complete_thumbnail_from_bytes(
            $manualThumbnailId,
            thumbnail_smoke_png(640, 360, [20, 80, 140]),
            'ChatGPT Image'
        ),
        'co najmniej 1280×720'
    );
    thumbnail_smoke_assert(
        find_thumbnail_version($manualThumbnailId)['status'] === 'prepared',
        'Odrzucony upload uniemożliwił poprawienie tej samej wersji manualnej.'
    );

    $firstCompleted = complete_thumbnail_from_bytes(
        $manualThumbnailId,
        thumbnail_smoke_png(1400, 900, [20, 80, 140]),
        'ChatGPT Image'
    );
    $createdFiles[] = app_path((string) $firstCompleted['original_path']);
    $createdFiles[] = app_path((string) $firstCompleted['public_path']);
    $publicInfo = getimagesize(app_path((string) $firstCompleted['public_path']));
    thumbnail_smoke_assert(
        (int) $publicInfo[0] === 1280
        && (int) $publicInfo[1] === 720
        && (string) $publicInfo['mime'] === 'image/webp',
        'Manualny upload nie utworzył wariantu 1280×720 WebP.'
    );
    thumbnail_smoke_assert(
        is_file(app_path((string) $firstCompleted['original_path']))
        && find_post($postId, true)['image_path'] === $firstCompleted['public_path'],
        'Nie zachowano oryginału albo nie przypisano obrazu do artykułu.'
    );

    $secondThumbnailId = prepare_thumbnail_version((int) $draft['id']);
    $secondCompleted = complete_thumbnail_from_bytes(
        $secondThumbnailId,
        thumbnail_smoke_png(1600, 900, [160, 70, 30]),
        'ChatGPT Image'
    );
    $createdFiles[] = app_path((string) $secondCompleted['original_path']);
    $createdFiles[] = app_path((string) $secondCompleted['public_path']);
    thumbnail_smoke_assert(
        is_file(app_path((string) $firstCompleted['public_path']))
        && find_post($postId, true)['image_path'] === $secondCompleted['public_path'],
        'Nowa wersja usunęła poprzednią albo nie została aktywowana.'
    );
    reject_thumbnail_version($secondThumbnailId, 'Kadr jest zbyt mało czytelny na telefonie.');
    thumbnail_smoke_assert(
        find_post($postId, true)['image_path'] === $firstCompleted['public_path']
        && (int) find_thumbnail_version($manualThumbnailId)['is_active'] === 1,
        'Odrzucenie nie przywróciło poprzedniej wersji.'
    );

    update_generation_mode('api');
    $apiThumbnailId = prepare_thumbnail_version((int) $draft['id']);
    $apiPrepared = find_thumbnail_version($apiThumbnailId);
    thumbnail_smoke_assert(
        $apiPrepared['prompt_text'] === $manualThumbnail['prompt_text'],
        'Tryb API otrzymał inny prompt niż manual.'
    );
    if ((bool) app_config('ai_image_generation_enabled')) {
    $apiBytes = thumbnail_smoke_png(2048, 1152, [35, 120, 75]);
    $transportCalls = 0;
    $apiCompleted = execute_thumbnail_api(
        $apiThumbnailId,
        static function (array $payload, string $apiKey, string $operationKey) use (&$transportCalls, $apiBytes): array {
            $transportCalls++;
            thumbnail_smoke_assert($apiKey === 'image-smoke-key', 'Transport nie otrzymał klucza.');
            thumbnail_smoke_assert($payload['model'] === 'gpt-image-2', 'Nie użyto aktualnego modelu obrazowego.');
            thumbnail_smoke_assert($payload['size'] === '2048x1152', 'API nie żąda 16:9.');
            thumbnail_smoke_assert($payload['output_format'] === 'webp', 'API nie żąda WebP.');
            thumbnail_smoke_assert(str_starts_with($operationKey, 'thumbnail-'), 'Brakuje klucza idempotencji.');
            return [
                'status' => 200,
                'body' => generation_json([
                    'data' => [['b64_json' => base64_encode($apiBytes)]],
                    'usage' => ['input_tokens' => 12, 'output_tokens' => 196, 'total_tokens' => 208],
                ]),
                'headers' => ['x-request-id' => 'req_thumbnail_smoke'],
                'network_error' => '',
            ];
        },
        'image-smoke-key'
    );
    $createdFiles[] = app_path((string) $apiCompleted['original_path']);
    $createdFiles[] = app_path((string) $apiCompleted['public_path']);
    thumbnail_smoke_assert(
        $transportCalls === 1
        && $apiCompleted['model'] === 'gpt-image-2'
        && $apiCompleted['provider_response_id'] === 'req_thumbnail_smoke'
        && json_decode((string) $apiCompleted['usage_json'], true)['total_tokens'] === 208,
        'Nie zapisano metadanych Images API.'
    );

    $failedThumbnailId = prepare_thumbnail_version((int) $draft['id']);
    thumbnail_smoke_expect(
        static fn () => execute_thumbnail_api(
            $failedThumbnailId,
            static fn (): array => [
                'status' => 400,
                'body' => generation_json(['error' => ['message' => 'Invalid image request']]),
                'headers' => [],
                'network_error' => '',
            ],
            'image-smoke-key'
        ),
        'Invalid image request'
    );
    thumbnail_smoke_assert(
        find_thumbnail_version($failedThumbnailId)['status'] === 'failed'
        && find_post($postId, true)['image_path'] === $apiCompleted['public_path']
        && is_file(app_path((string) $firstCompleted['public_path'])),
        'Nieudane API naruszyło wcześniejsze wersje obrazu.'
    );
    } else {
        $transportCalls = 0;
        $apiSkipped = execute_thumbnail_api(
            $apiThumbnailId,
            static function () use (&$transportCalls): array {
                $transportCalls++;
                throw new RuntimeException('Generator obrazu nie może zostać wywołany.');
            },
            'image-smoke-key'
        );
        thumbnail_smoke_assert(
            $transportCalls === 0
            && $apiSkipped['status'] === 'skipped'
            && str_contains((string) $apiSkipped['error_message'], 'pominięto'),
            'Wyłączony generator obrazu wykonał transport albo nie zapisał pominięcia.'
        );
        $apiCompleted = complete_thumbnail_from_bytes(
            $apiThumbnailId,
            thumbnail_smoke_png(2048, 1152, [35, 120, 75]),
            'Manual source fixture'
        );
        $createdFiles[] = app_path((string) $apiCompleted['original_path']);
        $createdFiles[] = app_path((string) $apiCompleted['public_path']);
    }

    echo "THUMBNAIL_SMOKE_OK\n";
} finally {
    foreach ($createdFiles as $path) {
        $resolvedRoot = (string) realpath(app_project_root());
        $resolvedDirectory = (string) realpath(dirname($path));
        if (is_file($path) && $resolvedDirectory !== '' && str_starts_with($resolvedDirectory, $resolvedRoot)) {
            unlink($path);
        }
    }
    if ($postId > 0) {
        $database->prepare('DELETE FROM thumbnail_versions WHERE post_id = :post_id')
            ->execute([':post_id' => $postId]);
    }
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
    thumbnail_smoke_assert(
        (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItems,
        'Test pozostawił wpis źródłowy.'
    );
}
