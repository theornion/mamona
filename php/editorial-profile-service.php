<?php

declare(strict_types=1);

const POPULAR_SCIENCE_PROFILE_KEY = 'popular_science';
const POPULAR_SCIENCE_CLEANUP_REASON = 'zmiana profilu redakcyjnego';

function list_editorial_profile_categories(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM editorial_profile_categories';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }

    return bueno_database()->query($sql . ' ORDER BY sort_order ASC, label ASC')->fetchAll();
}

function popular_science_category_slugs(): array
{
    return array_column(list_editorial_profile_categories(), 'slug');
}

function list_popular_science_sources(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM technical_sources WHERE profile_key = :profile_key';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $statement = bueno_database()->prepare(
        $sql . ' ORDER BY is_primary DESC, credibility_level DESC, name ASC'
    );
    $statement->execute([':profile_key' => POPULAR_SCIENCE_PROFILE_KEY]);

    return $statement->fetchAll();
}

function editorial_profile_cleanup_preview(): array
{
    $statement = bueno_database()->prepare(
        'SELECT topics.id AS topic_id, posts.id AS post_id, posts.title,
                GROUP_CONCAT(DISTINCT sources.name) AS source_names,
                COUNT(DISTINCT items.id) AS feed_item_count
         FROM editorial_topics AS topics
         INNER JOIN posts ON posts.id = topics.primary_post_id
         INNER JOIN feed_topic_memberships AS memberships ON memberships.topic_id = topics.id
         INNER JOIN discovered_feed_items AS items ON items.id = memberships.feed_item_id
         INNER JOIN technical_sources AS sources ON sources.id = items.technical_source_id
         WHERE posts.status = "idea"
         GROUP BY topics.id, posts.id
         HAVING SUM(
            CASE
                WHEN sources.profile_key != "developer" OR sources.is_active = 1 THEN 1
                ELSE 0
            END
         ) = 0
         ORDER BY datetime(topics.event_at) DESC, topics.id DESC'
    );
    $statement->execute();

    return $statement->fetchAll();
}

