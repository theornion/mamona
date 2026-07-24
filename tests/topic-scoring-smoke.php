<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_TOPIC_SCORING_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_TOPIC_SCORING_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function scoring_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scoring_feed_item(array $source, string $title, string $url, string $externalId, string $publishedAt): array
{
    $postId = persist_discovered_feed_item($source, [
        'external_id' => $externalId,
        'source_url' => $url,
        'title' => $title,
        'source_name' => $source['name'],
        'published_at' => $publishedAt,
        'summary' => 'Szczegółowy opis techniczny pozwalający przygotować porównanie i praktyczne wyjaśnienie dla czytelnika.',
        'category' => 'testing',
        'content_hash' => hash('sha256', $title . $externalId),
    ]);
    if ($postId === null) {
        throw new RuntimeException('Nie utworzono testowego pomysłu.');
    }
    $statement = bueno_database()->prepare(
        'SELECT items.id, memberships.topic_id
         FROM discovered_feed_items AS items
         INNER JOIN feed_topic_memberships AS memberships ON memberships.feed_item_id = items.id
         WHERE items.post_id = :post_id'
    );
    $statement->execute([':post_id' => $postId]);
    $item = $statement->fetch();

    return ['post_id' => $postId, 'feed_item_id' => (int) $item['id'], 'topic_id' => (int) $item['topic_id']];
}

$database = bueno_database();
$token = bin2hex(random_bytes(6));
$sourceIds = [];
$postIds = [];
$publishedPostId = 0;
$categoryId = ensure_feed_idea_category();
$fixedNow = new DateTimeImmutable('2030-01-10 12:00:00', new DateTimeZone('UTC'));

try {
    foreach ([['Primary', 1], ['Second', 0], ['Secondary only', 0]] as [$name, $primary]) {
        $sourceIds[] = save_technical_source([
            'name' => 'Scoring ' . $name . ' ' . $token,
            'website_url' => 'https://' . strtolower(str_replace(' ', '-', $name)) . '-' . $token . '.example.org/',
            'feed_url' => 'https://' . strtolower(str_replace(' ', '-', $name)) . '-' . $token . '.example.org/feed.xml',
            'source_type' => 'rss',
            'topic_category' => 'testing',
            'language' => 'en',
            'credibility_level' => 4,
            'is_primary' => $primary,
            'is_active' => 0,
        ]);
    }
    $sources = array_map('find_technical_source', $sourceIds);

    $publishedTitle = 'Poland Acme X9 processor benchmark ' . $token . ' results';
    $database->prepare(
        'INSERT INTO posts (
            category_id, title, excerpt, content, slug, status, is_published,
            author_id, editorial_origin, published_at, content_updated_at
         ) VALUES (
            :category_id, :title, "", "", :slug, "published", 1,
            :author_id, "manual", :published_at, :published_at
         )'
    )->execute([
        ':category_id' => $categoryId,
        ':title' => $publishedTitle,
        ':slug' => 'scoring-published-' . $token,
        ':author_id' => default_author_id(),
        ':published_at' => '2030-01-09 12:00:00',
    ]);
    $publishedPostId = (int) $database->lastInsertId();

    $first = scoring_feed_item(
        $sources[0],
        'Poland Acme X9 processor benchmark ' . $token,
        'https://primary.example.org/' . $token . '/x9',
        'primary-' . $token,
        '2030-01-10 10:00:00'
    );
    $second = scoring_feed_item(
        $sources[1],
        'Acme X9 processor benchmark for Poland ' . $token,
        'https://second.example.org/' . $token . '/x9',
        'second-' . $token,
        '2030-01-10 10:30:00'
    );
    $postIds = [$first['post_id'], $second['post_id']];
    $membership = $database->prepare(
        'SELECT topic_id FROM feed_topic_memberships WHERE feed_item_id = :feed_item_id'
    );
    $membership->execute([':feed_item_id' => $second['feed_item_id']]);
    $topicId = (int) $membership->fetchColumn();

    $scoreA = calculate_topic_score($topicId, $fixedNow, ['testing']);
    $scoreB = calculate_topic_score($topicId, $fixedNow, ['testing']);
    scoring_assert($scoreA === $scoreB, 'Punktacja nie jest deterministyczna dla tych samych danych.');
    scoring_assert($scoreA['has_primary_source'] === true, 'Nie rozpoznano źródła pierwotnego.');
    scoring_assert($scoreA['components']['independent_sources']['points'] === 8, 'Nie uwzględniono dwóch niezależnych źródeł.');
    scoring_assert($scoreA['components']['published_similarity']['points'] < 0, 'Podobieństwo do opublikowanego artykułu nie obniża wyniku.');
    scoring_assert($scoreA['score'] >= 0 && $scoreA['score'] <= 100, 'Wynik wykracza poza skalę 0–100.');

    $risk = scoring_feed_item(
        $sources[0],
        'Critical zero-day exploit in Acme Cloud ' . $token,
        'https://primary.example.org/' . $token . '/zero-day',
        'risk-' . $token,
        '2030-01-10 11:00:00'
    );
    $postIds[] = $risk['post_id'];
    $riskScore = score_editorial_topic($risk['topic_id'], $fixedNow, ['testing']);
    scoring_assert($riskScore['risk_level'] === 'high', 'Nie wykryto tematu wysokiego ryzyka.');
    scoring_assert($riskScore['automatic_eligible'] === false, 'Temat wysokiego ryzyka dopuszczono do automatyzacji.');
    $database->prepare(
        'UPDATE posts SET status = "scheduled", scheduled_at = :scheduled_at WHERE id = :id'
    )->execute([':scheduled_at' => '2030-01-10 11:30:00', ':id' => $risk['post_id']]);
    scoring_assert(
        !in_array($risk['post_id'], array_column(list_due_scheduled_posts($fixedNow, 50), 'id'), true),
        'Scheduler dopuścił temat wysokiego ryzyka.'
    );

    $noPrimary = scoring_feed_item(
        $sources[2],
        'Acme developer tooling guide ' . $token,
        'https://secondary-only.example.org/' . $token . '/guide',
        'secondary-only-' . $token,
        '2030-01-10 11:00:00'
    );
    $postIds[] = $noPrimary['post_id'];
    $noPrimaryScore = calculate_topic_score($noPrimary['topic_id'], $fixedNow, ['testing']);
    scoring_assert($noPrimaryScore['has_primary_source'] === false, 'Brak źródła pierwotnego nie został oznaczony.');
    scoring_assert($noPrimaryScore['automatic_eligible'] === false, 'Temat bez źródła pierwotnego dopuszczono do automatyzacji.');

    echo "TOPIC_SCORING_SMOKE_OK\n";
} finally {
    foreach ($postIds as $postId) {
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }
    if ($publishedPostId > 0) {
        $post = find_post($publishedPostId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($publishedPostId);
        }
        permanently_delete_post($publishedPostId);
    }
    foreach ($sourceIds as $sourceId) {
        $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
    }
}
