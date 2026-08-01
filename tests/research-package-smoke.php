<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_RESEARCH_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_RESEARCH_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function research_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function research_smoke_expect_exception(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        research_smoke_assert(
            str_contains($exception->getMessage(), $messagePart),
            'Nieoczekiwany komunikat błędu: ' . $exception->getMessage()
        );
        return;
    }

    throw new RuntimeException('Oczekiwany wyjątek nie został zgłoszony.');
}

function research_smoke_feed_item(array $source, string $title, string $url, string $token): array
{
    $postId = persist_discovered_feed_item($source, [
        'external_id' => $token,
        'source_url' => $url,
        'title' => $title,
        'source_name' => $source['name'],
        'published_at' => gmdate('Y-m-d H:i:s'),
        'summary' => 'Badacze opisali kontrolowany wynik oraz metodę pomiaru dla testu ' . $token . '.',
        'category' => 'physics',
        'content_hash' => hash('sha256', $title . $token),
    ]);
    if ($postId === null) {
        throw new RuntimeException('Testowy wpis został błędnie uznany za duplikat.');
    }
    $statement = bueno_database()->prepare(
        'SELECT items.id, memberships.topic_id
         FROM discovered_feed_items AS items
         INNER JOIN feed_topic_memberships AS memberships ON memberships.feed_item_id = items.id
         WHERE items.post_id = :post_id'
    );
    $statement->execute([':post_id' => $postId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Testowy wpis nie otrzymał tematu.');
    }

    return ['post_id' => $postId, 'feed_item_id' => (int) $row['id'], 'topic_id' => (int) $row['topic_id']];
}

$singleSourceSchema = research_package_schema(['S1']);
research_smoke_assert(
    ($singleSourceSchema['properties']['shared_facts']['maxItems'] ?? null) === 0,
    'Schemat jednego źródła pozwala modelowi tworzyć shared_facts.'
);
research_smoke_assert(
    ($singleSourceSchema['properties']['contradictions']['maxItems'] ?? null) === 0,
    'Schemat jednego źródła pozwala modelowi tworzyć contradictions.'
);
$multiSourceSchema = research_package_schema(['S1', 'S2']);
research_smoke_assert(
    ($multiSourceSchema['properties']['shared_facts']['items']['properties']['source_ids']['minItems'] ?? null) === 2,
    'Schemat shared_facts nie wymaga dwóch źródeł.'
);

$database = bueno_database();
$originalMode = generation_mode();
$baselineFeedItems = (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn();
$token = bin2hex(random_bytes(6));
$sourceIds = [];
$postIds = [];
$operationIds = [];

try {
    foreach (['Alpha', 'Beta'] as $index => $name) {
        $sourceIds[] = save_technical_source([
            'name' => 'Research ' . $name . ' ' . $token,
            'website_url' => 'https://research-' . strtolower($name) . '-' . $token . '.example.org/',
            'feed_url' => 'https://research-' . strtolower($name) . '-' . $token . '.example.org/feed.xml',
            'source_type' => 'rss',
            'topic_category' => 'physics',
            'language' => 'en',
            'credibility_level' => 5 - $index,
            'is_primary' => $index === 0 ? 1 : 0,
            'is_active' => 0,
        ]);
    }
    $sources = array_map('find_technical_source', $sourceIds);
    $first = research_smoke_feed_item(
        $sources[0],
        'Laboratory confirms a new controlled quantum measurement ' . $token,
        'https://research-alpha.example.org/' . $token,
        'a-' . $token
    );
    $second = research_smoke_feed_item(
        $sources[1],
        'New controlled quantum measurement confirmed in laboratory ' . $token,
        'https://research-beta.example.org/' . $token,
        'b-' . $token
    );
    $postIds = [$first['post_id'], $second['post_id']];
    $topicId = $first['topic_id'];
    if ($second['topic_id'] !== $topicId) {
        manual_merge_topics($second['topic_id'], $topicId, 'research-smoke');
    }
    research_smoke_assert(count(topic_feed_items($topicId)) === 2, 'Temat nie zawiera dwóch źródeł.');

    update_generation_mode('manual');
    $manualId = prepare_research_package_operation($topicId);
    $operationIds[] = $manualId;
    $manualOperation = find_generation_operation($manualId);
    $manualPackage = find_research_package_by_operation($manualId);
    research_smoke_assert($manualOperation !== null, 'Nie zapisano manualnej operacji researchowej.');
    research_smoke_assert($manualOperation['execution_mode'] === 'manual', 'Nie zapisano trybu manual.');
    research_smoke_assert($manualPackage !== null && $manualPackage['status'] === 'prepared', 'Nie zapisano osobnej paczki.');
    research_smoke_assert(str_contains($manualOperation['prompt_text'], '"source_id":"S1"'), 'Prompt nie numeruje źródeł.');
    research_smoke_assert(str_contains($manualOperation['prompt_text'], '"source_id":"S2"'), 'Prompt nie zawiera drugiego źródła.');
    research_smoke_assert(str_contains($manualOperation['prompt_text'], 'znak w znak') && str_contains($manualOperation['prompt_text'], 'Nie parafrazuj cytatu'), 'Prompt nie wymusza dosłownego evidence ze wskazanego źródła.');

    $numberedSources = research_numbered_sources($topicId);
    $firstSource = $numberedSources[0];
    $secondSource = $numberedSources[1];
    $validOutput = [
        'event_summary' => [
            'text' => 'Dwa źródła opisują kontrolowany wynik pomiaru.',
            'source_ids' => [$firstSource['source_id'], $secondSource['source_id']],
        ],
        'claims' => [[
            'claim_id' => 'C1',
            'claim' => 'Pierwsze źródło opisuje kontrolowany pomiar.',
            'source_ids' => [$firstSource['source_id']],
            'evidence' => [[
                'source_id' => $firstSource['source_id'],
                'excerpt' => $firstSource['title'],
            ]],
            'confidence' => 'high',
        ]],
        'shared_facts' => [[
            'fact' => 'Oba materiały opisują ten sam kontrolowany wynik.',
            'source_ids' => [$firstSource['source_id'], $secondSource['source_id']],
            'evidence' => [
                ['source_id' => $firstSource['source_id'], 'excerpt' => $firstSource['title']],
                ['source_id' => $secondSource['source_id'], 'excerpt' => $secondSource['title']],
            ],
            'confidence' => 'medium',
        ]],
        'contradictions' => [],
        'unknowns' => ['Materiały kanałów nie podają pełnego zestawu danych pomiarowych.'],
        'polish_context' => [],
        'comparisons' => [],
        'recommendation' => [
            'decision' => 'continue',
            'reason' => 'Wydarzenie ma dwa zgodne materiały i przypisane dowody.',
            'source_coverage' => 'sufficient',
        ],
    ];
    $manualOutput = import_manual_generation_response($manualId, generation_json($validOutput));
    research_smoke_assert($manualOutput === $validOutput, 'Import manualny zmienił paczkę.');
    $completedManualPackage = find_research_package_by_operation($manualId);
    research_smoke_assert($completedManualPackage['status'] === 'completed', 'Paczka manualna nie została zakończona.');
    $manualValidation = json_decode((string) $completedManualPackage['validation_json'], true);
    research_smoke_assert(($manualValidation['claim_count'] ?? 0) === 1, 'Nie zapisano raportu walidacji.');

    $invalidId = prepare_research_package_operation($topicId);
    $operationIds[] = $invalidId;
    $invalidOutput = $validOutput;
    $invalidOutput['shared_facts'][0]['source_ids'] = [$firstSource['source_id']];
    $invalidOutput['shared_facts'][0]['evidence'] = [[
        'source_id' => $firstSource['source_id'],
        'excerpt' => $firstSource['title'],
    ]];
    research_smoke_expect_exception(
        static fn () => import_manual_generation_response($invalidId, generation_json($invalidOutput)),
        'co najmniej 2'
    );
    research_smoke_assert(
        find_generation_operation($invalidId)['status'] === 'prepared'
        && find_research_package_by_operation($invalidId)['status'] === 'prepared',
        'Niepoprawna paczka manualna zmieniła stan operacji.'
    );

    $nonLiteralId = prepare_research_package_operation($topicId);
    $operationIds[] = $nonLiteralId;
    $nonLiteralOutput = $validOutput;
    $nonLiteralOutput['claims'][0]['evidence'][0]['excerpt'] = 'Parafraza, której nie ma dosłownie w materiale źródłowym.';
    research_smoke_expect_exception(
        static fn () => import_manual_generation_response($nonLiteralId, generation_json($nonLiteralOutput)),
        'dosłownym fragmentem'
    );
    research_smoke_assert(find_research_package_by_operation($nonLiteralId)['status'] === 'prepared', 'Niedosłowne evidence utworzyło paczkę researchową.');

    update_generation_mode('api');
    $apiId = prepare_research_package_operation($topicId);
    $operationIds[] = $apiId;
    $apiOperation = find_generation_operation($apiId);
    research_smoke_assert(
        $apiOperation['output_schema_json'] === $manualOperation['output_schema_json'],
        'Tryby manual i API używają różnych schematów.'
    );
    $apiOutput = execute_generation_operation(
        $apiId,
        static fn (array $payload): array => [
            'status' => 200,
            'body' => generation_json([
                'responseId' => 'resp_research_smoke',
                'candidates' => [[
                    'content' => ['parts' => [['text' => generation_json($validOutput)]]],
                    'finishReason' => 'STOP',
                ]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 30, 'total_tokens' => 80],
            ]),
            'headers' => [],
            'network_error' => '',
        ],
        'smoke-secret-key'
    );
    research_smoke_assert($apiOutput === $manualOutput, 'Tryb API zwrócił inny kontrakt niż manual.');
    $completedApiPackage = find_research_package_by_operation($apiId);
    research_smoke_assert(
        $completedApiPackage['status'] === 'completed' && $completedApiPackage['execution_mode'] === 'api',
        'Nie zapisano zakończonej paczki API z trybem wykonania.'
    );

    $invalidApiId = prepare_research_package_operation($topicId);
    $operationIds[] = $invalidApiId;
    research_smoke_expect_exception(
        static fn () => execute_generation_operation(
            $invalidApiId,
            static fn (): array => [
                'status' => 200,
                'body' => generation_json([
                    'responseId' => 'resp_research_invalid',
                    'candidates' => [[
                        'content' => ['parts' => [['text' => generation_json($invalidOutput)]]],
                        'finishReason' => 'STOP',
                    ]],
                ]),
                'headers' => [],
                'network_error' => '',
            ],
            'smoke-secret-key'
        ),
        'Nieprawidłowa odpowiedź Gemini API'
    );
    research_smoke_assert(
        find_generation_operation($invalidApiId)['status'] === 'failed'
        && find_research_package_by_operation($invalidApiId)['status'] === 'failed',
        'Niepoprawna paczka API nie została oznaczona jako failed.'
    );
    research_smoke_assert(
        (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItems + 2,
        'Test nieoczekiwanie zmienił liczbę wpisów źródłowych przed sprzątaniem.'
    );

    echo "RESEARCH_PACKAGE_SMOKE_OK\n";
} finally {
    foreach ($operationIds as $operationId) {
        $database->prepare('DELETE FROM generation_operations WHERE id = :id')->execute([':id' => $operationId]);
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
    update_generation_mode($originalMode);
    research_smoke_assert(
        (int) $database->query('SELECT COUNT(*) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItems,
        'Test pozostawił wpisy źródłowe.'
    );
}
