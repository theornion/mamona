<?php

declare(strict_types=1);

$studioDatabaseDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-studio-smoke-' . bin2hex(random_bytes(6));
if (!mkdir($studioDatabaseDirectory, 0700, true) && !is_dir($studioDatabaseDirectory)) throw new RuntimeException('Nie można utworzyć izolowanej bazy Studio.');
$studioDatabaseFile = $studioDatabaseDirectory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $studioDatabaseFile);
register_shutdown_function(static function () use ($studioDatabaseFile, $studioDatabaseDirectory): void {
    foreach ([$studioDatabaseFile, $studioDatabaseFile . '-wal', $studioDatabaseFile . '-shm'] as $file) if (is_file($file)) @unlink($file);
    if (is_dir($studioDatabaseDirectory)) @rmdir($studioDatabaseDirectory);
});

if (getenv('CMS_ALLOW_CONTENT_STUDIO_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_CONTENT_STUDIO_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_CONTENT_STUDIO_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function studio_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = bueno_database();
$token = bin2hex(random_bytes(5));
$sourceIds = [];
$postIds = [];
$jobIds = [];
$originalActivity = [];
$categoryId = 0;
$existingCategoryId = (int) $database->query(
    "SELECT id FROM post_categories WHERE slug = 'automatyczne-znaleziska'"
)->fetchColumn();

$rss = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<rss version="2.0"><channel><title>Studio</title><item>'
    . '<title>Scientists reveal a new telescope imaging method ' . $token . '</title>'
    . '<link>https://studio-' . $token . '.example.org/discovery</link>'
    . '<guid>studio-' . $token . '</guid><pubDate>Wed, 31 Jul 2026 10:00:00 GMT</pubDate>'
    . '<description>Researchers reveal how a telescope camera maps a planet and could help people.</description>'
    . '</item></channel></rss>';

try {
    foreach (list_technical_sources() as $source) {
        $originalActivity[(int) $source['id']] = (int) $source['is_active'];
        set_technical_source_active((int) $source['id'], false);
    }
    foreach (['good', 'broken'] as $kind) {
        $sourceIds[] = save_technical_source([
            'name' => 'Studio ' . $kind . ' ' . $token,
            'website_url' => 'https://' . $kind . '-' . $token . '.example.org/',
            'feed_url' => 'https://' . $kind . '-' . $token . '.example.org/feed.xml',
            'source_type' => 'rss',
            'topic_category' => 'science',
            'language' => 'en',
            'credibility_level' => 5,
            'is_primary' => 1,
            'is_active' => 1,
        ]);
    }

    $job = content_studio_create_job('smoke');
    $jobIds[] = (int) $job['id'];
    studio_assert((int) $job['total_units'] === 4, 'Całkowity postęp nie obejmuje źródeł, grupowania i scoringu.');
    content_studio_update_job((int) $job['id'], ['processed_units' => 1]);
    $quarter = content_studio_job_payload(content_studio_find_job((int) $job['id']));
    studio_assert($quarter['processed'] === 1 && $quarter['total'] === 4 && $quarter['percent'] === 25, 'Postęp X/Y jest liczony nieprawidłowo.');
    $parallelBlocked = false;
    try {
        content_studio_create_job('parallel');
    } catch (DomainException $exception) {
        $parallelBlocked = $exception->getCode() === 409;
    }
    studio_assert($parallelBlocked, 'Drugi równoległy start nie został zablokowany.');

    $partial = content_studio_run_job((int) $job['id'], static function (string $url) use ($rss): string {
        return str_contains($url, 'broken-') ? '<rss><broken>' : $rss;
    });
    studio_assert($partial['status'] === 'partial_success', 'Błąd jednego feedu został przedstawiony jako pełna porażka.');
    studio_assert((int) $partial['processed_units'] === (int) $partial['total_units'], 'Stan końcowy nie osiągnął X/Y.');
    studio_assert((int) $partial['created_count'] === 1 && (int) $partial['failed_source_count'] === 1, 'Liczniki częściowego sukcesu są nieprawidłowe.');
    studio_assert((string)find_technical_source($sourceIds[0])['health_status'] === 'healthy', 'Udane źródło nie zachowało stanu healthy.');
    studio_assert((string)find_technical_source($sourceIds[1])['health_status'] === 'degraded', 'Pierwsza porażka nie ustawiła stanu degraded.');
    $sourceResults = content_studio_decode_json((string) $partial['source_results_json']);
    studio_assert(
        count($sourceResults) === 2
            && count(array_filter($sourceResults, static fn (array $result): bool => $result['error'] !== '')) === 1,
        'Nie zachowano skróconego błędu źródła.'
    );

    set_technical_source_active($sourceIds[1], false);
    $second = content_studio_create_job('idempotency');
    $jobIds[] = (int) $second['id'];
    $success = content_studio_run_job((int) $second['id'], static fn (): string => $rss);
    studio_assert($success['status'] === 'success', 'Pełny przebieg nie zakończył się sukcesem.');
    studio_assert((int) $success['created_count'] === 0 && (int) $success['duplicate_count'] === 1, 'Ponowne pobranie nie jest idempotentne.');
    studio_assert((string)find_technical_source($sourceIds[0])['health_status'] === 'healthy' && (int)find_technical_source($sourceIds[0])['consecutive_failures'] === 0, 'Sukces nie wyzerował licznika zdrowia źródła.');

    $feedItems = $database->prepare(
        'SELECT post_id FROM discovered_feed_items WHERE technical_source_id IN (?, ?)'
    );
    $feedItems->execute($sourceIds);
    $postIds = array_map('intval', $feedItems->fetchAll(PDO::FETCH_COLUMN));
    studio_assert(count($postIds) === 1, 'Idempotencja utworzyła dodatkowy post.');
    $topicStatement = $database->prepare(
        'SELECT memberships.topic_id FROM feed_topic_memberships AS memberships
         INNER JOIN discovered_feed_items AS items ON items.id = memberships.feed_item_id
         WHERE items.technical_source_id = :source_id LIMIT 1'
    );
    $topicStatement->execute([':source_id' => $sourceIds[0]]);
    $topicId = (int) $topicStatement->fetchColumn();
    studio_assert(content_studio_validate_topic_ids([(string) $topicId]) === [$topicId], 'Kontrakt topic_ids odrzuca poprawny temat.');
    $limitBlocked = false;
    try {
        content_studio_validate_topic_ids(range(1, CONTENT_STUDIO_BATCH_LIMIT + 1));
    } catch (InvalidArgumentException) {
        $limitBlocked = true;
    }
    studio_assert($limitBlocked, 'Limit partii nie został wyegzekwowany.');

    $stale = content_studio_create_job('timeout');
    $jobIds[] = (int) $stale['id'];
    content_studio_update_job((int) $stale['id'], [
        'status' => 'running',
        'heartbeat_at' => '2000-01-01 00:00:00',
    ]);
    studio_assert(content_studio_expire_stale_jobs() === 1, 'Timeout nie oznaczył zadania jako przerwanego.');
    studio_assert(content_studio_find_job((int) $stale['id'])['status'] === 'interrupted', 'Przerwany job ma zły stan.');
    $retry = content_studio_create_job('retry');
    $jobIds[] = (int) $retry['id'];
    studio_assert($retry['status'] === 'queued', 'Po timeout nie można bezpiecznie ponowić zadania.');
    content_studio_update_job((int) $retry['id'], ['status' => 'interrupted', 'stage' => 'interrupted', 'finished_at' => gmdate('Y-m-d H:i:s')]);

    $javascript = (string) file_get_contents(dirname(__DIR__) . '/assets/js/admin-editorial-topics.js');
    $studioPage = (string) file_get_contents(dirname(__DIR__) . '/php/admin-content-studio.php');
    studio_assert(str_contains($javascript, 'selected.size >= limit'), 'UI nie pilnuje limitu wyboru.');
    studio_assert(str_contains($javascript, 'selected.add(Number(card.dataset.topicId))'), 'UI nie zachowuje zaznaczeń w stabilnym zbiorze ID.');
    studio_assert(str_contains($javascript, 'visibleCards()'), 'UI nie implementuje filtrowania i zaznaczania widocznych.');
    studio_assert(
        str_contains($studioPage, 'action="admin-content-studio.php"')
            && str_contains($studioPage, "header('Location: admin-content-studio.php', true, 303)"),
        'Fallback formularza RSS może otworzyć surową odpowiedź JSON.'
    );
    $liveJavascript = (string) file_get_contents(dirname(__DIR__) . '/assets/js/admin-content-studio.js');
    studio_assert(str_contains($liveJavascript, 'schedulePoll()') && str_contains($liveJavascript, 'retry_in_ms'), 'Live status nie obsługuje odświeżania/retry bez reloadu.');
    studio_assert(str_contains($liveJavascript, '[result.advice, result.error]') && str_contains($liveJavascript, "join(' Szczegóły: ')"), 'Studio ukrywa dokładny błąd źródła za ogólną poradą.');
    $lockedAttempts = 0;
    $retriedWrite = persist_discovered_feed_item_with_retry([], [], static function () use (&$lockedAttempts): int {
        $lockedAttempts++;
        if ($lockedAttempts < 3) throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        return 42;
    });
    studio_assert($retriedWrite === 42 && $lockedAttempts === 3, 'Chwilowa blokada SQLite nie jest ponawiana w ograniczony sposób.');

    $busyAttempts = 0; $busyExhausted = false; try { persist_discovered_feed_item_with_retry([], [], static function () use (&$busyAttempts): int { $busyAttempts++; throw new PDOException('SQLSTATE[HY000]: General error: 5 database is busy'); }); } catch (PDOException $exception) { $busyExhausted = str_contains(strtolower($exception->getMessage()), 'database is busy'); } studio_assert($busyExhausted && $busyAttempts === 5, 'Trwała blokada SQLite nie jest ponawiana w ograniczony sposób.');

    echo "CONTENT_STUDIO_SMOKE_OK\n";
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
    foreach ($originalActivity as $sourceId => $active) {
        set_technical_source_active($sourceId, $active === 1);
    }
    foreach ($jobIds as $jobId) {
        $database->prepare('DELETE FROM editorial_ingestion_jobs WHERE id = :id')->execute([':id' => $jobId]);
    }
    $categoryId = (int) $database->query(
        "SELECT id FROM post_categories WHERE slug = 'automatyczne-znaleziska'"
    )->fetchColumn();
    if ($categoryId > 0 && $existingCategoryId === 0) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $categoryId]);
    }
}
