<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_FEED_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_FEED_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function feed_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = bueno_database();
$token = bin2hex(random_bytes(5));
$sourceIds = [];
$originalActivity = [];
$createdPostIds = [];
$ideaCategoryId = 0;
$existingIdeaCategoryId = (int) $database->query(
    "SELECT id FROM post_categories WHERE slug = 'automatyczne-znaleziska'"
)->fetchColumn();
$baselineFeedItemCount = (int) $database->query('SELECT COUNT(id) FROM discovered_feed_items')->fetchColumn();

$rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title>Fixture RSS</title>
    <item>
      <title>Nowy procesor &amp; test</title>
      <link>https://news.example.org/cpu</link>
      <guid>fixture-cpu-1</guid>
      <pubDate>Wed, 10 Jan 2030 10:00:00 GMT</pubDate>
      <description><![CDATA[<p>Krótki opis z feedu.</p>]]></description>
      <content:encoded><![CDATA[PEŁNA TREŚĆ, KTÓREJ NIE WOLNO ZAPISAĆ]]></content:encoded>
      <category>hardware</category>
    </item>
    <item>
      <title>Nowa wersja narzędzia</title>
      <link>https://news.example.org/tool</link>
      <guid>fixture-tool-1</guid>
      <description>Opis drugiego wpisu.</description>
    </item>
  </channel>
</rss>
XML;

$atom = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Fixture Atom</title>
  <entry>
    <id>atom-1</id>
    <title>Wpis Atom</title>
    <link rel="alternate" href="https://news.example.org/atom"/>
    <updated>2030-01-10T11:00:00Z</updated>
    <summary>Krótki opis Atom.</summary>
    <category term="software"/>
  </entry>
</feed>
XML;

try {
    feed_assert(FEED_REQUEST_TIMEOUT_SECONDS <= 15, 'Limit czasu pobierania jest zbyt wysoki.');
    feed_assert(FEED_RESPONSE_MAX_BYTES === 2097152, 'Limit odpowiedzi nie wynosi 2 MB.');
    $privateBlocked = false;
    try {
        assert_public_feed_url('https://127.0.0.1/feed.xml');
    } catch (InvalidArgumentException) {
        $privateBlocked = true;
    }
    feed_assert($privateBlocked, 'Prywatny adres IP nie został zablokowany.');

    $atomSource = ['name' => 'Atom fixture', 'topic_category' => 'technology'];
    $atomItems = parse_feed_document($atom, $atomSource);
    feed_assert(count($atomItems) === 1 && $atomItems[0]['external_id'] === 'atom-1', 'Parser Atom nie normalizuje wpisu.');

    foreach (list_technical_sources() as $source) {
        $originalActivity[(int) $source['id']] = (int) $source['is_active'];
        set_technical_source_active((int) $source['id'], false);
    }
    foreach (['valid', 'broken'] as $kind) {
        $sourceIds[] = save_technical_source([
            'name' => 'Feed ' . $kind . ' ' . $token,
            'website_url' => 'https://' . $kind . '.example.org/',
            'feed_url' => 'https://' . $kind . '.example.org/feed.xml',
            'source_type' => 'rss',
            'topic_category' => 'technology',
            'language' => 'pl',
            'credibility_level' => 4,
            'is_primary' => 1,
            'is_active' => 1,
        ]);
    }

    $fetcher = static function (string $url) use ($rss): string {
        return str_contains($url, 'broken.') ? '<rss><broken>' : $rss;
    };
    $first = run_feed_ingestion($fetcher);
    feed_assert($first['created'] === 2, 'Pierwsze pobranie nie utworzyło dwóch pomysłów.');
    feed_assert($first['failed'] === 1, 'Uszkodzony XML nie został zapisany jako błąd źródła.');
    $second = run_feed_ingestion($fetcher);
    feed_assert($second['created'] === 0 && $second['duplicates'] === 2, 'Ponowne pobranie utworzyło duplikaty.');
    feed_assert($second['failed'] === 1, 'Błąd jednego źródła zatrzymał lub ukrył pozostałe wyniki.');

    $itemsStatement = $database->prepare(
        'SELECT discovered_feed_items.*, posts.status, posts.editorial_origin, posts.content
         FROM discovered_feed_items
         INNER JOIN posts ON posts.id = discovered_feed_items.post_id
         WHERE discovered_feed_items.technical_source_id = :source_id'
    );
    $itemsStatement->execute([':source_id' => $sourceIds[0]]);
    $items = $itemsStatement->fetchAll();
    feed_assert(count($items) === 2, 'Nie zapisano znormalizowanych metadanych feedu.');
    foreach ($items as $item) {
        $createdPostIds[] = (int) $item['post_id'];
        feed_assert($item['status'] === 'idea', 'Wpis feedu nie trafił do kolejki jako pomysł.');
        feed_assert($item['editorial_origin'] === 'automatic', 'Pomysł nie ma automatycznego pochodzenia.');
        feed_assert(strlen((string) $item['content_hash']) === 64, 'Brak skrótu do deduplikacji.');
        feed_assert(!str_contains((string) $item['content'], 'PEŁNA TREŚĆ'), 'System zapisał pełną treść artykułu.');
        feed_assert(!is_file(post_page_path((string) find_post((int) $item['post_id'])['slug'])), 'Pomysł otrzymał publiczny HTML.');
    }
    $ideaCategory = $database->query("SELECT * FROM post_categories WHERE slug = 'automatyczne-znaleziska'")->fetch();
    $ideaCategoryId = (int) ($ideaCategory['id'] ?? 0);
    feed_assert($ideaCategoryId > 0 && (int) $ideaCategory['is_editorial_only'] === 1, 'Kategoria pomysłów nie jest ukryta.');
    feed_assert(!in_array($ideaCategoryId, array_column(list_post_categories(), 'id'), true), 'Kategoria techniczna trafiła do publicznej nawigacji.');

    echo "FEED_INGESTION_SMOKE_OK\n";
} finally {
    if ($ideaCategoryId === 0) {
        $ideaCategoryId = (int) $database->query(
            "SELECT id FROM post_categories WHERE slug = 'automatyczne-znaleziska'"
        )->fetchColumn();
    }
    foreach ($createdPostIds as $postId) {
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }
    foreach ($sourceIds as $sourceId) {
        $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
    }
    foreach ($originalActivity as $sourceId => $active) {
        set_technical_source_active($sourceId, $active === 1);
    }
    if ($ideaCategoryId > 0 && $existingIdeaCategoryId === 0) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $ideaCategoryId]);
    }
    feed_assert(
        (int) $database->query('SELECT COUNT(id) FROM discovered_feed_items')->fetchColumn() === $baselineFeedItemCount,
        'Cleanup testu naruszył istniejące wpisy feedu.'
    );
}
