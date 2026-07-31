<?php

declare(strict_types=1);

function topic_scoring_text(array $items): string
{
    $parts = [];
    foreach ($items as $item) {
        $parts[] = (string) $item['title'];
        $parts[] = (string) $item['summary'];
    }

    return mb_strtolower(implode(' ', $parts));
}

function topic_published_similarity(string $title): array
{
    $target = event_title_features($title);
    $best = 0.0;
    $bestTitle = '';
    foreach (list_posts(null, true) as $post) {
        $published = event_title_features((string) $post['title']);
        if ($target['tokens'] === [] || $published['tokens'] === []) {
            continue;
        }
        $shared = array_intersect($target['tokens'], $published['tokens']);
        $union = array_unique(array_merge($target['tokens'], $published['tokens']));
        $jaccard = count($shared) / max(1, count($union));
        $coverage = count($shared) / max(1, min(count($target['tokens']), count($published['tokens'])));
        $similarity = round((0.6 * $jaccard) + (0.4 * $coverage), 4);
        if ($similarity > $best) {
            $best = $similarity;
            $bestTitle = (string) $post['title'];
        }
    }

    return ['similarity' => $best, 'title' => $bestTitle];
}

function topic_text_has_phrase(string $text, string $phrase): bool
{
    return preg_match(
        '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u',
        $text
    ) === 1;
}

function topic_signal_hits(string $text, array $signals): array
{
    return array_values(array_filter(
        $signals,
        static fn (string $signal): bool => str_contains($text, $signal)
    ));
}

function topic_risk_assessment(string $text): array
{
    $highPatterns = [
        'zero-day', '0-day', 'ransomware', 'data breach', 'wyciek danych',
        'exploit', 'malware', 'phishing', 'diagnosis', 'treatment',
        'investment advice', 'stock recommendation', 'kryptowalut', 'crypto',
    ];
    $mediumPatterns = [
        'security', 'bezpieczeństwo', 'privacy', 'prywatność', 'vulnerability',
        'podatność', 'rumor', 'plotka', 'unconfirmed', 'niepotwierdz',
        'eruption imminent', 'will erupt', 'wybuchnie',
    ];
    foreach ($highPatterns as $pattern) {
        if (str_contains($text, $pattern)) {
            return ['level' => 'high', 'penalty' => -20, 'reason' => 'Wykryto temat wysokiego ryzyka: „' . $pattern . '”.'];
        }
    }
    foreach ($mediumPatterns as $pattern) {
        if (str_contains($text, $pattern)) {
            return ['level' => 'medium', 'penalty' => -10, 'reason' => 'Temat wymaga dodatkowej kontroli twierdzeń: „' . $pattern . '”.'];
        }
    }

    return ['level' => 'low', 'penalty' => 0, 'reason' => 'Nie wykryto twierdzeń wymagających podwyższonej kontroli.'];
}

function topic_component(int $points, int $maximum, string $reason): array
{
    return ['points' => $points, 'maximum' => $maximum, 'reason' => $reason];
}

