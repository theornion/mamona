<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_PROFILE_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_PROFILE_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function profile_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function profile_test_item(array $source, string $title, string $summary, string $token): array
{
    $normalized = normalize_feed_item([
        'external_id' => $token,
        'url' => 'https://research-' . $token . '.example.org/story',
        'title' => $title,
        'published_at' => '2030-01-10 11:00:00',
        'summary' => $summary,
        'category' => 'ignored-publisher-label',
    ], $source);
    $postId = persist_discovered_feed_item($source, $normalized);
    if ($postId === null) {
        throw new RuntimeException('Nie utworzono testowego pomysłu.');
    }
    $statement = bueno_database()->prepare(
        'SELECT items.id, memberships.topic_id, items.category
         FROM discovered_feed_items AS items
         INNER JOIN feed_topic_memberships AS memberships ON memberships.feed_item_id = items.id
         WHERE items.post_id = :post_id'
    );
    $statement->execute([':post_id' => $postId]);
    $item = $statement->fetch();

    return [
        'post_id' => $postId,
        'feed_item_id' => (int) $item['id'],
        'topic_id' => (int) $item['topic_id'],
        'category' => (string) $item['category'],
    ];
}

$database = bueno_database();
$token = bin2hex(random_bytes(6));
$sourceIds = [];
$postIds = [];
$fixedNow = new DateTimeImmutable('2030-01-10 12:00:00', new DateTimeZone('UTC'));

try {
    profile_assert(count(list_editorial_profile_categories()) >= 8, 'Brakuje ośmiu kategorii profilu.');
    profile_assert(count(list_popular_science_sources()) >= 8, 'Brakuje ośmiu aktywnych źródeł profilu.');

    $scienceSourceId = save_technical_source([
        'name' => 'Profile Science ' . $token,
        'website_url' => 'https://science-' . $token . '.example.org/',
        'feed_url' => 'https://science-' . $token . '.example.org/feed.xml',
        'source_type' => 'rss',
        'topic_category' => 'materials-inventions',
        'language' => 'en',
        'credibility_level' => 5,
        'is_primary' => 1,
        'is_active' => 0,
    ]);
    $developerSourceId = save_technical_source([
        'name' => 'Profile Developer ' . $token,
        'website_url' => 'https://developer-' . $token . '.example.org/',
        'feed_url' => 'https://developer-' . $token . '.example.org/feed.xml',
        'source_type' => 'rss',
        'topic_category' => 'development',
        'language' => 'en',
        'credibility_level' => 5,
        'is_primary' => 1,
        'is_active' => 0,
    ]);
    $sourceIds = [$scienceSourceId, $developerSourceId];
    $database->prepare(
        'UPDATE technical_sources SET profile_key = :profile_key WHERE id = :id'
    )->execute([':profile_key' => POPULAR_SCIENCE_PROFILE_KEY, ':id' => $scienceSourceId]);
    $database->prepare(
        'UPDATE technical_sources SET profile_key = "developer" WHERE id = :id'
    )->execute([':id' => $developerSourceId]);

    $science = profile_test_item(
        find_technical_source($scienceSourceId),
        'New camera material reveals how cells repair unexpected damage ' . $token,
        'Researchers built a new imaging instrument and explain how its sensor works. '
            . 'The method could enable future medical devices and gives people a clearer view of a long-standing mystery.',
        'science-' . $token
    );
    $developer = profile_test_item(
        find_technical_source($developerSourceId),
        'SDK version 8.4 changelog now supports another cloud region ' . $token,
        'Release notes for developers describe an API update, framework version and database administrator option.',
        'developer-' . $token
    );
    $postIds = [$science['post_id'], $developer['post_id']];
    profile_assert(
        $science['category'] === 'materials-inventions',
        'Kategoria wydawcy ominęła kanoniczną kategorię profilu.'
    );

    $scienceScore = calculate_topic_score($science['topic_id'], $fixedNow, ['materials-inventions']);
    $developerScore = calculate_topic_score($developer['topic_id'], $fixedNow, ['materials-inventions']);
    profile_assert(
        $scienceScore['score'] >= $developerScore['score'] + 20,
        'Scoring nie odróżnia odkrycia z mechanizmem od changelogu.'
    );
    profile_assert(
        $scienceScore['components']['explainable_mechanism']['points'] > 0,
        'Popularnonaukowy temat nie otrzymał uzasadnienia mechanizmu.'
    );
    profile_assert(
        $developerScore['components']['developer_niche']['points'] < 0,
        'Changelog nie otrzymał kary za wąski profil deweloperski.'
    );
    $previewIds = array_map(
        static fn (array $row): int => (int) $row['post_id'],
        editorial_profile_cleanup_preview()
    );
    profile_assert(in_array($developer['post_id'], $previewIds, true), 'Podgląd porządkowania nie wykrył starego profilu.');
    try {
        execute_editorial_profile_cleanup(false, 'test');
        throw new RuntimeException('Porządkowanie zadziałało bez potwierdzenia.');
    } catch (InvalidArgumentException) {
        // Expected.
    }

    echo "POPULAR_SCIENCE_PROFILE_SMOKE_OK\n";
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
