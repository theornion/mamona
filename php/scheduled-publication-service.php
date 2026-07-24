<?php

declare(strict_types=1);

function scheduled_publication_log_path(): string
{
    return app_path('data/scheduled-publication.log');
}

function scheduled_publication_lock_path(): string
{
    return app_path('data/scheduled-publication.lock');
}

function scheduled_publication_log(string $event, array $context = []): void
{
    $entry = [
        'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        'event' => $event,
        'context' => $context,
    ];
    $line = json_encode(
        $entry,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($line)
        || file_put_contents(scheduled_publication_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Nie można zapisać logu planowanych publikacji.');
    }
}

function scheduled_publication_utc_now(?DateTimeImmutable $now = null): DateTimeImmutable
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return $now->setTimezone(new DateTimeZone('UTC'));
}

function scheduled_publication_day_bounds(DateTimeImmutable $now): array
{
    $local = $now->setTimezone(new DateTimeZone((string) app_config('timezone')));
    $start = $local->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
    $end = $start->modify('+1 day');

    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function count_publications_for_scheduler_day(DateTimeImmutable $now): int
{
    [$start, $end] = scheduled_publication_day_bounds($now);
    $statement = bueno_database()->prepare(
        "SELECT COUNT(*) FROM posts
         WHERE status = 'published' AND deleted_at IS NULL
           AND published_at >= :start AND published_at < :end"
    );
    $statement->execute([':start' => $start, ':end' => $end]);

    return (int) $statement->fetchColumn();
}

function list_due_scheduled_posts(DateTimeImmutable $now, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }
    $statement = bueno_database()->prepare(
        "SELECT posts.*
         FROM posts
         INNER JOIN post_categories ON post_categories.id = posts.category_id
         LEFT JOIN editorial_topics ON editorial_topics.primary_post_id = posts.id
         WHERE posts.status = 'scheduled'
           AND posts.deleted_at IS NULL
           AND post_categories.deleted_at IS NULL
           AND posts.scheduled_at IS NOT NULL
           AND datetime(posts.scheduled_at) <= datetime(:now)
           AND (editorial_topics.id IS NULL OR editorial_topics.automatic_eligible = 1)
         ORDER BY datetime(posts.scheduled_at) ASC, posts.id ASC
         LIMIT :limit"
    );
    $statement->bindValue(':now', $now->format('Y-m-d H:i:s'));
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function run_scheduled_publications(
    bool $dryRun = false,
    ?DateTimeImmutable $now = null,
    ?callable $publisher = null
): array {
    if (!$dryRun && !app_config('automatic_publishing')) {
        throw new RuntimeException('Automatyczna publikacja jest wyłączona. Ustaw CMS_AUTOMATIC_PUBLISHING=true.');
    }

    $lockHandle = fopen(scheduled_publication_lock_path(), 'c+');
    if ($lockHandle === false) {
        throw new RuntimeException('Nie można otworzyć blokady schedulera.');
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return [
            'status' => 'locked',
            'dry_run' => $dryRun,
            'published' => [],
            'failed' => [],
            'candidates' => [],
        ];
    }

    try {
        $now = scheduled_publication_utc_now($now);
        $limit = (int) app_config('daily_publication_limit');
        $alreadyPublished = count_publications_for_scheduler_day($now);
        $capacity = max(0, $limit - $alreadyPublished);
        $candidates = list_due_scheduled_posts($now, $capacity);
        $result = [
        'status' => 'completed',
        'dry_run' => $dryRun,
        'daily_limit' => $limit,
        'already_published_today' => $alreadyPublished,
        'capacity' => $capacity,
        'candidates' => array_map(static fn (array $post): array => [
            'id' => (int) $post['id'],
            'title' => (string) $post['title'],
            'scheduled_at' => (string) $post['scheduled_at'],
        ], $candidates),
        'published' => [],
        'failed' => [],
        ];

        scheduled_publication_log('run_started', [
            'dry_run' => $dryRun,
            'capacity' => $capacity,
            'candidate_ids' => array_column($result['candidates'], 'id'),
        ]);
        if ($dryRun) {
            scheduled_publication_log('dry_run_completed', ['candidates' => $result['candidates']]);
            return $result;
        }

        $publisher ??= static function (array $post) use ($now): void {
            $fresh = find_post((int) $post['id']);
            if ($fresh === null || $fresh['status'] !== 'scheduled') {
                throw new RuntimeException('Materiał nie ma już statusu scheduled.');
            }
            change_post_editorial_status(
                (int) $post['id'],
                'published',
                'Automatyczna publikacja po osiągnięciu zaplanowanego czasu',
                'scheduler',
                $now->format('Y-m-d H:i:s')
            );
        };

        foreach ($candidates as $post) {
            try {
                $publisher($post);
                $result['published'][] = (int) $post['id'];
                scheduled_publication_log('post_published', [
                    'post_id' => (int) $post['id'],
                    'title' => (string) $post['title'],
                ]);
            } catch (Throwable $exception) {
                $failure = [
                    'post_id' => (int) $post['id'],
                    'message' => $exception->getMessage(),
                ];
                $result['failed'][] = $failure;
                scheduled_publication_log('post_failed', $failure);
            }
        }
        scheduled_publication_log('run_completed', [
            'published' => $result['published'],
            'failed' => $result['failed'],
        ]);

        return $result;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
