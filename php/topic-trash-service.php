<?php

declare(strict_types=1);

const TOPIC_TRASH_RETENTION_DAYS = 10;

function topic_trash_utc(?DateTimeImmutable $now = null): DateTimeImmutable
{
    return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
}

function topic_trash_timestamp(?DateTimeImmutable $now = null): string
{
    return topic_trash_utc($now)->format('Y-m-d H:i:s');
}

function topic_trash_audit(int $topicId, string $title, string $action, string $actor, string $reason = '', string $origin = 'admin', array $details = []): void
{
    bueno_database()->prepare(
        'INSERT INTO topic_trash_audit (topic_id, topic_title, action, actor, reason, origin, details_json)
         VALUES (:topic_id, :title, :action, :actor, :reason, :origin, :details)'
    )->execute([
        ':topic_id' => $topicId,
        ':title' => mb_substr($title, 0, 500),
        ':action' => mb_substr($action, 0, 50),
        ':actor' => mb_substr($actor, 0, 100),
        ':reason' => mb_substr(trim($reason), 0, 1000),
        ':origin' => mb_substr($origin, 0, 100),
        ':details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ]);
}

function topic_active_processes(int $topicId): array
{
    $statement = bueno_database()->prepare(
        'SELECT id, batch_id, status, stage, progress_percent, wait_reason, error_message, updated_at
         FROM generation_batch_items
         WHERE topic_id = :topic_id
           AND status IN ("queued", "research", "draft", "quality_check", "images", "rate_limited")
         ORDER BY id DESC'
    );
    $statement->execute([':topic_id' => $topicId]);
    return $statement->fetchAll();
}

function trash_editorial_topic(int $topicId, string $actor = 'admin', string $reason = '', string $origin = 'admin', ?DateTimeImmutable $now = null): void
{
    if ($topicId < 1) throw new InvalidArgumentException('Nieprawidłowy identyfikator tematu.');
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'SELECT topics.*, posts.status AS current_post_status FROM editorial_topics AS topics
             INNER JOIN posts ON posts.id = topics.primary_post_id
             WHERE topics.id = :id AND topics.trashed_at IS NULL AND topics.purged_at IS NULL'
        );
        $statement->execute([':id' => $topicId]);
        $topic = $statement->fetch();
        if (!is_array($topic)) throw new RuntimeException('Temat nie istnieje, jest już w koszu albo został trwale usunięty.');
        $active = topic_active_processes($topicId);
        if ($active !== []) {
            throw new DomainException(sprintf(
                'Nie można przenieść tematu do kosza: batch #%d jest w stanie „%s”. Anuluj bezpiecznie proces lub poczekaj na jego zakończenie.',
                (int) $active[0]['batch_id'],
                (string) $active[0]['status']
            ));
        }
        $database->prepare(
            'UPDATE editorial_topics
             SET trashed_at = :at, trashed_by = :actor, trash_reason = :reason, trash_origin = :origin,
                 pre_trash_automatic_eligible = automatic_eligible,
                 pre_trash_post_status = :post_status, pre_trash_score = score,
                 automatic_eligible = 0, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND trashed_at IS NULL AND purged_at IS NULL'
        )->execute([
            ':at' => topic_trash_timestamp($now), ':actor' => mb_substr($actor, 0, 100),
            ':reason' => mb_substr(trim($reason), 0, 1000), ':origin' => mb_substr($origin, 0, 100),
            ':post_status' => (string) $topic['current_post_status'], ':id' => $topicId,
        ]);
        topic_trash_audit($topicId, (string) $topic['title'], 'trashed', $actor, $reason, $origin);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

function restore_editorial_topic(int $topicId, string $actor = 'admin', ?DateTimeImmutable $now = null): void
{
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare('SELECT * FROM editorial_topics WHERE id = :id AND trashed_at IS NOT NULL AND purged_at IS NULL');
        $statement->execute([':id' => $topicId]);
        $topic = $statement->fetch();
        if (!is_array($topic)) throw new RuntimeException('Tematu nie można przywrócić.');
        $database->prepare(
            'UPDATE editorial_topics SET trashed_at = NULL, trashed_by = NULL, trash_reason = "", trash_origin = "admin",
                 automatic_eligible = COALESCE(pre_trash_automatic_eligible, automatic_eligible), pre_trash_automatic_eligible = NULL,
                 pre_trash_post_status = NULL, pre_trash_score = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND purged_at IS NULL'
        )->execute([':id' => $topicId]);
        topic_trash_audit($topicId, (string) $topic['title'], 'restored', $actor, '', 'admin', [
            'previous_trashed_at' => $topic['trashed_at'], 'restored_at' => topic_trash_timestamp($now),
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

function permanently_purge_editorial_topic(int $topicId, string $actor = 'admin', string $reason = '', ?DateTimeImmutable $now = null): bool
{
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'SELECT topics.*, posts.status AS post_status,
                    (SELECT COUNT(*) FROM post_sources WHERE post_id = topics.primary_post_id) AS source_count,
                    (SELECT COUNT(*) FROM article_draft_versions WHERE topic_id = topics.id) AS draft_count,
                    (SELECT COUNT(*) FROM research_packages WHERE topic_id = topics.id) AS research_count
             FROM editorial_topics AS topics INNER JOIN posts ON posts.id = topics.primary_post_id
             WHERE topics.id = :id AND topics.trashed_at IS NOT NULL AND topics.purged_at IS NULL'
        );
        $statement->execute([':id' => $topicId]);
        $topic = $statement->fetch();
        if (!is_array($topic)) { $database->rollBack(); return false; }
        if (topic_active_processes($topicId) !== []) throw new DomainException('Temat ma aktywny proces i nie może zostać trwale usunięty.');
        $database->prepare(
            'UPDATE editorial_topics
             SET purged_at = :at, purged_by = :actor, automatic_eligible = 0, grouping_locked = 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND purged_at IS NULL'
        )->execute([':at' => topic_trash_timestamp($now), ':actor' => mb_substr($actor, 0, 100), ':id' => $topicId]);
        topic_trash_audit($topicId, (string) $topic['title'], 'purged_tombstone', $actor, $reason, 'retention', [
            'post_status' => $topic['post_status'], 'source_count' => (int) $topic['source_count'],
            'draft_count' => (int) $topic['draft_count'], 'research_count' => (int) $topic['research_count'],
            'policy' => 'tombstone_preserves_publication_sources_licenses_and_audit',
        ]);
        $database->commit();
        return true;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

function list_trashed_editorial_topics(string $search = '', string $status = '', string $sort = 'deadline', int $limit = 500): array
{
    $sortSql = match ($sort) {
        'title' => 'topics.title COLLATE NOCASE ASC',
        'score' => 'COALESCE(topics.score, -1) DESC, topics.trashed_at ASC',
        'trashed' => 'topics.trashed_at DESC',
        default => 'datetime(topics.trashed_at, "+10 days") ASC, topics.id ASC',
    };
    $where = ['topics.trashed_at IS NOT NULL', 'topics.purged_at IS NULL'];
    $params = [];
    if (trim($search) !== '') { $where[] = '(topics.title LIKE :search OR items.source_name LIKE :search)'; $params[':search'] = '%' . trim($search) . '%'; }
    if ($status !== '') { $where[] = 'posts.status = :status'; $params[':status'] = $status; }
    $statement = bueno_database()->prepare(
        'SELECT topics.*, COALESCE(topics.pre_trash_post_status, posts.status) AS post_status,
                COALESCE(topics.pre_trash_score, topics.score) AS score_before_trash,
                COUNT(DISTINCT memberships.feed_item_id) AS item_count,
                COUNT(DISTINCT items.technical_source_id) AS source_count,
                GROUP_CONCAT(DISTINCT items.source_name) AS source_names,
                (SELECT COUNT(*) FROM generation_batch_items WHERE topic_id = topics.id) AS operation_count,
                (SELECT COUNT(*) FROM article_draft_versions WHERE topic_id = topics.id) AS draft_count,
                (SELECT COUNT(*) FROM research_packages WHERE topic_id = topics.id) AS research_count,
                (SELECT COUNT(*) FROM posts AS publication WHERE publication.id = topics.primary_post_id AND publication.status = "published") AS publication_count,
                datetime(topics.trashed_at, "+10 days") AS purge_due_at
         FROM editorial_topics AS topics INNER JOIN posts ON posts.id = topics.primary_post_id
         LEFT JOIN feed_topic_memberships AS memberships ON memberships.topic_id = topics.id
         LEFT JOIN discovered_feed_items AS items ON items.id = memberships.feed_item_id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY topics.id ORDER BY ' . $sortSql . ' LIMIT :limit'
    );
    foreach ($params as $key => $value) $statement->bindValue($key, $value);
    $statement->bindValue(':limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function count_trashed_editorial_topics(): int
{
    return (int) bueno_database()->query('SELECT COUNT(*) FROM editorial_topics WHERE trashed_at IS NOT NULL AND purged_at IS NULL')->fetchColumn();
}

function topic_retention_days_remaining(string $trashedAt, ?DateTimeImmutable $now = null): int
{
    $trashed = new DateTimeImmutable($trashedAt, new DateTimeZone('UTC'));
    $seconds = $trashed->modify('+' . TOPIC_TRASH_RETENTION_DAYS . ' days')->getTimestamp() - topic_trash_utc($now)->getTimestamp();
    return max(0, (int) ceil($seconds / 86400));
}

function cleanup_trashed_editorial_topics(?DateTimeImmutable $now = null, string $actor = 'maintenance'): array
{
    $now = topic_trash_utc($now);
    $startedAt = topic_trash_timestamp();
    $cutoff = $now->modify('-' . TOPIC_TRASH_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');
    $statement = bueno_database()->prepare(
        'SELECT id FROM editorial_topics WHERE trashed_at IS NOT NULL AND purged_at IS NULL
         AND datetime(trashed_at) <= datetime(:cutoff) ORDER BY trashed_at, id'
    );
    $statement->execute([':cutoff' => $cutoff]);
    $result = ['deleted' => 0, 'skipped' => 0, 'errors' => []];
    foreach (array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)) as $id) {
        try {
            if (permanently_purge_editorial_topic($id, $actor, 'Automatyczne czyszczenie po 10 dniach', $now)) $result['deleted']++;
            else $result['skipped']++;
        } catch (Throwable $exception) {
            $result['errors'][] = ['topic_id' => $id, 'message' => $exception->getMessage()];
        }
    }
    bueno_database()->prepare(
        'INSERT INTO topic_trash_cleanup_runs (cutoff_at, deleted_count, skipped_count, error_count, error_json, started_at, finished_at)
         VALUES (:cutoff, :deleted, :skipped, :errors, :json, :started, :finished)'
    )->execute([
        ':cutoff' => $cutoff, ':deleted' => $result['deleted'], ':skipped' => $result['skipped'],
        ':errors' => count($result['errors']), ':json' => json_encode($result['errors'], JSON_UNESCAPED_UNICODE) ?: '[]',
        ':started' => $startedAt, ':finished' => topic_trash_timestamp(),
    ]);
    return $result + ['cutoff_at' => $cutoff];
}
