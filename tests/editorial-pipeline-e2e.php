<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_PIPELINE_E2E') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_PIPELINE_E2E=1, aby uruchomić pełny test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_ENV=development');
putenv('CMS_PUBLIC_URL=https://example.test');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_AUTOMATIC_PUBLISHING=true');
putenv('CMS_DAILY_PUBLICATION_LIMIT=50');
putenv('OPENAI_API_MOCK=true');
require_once dirname(__DIR__) . '/php/admin-database.php';

function pipeline_e2e_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pipeline_e2e_section(string $text): array
{
    return ['text' => $text, 'claim_ids' => ['C1'], 'source_ids' => ['S1']];
}

function pipeline_e2e_research(string $sourceTitle): array
{
    return [
        'event_summary' => [
            'text' => 'Źródło opisuje wynik eksperymentu: ' . $sourceTitle,
            'source_ids' => ['S1'],
        ],
        'claims' => [[
            'claim_id' => 'C1',
            'claim' => $sourceTitle,
            'source_ids' => ['S1'],
            'evidence' => [['source_id' => 'S1', 'excerpt' => $sourceTitle]],
            'confidence' => 'high',
        ]],
        'shared_facts' => [],
        'contradictions' => [],
        'unknowns' => ['Pełny zestaw danych nie został udostępniony w kanale RSS.'],
        'polish_context' => [],
        'comparisons' => [],
        'recommendation' => [
            'decision' => 'continue',
            'reason' => 'Źródło pierwotne pozwala przeprowadzić techniczny test procesu.',
            'source_coverage' => 'sufficient',
        ],
    ];
}

function pipeline_e2e_draft(string $sourceTitle, string $mode): array
{
    $empty = ['text' => '', 'claim_ids' => [], 'source_ids' => []];

    $draft = [
        'composition_mode' => $mode,
        'title' => mb_substr($sourceTitle, 0, 100),
        'lead' => pipeline_e2e_section('Eksperyment opisany przez źródło pierwotne dał kontrolowany wynik.'),
        'why_important' => pipeline_e2e_section('Wynik pomaga wyjaśnić badany mechanizm.'),
        'key_facts' => [pipeline_e2e_section('Źródło pierwotne opisuje metodę i wynik eksperymentu.')],
        'comparison_context' => $empty,
        'unknowns' => [[
            'text' => 'Pełny zestaw danych nie jest jeszcze dostępny.',
            'research_unknown_indexes' => [0],
        ]],
        'practical_takeaway' => pipeline_e2e_section('Wynik wymaga dalszej weryfikacji na pełnych danych.'),
        'seo_description' => 'Kontrolowany test procesu redakcyjnego opisujący wynik eksperymentu, jego znaczenie i ograniczenia dostępnych danych.',
        'category' => 'how-it-works',
        'image_alt' => 'Aparatura laboratoryjna używana podczas kontrolowanego eksperymentu',
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
    $policy = article_draft_length_policy($mode);
    $index = 1;
    while (article_draft_main_content_length($draft) < $policy['minimum_characters']) {
        $draft['practical_takeaway']['text'] .= ' Kontekst procesu ' . $index
            . ' porządkuje znaczenie eksperymentu, ograniczenia dostępnych danych, ostrożność interpretacji i dalsze kroki weryfikacji.';
        $index++;
    }

    return $draft;
}

function pipeline_e2e_quality(): array
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
        'similarity' => ['level' => 'low', 'explanation' => 'Tekst ma własną strukturę i język.'],
        'risk_flags' => [],
        'missing_elements' => [],
        'language_issues' => [],
        'original_value' => 'Materiał wyjaśnia znaczenie i ograniczenia wyniku.',
        'justification' => 'Wszystkie wymagane elementy mają podstawę w zatwierdzonym researchu.',
        'recommendation' => 'pass',
    ];
}

