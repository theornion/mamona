<?php

declare(strict_types=1);

const TOPIC_GROUPING_WINDOW_HOURS = 72;
const TOPIC_AUTOMATIC_MATCH_THRESHOLD = 0.74;
const TOPIC_SUGGESTED_MATCH_THRESHOLD = 0.50;

function normalize_event_title(string $title, string $sourceName = ''): string
{
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = mb_strtolower($title);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    $title = is_string($ascii) ? strtolower($ascii) : $title;
    $source = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($sourceName));
    $source = preg_replace('/[^a-z0-9]+/', ' ', is_string($source) ? $source : '') ?? '';

    $title = preg_replace('/\[[^\]]{1,40}\]|\([^\)]{1,40}\)$/', ' ', $title) ?? $title;
    if (trim($source) !== '') {
        $sourcePattern = preg_quote(trim($source), '/');
        $title = preg_replace('/(?:\s*[-|:]\s*)?' . $sourcePattern . '\s*$/', ' ', $title) ?? $title;
    }
    $title = preg_replace(
        '/\b(announcing|announce[sd]?|introducing|launch(?:es|ed)?|now available|'
        . 'general availability|official blog|update|aktualizacja|premiera|przedstawia|'
        . 'oglasza|nowosc|new release)\b/',
        ' ',
        $title
    ) ?? $title;
    $title = preg_replace('/[^a-z0-9.+#-]+/', ' ', $title) ?? '';
    $tokens = preg_split('/\s+/', trim($title)) ?: [];
    $stopWords = array_flip([
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'in',
        'into', 'is', 'of', 'on', 'or', 'our', 'the', 'to', 'with', 'your',
        'i', 'na', 'oraz', 'po', 'w', 'we', 'z', 'ze', 'dla', 'do', 'jak',
        'now', 'new', 'latest', 'today', 'using', 'use', 'build', 'building',
    ]);
    $tokens = array_values(array_unique(array_filter(
        $tokens,
        static fn (string $token): bool => mb_strlen($token) >= 2 && !isset($stopWords[$token])
    )));
    sort($tokens, SORT_STRING);

    return implode(' ', $tokens);
}

function event_title_features(string $title, string $sourceName = ''): array
{
    $normalized = normalize_event_title($title, $sourceName);
    $tokens = $normalized === '' ? [] : explode(' ', $normalized);
    $knownEntities = [
        'adk', 'amd', 'android', 'anthropic', 'apple', 'aws', 'azure',
        'chatgpt', 'cloudflare', 'docker', 'gemini', 'github', 'google',
        'intel', 'ios', 'kubernetes', 'meta', 'microsoft', 'nvidia', 'openai',
        'python', 'react', 'typescript', 'windows',
    ];
    $entities = array_values(array_intersect($tokens, $knownEntities));
    $informative = array_values(array_diff($tokens, $knownEntities));
    $models = array_values(array_filter(
        $tokens,
        static fn (string $token): bool => preg_match('/[a-z].*\d|\d.*[a-z]/', $token) === 1
    ));

    return [
        'normalized' => $normalized,
        'tokens' => $tokens,
        'entities' => $entities,
        'informative' => $informative,
        'models' => $models,
    ];
}

