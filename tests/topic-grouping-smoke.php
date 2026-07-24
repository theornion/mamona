<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_TOPIC_GROUPING_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_TOPIC_GROUPING_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function topic_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_feed_item(array $source, string $title, string $url, string $token): int
{
    $postId = persist_discovered_feed_item($source, [
        'external_id' => $token,
        'source_url' => $url,
        'title' => $title,
        'source_name' => $source['name'],
        'published_at' => gmdate('Y-m-d H:i:s'),
        'summary' => 'Kontrolowany opis testowy ' . $token,
        'category' => 'testing',
        'content_hash' => hash('sha256', $title . $token),
    ]);
    if ($postId === null) {
        throw new RuntimeException('Testowy wpis został błędnie uznany za duplikat źródłowy.');
    }
    $statement = bueno_database()->prepare(
        'SELECT id FROM discovered_feed_items WHERE post_id = :post_id'
    );
    $statement->execute([':post_id' => $postId]);

    return (int) $statement->fetchColumn();
}

$database = bueno_database();
$token = bin2hex(random_bytes(6));
$sourceIds = [];
$postIds = [];

try {
    foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
        $sourceIds[] = save_technical_source([
            'name' => 'Grouping ' . $name . ' ' . $token,
            'website_url' => 'https://' . strtolower($name) . '-' . $token . '.example.org/',
            'feed_url' => 'https://' . strtolower($name) . '-' . $token . '.example.org/feed.xml',
            'source_type' => 'rss',
            'topic_category' => 'testing',
            'language' => 'en',
            'credibility_level' => 4,
            'is_primary' => 1,
            'is_active' => 0,
        ]);
    }
    $sources = array_map('find_technical_source', $sourceIds);
    $eventUrl = 'https://news.example.org/' . $token . '/gpt-6';
    $itemA = test_feed_item(
        $sources[0],
        'OpenAI launches GPT-6 coding model for developers ' . $token,
        $eventUrl,
        'a-' . $token
    );
    $itemB = test_feed_item(
        $sources[1],
        'GPT-6 coding model for developers arrives from OpenAI ' . $token,
        'https://other.example.org/' . $token . '/gpt-6',
        'b-' . $token
    );
    $itemSameUrl = test_feed_item(
        $sources[2],
        'A completely different headline ' . $token,
        $eventUrl,
        'same-url-' . $token
    );
    $itemUnrelated = test_feed_item(
        $sources[1],
        'OpenAI updates billing dashboard limits ' . $token,
        'https://other.example.org/' . $token . '/billing',
        'unrelated-' . $token
    );

    $itemIds = [$itemA, $itemB, $itemSameUrl, $itemUnrelated];
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $statement = $database->prepare(
        'SELECT id, post_id FROM discovered_feed_items WHERE id IN (' . $placeholders . ')'
    );
    $statement->execute($itemIds);
    foreach ($statement->fetchAll() as $row) {
        $postIds[] = (int) $row['post_id'];
    }

    $membership = $database->prepare(
        'SELECT topic_id, confidence, match_method
         FROM feed_topic_memberships WHERE feed_item_id = :feed_item_id'
    );
    $membership->execute([':feed_item_id' => $itemA]);
    $topicA = $membership->fetch();
    $membership->execute([':feed_item_id' => $itemB]);
    $topicB = $membership->fetch();
    $membership->execute([':feed_item_id' => $itemSameUrl]);
    $topicSameUrl = $membership->fetch();
    $membership->execute([':feed_item_id' => $itemUnrelated]);
    $topicUnrelated = $membership->fetch();

    topic_assert((int) $topicA['topic_id'] === (int) $topicB['topic_id'], 'Podobne nagłówki nie zostały zgrupowane.');
    topic_assert((int) $topicA['topic_id'] === (int) $topicSameUrl['topic_id'], 'Identyczny URL utworzył osobny temat.');
    topic_assert((float) $topicB['confidence'] >= TOPIC_AUTOMATIC_MATCH_THRESHOLD, 'Nie zapisano pewności automatycznego dopasowania.');
    topic_assert((int) $topicA['topic_id'] !== (int) $topicUnrelated['topic_id'], 'Niezwiązane aktualizacje tej samej firmy zostały połączone.');
    topic_assert(count(topic_feed_items((int) $topicA['topic_id'])) === 3, 'Temat nie zawiera wszystkich potwierdzających źródeł.');

    manual_merge_topics((int) $topicUnrelated['topic_id'], (int) $topicA['topic_id'], 'test');
    $membership->execute([':feed_item_id' => $itemUnrelated]);
    topic_assert((int) $membership->fetchColumn() === (int) $topicA['topic_id'], 'Ręczne połączenie nie zadziałało.');
    $splitTopicId = manual_split_feed_item($itemUnrelated, 'test');
    topic_assert($splitTopicId !== (int) $topicA['topic_id'], 'Nie można było cofnąć błędnego grupowania.');

    $unrelatedPost = find_post($postIds[array_search($itemUnrelated, $itemIds, true)], true);
    topic_assert($unrelatedPost['status'] === 'idea', 'Rozdzielony wpis nie wrócił do kolejki pomysłów.');
    $splitPrimaryTopicId = manual_split_feed_item($itemA, 'test');
    topic_assert($splitPrimaryTopicId !== (int) $topicA['topic_id'], 'Nie można rozdzielić głównego wpisu tematu.');
    $remainingTopic = find_editorial_topic((int) $topicA['topic_id']);
    topic_assert(
        $remainingTopic !== null && (int) $remainingTopic['primary_post_id'] !== $postIds[0],
        'Temat nie otrzymał nowego głównego posta po rozdzieleniu.'
    );

    echo "TOPIC_GROUPING_SMOKE_OK\n";
} finally {
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
}