function calculate_topic_score(
    int $topicId,
    ?DateTimeImmutable $now = null,
    ?array $preferredCategories = null
): array {
    $topic = find_editorial_topic($topicId);
    if ($topic === null) {
        throw new RuntimeException('Nie znaleziono tematu do punktacji.');
    }
    $items = topic_feed_items($topicId);
    if ($items === []) {
        throw new RuntimeException('Temat nie zawiera wpisów źródłowych.');
    }
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'));
    $eventAt = new DateTimeImmutable((string) $topic['event_at'], new DateTimeZone('UTC'));
    $ageHours = max(0.0, ($now->getTimestamp() - $eventAt->getTimestamp()) / 3600);
    $freshness = match (true) {
        $ageHours <= 12 => 12,
        $ageHours <= 36 => 10,
        $ageHours <= 72 => 8,
        $ageHours <= 168 => 5,
        $ageHours <= 336 => 2,
        default => 0,
    };

    $sources = [];
    foreach ($items as $item) {
        $source = find_technical_source((int) $item['technical_source_id']);
        if (is_array($source)) {
            $sources[(int) $source['id']] = $source;
        }
    }
    $hasPrimary = array_filter(
        $sources,
        static fn (array $source): bool => (int) $source['is_primary'] === 1
    ) !== [];
    $sourceCount = count($sources);
    $confirmationPoints = match (true) {
        $sourceCount >= 3 => 10,
        $sourceCount === 2 => 8,
        default => 3,
    };

    $preferredCategories ??= (array) app_config('preferred_topic_categories');
    $preferredCategories = array_map('strtolower', $preferredCategories);
    $topicCategories = array_values(array_unique(array_map(
        static fn (array $item): string => strtolower((string) $item['category']),
        $items
    )));
    $matchedCategories = array_values(array_intersect($topicCategories, $preferredCategories));
    $categoryPoints = $matchedCategories !== [] ? 10 : 0;

    $text = topic_scoring_text($items);
    $mechanismHits = topic_signal_hits($text, [
        'how ', 'however', 'mechanism', 'works', 'method', 'technique', 'instrument',
        'sensor', 'camera', 'detector', 'telescope', 'microscope', 'material',
        'battery', 'robot', 'engine', 'experiment', 'measurement', 'observ',
        'analysis', 'study', 'researchers', 'scientists', 'engineers', 'evidence',
        'data reveal', 'mapped', 'imaging', 'prototype', 'quantum',
    ]);
    $mechanismPoints = match (true) {
        count($mechanismHits) >= 4 => 14,
        count($mechanismHits) >= 2 => 11,
        count($mechanismHits) === 1 => 7,
        default => 0,
    };

    $discoveryHits = topic_signal_hits($text, [
        'mystery', 'puzzle', 'paradox', 'unexpected', 'surprising', 'unknown',
        'question', 'why ', 'how ', 'first evidence', 'discovers', 'discovered',
        'discovery', 'reveals', 'new understanding', 'challenges', 'enigmatic',
        'zagadka', 'paradoks', 'dlaczego', 'odkry',
    ]);
    $discoveryPoints = match (true) {
        count($discoveryHits) >= 3 => 12,
        count($discoveryHits) >= 2 => 10,
        count($discoveryHits) === 1 => 6,
        default => 2,
    };

    $humanHits = topic_signal_hits($text, [
        'human', 'people', 'health', 'medical', 'brain', 'body', 'climate',
        'energy', 'transport', 'water', 'food', 'pollution', 'hazard', 'earth',
        'environment', 'weather', 'agriculture', 'disease', 'mobility', 'safety',
        'człowiek', 'zdrow', 'klimat', 'energia', 'transport',
    ]);
    $humanPoints = match (true) {
        count($humanHits) >= 3 => 10,
        count($humanHits) >= 1 => 7,
        default => 3,
    };

    $futureHits = topic_signal_hits($text, [
        'could enable', 'could lead', 'may help', 'potential', 'future',
        'next generation', 'advance', 'improve', 'new class', 'opens a way',
        'applications', 'allow scientists', 'pozwoli', 'przyszło',
    ]);
    $futurePoints = match (true) {
        count($futureHits) >= 2 => 8,
        count($futureHits) === 1 => 5,
        default => 2,
    };

    $problemHits = topic_signal_hits($text, [
        'problem', 'challenge', 'mystery', 'unknown', 'risk', 'limit',
        'cannot', 'difficult', 'puzzle', 'zagadka',
    ]);
    $narrativePoints = $problemHits !== [] && $discoveryHits !== [] && ($humanHits !== [] || $futureHits !== [])
        ? 6
        : (($discoveryHits !== [] && ($humanHits !== [] || $futureHits !== [])) ? 4 : 1);

    $visualHits = topic_signal_hits($text, [
        'camera', 'image', 'imaging', 'map', 'volcano', 'earthquake', 'spacecraft',
        'telescope', 'microscope', 'robot', 'material', 'device', 'instrument',
        'prototype', 'planet', 'galaxy', 'mars', 'moon', 'ocean', 'satellite',
    ]);
    $visualPoints = count($visualHits) >= 2 ? 6 : ($visualHits !== [] ? 4 : 1);

    $developerHits = topic_signal_hits($text, [
        'changelog', 'sdk', 'framework', 'library update', 'api update',
        'developer preview', 'cloud region', 'instance type', 'now supports',
        'generally available', 'github actions', 'kubernetes', 'database engine',
        'release notes', 'version ', 'devops', 'administrator',
    ]);
    $developerPenalty = $developerHits !== [] ? -25 : 0;
    $marketingHits = topic_signal_hits($text, [
        'industry-leading', 'world-class', 'revolutionary product', 'customers can now',
        'announces availability', 'limited offer', 'best-in-class',
    ]);
    $marketingPenalty = $marketingHits !== [] ? -15 : 0;
    $institutionalHits = topic_signal_hits($text, [
        'welcomes', 'appointed', 'president', 'mourns', 'passing of',
        'obituary', 'workshop', 'seminar', 'webinar', 'conference registration',
        'community event', 'award ceremony', 'new crew members welcomed',
        'meet nasa’s new', 'meet nasa\'s new',
    ]);
    $institutionalPenalty = $institutionalHits !== [] ? -25 : 0;
    $noMechanismPenalty = $mechanismHits === [] ? -8 : 0;

    $sensationalHits = topic_signal_hits($text, [
        'shocking', 'unbelievable', 'mind-blowing', 'destroys', 'game-changer',
        'revolutionary', 'doomsday', 'apocalypse', 'imminent eruption',
        'szokuj', 'niewiarygod', 'wybuchnie',
    ]);
    $sensationalPenalty = $sensationalHits !== [] ? -12 : 0;
    $risk = topic_risk_assessment($text);
    $publishedSimilarity = topic_published_similarity((string) $topic['title']);
    $similarityPenalty = match (true) {
        $publishedSimilarity['similarity'] >= 0.80 => -15,
        $publishedSimilarity['similarity'] >= 0.65 => -10,
        $publishedSimilarity['similarity'] >= 0.50 => -5,
        default => 0,
    };

    $components = [
        'freshness' => topic_component($freshness, 12, sprintf('Świeżość wydarzenia: %.1f godz.', $ageHours)),
        'primary_source' => topic_component(
            $hasPrimary ? 12 : 0,
            12,
            $hasPrimary ? 'Temat ma wiarygodne źródło pierwotne.' : 'Brak źródła pierwotnego; wymagane potwierdzenie.'
        ),
        'independent_sources' => topic_component($confirmationPoints, 10, 'Niezależne źródła: ' . $sourceCount . '.'),
        'profile_fit' => topic_component(
            $categoryPoints,
            10,
            $matchedCategories !== []
                ? 'Dopasowanie do profilu: ' . implode(', ', $matchedCategories) . '.'
                : 'Temat nie należy do kategorii profilu popularnonaukowego.'
        ),
        'explainable_mechanism' => topic_component(
            $mechanismPoints,
            14,
            $mechanismHits !== []
                ? 'Można wyjaśnić mechanizm/metodę: ' . implode(', ', array_slice($mechanismHits, 0, 4)) . '.'
                : 'Brak uchwytnego mechanizmu lub metody do wyjaśnienia.'
        ),
        'discovery_question' => topic_component(
            $discoveryPoints,
            12,
            $discoveryHits !== []
                ? 'Obecne odkrycie, pytanie lub zderzenie z intuicją: ' . implode(', ', array_slice($discoveryHits, 0, 3)) . '.'
                : 'Ograniczony element odkrycia lub pytania badawczego.'
        ),
        'human_significance' => topic_component(
            $humanPoints,
            10,
            $humanHits !== []
                ? 'Widoczne znaczenie dla człowieka albo świata: ' . implode(', ', array_slice($humanHits, 0, 3)) . '.'
                : 'Znaczenie dla zwykłego czytelnika wymaga dopracowania.'
        ),
        'future_impact' => topic_component(
            $futurePoints,
            8,
            $futureHits !== []
                ? 'Źródło wskazuje możliwy przyszły wpływ: ' . implode(', ', array_slice($futureHits, 0, 2)) . '.'
                : 'Brak wyraźnie opisanego wpływu na przyszłość.'
        ),
        'problem_discovery_return' => topic_component(
            $narrativePoints,
            6,
            $narrativePoints === 6
                ? 'Materiał wspiera pełną narrację problem–odkrycie–powrót.'
                : 'Narracja problem–odkrycie–powrót jest tylko częściowo wsparta.'
        ),
        'visual_potential' => topic_component(
            $visualPoints,
            6,
            $visualHits !== []
                ? 'Możliwa uczciwa grafika reprezentatywna: ' . implode(', ', array_slice($visualHits, 0, 3)) . '.'
                : 'Ograniczony potencjał reprezentatywnej grafiki.'
        ),
        'developer_niche' => topic_component(
            $developerPenalty,
            0,
            $developerPenalty < 0
                ? 'Changelog lub wąski temat deweloperski: ' . implode(', ', array_slice($developerHits, 0, 3)) . '.'
                : 'Temat nie wygląda na changelog ani komunikat wyłącznie dla programistów.'
        ),
        'marketing' => topic_component(
            $marketingPenalty,
            0,
            $marketingPenalty < 0 ? 'Wykryto język marketingowy bez wartości badawczej.' : 'Brak wyraźnego języka czysto marketingowego.'
        ),
        'institutional_news' => topic_component(
            $institutionalPenalty,
            0,
            $institutionalPenalty < 0
                ? 'Komunikat kadrowy, wydarzenie lub wiadomość instytucjonalna: ' . implode(', ', array_slice($institutionalHits, 0, 3)) . '.'
                : 'Temat nie jest komunikatem kadrowym ani zapowiedzią wydarzenia.'
        ),
        'missing_mechanism' => topic_component(
            $noMechanismPenalty,
            0,
            $noMechanismPenalty < 0 ? 'Kara za brak możliwego do wyjaśnienia mechanizmu.' : 'Temat zawiera mechanizm lub metodę.'
        ),
        'published_similarity' => topic_component(
            $similarityPenalty,
            0,
            $similarityPenalty < 0
                ? sprintf('Podobieństwo %.1f%% do: %s.', $publishedSimilarity['similarity'] * 100, $publishedSimilarity['title'])
                : 'Brak istotnego podobieństwa do opublikowanych artykułów.'
        ),
        'topic_risk' => topic_component((int) $risk['penalty'], 0, (string) $risk['reason']),
        'sensationalism' => topic_component(
            $sensationalPenalty,
            0,
            $sensationalPenalty < 0
                ? 'Sensacyjne twierdzenie bez premii: ' . implode(', ', array_slice($sensationalHits, 0, 3)) . '.'
                : 'Brak clickbaitu i nieuprawnionej sensacyjności.'
        ),
    ];
    $score = max(0, min(100, array_sum(array_column($components, 'points'))));
    $automaticEligible = $risk['level'] !== 'high'
        && $hasPrimary
        && $developerPenalty === 0
        && $institutionalPenalty === 0
        && $sensationalPenalty === 0;

    return [
        'topic_id' => $topicId,
        'score' => $score,
        'risk_level' => $risk['level'],
        'automatic_eligible' => $automaticEligible,
        'has_primary_source' => $hasPrimary,
        'profile' => POPULAR_SCIENCE_PROFILE_KEY,
        'components' => $components,
        'scored_at' => $now->format('Y-m-d H:i:s'),
    ];
}

