<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_TOPIC_TRASH_SMOKE') !== '1') { fwrite(STDERR, "Ustaw CMS_ALLOW_TOPIC_TRASH_SMOKE=1, aby uruchomić test na lokalnej bazie.\n"); exit(2); }
putenv('CMS_SKIP_PUBLIC_SYNC=1'); putenv('CMS_GENERATION_MODE=api'); putenv('CMS_GENERATION_PROVIDER=gemini');
putenv('GEMINI_API_MOCK=true'); putenv('CMS_BATCH_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function trash_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function trash_expect(callable $callback, string $needle): void {
    try { $callback(); } catch (Throwable $exception) { trash_assert(str_contains($exception->getMessage(), $needle), 'Nieoczekiwany błąd: ' . $exception->getMessage()); return; }
    throw new RuntimeException('Oczekiwany wyjątek nie wystąpił.');
}

$database = bueno_database(); $token = bin2hex(random_bytes(6)); $sourceId = 0; $postIds = []; $batchIds = [];
$now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
try {
    update_generation_mode('api');
    $sourceId = save_technical_source(['name' => 'Trash smoke ' . $token, 'website_url' => 'https://example.com/' . $token, 'feed_url' => 'https://example.com/' . $token . '.xml', 'source_type' => 'rss', 'topic_category' => 'science', 'language' => 'pl', 'credibility_level' => 5, 'is_primary' => 1, 'is_active' => 1]);
    $source = find_technical_source($sourceId); $topicIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $postId = persist_discovered_feed_item($source, ['external_id' => $token . '-' . $i, 'source_url' => 'https://example.com/' . $token . '/' . $i, 'title' => 'Temat kosza ' . $token . ' ' . $i, 'source_name' => 'Trash smoke', 'published_at' => '2026-07-31 10:00:00', 'summary' => 'Kontrolowany materiał do testu retencji.', 'category' => 'science', 'content_hash' => hash('sha256', $token . '-' . $i)]);
        $postIds[] = $postId;
        $statement = $database->prepare('SELECT topic_id FROM feed_topic_memberships m INNER JOIN discovered_feed_items i ON i.id = m.feed_item_id WHERE i.post_id = :id');
        $statement->execute([':id' => $postId]); $topicIds[] = (int) $statement->fetchColumn();
        $database->prepare('UPDATE editorial_topics SET automatic_eligible = 1, score = 80 WHERE id = :id')->execute([':id' => $topicIds[array_key_last($topicIds)]]);
    }
    $relationsBefore = count(topic_feed_items($topicIds[0]));
    trash_editorial_topic($topicIds[0], 'tester', 'pojedynczy', 'test', $now);
    trash_assert(find_editorial_topic($topicIds[0]) === null, 'Temat z kosza nadal jest aktywny.');
    trash_expect(static fn () => content_studio_validate_topic_ids([$topicIds[0]]), 'nie istnieje lub nie jest aktywny');
    restore_editorial_topic($topicIds[0], 'tester', $now->modify('+1 hour'));
    trash_assert(find_editorial_topic($topicIds[0]) !== null, 'Nie przywrócono tematu.');
    trash_assert(count(topic_feed_items($topicIds[0])) === $relationsBefore, 'Przywrócenie zmieniło relacje RSS.');
    trash_assert((int) find_editorial_topic($topicIds[0])['automatic_eligible'] === 1, 'Nie odtworzono kwalifikacji automatyzacji.');

    trash_editorial_topic($topicIds[1], 'tester', '', 'test', $now->modify('-10 days +1 second'));
    trash_editorial_topic($topicIds[2], 'tester', '', 'test', $now->modify('-10 days'));
    $database->prepare('UPDATE posts SET status = "published", is_published = 1 WHERE id = :id')->execute([':id' => $postIds[2]]);
    $batch = create_generation_batch([$topicIds[3]], 'trash-active-' . $token, 'tester'); $batchIds[] = (int) $batch['id'];
    trash_expect(static fn () => trash_editorial_topic($topicIds[3], 'tester', '', 'test', $now), 'Nie można przenieść');
    $database->prepare('UPDATE editorial_topics SET trashed_at = :at, trashed_by = "fixture", automatic_eligible = 0 WHERE id = :id')->execute([':at' => $now->modify('-11 days')->format('Y-m-d H:i:s'), ':id' => $topicIds[3]]);
    trash_editorial_topic($topicIds[4], 'tester', '', 'test', $now->modify('-11 days'));

    $cleanup = cleanup_trashed_editorial_topics($now, 'test-cleanup');
    trash_assert($cleanup['deleted'] === 2, 'Cleanup nie objął dokładnej granicy i starszego tematu.');
    trash_assert(count($cleanup['errors']) === 1 && $cleanup['errors'][0]['topic_id'] === $topicIds[3], 'Błąd aktywnego rekordu nie został odizolowany.');
    trash_assert($database->query('SELECT purged_at FROM editorial_topics WHERE id = ' . $topicIds[1])->fetchColumn() === null, 'Temat sekundę przed granicą usunięto za wcześnie.');
    trash_assert($database->query('SELECT purged_at FROM editorial_topics WHERE id = ' . $topicIds[2])->fetchColumn() !== null, 'Temat dokładnie na granicy nie został usunięty.');
    trash_assert((int) $database->query('SELECT COUNT(*) FROM posts WHERE id = ' . $postIds[2] . ' AND status = "published"')->fetchColumn() === 1, 'Cleanup usunął publikację.');
    $again = cleanup_trashed_editorial_topics($now, 'test-cleanup'); trash_assert($again['deleted'] === 0, 'Cleanup nie jest idempotentny.');
    trash_assert((int) $database->query('SELECT COUNT(*) FROM topic_trash_audit WHERE topic_id IN (' . implode(',', $topicIds) . ')')->fetchColumn() >= 5, 'Brakuje audytu kosza.');
    echo "TOPIC_TRASH_SMOKE_OK\n";
} finally {
    if ($batchIds !== []) $database->exec('DELETE FROM generation_batches WHERE id IN (' . implode(',', $batchIds) . ')');
    if ($postIds !== []) $database->exec('DELETE FROM posts WHERE id IN (' . implode(',', $postIds) . ')');
    if ($sourceId > 0) $database->prepare('DELETE FROM technical_sources WHERE id = :id')->execute([':id' => $sourceId]);
    $database->exec('DELETE FROM topic_trash_audit WHERE actor IN ("tester", "test-cleanup")');
    $database->exec('DELETE FROM topic_trash_cleanup_runs WHERE cutoff_at = "2026-07-22 12:00:00"');
}