function execute_editorial_profile_cleanup(bool $confirmed, string $actor = 'admin'): array
{
    if (!$confirmed) {
        throw new InvalidArgumentException('Operacja wymaga jednoznacznego potwierdzenia.');
    }
    $actor = trim($actor) !== '' ? mb_substr(trim($actor), 0, 100) : 'admin';
    $preview = editorial_profile_cleanup_preview();
    $postIds = array_values(array_map(
        static fn (array $row): int => (int) $row['post_id'],
        $preview
    ));
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'INSERT INTO editorial_profile_cleanup_runs (
                profile_key, reason, status, preview_count,
                affected_post_ids_json, actor
             ) VALUES (
                :profile_key, :reason, "running", :preview_count,
                :post_ids, :actor
             )'
        );
        $statement->execute([
            ':profile_key' => POPULAR_SCIENCE_PROFILE_KEY,
            ':reason' => POPULAR_SCIENCE_CLEANUP_REASON,
            ':preview_count' => count($postIds),
            ':post_ids' => json_encode($postIds, JSON_THROW_ON_ERROR),
            ':actor' => $actor,
        ]);
        $runId = (int) $database->lastInsertId();
        $update = $database->prepare(
            'UPDATE posts
             SET status = "rejected", is_published = 0,
                 rejection_reason = :reason, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = "idea"'
        );
        $affected = 0;
        foreach ($postIds as $postId) {
            $update->execute([':reason' => POPULAR_SCIENCE_CLEANUP_REASON, ':id' => $postId]);
            if ($update->rowCount() !== 1) {
                continue;
            }
            record_post_status_change(
                $postId,
                'idea',
                'rejected',
                POPULAR_SCIENCE_CLEANUP_REASON,
                $actor
            );
            $affected++;
        }
        $database->prepare(
            'UPDATE editorial_profile_cleanup_runs
             SET status = "completed", affected_count = :affected_count,
                 completed_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([':affected_count' => $affected, ':id' => $runId]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return [
        'run_id' => $runId,
        'preview_count' => count($postIds),
        'affected_count' => $affected,
        'post_ids' => $postIds,
    ];
}

function list_editorial_profile_cleanup_runs(int $limit = 20): array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM editorial_profile_cleanup_runs
         ORDER BY id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function purge_developer_feed_records(string $archivePath, bool $confirmed): array
{
    if (!$confirmed) {
        throw new InvalidArgumentException('Trwałe usunięcie wymaga jawnego potwierdzenia.');
    }
    $database = bueno_database();
    $items = $database->query(
        'SELECT items.*, posts.status AS post_status, posts.title AS post_title,
                sources.name AS technical_source_name
         FROM discovered_feed_items AS items
         INNER JOIN posts ON posts.id = items.post_id
         INNER JOIN technical_sources AS sources ON sources.id = items.technical_source_id
         WHERE sources.profile_key = "developer"
         ORDER BY items.id ASC'
    )->fetchAll();
    $published = array_values(array_filter(
        $items,
        static fn (array $item): bool => $item['post_status'] === 'published'
    ));
    if ($published !== []) {
        throw new RuntimeException('W zakresie znajdują się opublikowane artykuły; usunięcie zostało zablokowane.');
    }
    $postIds = array_values(array_unique(array_map(
        static fn (array $item): int => (int) $item['post_id'],
        $items
    )));
    $feedItemIds = array_values(array_map(
        static fn (array $item): int => (int) $item['id'],
        $items
    ));
    $snapshot = [
        'exported_at' => gmdate(DATE_ATOM),
        'reason' => 'Jawne polecenie użytkownika: trwałe usunięcie starych wpisów deweloperskich.',
        'technical_sources' => $database->query(
            'SELECT * FROM technical_sources WHERE profile_key = "developer" ORDER BY id'
        )->fetchAll(),
        'feed_items' => $items,
        'posts' => [],
        'post_status_history' => [],
        'topic_grouping_history' => [],
    ];
    if ($postIds !== []) {
        $postPlaceholders = implode(',', array_fill(0, count($postIds), '?'));
        $statement = $database->prepare('SELECT * FROM posts WHERE id IN (' . $postPlaceholders . ') ORDER BY id');
        $statement->execute($postIds);
        $snapshot['posts'] = $statement->fetchAll();
        $statement = $database->prepare(
            'SELECT * FROM post_status_history WHERE post_id IN (' . $postPlaceholders . ') ORDER BY id'
        );
        $statement->execute($postIds);
        $snapshot['post_status_history'] = $statement->fetchAll();
    }
    if ($feedItemIds !== []) {
        $feedPlaceholders = implode(',', array_fill(0, count($feedItemIds), '?'));
        $statement = $database->prepare(
            'SELECT * FROM topic_grouping_history WHERE feed_item_id IN (' . $feedPlaceholders . ') ORDER BY id'
        );
        $statement->execute($feedItemIds);
        $snapshot['topic_grouping_history'] = $statement->fetchAll();
    }

    $directory = dirname($archivePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu archiwum.');
    }
    $temporaryPath = $archivePath . '.tmp-' . bin2hex(random_bytes(4));
    $encoded = json_encode(
        $snapshot,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false || !rename($temporaryPath, $archivePath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Nie można zapisać eksportu przed usunięciem.');
    }

    $database->beginTransaction();
    try {
        if ($feedItemIds !== []) {
            $feedPlaceholders = implode(',', array_fill(0, count($feedItemIds), '?'));
            foreach (['topic_grouping_candidates', 'topic_grouping_history'] as $table) {
                $statement = $database->prepare(
                    'DELETE FROM ' . $table . ' WHERE feed_item_id IN (' . $feedPlaceholders . ')'
                );
                $statement->execute($feedItemIds);
            }
        }
        if ($postIds !== []) {
            $postPlaceholders = implode(',', array_fill(0, count($postIds), '?'));
            $statement = $database->prepare(
                'DELETE FROM posts
                 WHERE id IN (' . $postPlaceholders . ')
                   AND status != "published"'
            );
            $statement->execute($postIds);
            if ($statement->rowCount() !== count($postIds)) {
                throw new RuntimeException('Nie usunięto dokładnie sprawdzonego zestawu starych postów.');
            }
        }
        $database->prepare(
            'INSERT INTO editorial_profile_cleanup_runs (
                profile_key, reason, status, preview_count, affected_count,
                affected_post_ids_json, actor, completed_at
             ) VALUES (
                :profile_key, :reason, "permanently_deleted", :count, :count,
                :post_ids, "codex-user-request", CURRENT_TIMESTAMP
             )'
        )->execute([
            ':profile_key' => POPULAR_SCIENCE_PROFILE_KEY,
            ':reason' => 'jawne polecenie trwałego usunięcia po zmianie profilu',
            ':count' => count($postIds),
            ':post_ids' => json_encode($postIds, JSON_THROW_ON_ERROR),
        ]);
        $runId = (int) $database->lastInsertId();
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return [
        'run_id' => $runId,
        'deleted_feed_items' => count($feedItemIds),
        'deleted_posts' => count($postIds),
        'archive_path' => $archivePath,
    ];
}
