<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_SCHEDULER_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_SCHEDULER_SMOKE=1, aby uruchomić test na lokalnej bazie.\n");
    exit(2);
}

putenv('CMS_PUBLIC_URL=https://example.test');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_AUTOMATIC_PUBLISHING=true');
putenv('CMS_DAILY_PUBLICATION_LIMIT=3');
require_once dirname(__DIR__) . '/php/admin-database.php';

function scheduler_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = bueno_database();
$token = bin2hex(random_bytes(5));
$categoryId = 0;
$postIds = [];
$logPath = scheduled_publication_log_path();
$lockPath = scheduled_publication_lock_path();
$logBefore = is_file($logPath) ? file_get_contents($logPath) : null;
$lockBefore = is_file($lockPath) ? file_get_contents($lockPath) : null;
$now = new DateTimeImmutable('2030-01-10 12:00:00', new DateTimeZone('UTC'));

try {
    $database->prepare(
        'INSERT INTO post_categories (title, description, slug, sort_order)
         VALUES (:title, :description, :slug, 999999)'
    )->execute([
        ':title' => 'Scheduler ' . $token,
        ':description' => 'Test schedulera.',
        ':slug' => 'scheduler-' . $token,
    ]);
    $categoryId = (int) $database->lastInsertId();

    foreach ([1, 2, 3, 4] as $number) {
        $postId = create_post($categoryId, 'Scheduler wpis ' . $number . ' ' . $token, 'Opis.', 'Treść.');
        $postIds[] = $postId;
        $scheduledAt = $number === 4 ? '2030-01-11 12:00:00' : '2030-01-10 10:0' . $number . ':00';
        $database->prepare(
            "UPDATE posts SET status = 'scheduled', is_published = 0, scheduled_at = :scheduled_at WHERE id = :id"
        )->execute([':scheduled_at' => $scheduledAt, ':id' => $postId]);
        record_post_status_change($postId, 'draft', 'scheduled', 'Przygotowanie testu', 'test');
    }

    $dryRun = run_scheduled_publications(true, $now);
    scheduler_assert(count($dryRun['candidates']) === 3, 'Dry-run nie pokazuje trzech należnych publikacji.');
    scheduler_assert(count(list_editorial_queue('scheduled')) >= 4, 'Dry-run zmienił status materiałów.');

    $heldLock = fopen($lockPath, 'c+');
    scheduler_assert($heldLock !== false && flock($heldLock, LOCK_EX | LOCK_NB), 'Nie udało się przygotować testu blokady.');
    $locked = run_scheduled_publications(false, $now);
    scheduler_assert($locked['status'] === 'locked', 'Równoległe uruchomienie nie zostało zablokowane.');
    flock($heldLock, LOCK_UN);
    fclose($heldLock);

    $attempt = 0;
    $firstRun = run_scheduled_publications(false, $now, static function (array $post) use (&$attempt, $now): void {
        $attempt++;
        if ($attempt === 1) {
            throw new RuntimeException('Kontrolowany błąd pierwszego materiału.');
        }
        change_post_editorial_status((int) $post['id'], 'published', 'Test schedulera', 'scheduler', $now->format('Y-m-d H:i:s'));
    });
    scheduler_assert(count($firstRun['failed']) === 1, 'Nie zapisano kontrolowanego błędu.');
    scheduler_assert(count($firstRun['published']) === 2, 'Błąd pierwszego materiału zablokował kolejne.');

    $secondRun = run_scheduled_publications(false, $now);
    scheduler_assert(count($secondRun['published']) === 1, 'Drugie uruchomienie nie opublikowało pozostałego materiału.');
    $thirdRun = run_scheduled_publications(false, $now);
    scheduler_assert($thirdRun['published'] === [], 'Trzecie uruchomienie opublikowało artykuł ponownie.');
    scheduler_assert($thirdRun['capacity'] === 0, 'Scheduler nie respektuje dziennego limitu.');
    scheduler_assert(find_post($postIds[3])['status'] === 'scheduled', 'Artykuł z przyszłości został opublikowany.');

    $history = list_post_status_history($postIds[0]);
    $schedulerEntries = array_filter($history, static fn (array $entry): bool => $entry['actor'] === 'scheduler');
    scheduler_assert(count($schedulerEntries) === 1, 'Publikacja schedulera nie ma jednoznacznej historii.');
    $log = (string) file_get_contents($logPath);
    scheduler_assert(str_contains($log, '"event":"post_failed"'), 'Log nie zawiera błędu materiału.');
    scheduler_assert(str_contains($log, '"event":"post_published"'), 'Log nie zawiera sukcesu publikacji.');

    echo "SCHEDULED_PUBLICATION_SMOKE_OK\n";
} finally {
    foreach ($postIds as $postId) {
        $post = find_post($postId, true);
        if ($post !== null && $post['deleted_at'] === null) {
            delete_post($postId);
        }
        permanently_delete_post($postId);
    }
    if ($categoryId > 0) {
        $database->prepare('DELETE FROM post_categories WHERE id = :id')->execute([':id' => $categoryId]);
    }
    if (is_string($logBefore)) {
        write_public_file_atomically($logPath, $logBefore);
    } elseif (is_file($logPath)) {
        unlink($logPath);
    }
    if (is_string($lockBefore)) {
        write_public_file_atomically($lockPath, $lockBefore);
    } elseif (is_file($lockPath)) {
        unlink($lockPath);
    }
}