function pipeline_e2e_content(array $draft): string
{
    $escape = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $parts = [
        '<p>' . $escape((string) $draft['lead']['text']) . '</p>',
        '<h2>Dlaczego to ważne</h2><p>' . $escape((string) $draft['why_important']['text']) . '</p>',
    ];
    foreach ((array) $draft['key_facts'] as $fact) {
        $parts[] = '<p>' . $escape((string) $fact['text']) . '</p>';
    }
    $parts[] = '<h2>Czego jeszcze nie wiadomo</h2><p>'
        . $escape((string) $draft['unknowns'][0]['text']) . '</p>';
    $parts[] = '<p>' . $escape((string) $draft['practical_takeaway']['text']) . '</p>';

    return implode("\n", $parts);
}

function pipeline_e2e_restore_file(string $path, string|false|null $before): void
{
    if (is_string($before)) {
        write_public_file_atomically($path, $before);
    } elseif (is_file($path)) {
        unlink($path);
    }
}

$database = bueno_database();
$originalMode = generation_mode();
$originalActivity = [];
$sourceIds = [];
$categoryIds = [];
$postIds = [];
$operationIds = [];
$createdFiles = [];
$results = [];
$existingIdeaCategoryId = (int) $database->query(
    "SELECT id FROM post_categories WHERE slug = 'automatyczne-znaleziska'"
)->fetchColumn();
$baselineFeedItems = (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn();
$publicFiles = [
    app_path('sitemap.xml'),
    app_path('feed.xml'),
    app_path('robots.txt'),
    scheduled_publication_log_path(),
    scheduled_publication_lock_path(),
];
$publicFilesBefore = [];
foreach ($publicFiles as $path) {
    $publicFilesBefore[$path] = is_file($path) ? file_get_contents($path) : null;
}

try {
    foreach (list_technical_sources() as $source) {
        $sourceId = (int) $source['id'];
        $originalActivity[$sourceId] = (int) $source['is_active'];
        set_technical_source_active($sourceId, false);
    }

    foreach (['manual', 'api'] as $scenarioIndex => $mode) {
        $token = $mode . '-' . bin2hex(random_bytes(5));
        $sourceTitle = 'Eksperyment ujawnia kontrolowany mechanizm ' . $token;
        $sourceId = save_technical_source([
            'name' => 'Mamona E2E ' . $token,
            'website_url' => 'https://' . $token . '.example.org/',
            'feed_url' => 'https://' . $token . '.example.org/feed.xml',
            'source_type' => 'rss',
            'topic_category' => 'how-it-works',
            'language' => 'pl',
            'credibility_level' => 5,
            'is_primary' => 1,
            'is_active' => 1,
        ]);
        $sourceIds[] = $sourceId;

        $rss = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0"><channel><title>Mamona E2E</title><item>'
            . '<title>' . htmlspecialchars($sourceTitle, ENT_XML1, 'UTF-8') . '</title>'
            . '<link>https://' . $token . '.example.org/result</link>'
            . '<guid>e2e-' . $token . '</guid>'
            . '<pubDate>Tue, 10 Jan 2040 10:00:00 GMT</pubDate>'
            . '<description>Badacze opisali metodę, kontrolowany wynik i ograniczenia danych.</description>'
            . '<category>how-it-works</category></item></channel></rss>';
        $fetcher = static fn (): string => $rss;
        $firstFetch = run_feed_ingestion($fetcher);
        $secondFetch = run_feed_ingestion($fetcher);
        pipeline_e2e_assert(
            $firstFetch['created'] === 1 && $secondFetch['created'] === 0 && $secondFetch['duplicates'] === 1,
            "Deduplikacja RSS nie zadziałała w scenariuszu {$mode}."
        );
        set_technical_source_active($sourceId, false);

        $itemStatement = $database->prepare(
            'SELECT items.id, items.post_id, memberships.topic_id
             FROM discovered_feed_items AS items
             INNER JOIN feed_topic_memberships AS memberships ON memberships.feed_item_id = items.id
             WHERE items.technical_source_id = :source_id'
        );
        $itemStatement->execute([':source_id' => $sourceId]);
        $item = $itemStatement->fetch();
        pipeline_e2e_assert(is_array($item), "Nie utworzono tematu w scenariuszu {$mode}.");
        $postId = (int) $item['post_id'];
        $topicId = (int) $item['topic_id'];
        $postIds[] = $postId;

        $score = score_editorial_topic($topicId, new DateTimeImmutable('2040-01-10 11:00:00 UTC'));
        pipeline_e2e_assert(
            $score['automatic_eligible'] === true && $score['score'] >= 0 && $score['score'] <= 100,
            "Punktacja nie zakwalifikowała bezpiecznego tematu w scenariuszu {$mode}."
        );

        update_generation_mode($mode);
        $researchOperationId = prepare_research_package_operation($topicId);
        $operationIds[] = $researchOperationId;
        if ($mode === 'manual') {
            import_manual_generation_response(
                $researchOperationId,
                generation_json(pipeline_e2e_research($sourceTitle))
            );
        } else {
            execute_generation_operation($researchOperationId);
        }
        $research = find_research_package_by_operation($researchOperationId);
        pipeline_e2e_assert(
            is_array($research) && $research['status'] === 'completed',
            "Research nie został ukończony w scenariuszu {$mode}."
        );
        approve_research_package((int) $research['id']);

        $draftOperationId = prepare_article_draft_operation((int) $research['id'], 'informational');
        $operationIds[] = $draftOperationId;
        if ($mode === 'manual') {
            import_manual_generation_response(
                $draftOperationId,
                generation_json(pipeline_e2e_draft($sourceTitle, 'informational'))
            );
        } else {
            execute_generation_operation($draftOperationId);
        }
        $draftRecord = find_article_draft_by_operation($draftOperationId);
        $draft = json_decode((string) $draftRecord['draft_json'], true, 128, JSON_THROW_ON_ERROR);

        $qualityOperationId = prepare_quality_check_operation((int) $draftRecord['id']);
        $operationIds[] = $qualityOperationId;
        if ($mode === 'manual') {
            import_manual_generation_response($qualityOperationId, generation_json(pipeline_e2e_quality()));
        } else {
            execute_generation_operation($qualityOperationId);
        }
        $quality = find_quality_check_by_operation($qualityOperationId);
        pipeline_e2e_assert(
            is_array($quality) && (int) $quality['passed'] === 1,
            "Kontrola jakości nie została zaliczona w scenariuszu {$mode}."
        );

        $thumbnailId = prepare_thumbnail_version((int) $draftRecord['id']);
        if ($mode === 'manual') {
            $thumbnail = complete_thumbnail_from_bytes(
                $thumbnailId,
                thumbnail_mock_image_bytes(),
                'ChatGPT Image manual E2E'
            );
        } else {
            $thumbnail = execute_thumbnail_api($thumbnailId);
        }
        $createdFiles[] = app_path((string) $thumbnail['original_path']);
        $createdFiles[] = app_path((string) $thumbnail['public_path']);
        $imageInfo = getimagesize(app_path((string) $thumbnail['public_path']));
        pipeline_e2e_assert(
            (int) $imageInfo[0] === 1280 && (int) $imageInfo[1] === 720,
            "Miniatura nie ma wymiaru 1280×720 w scenariuszu {$mode}."
        );

        $database->prepare(
            'INSERT INTO post_categories (title, description, slug, sort_order)
             VALUES (:title, :description, :slug, 999999)'
        )->execute([
            ':title' => 'E2E ' . strtoupper($mode),
            ':description' => 'Kategoria pełnego testu procesu.',
            ':slug' => 'e2e-' . $token,
        ]);
        $categoryId = (int) $database->lastInsertId();
        $categoryIds[] = $categoryId;
        $database->prepare('UPDATE posts SET category_id = :category_id WHERE id = :id')
            ->execute([':category_id' => $categoryId, ':id' => $postId]);

        $post = find_post($postId);
        update_post(
            $postId,
            (string) $draft['title'],
            (string) $draft['lead']['text'],
            pipeline_e2e_content($draft),
            (string) $thumbnail['public_path'],
            false,
            '',
            null,
            [],
            'cover',
            [],
            'review'
        );
        $scheduledUtc = new DateTimeImmutable(
            '2040-01-10 ' . (12 + $scenarioIndex) . ':00:00',
            new DateTimeZone('UTC')
        );
        update_post_editorial_fields($postId, [
            'author_id' => default_author_id(),
            'scheduled_at' => editorial_datetime_for_input($scheduledUtc->format('Y-m-d H:i:s')),
            'content_updated_at' => editorial_datetime_for_input($scheduledUtc->modify('-10 minutes')->format('Y-m-d H:i:s')),
            'seo_description' => (string) $draft['seo_description'],
            'image_alt' => (string) $draft['image_alt'],
            'ai_components' => ['research', 'text', 'seo', 'image'],
            'ai_disclosure' => 'Materiał przygotowano w kontrolowanym teście pipeline’u z udziałem AI.',
        ], [[
            'source_url' => 'https://' . $token . '.example.org/result',
            'source_title' => $sourceTitle,
            'publisher_name' => 'Mamona E2E',
            'source_type' => 'primary',
        ]]);
        change_post_editorial_status($postId, 'scheduled', 'Pełny test TASK-22', 'e2e');

        $run = run_scheduled_publications(false, $scheduledUtc->modify('+5 minutes'));
        pipeline_e2e_assert(
            in_array($postId, $run['published'], true),
            "Scheduler nie opublikował materiału w scenariuszu {$mode}."
        );
        $duplicateRun = run_scheduled_publications(false, $scheduledUtc->modify('+6 minutes'));
        pipeline_e2e_assert(
            !in_array($postId, $duplicateRun['published'], true),
            "Scheduler opublikował materiał drugi raz w scenariuszu {$mode}."
        );

        $published = find_post($postId);
        $articlePath = post_page_path((string) $published['slug']);
        $createdFiles[] = $articlePath;
        pipeline_e2e_assert(is_file($articlePath), "Nie utworzono HTML artykułu w scenariuszu {$mode}.");
        write_discovery_files();
        $canonical = post_canonical_url($published);
        pipeline_e2e_assert(
            str_contains((string) file_get_contents(app_path('sitemap.xml')), $canonical)
            && str_contains((string) file_get_contents(app_path('feed.xml')), $canonical),
            "Sitemap lub RSS nie zawiera publikacji ze scenariusza {$mode}."
        );

        $results[$mode] = [
            'post_id' => $postId,
            'topic_id' => $topicId,
            'score' => $score['score'],
            'research_mode' => $research['execution_mode'],
            'draft_mode' => $draftRecord['execution_mode'],
            'quality_mode' => $quality['execution_mode'],
            'thumbnail_mode' => $thumbnail['execution_mode'],
            'published' => true,
        ];
    }

    pipeline_e2e_assert(
        $results['manual']['research_mode'] === 'manual'
        && $results['api']['research_mode'] === 'api'
        && find_generation_operation($operationIds[0]) !== null,
        'Zmiana trybu ukryła wcześniejsze materiały albo nie zachowała sposobu wykonania.'
    );
    echo generation_json(['status' => 'EDITORIAL_PIPELINE_E2E_OK', 'scenarios' => $results]) . PHP_EOL;
} finally {
    foreach ($postIds as $postId) {
        $database->prepare('DELETE FROM thumbnail_versions WHERE post_id = :post_id')
            ->execute([':post_id' => $postId]);
    }
    foreach (array_reverse($operationIds) as $operationId) {
        $database->prepare('DELETE FROM generation_operations WHERE id = :id')
            ->execute([':id' => $operationId]);
    }
    foreach ($postIds as $postId) {
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }
    foreach ($sourceIds as $sourceId) {
        $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
    }
    foreach ($categoryIds as $categoryId) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $categoryId]);
    }
    foreach ($originalActivity as $sourceId => $active) {
        set_technical_source_active($sourceId, $active === 1);
    }
    foreach (array_unique($createdFiles) as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    foreach ($publicFilesBefore as $path => $before) {
        pipeline_e2e_restore_file($path, $before);
    }
    if ($existingIdeaCategoryId === 0) {
        $ideaCategoryId = (int) $database->query(
            "SELECT id FROM post_categories WHERE slug = 'automatyczne-znaleziska'"
        )->fetchColumn();
        if ($ideaCategoryId > 0) {
            $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $ideaCategoryId]);
        }
    }
    update_generation_mode($originalMode);
    pipeline_e2e_assert(
        (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItems,
        'Test E2E pozostawił wpisy źródłowe.'
    );
}