function compare_feed_events(array $left, array $right): array
{
    if ((string) $left['source_url'] === (string) $right['source_url']) {
        return ['confidence' => 1.0, 'automatic' => true, 'explanation' => 'Identyczny URL źródłowy.'];
    }
    if ((string) $left['content_hash'] === (string) $right['content_hash']) {
        return ['confidence' => 0.99, 'automatic' => true, 'explanation' => 'Identyczny skrót tytułu i opisu.'];
    }

    $leftFeatures = event_title_features((string) $left['title'], (string) ($left['source_name'] ?? ''));
    $rightFeatures = event_title_features((string) $right['title'], (string) ($right['source_name'] ?? ''));
    $leftTokens = $leftFeatures['tokens'];
    $rightTokens = $rightFeatures['tokens'];
    $shared = array_values(array_intersect($leftTokens, $rightTokens));
    $sharedInformative = array_values(array_intersect($leftFeatures['informative'], $rightFeatures['informative']));
    $sharedEntities = array_values(array_intersect($leftFeatures['entities'], $rightFeatures['entities']));
    $union = array_values(array_unique(array_merge($leftTokens, $rightTokens)));

    if ($leftTokens === [] || $rightTokens === [] || $shared === []) {
        return ['confidence' => 0.0, 'automatic' => false, 'explanation' => 'Brak wspólnych istotnych słów.'];
    }

    $dice = (2 * count($shared)) / (count($leftTokens) + count($rightTokens));
    $coverage = count($shared) / max(1, min(count($leftTokens), count($rightTokens)));
    $entityBonus = $sharedEntities !== [] ? 1.0 : 0.0;
    $confidence = (0.55 * $dice) + (0.35 * $coverage) + (0.10 * $entityBonus);

    // Ta sama firma bez wspólnego opisu zdarzenia nigdy nie wystarcza do połączenia.
    if ($sharedInformative === []) {
        $confidence = min($confidence, 0.35);
    } elseif (count($sharedInformative) === 1 && count($shared) < 3) {
        $confidence = min($confidence, 0.58);
    }
    if (
        $leftFeatures['models'] !== []
        && $rightFeatures['models'] !== []
        && array_intersect($leftFeatures['models'], $rightFeatures['models']) === []
    ) {
        $confidence = min($confidence, 0.49);
    }

    $confidence = round(max(0.0, min(1.0, $confidence)), 4);
    $automatic = $confidence >= TOPIC_AUTOMATIC_MATCH_THRESHOLD
        && (count($sharedInformative) >= 2 || (count($shared) >= 3 && $coverage >= 0.8));

    return [
        'confidence' => $confidence,
        'automatic' => $automatic,
        'explanation' => sprintf(
            'Wspólne słowa: %s; podobieństwo %.1f%%.',
            $shared !== [] ? implode(', ', $shared) : 'brak',
            $confidence * 100
        ),
    ];
}

function find_editorial_topic(int $topicId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT editorial_topics.*, posts.status AS primary_post_status
         FROM editorial_topics
         INNER JOIN posts ON posts.id = editorial_topics.primary_post_id
         WHERE editorial_topics.id = :id'
    );
    $statement->execute([':id' => $topicId]);
    $topic = $statement->fetch();

    return is_array($topic) ? $topic : null;
}

function ensure_topic_for_feed_item(int $feedItemId): int
{
    $database = bueno_database();
    $statement = $database->prepare(
        'SELECT topic_id FROM feed_topic_memberships WHERE feed_item_id = :feed_item_id'
    );
    $statement->execute([':feed_item_id' => $feedItemId]);
    $topicId = (int) $statement->fetchColumn();
    if ($topicId > 0) {
        return $topicId;
    }

    $statement = $database->prepare(
        'SELECT * FROM discovered_feed_items WHERE id = :id'
    );
    $statement->execute([':id' => $feedItemId]);
    $item = $statement->fetch();
    if (!is_array($item)) {
        throw new RuntimeException('Nie znaleziono wpisu źródłowego.');
    }

    $normalized = normalize_event_title((string) $item['title'], (string) $item['source_name']);
    $statement = $database->prepare(
        'INSERT INTO editorial_topics (
            primary_post_id, title, normalized_title, event_at
         ) VALUES (
            :primary_post_id, :title, :normalized_title, :event_at
         )'
    );
    $statement->execute([
        ':primary_post_id' => (int) $item['post_id'],
        ':title' => $item['title'],
        ':normalized_title' => $normalized,
        ':event_at' => $item['published_at'] ?: $item['first_detected_at'],
    ]);
    $topicId = (int) $database->lastInsertId();
    $statement = $database->prepare(
        'INSERT INTO feed_topic_memberships (
            feed_item_id, topic_id, confidence, match_method
         ) VALUES (:feed_item_id, :topic_id, 1, "single")'
    );
    $statement->execute([':feed_item_id' => $feedItemId, ':topic_id' => $topicId]);

    return $topicId;
}