function score_editorial_topic(
    int $topicId,
    ?DateTimeImmutable $now = null,
    ?array $preferredCategories = null
): array {
    $score = calculate_topic_score($topicId, $now, $preferredCategories);
    $breakdown = json_encode(
        $score,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $database->prepare(
            'UPDATE editorial_topics
             SET score = :score, scoring_breakdown_json = :breakdown,
                 risk_level = :risk_level, automatic_eligible = :automatic_eligible,
                 scored_at = :scored_at, updated_at = CURRENT_TIMESTAMP
             WHERE id = :topic_id'
        )->execute([
            ':score' => $score['score'],
            ':breakdown' => $breakdown,
            ':risk_level' => $score['risk_level'],
            ':automatic_eligible' => $score['automatic_eligible'] ? 1 : 0,
            ':scored_at' => $score['scored_at'],
            ':topic_id' => $topicId,
        ]);
        $database->prepare(
            'INSERT INTO topic_score_history (
                topic_id, score, risk_level, automatic_eligible, breakdown_json, scored_at
             ) VALUES (
                :topic_id, :score, :risk_level, :automatic_eligible, :breakdown, :scored_at
             )'
        )->execute([
            ':topic_id' => $topicId,
            ':score' => $score['score'],
            ':risk_level' => $score['risk_level'],
            ':automatic_eligible' => $score['automatic_eligible'] ? 1 : 0,
            ':breakdown' => $breakdown,
            ':scored_at' => $score['scored_at'],
        ]);
        $topic = find_editorial_topic($topicId);
        if ($topic !== null) {
            $database->prepare(
                'UPDATE posts SET quality_score = :score, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute([':score' => $score['score'], ':id' => (int) $topic['primary_post_id']]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return $score;
}

function run_topic_scoring(?DateTimeImmutable $now = null, bool $includeRejected = false): array
{
    $sql = 'SELECT topics.id
            FROM editorial_topics AS topics
            INNER JOIN posts ON posts.id = topics.primary_post_id';
    $sql .= ' WHERE topics.trashed_at IS NULL AND topics.purged_at IS NULL';
    if (!$includeRejected) $sql .= ' AND posts.status != "rejected"';
    $topicIds = bueno_database()->query($sql . ' ORDER BY topics.id ASC')->fetchAll();
    $result = ['processed' => 0, 'failed' => 0, 'high_risk' => 0, 'scores' => [], 'errors' => []];
    foreach ($topicIds as $row) {
        try {
            $score = score_editorial_topic((int) $row['id'], $now);
            $result['processed']++;
            if ($score['risk_level'] === 'high') {
                $result['high_risk']++;
            }
            $result['scores'][] = ['topic_id' => $score['topic_id'], 'score' => $score['score']];
        } catch (Throwable $exception) {
            $result['failed']++;
            $result['errors'][] = ['topic_id' => (int) $row['id'], 'error' => $exception->getMessage()];
        }
    }

    return $result;
}

function topic_score_breakdown(array $topic): array
{
    try {
        $decoded = json_decode((string) ($topic['scoring_breakdown_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }

    return is_array($decoded['components'] ?? null) ? $decoded['components'] : [];
}
