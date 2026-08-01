<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_SOURCE_GATE_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_SOURCE_GATE_SMOKE=1; test wymaga izolowanej bazy CMS_TEST_DATABASE_FILE.\n");
    exit(2);
}
if (trim((string) getenv('CMS_TEST_DATABASE_FILE')) === '') {
    throw new RuntimeException('Test regresji źródeł nie może działać na produkcyjnej bazie lokalnej.');
}
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_GENERATION_MODE=api');
putenv('GEMINI_API_MOCK=true');
putenv('CMS_BATCH_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function source_gate_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function source_gate_topic(string $token, int $index): array
{
    $titles = [
        1 => 'Teleskop zarejestrował nietypowy rozbłysk w odległej galaktyce',
        2 => 'Biolodzy opisali mechanizm regeneracji tkanek u aksolotla',
        3 => 'Inżynierowie zmierzyli wydajność nowego ogniwa słonecznego',
        4 => 'Archiwum nie zawiera materiału wejściowego dla kontrolnego tematu',
    ];
    $sourceId = save_technical_source([
        'name' => "Feed {$index} {$token}", 'website_url' => "https://feed{$index}.example.com/",
        'feed_url' => "https://feed{$index}.example.com/rss.xml", 'source_type' => 'rss',
        'topic_category' => 'science', 'language' => 'pl', 'credibility_level' => 5,
        'is_primary' => 1, 'is_active' => 1,
    ]);
    $source = find_technical_source($sourceId);
    $postId = persist_discovered_feed_item($source, [
        'external_id' => "{$token}-{$index}", 'source_url' => "https://feed{$index}.example.com/article-{$index}",
        'title' => $titles[$index],
        'source_name' => "Feed {$index}", 'published_at' => gmdate('Y-m-d H:i:s'),
        'summary' => 'Legalnie pobrany opis RSS zawiera konkretny wynik, datę i ograniczony zakres informacji wystarczający do konserwatywnego researchu.',
        'category' => 'science', 'content_hash' => hash('sha256', "{$token}-{$index}"),
    ]);
    $statement = bueno_database()->prepare(
        'SELECT m.topic_id,i.id feed_item_id FROM discovered_feed_items i
         INNER JOIN feed_topic_memberships m ON m.feed_item_id=i.id WHERE i.post_id=:post'
    );
    $statement->execute([':post' => $postId]);
    return ['source_id' => $sourceId, 'post_id' => $postId, ...$statement->fetch()];
}

$token = bin2hex(random_bytes(5));
$topics = [source_gate_topic($token, 1), source_gate_topic($token, 2), source_gate_topic($token, 3)];
foreach ($topics as $fixture) {
    $topicId = (int) $fixture['topic_id'];
    $policy = research_policy_for_topic($topicId, 'low', false);
    source_gate_assert(($policy['decision'] ?? '') === 'continue' && ($policy['code'] ?? '') === 'safe_feed_excerpt',
        "Poprawny wpis RSS tematu {$topicId} został zablokowany.");
    $operationId = prepare_research_package_operation($topicId);
    $operation = find_generation_operation($operationId);
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    source_gate_assert(count($input['numbered_sources'] ?? []) === 1
        && ($input['research_policy']['material_scope'] ?? '') === 'feed_excerpt_only',
        'Research nie zachował ograniczonego materiału title/summary/date/link z feedu.');
}

$first = $topics[0];
$enrichment = enrich_topic_sources((int) $first['topic_id'], static function (): array {
    throw new RuntimeException('Strona źródłowa zwróciła HTTP 403.');
});
source_gate_assert($enrichment['permanent_failed'] === 1 && $enrichment['retryable_failed'] === 0
    && ($enrichment['errors'][0]['code'] ?? '') === 'http_forbidden', 'HTTP 403 został uznany za awarię przejściową.');
source_gate_assert((research_policy_for_topic((int) $first['topic_id'], 'low', false)['code'] ?? '') === 'safe_feed_excerpt',
    'Niedostępna pełna strona unieważniła poprawne dane RSS.');
$rate = source_enrichment_failure_details(new RuntimeException('Strona źródłowa zwróciła HTTP 429.'));
source_gate_assert($rate['retryable'] === true && $rate['code'] === 'http_rate_limited', 'HTTP 429 nie został ograniczony do retry źródła.');

$noData = source_gate_topic($token, 4);
bueno_database()->prepare('UPDATE technical_sources SET is_active=0 WHERE id=:id')->execute([':id' => (int) $noData['source_id']]);
$noDataPolicy = research_policy_for_topic((int) $noData['topic_id'], 'low', false);
source_gate_assert(($noDataPolicy['decision'] ?? '') === 'blocked' && ($noDataPolicy['code'] ?? '') === 'no_source_material',
    'Prawdziwy brak materiału nie uruchamia pozyskania/retry.');

$batch = create_generation_batch([(int) $topics[1]['topic_id']], 'source-gate-' . $token);
$item = generation_batch_item_rows((int) $batch['id'])[0];
generation_batch_update_item((int) $item['id'], [
    'status' => 'auto_retry_scheduled', 'stage' => 'research', 'outcome' => 'research_enrichment_scheduled',
    'available_at' => gmdate('Y-m-d H:i:s', time() + 300),
    'wait_reason' => 'Zaplanowano ponowne pozyskanie legalnych źródeł.',
]);
generation_batch_audit((int) $batch['id'], (int) $item['id'], 'research_auto_retry_queued', 'worker', [
    'enrichment' => ['errors' => [['code' => 'http_forbidden', 'http_status' => 403, 'retryable' => false]]],
]);
$reconciled = generation_batch_reconcile_feed_enrichment_regression([(int) $item['id']]);
$after = generation_batch_find_item((int) $item['id']);
source_gate_assert(count($reconciled) === 1 && $after['status'] === 'queued' && $after['outcome'] === 'safe_feed_research_queued',
    'Stary research_enrichment_scheduled nie został automatycznie odblokowany.');

$fingerprint = research_sources_fingerprint((int) $topics[1]['topic_id']);
source_gate_assert(generation_batch_should_attempt_research_enrichment((int) $item['id'], $fingerprint), 'Pierwsza strategia enrichment została błędnie zablokowana.');
generation_batch_audit((int) $batch['id'], (int) $item['id'], 'research_enrichment_attempted', 'worker', ['fingerprint' => $fingerprint]);
source_gate_assert(!generation_batch_should_attempt_research_enrichment((int) $item['id'], $fingerprint)
    && generation_batch_should_attempt_research_enrichment((int) $item['id'], hash('sha256', $fingerprint)),
    'Circuit breaker nie blokuje identycznego fingerprintu albo blokuje zmienioną strategię.');

echo "SOURCE_GATE_REGRESSION_SMOKE_OK\n";