function topic_feed_items(int $topicId): array
{
    $statement = bueno_database()->prepare(
        'SELECT discovered_feed_items.*, feed_topic_memberships.confidence,
                feed_topic_memberships.match_method
         FROM feed_topic_memberships
         INNER JOIN discovered_feed_items
            ON discovered_feed_items.id = feed_topic_memberships.feed_item_id
         WHERE feed_topic_memberships.topic_id = :topic_id
         ORDER BY datetime(COALESCE(discovered_feed_items.published_at, discovered_feed_items.first_detected_at)) ASC,
                  discovered_feed_items.id ASC'
    );
    $statement->execute([':topic_id' => $topicId]);

    return $statement->fetchAll();
}

function sync_topic_post_sources(int $topicId): void
{
    $topic = find_editorial_topic($topicId);
    if ($topic === null) {
        return;
    }
    $sources = [];
    foreach (topic_feed_items($topicId) as $item) {
        $source = find_technical_source((int) $item['technical_source_id']);
        $sources[] = [
            'source_url' => $item['source_url'],
            'source_title' => $item['title'],
            'publisher_name' => $item['source_name'],
            'source_type' => !empty($source['is_primary']) ? 'primary' : 'secondary',
            'source_published_at' => $item['published_at'],
        ];
    }
    replace_post_sources((int) $topic['primary_post_id'], $sources);
}

function set_grouped_post_state(int $postId, int $primaryPostId, string $method): void
{
    if ($postId === $primaryPostId) {
        return;
    }
    $post = find_post($postId, true);
    if ($post === null || $post['deleted_at'] !== null || $post['status'] !== 'idea') {
        return;
    }
    $reason = ($method === 'manual' ? 'Połączono ręcznie' : 'Połączono automatycznie')
        . ' z tematem posta #' . $primaryPostId . '.';
    $statement = bueno_database()->prepare(
        'UPDATE posts
         SET status = "rejected", is_published = 0, rejection_reason = :reason,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([':id' => $postId, ':reason' => $reason]);
    record_post_status_change($postId, 'idea', 'rejected', $reason, 'topic-grouping');
}

function restore_split_post_state(int $postId): void
{
    $post = find_post($postId, true);
    if (
        $post === null
        || $post['deleted_at'] !== null
        || $post['status'] !== 'rejected'
        || !str_starts_with((string) $post['rejection_reason'], 'Połączono ')
    ) {
        return;
    }
    $statement = bueno_database()->prepare(
        'UPDATE posts
         SET status = "idea", rejection_reason = "", updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([':id' => $postId]);
    record_post_status_change($postId, 'rejected', 'idea', 'Cofnięto grupowanie wpisu źródłowego.', 'topic-grouping');
}

function move_feed_item_to_topic(
    int $feedItemId,
    int $targetTopicId,
    float $confidence,
    string $method,
    string $actor = 'system'
): void {
    $database = bueno_database();
    $target = find_editorial_topic($targetTopicId);
    if ($target === null) {
        throw new RuntimeException('Nie znaleziono docelowego tematu.');
    }
    $statement = $database->prepare(
        'SELECT feed_topic_memberships.topic_id, discovered_feed_items.post_id
         FROM feed_topic_memberships
         INNER JOIN discovered_feed_items ON discovered_feed_items.id = feed_topic_memberships.feed_item_id
         WHERE feed_topic_memberships.feed_item_id = :feed_item_id'
    );
    $statement->execute([':feed_item_id' => $feedItemId]);
    $membership = $statement->fetch();
    if (!is_array($membership)) {
        throw new RuntimeException('Wpis nie ma przypisanego tematu.');
    }
    $fromTopicId = (int) $membership['topic_id'];
    if ($fromTopicId === $targetTopicId) {
        return;
    }

    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            'UPDATE feed_topic_memberships
             SET topic_id = :topic_id, confidence = :confidence, match_method = :method,
                 created_at = CURRENT_TIMESTAMP
             WHERE feed_item_id = :feed_item_id'
        );
        $statement->execute([
            ':topic_id' => $targetTopicId,
            ':confidence' => max(0.0, min(1.0, $confidence)),
            ':method' => $method,
            ':feed_item_id' => $feedItemId,
        ]);
        $statement = $database->prepare(
            'INSERT INTO topic_grouping_history (
                feed_item_id, from_topic_id, to_topic_id, action, confidence, actor
             ) VALUES (
                :feed_item_id, :from_topic_id, :to_topic_id, :action, :confidence, :actor
             )'
        );
        $statement->execute([
            ':feed_item_id' => $feedItemId,
            ':from_topic_id' => $fromTopicId,
            ':to_topic_id' => $targetTopicId,
            ':action' => $method === 'manual' ? 'manual_merge' : 'automatic_merge',
            ':confidence' => $confidence,
            ':actor' => $actor,
        ]);
        set_grouped_post_state(
            (int) $membership['post_id'],
            (int) $target['primary_post_id'],
            $method
        );
        $remaining = $database->prepare(
            'SELECT COUNT(*) FROM feed_topic_memberships WHERE topic_id = :topic_id'
        );
        $remaining->execute([':topic_id' => $fromTopicId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $database->prepare('DELETE FROM editorial_topics WHERE id = :id')
                ->execute([':id' => $fromTopicId]);
        }
        $database->prepare(
            'UPDATE editorial_topics SET updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':id' => $targetTopicId]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    sync_topic_post_sources($targetTopicId);
    if (function_exists('score_editorial_topic')) {
        score_editorial_topic($targetTopicId);
    }
}

function save_topic_candidate(
    int $feedItemId,
    int $topicId,
    float $confidence,
    string $explanation,
    string $status = 'suggested'
): void {
    $statement = bueno_database()->prepare(
        'INSERT INTO topic_grouping_candidates (
            feed_item_id, candidate_topic_id, confidence, explanation, status
         ) VALUES (
            :feed_item_id, :topic_id, :confidence, :explanation, :status
         )
         ON CONFLICT(feed_item_id, candidate_topic_id) DO UPDATE SET
            confidence = excluded.confidence,
            explanation = excluded.explanation,
            status = CASE
                WHEN topic_grouping_candidates.status = "rejected" THEN "rejected"
                ELSE excluded.status
            END'
    );
    $statement->execute([
        ':feed_item_id' => $feedItemId,
        ':topic_id' => $topicId,
        ':confidence' => $confidence,
        ':explanation' => $explanation,
        ':status' => $status,
    ]);
}

function group_discovered_feed_item(int $feedItemId): array
{
    $database = bueno_database();
    $currentTopicId = ensure_topic_for_feed_item($feedItemId);
    $currentTopic = find_editorial_topic($currentTopicId);
    $statement = $database->prepare('SELECT * FROM discovered_feed_items WHERE id = :id');
    $statement->execute([':id' => $feedItemId]);
    $item = $statement->fetch();
    if (!is_array($item) || $currentTopic === null) {
        throw new RuntimeException('Nie można przygotować wpisu do grupowania.');
    }
    $database->prepare(
        'UPDATE editorial_topics
         SET normalized_title = :normalized_title, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute([
        ':id' => $currentTopicId,
        ':normalized_title' => normalize_event_title((string) $item['title'], (string) $item['source_name']),
    ]);
    if ((int) $currentTopic['grouping_locked'] === 1) {
        return ['action' => 'locked', 'confidence' => 0.0, 'topic_id' => $currentTopicId];
    }

    $statement = $database->prepare(
        'SELECT other.*, memberships.topic_id,
                topics.grouping_locked,
                ABS((julianday(COALESCE(other.published_at, other.first_detected_at))
                    - julianday(COALESCE(:published_at, :detected_at))) * 24) AS hours_apart
         FROM discovered_feed_items AS other
         INNER JOIN feed_topic_memberships AS memberships
            ON memberships.feed_item_id = other.id
         INNER JOIN editorial_topics AS topics ON topics.id = memberships.topic_id
         INNER JOIN posts AS topic_posts ON topic_posts.id = topics.primary_post_id
         WHERE other.id != :feed_item_id
           AND other.id < :feed_item_id
           AND memberships.topic_id != :current_topic_id
           AND topic_posts.status != "rejected"
           AND (
                other.source_url = :source_url
                OR ABS((julianday(COALESCE(other.published_at, other.first_detected_at))
                    - julianday(COALESCE(:published_at, :detected_at))) * 24) <= :window_hours
           )
         ORDER BY datetime(COALESCE(other.published_at, other.first_detected_at)) DESC
         LIMIT 400'
    );
    $statement->execute([
        ':published_at' => $item['published_at'],
        ':detected_at' => $item['first_detected_at'],
        ':feed_item_id' => $feedItemId,
        ':current_topic_id' => $currentTopicId,
        ':source_url' => $item['source_url'],
        ':window_hours' => TOPIC_GROUPING_WINDOW_HOURS,
    ]);

    $bestByTopic = [];
    foreach ($statement->fetchAll() as $candidate) {
        $comparison = compare_feed_events($item, $candidate);
        $topicId = (int) $candidate['topic_id'];
        $comparison['topic_id'] = $topicId;
        $comparison['same_source'] = (int) $candidate['technical_source_id'] === (int) $item['technical_source_id'];
        $comparison['locked'] = (int) $candidate['grouping_locked'] === 1;
        if (!isset($bestByTopic[$topicId]) || $comparison['confidence'] > $bestByTopic[$topicId]['confidence']) {
            $bestByTopic[$topicId] = $comparison;
        }
    }
    usort($bestByTopic, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
    $best = $bestByTopic[0] ?? null;
    if (!is_array($best) || $best['confidence'] < TOPIC_SUGGESTED_MATCH_THRESHOLD) {
        return ['action' => 'single', 'confidence' => (float) ($best['confidence'] ?? 0), 'topic_id' => $currentTopicId];
    }

    $canMerge = $best['automatic']
        && !$best['locked']
        && (!$best['same_source'] || $best['confidence'] >= 0.99);
    save_topic_candidate(
        $feedItemId,
        (int) $best['topic_id'],
        (float) $best['confidence'],
        (string) $best['explanation'],
        $canMerge ? 'accepted' : 'suggested'
    );
    if (!$canMerge) {
        return [
            'action' => 'suggested',
            'confidence' => (float) $best['confidence'],
            'topic_id' => $currentTopicId,
            'candidate_topic_id' => (int) $best['topic_id'],
        ];
    }

    move_feed_item_to_topic(
        $feedItemId,
        (int) $best['topic_id'],
        (float) $best['confidence'],
        'automatic'
    );

    return [
        'action' => 'merged',
        'confidence' => (float) $best['confidence'],
        'topic_id' => (int) $best['topic_id'],
    ];
}

function run_topic_grouping(): array
{
    bueno_database()->exec(
        'DELETE FROM topic_grouping_candidates WHERE status = "suggested"'
    );
    $rows = bueno_database()->query(
        'SELECT items.id
         FROM discovered_feed_items AS items
         INNER JOIN posts ON posts.id = items.post_id
         WHERE posts.status != "rejected"
         ORDER BY datetime(COALESCE(items.published_at, items.first_detected_at)) ASC, items.id ASC'
    )->fetchAll();
    $result = ['processed' => 0, 'merged' => 0, 'suggested' => 0, 'single' => 0, 'failed' => 0, 'errors' => []];
    foreach ($rows as $row) {
        try {
            $grouped = group_discovered_feed_item((int) $row['id']);
            $result['processed']++;
            $action = (string) $grouped['action'];
            if (array_key_exists($action, $result)) {
                $result[$action]++;
            }
        } catch (Throwable $exception) {
            $result['failed']++;
            $result['errors'][] = ['feed_item_id' => (int) $row['id'], 'error' => $exception->getMessage()];
        }
    }

    return $result;
}

function manual_merge_topics(int $sourceTopicId, int $targetTopicId, string $actor = 'admin'): void
{
    if ($sourceTopicId <= 0 || $targetTopicId <= 0 || $sourceTopicId === $targetTopicId) {
        throw new InvalidArgumentException('Wybierz dwa różne tematy.');
    }
    if (find_editorial_topic($sourceTopicId) === null || find_editorial_topic($targetTopicId) === null) {
        throw new RuntimeException('Nie znaleziono wybranego tematu.');
    }
    $items = topic_feed_items($sourceTopicId);
    foreach ($items as $item) {
        move_feed_item_to_topic((int) $item['id'], $targetTopicId, 1.0, 'manual', $actor);
    }
    bueno_database()->prepare(
        'UPDATE editorial_topics SET grouping_locked = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':id' => $targetTopicId]);
}

function manual_split_feed_item(int $feedItemId, string $actor = 'admin'): int
{
    $database = bueno_database();
    $statement = $database->prepare(
        'SELECT memberships.topic_id, items.*
         FROM feed_topic_memberships AS memberships
         INNER JOIN discovered_feed_items AS items ON items.id = memberships.feed_item_id
         WHERE memberships.feed_item_id = :feed_item_id'
    );
    $statement->execute([':feed_item_id' => $feedItemId]);
    $item = $statement->fetch();
    if (!is_array($item)) {
        throw new RuntimeException('Nie znaleziono wpisu do rozdzielenia.');
    }
    $fromTopicId = (int) $item['topic_id'];
    $fromItems = topic_feed_items($fromTopicId);
    if (count($fromItems) <= 1) {
        throw new InvalidArgumentException('Ten wpis jest już osobnym tematem.');
    }
    $fromTopic = find_editorial_topic($fromTopicId);
    if ($fromTopic === null) {
        throw new RuntimeException('Nie znaleziono tematu wpisu.');
    }

    $database->beginTransaction();
    try {
        if ((int) $fromTopic['primary_post_id'] === (int) $item['post_id']) {
            $replacement = null;
            foreach ($fromItems as $candidate) {
                if ((int) $candidate['id'] !== $feedItemId) {
                    $replacement = $candidate;
                    break;
                }
            }
            if (!is_array($replacement)) {
                throw new RuntimeException('Nie znaleziono wpisu zastępującego temat.');
            }
            $database->prepare(
                'UPDATE editorial_topics
                 SET primary_post_id = :post_id, title = :title,
                     normalized_title = :normalized_title,
                     event_at = :event_at, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                ':post_id' => (int) $replacement['post_id'],
                ':title' => $replacement['title'],
                ':normalized_title' => normalize_event_title(
                    (string) $replacement['title'],
                    (string) $replacement['source_name']
                ),
                ':event_at' => $replacement['published_at'] ?: $replacement['first_detected_at'],
                ':id' => $fromTopicId,
            ]);
            restore_split_post_state((int) $replacement['post_id']);
        }
        $statement = $database->prepare(
            'INSERT INTO editorial_topics (
                primary_post_id, title, normalized_title, event_at, grouping_locked
             ) VALUES (
                :post_id, :title, :normalized_title, :event_at, 1
             )'
        );
        $statement->execute([
            ':post_id' => (int) $item['post_id'],
            ':title' => $item['title'],
            ':normalized_title' => normalize_event_title((string) $item['title'], (string) $item['source_name']),
            ':event_at' => $item['published_at'] ?: $item['first_detected_at'],
        ]);
        $newTopicId = (int) $database->lastInsertId();
        $database->prepare(
            'UPDATE feed_topic_memberships
             SET topic_id = :topic_id, confidence = 1, match_method = "manual_split",
                 created_at = CURRENT_TIMESTAMP
             WHERE feed_item_id = :feed_item_id'
        )->execute([':topic_id' => $newTopicId, ':feed_item_id' => $feedItemId]);
        $database->prepare(
            'INSERT INTO topic_grouping_history (
                feed_item_id, from_topic_id, to_topic_id, action, confidence, actor
             ) VALUES (:feed_item_id, :from_topic_id, :to_topic_id, "manual_split", 1, :actor)'
        )->execute([
            ':feed_item_id' => $feedItemId,
            ':from_topic_id' => $fromTopicId,
            ':to_topic_id' => $newTopicId,
            ':actor' => $actor,
        ]);
        $database->prepare(
            'UPDATE editorial_topics SET grouping_locked = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':id' => $fromTopicId]);
        restore_split_post_state((int) $item['post_id']);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    sync_topic_post_sources($fromTopicId);
    sync_topic_post_sources($newTopicId);
    if (function_exists('score_editorial_topic')) {
        score_editorial_topic($fromTopicId);
        score_editorial_topic($newTopicId);
    }

    return $newTopicId;
}

function list_editorial_topics(int $limit = 200, string $filter = 'active'): array
{
    if (!in_array($filter, ['active', 'profile-rejected', 'all'], true)) {
        throw new InvalidArgumentException('Nieprawidłowy filtr tematów.');
    }
    $where = match ($filter) {
        'active' => ' WHERE posts.status != "rejected"',
        'profile-rejected' => ' WHERE posts.status = "rejected" AND posts.rejection_reason = :profile_reason',
        default => '',
    };
    $statement = bueno_database()->prepare(
        'SELECT topics.*, posts.status,
                COUNT(memberships.feed_item_id) AS item_count,
                COUNT(DISTINCT items.technical_source_id) AS source_count,
                GROUP_CONCAT(DISTINCT items.source_name) AS source_names
         FROM editorial_topics AS topics
         INNER JOIN posts ON posts.id = topics.primary_post_id
         LEFT JOIN feed_topic_memberships AS memberships ON memberships.topic_id = topics.id
         LEFT JOIN discovered_feed_items AS items ON items.id = memberships.feed_item_id
         ' . $where . '
         GROUP BY topics.id
         ORDER BY COALESCE(topics.score, -1) DESC,
                  datetime(topics.event_at) DESC, topics.id DESC
         LIMIT :limit'
    );
    if ($filter === 'profile-rejected') {
        $statement->bindValue(':profile_reason', POPULAR_SCIENCE_CLEANUP_REASON);
    }
    $statement->bindValue(':limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function list_suggested_topic_matches(int $limit = 100): array
{
    $statement = bueno_database()->prepare(
        'SELECT candidates.*, items.title AS item_title, items.source_name,
                topics.title AS topic_title
         FROM topic_grouping_candidates AS candidates
         INNER JOIN discovered_feed_items AS items ON items.id = candidates.feed_item_id
         INNER JOIN editorial_topics AS topics ON topics.id = candidates.candidate_topic_id
         INNER JOIN posts ON posts.id = topics.primary_post_id
         WHERE candidates.status = "suggested"
           AND posts.status != "rejected"
         ORDER BY candidates.confidence DESC, candidates.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function reject_topic_candidate(int $candidateId): void
{
    $statement = bueno_database()->prepare(
        'UPDATE topic_grouping_candidates
         SET status = "rejected", decided_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = "suggested"'
    );
    $statement->execute([':id' => $candidateId]);
    if ($statement->rowCount() === 0) {
        throw new RuntimeException('Nie znaleziono aktywnej sugestii.');
    }
}

function accept_topic_candidate(int $candidateId, string $actor = 'admin'): void
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM topic_grouping_candidates
         WHERE id = :id AND status = "suggested"'
    );
    $statement->execute([':id' => $candidateId]);
    $candidate = $statement->fetch();
    if (!is_array($candidate)) {
        throw new RuntimeException('Nie znaleziono aktywnej sugestii.');
    }
    move_feed_item_to_topic(
        (int) $candidate['feed_item_id'],
        (int) $candidate['candidate_topic_id'],
        (float) $candidate['confidence'],
        'manual',
        $actor
    );
    bueno_database()->prepare(
        'UPDATE topic_grouping_candidates
         SET status = "accepted", decided_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':id' => $candidateId]);
    bueno_database()->prepare(
        'UPDATE editorial_topics SET grouping_locked = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':id' => (int) $candidate['candidate_topic_id']]);
}
