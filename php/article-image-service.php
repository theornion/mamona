<?php

declare(strict_types=1);

require_once __DIR__ . '/image-rights-service.php';

const ARTICLE_IMAGE_ROLES = ['hero', 'inline'];
const ARTICLE_IMAGE_LAYOUTS = ['full', 'left', 'right', 'breakout'];
const ARTICLE_IMAGE_STATUSES = ['planned', 'selected', 'downloaded', 'manual_review', 'missing'];
const ARTICLE_IMAGE_GEMINI_OPERATION_TYPE = 'image_vision_assessment';
const ARTICLE_IMAGE_SAFE_LICENSES = ['cc0', 'public-domain', 'cc-by', 'cc-by-sa', 'pexels-license', 'local-editorial'];
const ARTICLE_IMAGE_RELATIONS = ['exact_subject', 'mechanism', 'apparatus', 'analogy_scale', 'related_context'];
const ARTICLE_BLOCK_SECTION_VARIANTS = [
    'default', 'lead', 'importance', 'facts', 'fact', 'context',
    'unknowns', 'unknown', 'narrative', 'takeaway',
    'v2-prose', 'v2-explainer', 'v2-curiosity', 'v2-history',
    'v2-comparison', 'v2-short_callout', 'v2-unknowns', 'v2-takeaway',
];
const ARTICLE_IMAGE_CANONICAL_SECTION_IDS = [
    'lead',
    'why-important',
    'fact-1',
    'fact-2',
    'fact-3',
    'fact-4',
    'fact-5',
    'comparison',
    'unknown-1',
    'unknown-2',
    'unknown-3',
    'narrative-opening-question',
    'narrative-pursuit',
    'narrative-topic-b',
    'narrative-apparent-dead-end',
    'narrative-return-to-topic-a',
    'narrative-close-topic-b',
    'narrative-answer-and-punchline',
    'takeaway',
];
const ARTICLE_IMAGE_ALWAYS_AVAILABLE_SECTION_IDS = ['lead', 'why-important', 'fact-1', 'takeaway'];

function article_image_schema(): array
{
    $string = ['type' => 'string'];

    return [
        'type' => 'object',
        'properties' => [
            'role' => ['type' => 'string', 'enum' => ARTICLE_IMAGE_ROLES],
            'section_id' => $string,
            'visual_intent' => $string,
            'search_queries' => ['type' => 'array', 'items' => $string],
            'expected_content' => $string,
            'source_page_url' => $string,
            'source_file_url' => $string,
            'local_path' => $string,
            'author' => $string,
            'license' => $string,
            'license_url' => $string,
            'attribution' => $string,
            'alt' => $string,
            'caption' => $string,
            'layout' => ['type' => 'string', 'enum' => ARTICLE_IMAGE_LAYOUTS],
            'status' => ['type' => 'string', 'enum' => ARTICLE_IMAGE_STATUSES],
            'slot_id' => ['type' => 'string'],
            'must_be_direct' => ['type' => 'boolean'],
            'acceptable_related' => ['type' => 'boolean'],
            'search_queries_related' => ['type' => 'array', 'items' => $string],
            'topic_source' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'relationship_policy' => ['type' => 'string', 'enum' => ['ww_contextual_v1']],
        ],
        'required' => [
            'role', 'section_id', 'visual_intent', 'search_queries', 'expected_content',
            'source_page_url', 'source_file_url', 'local_path', 'author', 'license',
            'license_url', 'attribution', 'alt', 'caption', 'layout', 'status',
        ],
        'additionalProperties' => false,
    ];
}

function article_planned_image_schema(string $role): array
{
    if (!in_array($role, ARTICLE_IMAGE_ROLES, true)) {
        throw new InvalidArgumentException('Nieprawidłowa rola planowanej ilustracji.');
    }

    $schema = article_image_schema();
    $schema['properties']['role'] = ['type' => 'string', 'enum' => [$role]];
    $schema['properties']['status'] = ['type' => 'string', 'enum' => ['planned']];
    $schema['properties']['search_queries']['minItems'] = 1;
    foreach ([
        'source_page_url',
        'source_file_url',
        'local_path',
        'author',
        'license',
        'license_url',
        'attribution',
    ] as $field) {
        $schema['properties'][$field] = ['type' => 'string', 'enum' => ['']];
    }
    if ($role === 'hero') {
        $schema['properties']['section_id'] = ['type' => 'string', 'enum' => ['article']];
        $schema['properties']['layout'] = ['type' => 'string', 'enum' => ['full']];
    } else {
        $schema['properties']['section_id'] = [
            'type' => 'string',
            'enum' => ARTICLE_IMAGE_CANONICAL_SECTION_IDS,
        ];
    }

    return $schema;
}

function article_inline_image_target_count(int $characterCount): int
{
    if ($characterCount <= 0) {
        return 0;
    }

    return min(3, max(1, (int) floor(($characterCount + 100) / 1000)));
}

/** Builds a bounded, non-repeating semantic cascade. Model queries remain hints; the search uses real providers. */
function article_image_semantic_queries(array $plannedImage, ?int $budget = null): array
{
    $budget ??= (int) app_config('source_image_query_budget_per_slot');
    $base = array_values(array_unique(array_filter(array_map(
        static fn ($query): string => trim((string) $query),
        (array) ($plannedImage['search_queries'] ?? [])
    ))));
    $intent = trim((string) ($plannedImage['expected_content'] ?? $plannedImage['visual_intent'] ?? ''));
    if ($intent !== '') {
        $base[] = $intent;
    }
    // A slot can legitimately contain several literal/direct formulations.
    // Do not let them starve the recovery tiers: metadata discovery is cheap,
    // while Vision remains bounded later by the ranked shortlist.
    $budget = max($budget, min(8, count($base) + 3));
    $seed = trim((string) ($base[0] ?? $intent));
    $explicitRelated = array_values(array_unique(array_filter(array_map(
        static fn ($query): string => trim((string) $query),
        (array) ($plannedImage['search_queries_related'] ?? [])
    ))));
    $levels = [
        'exact_subject' => $base,
        'related_context' => [...$explicitRelated, $seed . ' research context science'],
        'mechanism' => [$seed . ' mechanism diagram', $seed . ' phenomenon illustration'],
        'apparatus' => [$seed . ' scientific apparatus laboratory', $seed . ' experiment equipment'],
        'analogy_scale' => [$seed . ' scale spectrum nanostructure educational illustration'],
    ];
    $queries = [];
    $seen = [];
    foreach ($levels as $relation => $items) {
        foreach ($items as $query) {
            $query = trim(preg_replace('/\s+/', ' ', (string) $query) ?? '');
            $key = mb_strtolower($query);
            if ($query === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sameRelationCount = count(array_filter($queries, static fn (array $item): bool => ($item['relation'] ?? '') === $relation));
            $level = $relation === 'exact_subject' ? ($sameRelationCount === 0 ? 'exact_direct' : 'broader_direct') : 'recovery_' . $relation;
            $queries[] = ['query' => mb_substr($query, 0, 200), 'relation' => $relation, 'level' => $level,
                'query_origin' => (string) ($plannedImage['query_origin'] ?? 'canonical_visual_plan')];
            if (count($queries) >= max(1, $budget)) {
                return $queries;
            }
        }
    }
    return $queries;
}

/** W/W closure prioritizes broader illustrative function instead of replaying exhausted direct queries. */
function article_image_contextual_queries(array $plannedImage, int $budget = 8): array
{
    $direct = array_values(array_unique(array_filter(array_map('strval', (array) ($plannedImage['search_queries'] ?? [])))));
    $related = array_values(array_unique(array_filter(array_map('strval', (array) ($plannedImage['search_queries_related'] ?? [])))));
    $seed = trim((string) ($direct[0] ?? $plannedImage['visual_intent'] ?? ''));
    $article = trim((string) ($plannedImage['article_title'] ?? ''));
    $queries = [];
    $append = static function (string $query, string $relation, string $level) use (&$queries, $budget): void {
        $query = trim($query);
        if ($query === '' || count($queries) >= max(1, $budget)) return;
        $key = mb_strtolower($query);
        foreach ($queries as $item) if (mb_strtolower((string) $item['query']) === $key) return;
        $queries[] = ['query'=>mb_substr($query, 0, 200),'relation'=>$relation,'level'=>$level,'query_origin'=>'ww_slot_adaptation'];
    };
    // Slot adaptation must be driven by the slot itself.  Mixing the article
    // title into every slot made a secondary Peto/cancer slot inherit the
    // article-wide "jellyfish" query and repeatedly rank irrelevant wildlife.
    $slotHaystack = mb_strtolower(implode(' ', [...$direct, ...$related,
        (string) ($plannedImage['visual_intent'] ?? '')]));
    $articleHaystack = mb_strtolower((string) ($plannedImage['article_title'] ?? ''));
    $comparativeOncology = preg_match('/(?=.*(?:cancer|oncolog|nowotwor))(?=.*(?:animal|zwierz|species|gatunk|peto))/u', $slotHaystack) === 1;
    if ($comparativeOncology) {
        // A concrete representative species is a defensible contextual visual
        // for Peto's paradox and yields photographs instead of paper/OGV files.
        $append('African elephant', 'related_context', 'contextual_related');
        $append('blue whale', 'related_context', 'contextual_related');
        $append('comparative oncology animals', 'related_context', 'domain_related');
    }
    $domainSeeds = [
        ['/(?:jellyfish|meduz)/u','jellyfish marine biology'],
        ['/(?:animal|zwierz)/u','wildlife animals'],
        ['/(?:brain|neural|neuron|mÃ³zg)/u','brain neuroscience laboratory'],
        ['/(?:microscop|mikroskop)/u','microscopy laboratory'],
        ['/(?:quantum|kwant)/u','quantum technology laboratory'],
        ['/(?:wireless|bezprzewod)/u','wireless communication technology'],
        ['/(?:sensor|czujnik)/u','electronic sensors laboratory'],
        ['/(?:antenna|anten)/u','wireless antenna technology'],
    ];
    $matchedDomain = $comparativeOncology;
    foreach ($domainSeeds as [$pattern,$query]) if (preg_match($pattern, $slotHaystack) === 1) {
        if ($comparativeOncology && $query === 'wildlife animals') continue;
        $append($query, 'related_context', 'domain_related');
        $matchedDomain = true;
    }
    if (!$matchedDomain) foreach ($domainSeeds as [$pattern,$query]) if (preg_match($pattern, $articleHaystack) === 1) {
        $append($query, 'related_context', 'domain_related');
    }
    foreach ($related as $query) $append($query, 'related_context', 'strong_related');
    $append($seed . ' research', 'related_context', 'contextual_related');
    $append($seed . ' laboratory', 'apparatus', 'contextual_related');
    $append($seed . ' science', 'related_context', 'domain_related');
    $append($article . ' research context', 'related_context', 'domain_related');
    foreach (array_slice($direct, 1) as $query) $append($query . ' research', 'related_context', 'contextual_related');
    return $queries;
}

/**
 * Keep the subject-bearing start of an over-specific provider query available
 * after the literal query has failed. This is still a direct-search attempt:
 * it neither changes the slot relationship policy nor accepts a candidate
 * without the normal legal and Vision gates.
 */
function article_image_direct_query_recovery_variants(array $queries, int $limit = 2): array
{
    $variants = [];
    foreach ($queries as $query) {
        $words = preg_split('/\s+/', trim(preg_replace('/\s+/', ' ', (string) $query) ?? '')) ?: [];
        if (count($words) >= 5 && mb_strtolower((string) ($words[0] ?? ''), 'UTF-8') === 'human') {
            $variant = trim(implode(' ', array_slice($words, 0, 3)));
            if ($variant !== '') $variants[mb_strtolower($variant)] = $variant;
        }
    }
    if (count($variants) >= max(1, $limit)) return array_slice(array_values($variants), 0, $limit);
    foreach ($queries as $query) {
        $query = trim(preg_replace('/\s+/', ' ', (string) $query) ?? '');
        $words = preg_split('/\s+/', $query) ?: [];
        if (count($words) < 5) continue;
        $length = max(3, min(4, count($words) - 3));
        $variant = trim(implode(' ', array_slice($words, 0, $length)));
        if ($variant === '' || preg_match('/\b(?:of|in|for|and|or|the|a|an)\b/iu', $variant) === 1) continue;
        $variants[mb_strtolower($variant)] = $variant;
        if (count($variants) >= max(1, $limit)) return array_values($variants);
    }
    return array_values($variants);
}

/** P05: direct acquisition must not silently broaden into related-image recovery. */
function article_image_direct_queries(array $plannedImage, ?int $budget = null): array
{
    $queries = array_values(array_unique(array_filter(array_map(
        static fn ($query): string => trim((string) $query),
        (array) ($plannedImage['search_queries'] ?? [])
    ))));
    $recoveryVariants = article_image_direct_query_recovery_variants($queries);
    foreach ($recoveryVariants as $variant) $queries[] = $variant;
    // A recovery replan may identify a mechanism-level query that still
    // illustrates a direct slot.  Search it as a broader-direct candidate;
    // Vision must still confirm direct or broader-direct fit, so this never
    // silently admits a contextual asset into a direct-only slot.
    if (in_array((string) ($plannedImage['recovery_relationship_policy'] ?? ''), ['direct', 'broader_direct'], true)) {
        foreach ((array) ($plannedImage['search_queries_related'] ?? []) as $query) {
            $query = trim((string) $query);
            if ($query !== '') $queries[] = $query;
        }
    }
    $budget = max($budget ?? (int) app_config('source_image_query_budget_per_slot'), count($queries));
    $intent = trim((string) ($plannedImage['expected_content'] ?? $plannedImage['visual_intent'] ?? ''));
    if ($intent !== '') $queries[] = $intent;
    $result = [];
    foreach ($queries as $query) {
        $key = mb_strtolower($query);
        if ($query === '' || isset($result[$key])) continue;
        $result[$key] = ['query' => mb_substr($query, 0, 200), 'relation' => 'exact_subject',
            'level' => $result === [] ? 'exact_direct' : 'broader_direct',
            'query_origin' => (string) ($plannedImage['query_origin'] ?? 'canonical_visual_plan')];
        if (count($result) >= max(1, $budget)) break;
    }
    return array_values($result);
}

/** Search provenance is audit-only and must never enter the canonical image contract. */
function article_image_canonical_payload(array $payload): array
{
    unset($payload['query_origin'], $payload['recovery_relationship_policy'], $payload['article_title']);
    return $payload;
}

/** Collect and rank the cheap metadata pool across all slot queries before any Vision call. */
function article_image_ranked_candidate_pool(
    array $planned,
    array $attempts,
    callable $searcher,
    array $usedUrls = [],
    ?int $candidateLimitPerQuery = null
): array {
    $candidateLimitPerQuery ??= (int) app_config('source_image_candidate_budget_per_query');
    $pool = []; $audit = []; $errors = []; $providerCount = 0; $hardRejects = [];
    $attemptCount = count($attempts);
    foreach ($attempts as $attemptIndex => $attempt) {
        $query = (string) ($attempt['query'] ?? '');
        $relation = (string) ($attempt['relation'] ?? 'exact_subject');
        $level = (string) ($attempt['level'] ?? $relation);
        $queryOrigin = (string) ($attempt['query_origin'] ?? 'canonical_visual_plan');
        try { $results = (array) $searcher($query); }
        catch (Throwable $exception) {
            $audit[] = ['query'=>$query,'level'=>$level,'query_origin'=>$queryOrigin,'source'=>'','result'=>'search_error','reason'=>$exception->getMessage()];
            $errors[] = $exception->getMessage();
            continue;
        }
        $providerCount += count($results);
        foreach (array_slice($results, 0, max(1, $candidateLimitPerQuery)) as $result) {
            if (!is_array($result)) continue;
            $url = trim((string) ($result['source_file_url'] ?? ''));
            $provider = (string) ($result['provider'] ?? 'unknown');
            $key = $url !== '' ? $url : $provider . ':' . (string) ($result['provider_id'] ?? '');
            if ($key === ':' || isset($usedUrls[$url]) || isset($pool[$key])) {
                $hardRejects['duplicate'] = ($hardRejects['duplicate'] ?? 0) + 1;
                $audit[] = ['query'=>$query,'level'=>$level,'query_origin'=>$queryOrigin,'source'=>$provider,'result'=>'rejected','reason'=>'duplicate'];
                continue;
            }
            try { $candidate = validate_source_image_candidate($result); }
            catch (Throwable $exception) {
                $reason = 'hard_technical_ineligible:' . article_image_local_reject_reason($exception);
                $hardRejects[$reason] = ($hardRejects[$reason] ?? 0) + 1;
                $audit[] = ['query'=>$query,'level'=>$level,'query_origin'=>$queryOrigin,'source'=>$provider,
                    'url'=>$url,'result'=>'rejected','reason'=>$reason];
                continue;
            }
            $hardReason = article_image_license_is_auto_safe((string) ($result['license'] ?? ''))
                ? source_image_candidate_hard_reject_reason($candidate, $planned)
                : 'rights_or_license';
            if ($hardReason !== null) {
                $reason = 'hard_technical_ineligible:' . $hardReason;
                $hardRejects[$reason] = ($hardRejects[$reason] ?? 0) + 1;
                $audit[] = ['query'=>$query,'level'=>$level,'query_origin'=>$queryOrigin,'source'=>$provider,
                    'url'=>$url,'result'=>'rejected','reason'=>$reason];
                continue;
            }
            // Query order is an editorial priority.  Without this bounded
            // bonus a later generic provider hit could outrank the concrete
            // contextual fallback selected for the slot.
            $score = article_image_candidate_score($candidate, $planned, $query, $relation)
                + max(0, ($attemptCount - (int) $attemptIndex) * 100);
            $pool[$key] = ['score'=>$score,'candidate'=>$candidate,'query'=>$query,'relation'=>$relation,'level'=>$level,'query_origin'=>$queryOrigin];
        }
    }
    $ranked = array_values($pool);
    usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
        ?: strcmp((string) ($a['candidate']['provider_id'] ?? ''), (string) ($b['candidate']['provider_id'] ?? '')));
    $audit[] = ['query'=>'','level'=>'pool','source'=>'','result'=>'ranked',
        'topic_source'=>(string) ($planned['topic_source'] ?? 'A'),
        'number_of_provider_candidates'=>$providerCount,'hard_reject_count'=>array_sum($hardRejects),
        'hard_reject_reasons'=>$hardRejects,'ranked_candidate_count'=>count($ranked),
        'search_levels'=>array_values(array_unique(array_map(static fn (array $attempt): string => (string) ($attempt['level'] ?? $attempt['relation'] ?? ''), $attempts)))];
    return ['ranked'=>$ranked,'audit'=>$audit,'errors'=>$errors,'number_of_provider_candidates'=>$providerCount,
        'hard_reject_count'=>array_sum($hardRejects),'hard_reject_reasons'=>$hardRejects,'ranked_candidate_count'=>count($ranked)];
}

function article_image_candidate_score(array $candidate, array $plannedImage, string $query, string $relation): int
{
    if (!article_image_license_is_auto_safe((string) ($candidate['license'] ?? ''))
        || !source_image_candidate_is_suitable_for_role($candidate, $plannedImage)) {
        return PHP_INT_MIN;
    }
    $relationScore = array_search($relation, ARTICLE_IMAGE_RELATIONS, true);
    $score = $relationScore === false ? 0 : (500 - ($relationScore * 70));
    $score += min(160, (int) floor(min((int) ($candidate['width'] ?? 0), 3200) / 20));
    $score += source_image_candidate_matches_query($candidate, $query, 1) ? 250 : 0;
    // Provider query order is only a retrieval hint. The candidate's own
    // title and source identity must dominate before a scarce Vision call.
    $score += article_image_semantic_gate_score($candidate, $plannedImage) * 4;
    $providerWeight = [
        'smithsonian' => 320, 'europeana' => 300, 'eso' => 300, 'nasa' => 290,
        'usgs' => 290, 'nci' => 290, 'wikimedia' => 190, 'openverse' => 170,
        'pexels' => 80,
    ];
    $licenseWeight = ['cc0' => 170, 'public-domain' => 160, 'cc-by' => 90, 'cc-by-sa' => 80, 'pexels-license' => 20];
    $score += $providerWeight[strtolower((string) ($candidate['provider'] ?? ''))] ?? 0;
    $score += $licenseWeight[normalize_reuse_license((string) ($candidate['license'] ?? ''))] ?? 0;
    $text = mb_strtolower((string) ($candidate['title'] ?? '') . ' ' . (string) ($candidate['source_page_url'] ?? ''));
    if (preg_match('/watermark|stock photo|shutterstock|alamy/', $text)) $score -= 1000;
    if ((string) ($plannedImage['role'] ?? '') === 'inline' && preg_match('/diagram|schematic|micrograph|spectrum|plot|chart/', $text)) $score += 80;
    return $score;
}

function article_image_honest_copy(array $plannedImage, string $relation, array $candidate): array
{
    $title = trim((string) ($candidate['title'] ?? 'ilustracja źródłowa'));
    $prefix = match ($relation) {
        'apparatus' => 'Typowa aparatura lub środowisko badawcze; nie jest to urządzenie opisane w badaniu.',
        'related_context' => 'Ilustracja pokazuje powiązany kontekst, a nie dokładny obiekt opisany w tekście.',
        'analogy_scale' => 'Ilustracja objaśnia skalę lub analogię związaną z opisywanym zjawiskiem.',
        'mechanism' => 'Ilustracja przedstawia mechanizm związany z opisywanym zjawiskiem.',
        default => '',
    };
    return [
        ...$plannedImage,
        'relationship' => $relation,
        'alt' => $prefix !== '' ? $prefix . ' ' . $title : (string) $plannedImage['alt'],
        'caption' => trim((string) $plannedImage['caption']) ?: $title,
    ];
}

function article_image_context_note(array $image): string
{
    $manifest = image_rights_manifest_from_record($image);
    if (($manifest['provider'] ?? '') === 'local-editorial') {
        return 'Ilustracja redakcyjna — nie jest zdjęciem opisywanego wydarzenia.';
    }
    return match ((string) ($image['relationship'] ?? 'exact_subject')) {
        'apparatus' => 'Ilustracja aparatury typowej — nie przedstawia urządzenia z opisywanego badania.',
        'related_context' => 'Ilustracja kontekstowa — nie przedstawia bezpośrednio opisywanego wydarzenia.',
        'analogy_scale' => 'Ilustracja objaśnia skalę lub analogię, a nie samo opisywane wydarzenie.',
        'mechanism' => 'Ilustracja objaśniająca mechanizm — nie jest zdjęciem opisywanego wydarzenia.',
        default => '',
    };
}

function article_section_blocks(array $draft): array
{
    $sections = [];
    $append = static function (string $id, string $heading, string $text) use (&$sections): void {
        $text = trim(strip_tags($text));
        if ($text !== '') {
            $sections[] = [
                'id' => $id,
                'heading' => $heading,
                'text' => $text,
                'character_count' => mb_strlen($text),
            ];
        }
    };
    if (isset($draft['sections']) && is_array($draft['sections'])) {
        foreach ($draft['sections'] as $section) {
            $append((string) ($section['section_id'] ?? ''), (string) ($section['heading'] ?? ''), (string) ($section['body'] ?? ''));
            $last = array_key_last($sections);
            if ($last !== null) {
                $sections[$last]['topic_role'] = (string) ($section['topic_role'] ?? 'A');
                $sections[$last]['content_type'] = (string) ($section['content_type'] ?? 'prose');
                $sections[$last]['visual_slot_id'] = (string) ($section['visual_slot_id'] ?? '');
            }
        }
        return $sections;
    }
    $append('lead', '', (string) ($draft['lead']['text'] ?? ''));
    $append('why-important', 'Dlaczego to ważne', (string) ($draft['why_important']['text'] ?? ''));
    foreach ((array) ($draft['key_facts'] ?? []) as $index => $fact) {
        $append('fact-' . ($index + 1), 'Najważniejsze fakty', (string) ($fact['text'] ?? ''));
    }
    $append('comparison', 'Kontekst', (string) ($draft['comparison_context']['text'] ?? ''));
    foreach ((array) ($draft['unknowns'] ?? []) as $index => $unknown) {
        $append('unknown-' . ($index + 1), 'Czego jeszcze nie wiadomo', (string) ($unknown['text'] ?? ''));
    }
    foreach ((array) ($draft['narrative'] ?? []) as $key => $section) {
        $append('narrative-' . str_replace('_', '-', (string) $key), '', (string) ($section['text'] ?? ''));
    }
    $append('takeaway', 'Co z tego wynika', (string) ($draft['practical_takeaway']['text'] ?? ''));

    return $sections;
}

function article_illustration_plan_schema(?int $inlineCount = null, ?array $inlineSectionIds = null): array
{
    $inlineSchema = ['type' => 'array', 'items' => article_planned_image_schema('inline')];
    if ($inlineCount !== null) {
        if ($inlineCount < 0 || $inlineCount > 3) {
            throw new InvalidArgumentException('Nieprawidłowa liczba ilustracji inline w schemacie.');
        }
        $inlineSchema['minItems'] = $inlineCount;
        $inlineSchema['maxItems'] = $inlineCount;
    }
    if ($inlineSectionIds !== null) {
        $inlineSectionIds = array_values(array_unique($inlineSectionIds));
        if ($inlineSectionIds === []) {
            throw new InvalidArgumentException('Schemat zawiera nieprawidłowe identyfikatory sekcji inline.');
        }
        $inlineSchema['items']['properties']['section_id']['enum'] = $inlineSectionIds;
    }

    return [
        'type' => 'object',
        'properties' => [
            'hero' => article_planned_image_schema('hero'),
            'inline' => $inlineSchema,
        ],
        'required' => ['hero', 'inline'],
        'additionalProperties' => false,
    ];
}

function build_planned_illustration_fixture(array $draft): array
{
    $makeImage = static fn (string $role, string $sectionId, string $layout, string $intent): array => [
        'role' => $role,
        'section_id' => $sectionId,
        'visual_intent' => $intent,
        'search_queries' => [$role === 'hero'
            ? 'documentary photograph natural scene scientific subject'
            : 'popular science ' . str_replace('-', ' ', $sectionId)],
        'expected_content' => $intent,
        'source_page_url' => '',
        'source_file_url' => '',
        'local_path' => '',
        'author' => '',
        'license' => '',
        'license_url' => '',
        'attribution' => '',
        'alt' => $intent,
        'caption' => $intent,
        'layout' => $layout,
        'status' => 'planned',
    ];
    $plan = [
        'hero' => $makeImage(
            'hero',
            'article',
            'full',
            'Atrakcyjna pozioma fotografia okładkowa z jednym czytelnym motywem przedstawiająca temat: '
                . trim((string) ($draft['title'] ?? 'artykuł popularnonaukowy'))
        ),
        'inline' => [],
    ];
    $target = article_inline_image_target_count(article_draft_main_content_length($draft));
    $sections = article_section_blocks($draft);
    $sectionsById = [];
    foreach ($sections as $section) {
        $sectionsById[(string) $section['id']] = $section;
    }
    $orderedSections = [];
    foreach (ARTICLE_IMAGE_ALWAYS_AVAILABLE_SECTION_IDS as $sectionId) {
        if (isset($sectionsById[$sectionId])) {
            $orderedSections[] = $sectionsById[$sectionId];
            unset($sectionsById[$sectionId]);
        }
    }
    array_push($orderedSections, ...array_values($sectionsById));
    foreach (array_slice($orderedSections, 0, $target) as $index => $section) {
        $plan['inline'][] = $makeImage(
            'inline',
            (string) $section['id'],
            ['full', 'left', 'right', 'breakout'][$index % 4],
            'Konkretna ilustracja treści sekcji ' . (string) $section['id']
        );
    }

    return $plan;
}

function validate_planned_article_image(array $image, string $expectedRole, array $sectionIds): void
{
    validate_generation_value($image, article_image_schema());
    if (($image['role'] ?? '') !== $expectedRole || ($image['status'] ?? '') !== 'planned') {
        throw new InvalidArgumentException('Plan ilustracji ma nieprawidłową rolę albo status.');
    }
    if ($expectedRole === 'hero') {
        if (($image['section_id'] ?? '') !== 'article') {
            throw new InvalidArgumentException('Grafika hero musi być powiązana z całym artykułem.');
        }
    } elseif (!in_array((string) ($image['section_id'] ?? ''), $sectionIds, true)) {
        throw new InvalidArgumentException('Ilustracja inline wskazuje nieistniejącą sekcję.');
    }
    foreach (['visual_intent', 'expected_content', 'alt', 'caption'] as $field) {
        if (trim((string) ($image[$field] ?? '')) === '') {
            throw new InvalidArgumentException("Plan ilustracji wymaga pola {$field}.");
        }
    }
    if ((array) ($image['search_queries'] ?? []) === []) {
        throw new InvalidArgumentException('Plan ilustracji wymaga co najmniej jednego zapytania.');
    }
    foreach (['source_page_url', 'source_file_url', 'local_path', 'author', 'license', 'license_url', 'attribution'] as $field) {
        if (trim((string) ($image[$field] ?? '')) !== '') {
            throw new InvalidArgumentException("Model nie może wypełniać pola źródłowego {$field}.");
        }
    }
}

function validate_article_illustration_plan(array $plan, array $sections, int $characterCount): void
{
    validate_generation_value($plan, article_illustration_plan_schema());
    $sectionIds = array_column($sections, 'id');
    validate_planned_article_image((array) $plan['hero'], 'hero', $sectionIds);
    $inline = (array) $plan['inline'];
    $target = article_inline_image_target_count($characterCount);
    if (count($inline) !== $target) {
        throw new InvalidArgumentException("Plan wymaga {$target} ilustracji inline, otrzymano " . count($inline) . '.');
    }
    $usedSections = [];
    foreach ($inline as $image) {
        validate_planned_article_image((array) $image, 'inline', $sectionIds);
        if (isset($usedSections[$image['section_id']])) {
            throw new InvalidArgumentException('Dwie ilustracje nie mogą mechanicznie wskazywać tej samej sekcji.');
        }
        $usedSections[$image['section_id']] = true;
    }
}

function normalize_reuse_license(string $license): string
{
    return image_rights_normalize_license($license);
}

function article_image_license_is_auto_safe(string $license): bool
{
    return in_array(normalize_reuse_license($license), ARTICLE_IMAGE_SAFE_LICENSES, true);
}

function source_image_watermark_preflight(array $candidate): ?string
{
    $provider = mb_strtolower((string) ($candidate['provider'] ?? 'unknown'));
    $url = mb_strtolower((string) ($candidate['source_file_url'] ?? ''));
    $metadata = mb_strtolower(implode(' ', array_map('strval', array_filter([
        $candidate['title'] ?? '', $candidate['attribution'] ?? '', $candidate['rights_statement_raw'] ?? '',
        $candidate['asset_type'] ?? '', $candidate['download_type'] ?? '',
    ], 'is_scalar'))));
    if (preg_match('~rawpixel|shutterstock|alamy|istock|istockphoto|gettyimages~', $provider . ' ' . $url . ' ' . $metadata)) {
        return 'provider_or_asset_is_stock_preview';
    }
    if (preg_match('~(?:^|[/_.-])(watermark(?:ed)?|preview|sample|comp)(?:[/_.?-]|$)~', $url)
        || preg_match('/watermark(?:ed)?|stock comp|preview image|sample image/', $metadata)) {
        return 'url_or_metadata_indicates_watermarked_preview';
    }
    if (($candidate['is_original_download'] ?? true) !== true) return 'asset_is_not_original_download_endpoint';
    return null;
}

function source_png_has_alpha_channel(string $bytes): bool
{
    return strlen($bytes) >= 26
        && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n"
        && substr($bytes, 12, 4) === 'IHDR'
        && in_array(ord($bytes[25]), [4, 6], true);
}

function source_webp_has_alpha_channel(string $bytes): bool
{
    if (strlen($bytes) < 20 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') return false;
    $offset = 12;
    $length = strlen($bytes);
    while ($offset + 8 <= $length) {
        $chunk = substr($bytes, $offset, 4);
        $size = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? 0;
        $dataOffset = $offset + 8;
        if ($size < 0 || $dataOffset + $size > $length) return false;
        if ($chunk === 'VP8X' && $size >= 1) return (ord($bytes[$dataOffset]) & 0x10) !== 0;
        if ($chunk === 'VP8L' && $size >= 5 && ord($bytes[$dataOffset]) === 0x2F) {
            $bits = unpack('V', substr($bytes, $dataOffset + 1, 4))[1] ?? 0;
            return ($bits & 0x10000000) !== 0;
        }
        $offset = $dataOffset + $size + ($size % 2);
    }
    return false;
}

function source_image_has_actual_transparency(string $bytes, string $mime): bool
{
    if (!in_array($mime, ['image/png','image/webp'], true)) return false;
    // Some local PHP installations intentionally omit GD. Container alpha is
    // still a reliable presentation signal for PNG and WebP in that case.
    if (!function_exists('imagecreatefromstring')) {
        return $mime === 'image/png'
            ? source_png_has_alpha_channel($bytes)
            : source_webp_has_alpha_channel($bytes);
    }
    $image = @imagecreatefromstring($bytes);
    if ($image === false) return false;
    try {
        $width = imagesx($image); $height = imagesy($image);
        for ($y = 0; $y < $height; $y++) for ($x = 0; $x < $width; $x++) {
            if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) return true;
        }
        return false;
    } finally { imagedestroy($image); }
}

/** Conservative raster signal only; metadata/endpoint checks remain authoritative. */
function source_image_raster_watermark_reason(string $bytes, string $mime): ?string
{
    if (!in_array($mime, ['image/png','image/webp'], true) || !function_exists('imagecreatefromstring')) return null;
    $image = @imagecreatefromstring($bytes); if ($image === false) return null;
    try {
        $width=imagesx($image);$height=imagesy($image);$tiles=[];$step=max(4,(int)floor(min($width,$height)/120));
        for($y=0;$y<$height;$y+=$step)for($x=0;$x<$width;$x+=$step){$color=imagecolorat($image,$x,$y);$alpha=($color>>24)&0x7F;if($alpha>=18&&$alpha<=108){$key=(($color>>20)&0xF).(($color>>12)&0xF).(($color>>4)&0xF);$tiles[$key]=($tiles[$key]??0)+1;}}
        return $tiles !== [] && max($tiles) >= 80 ? 'repeated_semitransparent_raster_mark' : null;
    } finally { imagedestroy($image); }
}

function validate_source_image_candidate(array $candidate): array
{
    $watermarkReason = source_image_watermark_preflight($candidate);
    if ($watermarkReason !== null) throw new InvalidArgumentException('watermark_rejected: ' . $watermarkReason);
    $required = [
        'source_page_url', 'source_file_url', 'author', 'license', 'license_url',
        'attribution', 'width', 'height', 'provider', 'provider_id',
    ];
    foreach ($required as $field) {
        if (!array_key_exists($field, $candidate) || (is_string($candidate[$field]) && trim($candidate[$field]) === '')) {
            throw new InvalidArgumentException("Wynik źródła nie zawiera pola {$field}.");
        }
    }
    foreach (['source_page_url', 'source_file_url', 'license_url'] as $field) {
        $url = (string) $candidate[$field];
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException("Pole {$field} musi być rzeczywistym adresem HTTPS.");
        }
    }
    if ((int) $candidate['width'] < 0 || (int) $candidate['height'] < 0) {
        throw new InvalidArgumentException('Wynik źródła ma nieprawidłowe wymiary.');
    }
    $candidate['license_normalized'] = normalize_reuse_license((string) $candidate['license']);
    $candidate['rights_manifest'] = image_rights_manifest(
        $candidate,
        (string) ($candidate['chosen_query'] ?? 'provider_search'),
        (string) ($candidate['topic_role'] ?? 'unassigned')
    );
    $candidate['status'] = 'selected';

    return $candidate;
}

final class SourceImageProviderRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct('provider_rate_limited');
    }
}

function source_image_json_transport(string $url): array
{
    validate_remote_image_url($url);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić klienta źródła obrazów.');
    }
    $headers = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => (int) app_config('source_image_timeout_seconds'),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'MamonaSourceImageSearch/1.0',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $position = strpos($line, ':');
            if ($position !== false) $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            return strlen($line);
        },
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($status === 429) {
        throw new SourceImageProviderRateLimitException(max(0, min(5, (int) ($headers['retry-after'] ?? 0))));
    }
    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'Źródło obrazów zwróciło HTTP ' . $status . '.');
    }
    $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Źródło obrazów zwróciło nieprawidłowy JSON.');
    }

    return $decoded;
}

function search_wikimedia_commons_images(string $query, ?callable $transport = null): array
{
    $transport ??= 'source_image_json_transport';
    $url = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search'
        . '&gsrnamespace=6&gsrlimit=20&prop=imageinfo&iiprop=url%7Csize%7Cmime%7Cextmetadata'
        . '&iiurlwidth=1600&format=json&origin=*&gsrsearch=' . rawurlencode($query);
    $response = $transport($url);
    $results = [];
    foreach ((array) ($response['query']['pages'] ?? []) as $page) {
        $info = (array) ($page['imageinfo'][0] ?? []);
        $meta = (array) ($info['extmetadata'] ?? []);
        $value = static fn (string $key): string => trim(strip_tags((string) ($meta[$key]['value'] ?? '')));
        $fileUrl = (string) ($info['url'] ?? '');
        $pageUrl = (string) ($info['descriptionurl'] ?? '');
        $mime = strtolower(trim((string) ($info['mime'] ?? '')));
        if ($mime !== '' && !in_array($mime, ['image/jpeg','image/png','image/webp','image/tiff','image/x-tiff'], true)) continue;
        $licenseUrl = $value('LicenseUrl');
        $author = $value('Artist') ?: $value('Credit');
        $license = $value('LicenseShortName') ?: $value('UsageTerms');
        $riskFlags = image_rights_risk_flags((string) ($page['title'] ?? ''), $value('ImageDescription'), $value('Categories'), $value('Credit'));
        $normalizedLicense = normalize_reuse_license($license);
        if ($licenseUrl === '' && $normalizedLicense === 'public-domain') {
            $licenseUrl = 'https://creativecommons.org/publicdomain/mark/1.0/';
        } elseif ($licenseUrl === '' && $normalizedLicense === 'cc0') {
            $licenseUrl = 'https://creativecommons.org/publicdomain/zero/1.0/';
        }
        if ($fileUrl === '' || $pageUrl === '' || $licenseUrl === '' || $author === '' || $license === '') {
            continue;
        }
        try {
            $candidate = [
                'title' => (string) ($page['title'] ?? ''),
                'source_page_url' => $pageUrl,
                'source_file_url' => $fileUrl,
                'author' => $author,
                'license' => $license,
                'license_url' => $licenseUrl,
                'attribution' => trim($value('Attribution')) ?: trim($author . ', ' . $license),
                'width' => (int) ($info['thumbwidth'] ?? $info['width'] ?? 0),
                'height' => (int) ($info['thumbheight'] ?? $info['height'] ?? 0),
                'provider' => 'wikimedia',
                'provider_id' => (string) ($page['pageid'] ?? sha1($pageUrl)),
                'chosen_query' => $query,
                'topic_role' => 'candidate',
                ...$riskFlags,
            ];
            if (source_image_candidate_hard_reject_reason($candidate, ['role' => 'inline']) !== null) continue;
            $results[] = validate_source_image_candidate($candidate);
        } catch (InvalidArgumentException) {
            continue;
        }
    }

    return $results;
}

function search_openverse_images(string $query, ?callable $transport = null): array
{
    $transport ??= 'source_image_json_transport';
    $response = $transport(
        'https://api.openverse.org/v1/images/?page_size=20&mature=false&license=cc0%2Cpdm%2Cby&q='
        . rawurlencode($query)
    );
    $results = [];
    foreach ((array) ($response['results'] ?? []) as $item) {
        $license = trim((string) ($item['license'] ?? ''));
        $version = trim((string) ($item['license_version'] ?? ''));
        if ($version !== '') {
            $license .= '-' . $version;
        }
        $candidate = [
            'title' => trim((string) ($item['title'] ?? '')),
            'source_page_url' => (string) ($item['foreign_landing_url'] ?? ''),
            'source_file_url' => (string) ($item['url'] ?? ''),
            'author' => trim((string) ($item['creator'] ?? '')),
            'license' => $license,
            'license_url' => (string) ($item['license_url'] ?? ''),
            'attribution' => trim((string) ($item['attribution'] ?? '')),
            'width' => (int) ($item['width'] ?? 0),
            'height' => (int) ($item['height'] ?? 0),
            'provider' => 'openverse',
            'provider_id' => (string) ($item['id'] ?? ''),
            'chosen_query' => $query,
            'topic_role' => 'candidate',
            ...image_rights_risk_flags(trim((string) ($item['title'] ?? '')), generation_json((array) ($item['tags'] ?? []))),
        ];
        try {
            $results[] = validate_source_image_candidate($candidate);
        } catch (InvalidArgumentException) {
            continue;
        }
    }

    return $results;
}

function source_image_first_text(mixed $value): string
{
    if (is_array($value)) $value = reset($value);
    return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
}

function source_image_candidate(array $values, string $query): ?array
{
    $flags = image_rights_risk_flags((string) ($values['title'] ?? ''), (string) ($values['rights_statement_raw'] ?? ''), (string) ($values['description'] ?? ''));
    foreach ($flags as $key => $value) $values[$key] = (bool) ($values[$key] ?? $value);
    $values['chosen_query'] = $query;
    $values['topic_role'] = (string) ($values['topic_role'] ?? 'candidate');
    try { return validate_source_image_candidate($values); } catch (InvalidArgumentException) { return null; }
}

function search_smithsonian_images(string $query, ?callable $transport = null): array
{
    if ((string) app_config('smithsonian_api_key') === '' && $transport === null) return [];
    $transport ??= 'source_image_json_transport';
    $response = $transport('https://api.si.edu/openaccess/api/v1.0/search?q=' . rawurlencode($query) . '&rows=20&api_key=' . rawurlencode((string) app_config('smithsonian_api_key')));
    $results = [];
    foreach ((array) ($response['response']['rows'] ?? []) as $row) {
        foreach ((array) ($row['content']['descriptiveNonRepeating']['online_media']['media'] ?? []) as $asset) {
            $asset = (array) $asset; $rights = trim((string) ($asset['usage']['access'] ?? $asset['usage']['text'] ?? ''));
            if (image_rights_normalize_license($rights) !== 'cc0') continue;
            $resources = (array) ($asset['resources'] ?? []); $resource = (array) (end($resources) ?: []);
            $author = source_image_first_text($row['content']['freetext']['name'][0]['content'] ?? 'Smithsonian Institution') ?: 'Smithsonian Institution';
            $candidate = source_image_candidate(['title' => source_image_first_text($row['title'] ?? ''),
                'source_page_url' => (string) ($row['url'] ?? $asset['content'] ?? ''), 'source_file_url' => (string) ($resource['url'] ?? $asset['content'] ?? ''),
                'author' => $author, 'license' => 'CC0 1.0', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
                'attribution' => $author . ' — Smithsonian Open Access, CC0', 'rights_statement_raw' => $rights,
                'width' => (int) ($resource['width'] ?? 0), 'height' => (int) ($resource['height'] ?? 0),
                'provider' => 'smithsonian', 'provider_id' => (string) ($asset['ids']['id'] ?? $row['id'] ?? '')], $query);
            if ($candidate !== null) $results[] = $candidate;
        }
    }
    return $results;
}

function europeana_allowed_rights(string $rights): ?array
{
    $key = rtrim(trim($rights), '/');
    $map = [
        'http://creativecommons.org/publicdomain/zero/1.0' => ['CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
        'https://creativecommons.org/publicdomain/zero/1.0' => ['CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
        'http://creativecommons.org/publicdomain/mark/1.0' => ['Public Domain', 'https://creativecommons.org/publicdomain/mark/1.0/'],
        'https://creativecommons.org/publicdomain/mark/1.0' => ['Public Domain', 'https://creativecommons.org/publicdomain/mark/1.0/'],
        'http://creativecommons.org/licenses/by/4.0' => ['CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/'],
        'https://creativecommons.org/licenses/by/4.0' => ['CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/'],
        'http://creativecommons.org/licenses/by-sa/4.0' => ['CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/'],
        'https://creativecommons.org/licenses/by-sa/4.0' => ['CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/'],
    ];
    return $map[$key] ?? null;
}

function search_europeana_images(string $query, ?callable $transport = null): array
{
    if ((string) app_config('europeana_api_key') === '' && $transport === null) return [];
    $transport ??= 'source_image_json_transport';
    $response = $transport('https://api.europeana.eu/record/v2/search.json?media=true&reusability=open&rows=20&query=' . rawurlencode($query) . '&wskey=' . rawurlencode((string) app_config('europeana_api_key')));
    $results = [];
    foreach ((array) ($response['items'] ?? []) as $item) {
        $rightsRaw = source_image_first_text($item['rights'] ?? $item['edmRights'] ?? ''); $license = europeana_allowed_rights($rightsRaw);
        if ($license === null) continue;
        $author = source_image_first_text($item['dcCreator'] ?? $item['dataProvider'] ?? '');
        $candidate = source_image_candidate(['title' => source_image_first_text($item['title'] ?? ''),
            'source_page_url' => source_image_first_text($item['edmIsShownAt'] ?? $item['guid'] ?? ''),
            'source_file_url' => source_image_first_text($item['edmIsShownBy'] ?? ''),
            'author' => $author, 'license' => $license[0], 'license_url' => $license[1],
            'attribution' => trim($author . ', ' . $license[0] . ' — Europeana'), 'rights_statement_raw' => $rightsRaw,
            'width' => (int) ($item['webResourceWidth'] ?? 0), 'height' => (int) ($item['webResourceHeight'] ?? 0),
            'provider' => 'europeana', 'provider_id' => (string) ($item['id'] ?? '')], $query);
        if ($candidate !== null) $results[] = $candidate;
    }
    return $results;
}

function source_image_pexels_transport(string $url): array
{
    $key = (string) app_config('pexels_api_key'); if ($key === '') return [];
    image_provider_rate_limit_acquire('pexels', (int) app_config('pexels_api_hourly_limit'));
    $curl = curl_init($url); if ($curl === false) throw new RuntimeException('Nie można uruchomić klienta Pexels.');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int) app_config('source_image_timeout_seconds'), CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: ' . $key], CURLOPT_USERAGENT => 'MamonaSourceImageSearch/1.0']);
    $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) throw new RuntimeException($error !== '' ? $error : 'Pexels zwrócił HTTP ' . $status . '.');
    $decoded = json_decode($body, true, 128, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : [];
}

function search_pexels_images(string $query, ?callable $transport = null): array
{
    if ((string) app_config('pexels_api_key') === '' && $transport === null) return [];
    $transport ??= 'source_image_pexels_transport'; $response = $transport('https://api.pexels.com/v1/search?per_page=20&orientation=landscape&query=' . rawurlencode($query));
    $results = [];
    foreach ((array) ($response['photos'] ?? []) as $item) {
        $author = trim((string) ($item['photographer'] ?? ''));
        $candidate = source_image_candidate(['title' => trim((string) ($item['alt'] ?? '')), 'description' => trim((string) ($item['alt'] ?? '')),
            'source_page_url' => (string) ($item['url'] ?? ''), 'source_file_url' => (string) ($item['src']['large2x'] ?? $item['src']['large'] ?? ''),
            'author' => $author, 'license' => 'Pexels License', 'license_url' => 'https://www.pexels.com/license/',
            'attribution' => 'Photo by ' . $author . ' on Pexels', 'rights_statement_raw' => 'Pexels License: commercial use and modifications allowed',
            'width' => (int) ($item['width'] ?? 0), 'height' => (int) ($item['height'] ?? 0), 'provider' => 'pexels', 'provider_id' => (string) ($item['id'] ?? '')], $query);
        if ($candidate !== null) $results[] = $candidate;
    }
    return $results;
}

function validate_institutional_image_candidate(array $candidate, string $provider, string $query = 'institutional_asset'): array
{
    $provider = strtolower($provider); $rights = mb_strtolower((string) ($candidate['rights_statement_raw'] ?? $candidate['license'] ?? ''));
    if ($provider === 'smithsonian' && image_rights_normalize_license($rights) !== 'cc0') throw new InvalidArgumentException('Smithsonian full-auto wymaga CC0 per asset.');
    if ($provider === 'eso' && (!str_contains($rights, 'cc by 4.0') || preg_match('/exception|excluded|not covered/u', $rights))) throw new InvalidArgumentException('Asset ESO ma wyjątek lub brak domyślnej CC BY 4.0.');
    if (in_array($provider, ['nasa', 'usgs'], true) && preg_match('/third[- ]party|copyright|courtesy|rights reserved|©/u', $rights)) throw new InvalidArgumentException('Asset instytucjonalny ma oznaczenie podmiotu trzeciego.');
    if ($provider === 'nci' && !preg_match('/\bpublic domain\b/u', $rights)) throw new InvalidArgumentException('NCI full-auto wymaga oznaczenia Public Domain per asset.');
    $candidate['provider'] = $provider; $candidate['chosen_query'] = $query; return validate_source_image_candidate($candidate);
}

function search_institutional_catalog_images(string $provider, string $query, ?callable $transport = null): array
{
    if (!in_array($provider, ['eso', 'usgs', 'nci'], true)) throw new InvalidArgumentException('Nieznany katalog instytucjonalny.');
    $url = trim((string) app_config($provider . '_asset_catalog_url'));
    if ($transport === null && $url === '') return [];
    if ($url === '') $url = 'https://example.invalid/' . $provider . '/asset-catalog';
    if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new InvalidArgumentException('Katalog assetów instytucjonalnych musi używać HTTPS.');
    }
    $transport ??= 'source_image_json_transport';
    $response = $transport($url . (str_contains($url, '?') ? '&' : '?') . 'q=' . rawurlencode($query));
    $results = [];
    foreach ((array) ($response['results'] ?? []) as $candidate) {
        if (!is_array($candidate)) continue;
        try { $results[] = validate_institutional_image_candidate($candidate, $provider, $query); } catch (InvalidArgumentException) {}
    }
    return $results;
}

function search_nasa_images(string $query, ?callable $transport = null): array
{
    $transport ??= 'source_image_json_transport';
    $response = $transport('https://images-api.nasa.gov/search?media_type=image&page_size=20&q=' . rawurlencode($query));
    $results = [];
    foreach ((array) ($response['collection']['items'] ?? []) as $item) {
        $data = (array) ($item['data'][0] ?? []); $link = (array) ($item['links'][0] ?? []);
        $author = trim((string) ($data['photographer'] ?? $data['secondary_creator'] ?? 'NASA')) ?: 'NASA';
        $candidate = source_image_candidate(['title' => (string) ($data['title'] ?? ''), 'description' => (string) ($data['description'] ?? ''),
            'source_page_url' => (string) ($item['href'] ?? ''), 'source_file_url' => (string) ($link['href'] ?? ''),
            'author' => $author, 'license' => 'Public Domain', 'license_url' => 'https://www.nasa.gov/nasa-brand-center/images-and-media/',
            'attribution' => trim('NASA/' . $author, '/'), 'rights_statement_raw' => 'NASA-produced media; no third-party credit marker',
            'width' => 0, 'height' => 0, 'provider' => 'nasa', 'provider_id' => (string) ($data['nasa_id'] ?? ''),
            'third_party_warning' => false], $query);
        if ($candidate !== null) $results[] = $candidate;
    }
    return $results;
}

function source_image_search_cached(string $provider, string $query, ?callable $transport, callable $loader): array
{
    if ($transport !== null) return $loader();
    $cacheQuery = $provider === 'wikimedia' ? 'raster-filter-v3 ' . $query : $query;
    $cached = image_provider_cache_get($provider, $cacheQuery);
    if ($cached !== null) return $cached;
    $results = $loader();
    if ($results !== []) image_provider_cache_put($provider, $cacheQuery, $results);
    return $results;
}

function search_source_images(string $query, ?string $provider = null, ?callable $transport = null): array
{
    $query = trim($query);
    if ($query === '' || mb_strlen($query) > 200) {
        throw new InvalidArgumentException('Zapytanie obrazu musi mieć od 1 do 200 znaków.');
    }
    $provider = strtolower($provider ?? (string) app_config('source_image_provider'));
    if (in_array($provider, ['unsplash', 'pixabay'], true)) {
        throw new InvalidArgumentException(ucfirst($provider) . ' jest wyłącznie źródłem ręcznym i nie działa w full-auto.');
    }
    if (!in_array($provider, ['wikimedia', 'openverse', 'smithsonian', 'europeana', 'pexels', 'nasa', 'eso', 'usgs', 'nci'], true)) {
        throw new InvalidArgumentException('Nieznany provider obrazów.');
    }

    return source_image_search_cached($provider, $query, $transport, static fn (): array => match ($provider) {
        'wikimedia' => search_wikimedia_commons_images($query, $transport),
        'openverse' => search_openverse_images($query, $transport),
        'smithsonian' => search_smithsonian_images($query, $transport),
        'europeana' => search_europeana_images($query, $transport),
        'pexels' => search_pexels_images($query, $transport),
        'nasa' => search_nasa_images($query, $transport),
        'eso', 'usgs', 'nci' => search_institutional_catalog_images($provider, $query, $transport),
        'unsplash', 'pixabay' => throw new InvalidArgumentException(ucfirst($provider) . ' jest wyłącznie źródłem ręcznym i nie działa w full-auto.'),
        default => throw new InvalidArgumentException('Dozwolone źródła obrazów to Wikimedia Commons i Openverse.'),
    });
}

/**
 * P3-C: Preselection score for an image candidate against a planned image.
 *
 * This is NOT the final publication gate. It is a cheap, deterministic
 * token-overlap heuristic used to rank candidates before multimodal assessment.
 * The final decision requires article_image_multimodal_assess() ACCEPT.
 *
 * Returns 0–100. Higher means better keyword overlap with the planned image intent.
 */
function article_image_semantic_gate_score(array $candidate, array $plannedImage): int
{
    $title = mb_strtolower((string) ($candidate['title'] ?? ''));
    $sourcePath = mb_strtolower(rawurldecode((string) ($candidate['source_page_url'] ?? '')));
    // Search provenance remains auditable, but must not make an unrelated
    // candidate inherit semantic relevance from the query that found it.
    $combined = $title . ' ' . $sourcePath;

    /* Token-based relevance: compare candidate tokens against planned visual_intent + expected_content. */
    $plannedTokens = article_image_semantic_gate_tokenize(implode(' ', [
        (string) ($plannedImage['visual_intent'] ?? ''),
        (string) ($plannedImage['expected_content'] ?? ''),
        implode(' ', array_map('strval', (array) ($plannedImage['search_queries'] ?? []))),
    ]));
    $titleTokens = article_image_semantic_gate_tokenize($title);
    $candidateTokens = article_image_semantic_gate_tokenize($combined);

    if ($plannedTokens === []) {
        return 50; /* No plan to compare against — neutral. */
    }

    $hits = 0;
    foreach ($plannedTokens as $token) {
        foreach ($candidateTokens as $candidateToken) {
            if (article_image_semantic_tokens_match($token, $candidateToken)) {
                $hits++;
                break;
            }
        }
    }

    /* A concise candidate title need not repeat every detail from a rich VisualSlot plan. */
    $denominator = max(1, min(count($plannedTokens), count($titleTokens)));
    $score = min(100, (int) round(($hits / $denominator) * 100));

    /* Bonus: if the candidate title contains at least 2 planned tokens, boost. */
    if ($hits >= 2) {
        $score = min(100, $score + 15);
    }

    return $score;
}

/** Unicode-safe loose token match for inflected words used only by the cheap prefilter. */
function article_image_semantic_tokens_match(string $plannedToken, string $candidateToken): bool
{
    if ($plannedToken === $candidateToken) {
        return true;
    }
    $plannedLength = mb_strlen($plannedToken, 'UTF-8');
    $candidateLength = mb_strlen($candidateToken, 'UTF-8');
    $prefixLength = min($plannedLength, $candidateLength);
    if ($prefixLength < 4 || abs($plannedLength - $candidateLength) > 4) {
        return false;
    }

    return mb_substr($plannedToken, 0, $prefixLength, 'UTF-8')
        === mb_substr($candidateToken, 0, $prefixLength, 'UTF-8');
}

/** Tokenize a string into distinctive keywords for semantic gate comparison. */
function article_image_semantic_gate_tokenize(string $text, ?array $stopWords = null): array
{
    $lower = mb_strtolower($text, 'UTF-8');
    // Unicode-aware split: separate on any non-letter/non-digit character
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = $stopWords ?? [
        'the', 'a', 'an', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by',
        'from', 'and', 'or', 'is', 'it', 'as', 'that', 'this', 'image', 'photo',
        'picture', 'file', 'view', 'illustration', 'commons', 'wikimedia',
    ];
    $result = [];
    foreach ($parts as $part) {
        if (mb_strlen($part, 'UTF-8') < 4 || in_array($part, $stop, true)) {
            continue;
        }
        $result[] = $part;
    }
    return array_values(array_unique($result));
}

/**
 * Compare two short editorial descriptions without treating inflectional
 * variants (for example "makak" / "makaki") as different subjects.
 * This is deliberately a diversity signal, never a relevance score.
 */
function article_image_semantic_text_similarity(string $first, string $second): float
{
    $left = article_image_semantic_gate_tokenize($first);
    $right = article_image_semantic_gate_tokenize($second);
    if ($left === [] || $right === []) return 0.0;
    $hits = 0;
    foreach ($left as $token) {
        foreach ($right as $other) {
            if (article_image_semantic_tokens_match($token, $other)) {
                $hits++;
                break;
            }
        }
    }
    return $hits / max(count($left), count($right));
}

/** Build one auditable semantic profile from the data already saved for Vision. */
function article_image_diversity_profile(array $image): array
{
    $assessment = is_array($image['multimodal_assessment'] ?? null)
        ? $image['multimodal_assessment']
        : (json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true) ?: []);
    $caption = trim((string) ($image['caption'] ?? ''));
    $visionCaption = trim((string) ($assessment['suggested_caption'] ?? ''));
    $subject = trim((string) ($assessment['visual_subject'] ?? ''));
    $function = trim((string) ($assessment['visual_function'] ?? ''));
    return [
        'caption' => $caption !== '' ? $caption : $visionCaption,
        'subject' => $subject !== '' ? $subject : ($visionCaption !== '' ? $visionCaption : $caption),
        'function' => $function !== '' ? $function : trim((string) ($image['expected_content'] ?? $image['visual_intent'] ?? '')),
        'visual_type' => (string) ($assessment['visual_type'] ?? ''),
        'relationship_level' => (string) ($assessment['relationship_level'] ?? ''),
    ];
}

/**
 * A pair is redundant only when actual-image evidence says that it performs
 * effectively the same illustrative job. Similar topic alone is allowed.
 */
function article_image_semantic_duplicate_reason(array $candidate, array $accepted): ?array
{
    $left = article_image_diversity_profile($candidate);
    $right = article_image_diversity_profile($accepted);
    $captionSimilarity = article_image_semantic_text_similarity($left['caption'], $right['caption']);
    $subjectSimilarity = article_image_semantic_text_similarity($left['subject'], $right['subject']);
    $functionSimilarity = article_image_semantic_text_similarity($left['function'], $right['function']);
    $sameType = $left['visual_type'] !== '' && $left['visual_type'] === $right['visual_type'];
    $sameRelationship = $left['relationship_level'] !== '' && $left['relationship_level'] === $right['relationship_level'];

    // Near-identical semantic captions are a strong editorial warning. A
    // matching visual type/relationship prevents false positives for two
    // genuinely different explanatory views of the same object.
    if ($captionSimilarity >= 0.82 && ($sameType || $sameRelationship || $subjectSimilarity >= 0.62)) {
        return [
            'code' => 'semantic_duplicate',
            'caption_similarity' => round($captionSimilarity, 3),
            'subject_similarity' => round($subjectSimilarity, 3),
            'function_similarity' => round($functionSimilarity, 3),
            'against_image_id' => (int) ($accepted['id'] ?? 0),
        ];
    }
    if ($subjectSimilarity >= 0.82 && $functionSimilarity >= 0.72 && ($sameType || $sameRelationship)) {
        return [
            'code' => 'insufficient_new_value',
            'caption_similarity' => round($captionSimilarity, 3),
            'subject_similarity' => round($subjectSimilarity, 3),
            'function_similarity' => round($functionSimilarity, 3),
            'against_image_id' => (int) ($accepted['id'] ?? 0),
        ];
    }
    return null;
}

final class ArticleImageSemanticDuplicateException extends InvalidArgumentException
{
    public function __construct(public readonly array $diversity)
    {
        parent::__construct((string) ($diversity['code'] ?? 'semantic_duplicate')
            . ': redundant_visual_against_image_' . (int) ($diversity['against_image_id'] ?? 0));
    }
}

/** Reject only a redundant slot; all other accepted images remain untouched. */
function article_image_assert_selected_diversity(int $postId, array $selected): void
{
    foreach (list_article_images($postId) as $accepted) {
        if ((int) ($accepted['editorial_rejected'] ?? 0) === 1
            || (int) ($accepted['multimodal_accepted'] ?? 0) !== 1
            || (string) ($accepted['status'] ?? '') !== 'downloaded'
            || ((string) ($accepted['role'] ?? '') === (string) ($selected['role'] ?? '')
                && (string) ($accepted['section_id'] ?? '') === (string) ($selected['section_id'] ?? ''))) {
            continue;
        }
        $reason = article_image_semantic_duplicate_reason($selected, $accepted);
        if ($reason !== null) {
            throw new ArticleImageSemanticDuplicateException($reason);
        }
    }
}

/**
 * Multimodal semantic/editorial assessment for an image candidate.
 *
 * In production, this calls Gemini Vision with article context + actual image.
 * In tests, this is mockable via a callback or interface.
 *
 * Returns structured result:
 * [
 *   'semantic_relevance' => 0-10,
 *   'editorial_fit' => 0-10,
 *   'depicts_required_subject' => bool,
 *   'misleading' => bool,
 *   'inappropriate' => bool,
 *   'contains_readable_text' => bool,
 *   'detail_density' => 'low'|'medium'|'high',
 *   'visual_type' => 'photo'|'illustration'|'diagram'|'chart'|'screenshot'|'other',
 *   'safe_for_side_layout' => bool,
 *   'decision' => 'accept'|'reject',
 *   'reason' => string,
 * ]
 */
function article_image_multimodal_assess(
    array $candidate,
    array $plannedImage,
    string $articleContext,
    ?callable $geminiVisionCallback = null
): array {
    if ($geminiVisionCallback === null) {
        throw new RuntimeException(
            'Multimodal image assessment requires Gemini Vision callback or production provider.'
        );
    }
    $assessment = $geminiVisionCallback($candidate, $plannedImage, $articleContext);
    if (!is_array($assessment)) {
        throw new RuntimeException('Gemini Vision musi zwrócić strukturalną ocenę obrazu.');
    }
    $assessment = [
        'semantic_relevance' => (int) ($assessment['semantic_relevance'] ?? -1),
        'editorial_fit' => (int) ($assessment['editorial_fit'] ?? -1),
        'hero_fit' => (int) ($assessment['hero_fit'] ?? $assessment['editorial_fit'] ?? -1),
        'depicts_required_subject' => $assessment['depicts_required_subject'] ?? null,
        'misleading' => $assessment['misleading'] ?? null,
        'inappropriate' => $assessment['inappropriate'] ?? null,
        'decision' => strtolower(trim((string) ($assessment['decision'] ?? ''))),
        'reason' => trim((string) ($assessment['reason'] ?? '')),
        'relationship_level' => strtolower(trim((string) ($assessment['relationship_level'] ?? (
            !empty($assessment['depicts_required_subject']) ? 'direct' : (($assessment['decision'] ?? '') === 'accept' ? 'strong_related' : 'unrelated')
        )))),
        'contextual_useful' => (bool) ($assessment['contextual_useful'] ?? (($assessment['decision'] ?? '') === 'accept')),
        'honest_caption_possible' => (bool) ($assessment['honest_caption_possible'] ?? (($assessment['decision'] ?? '') === 'accept')),
        'suggested_caption' => trim((string) ($assessment['suggested_caption'] ?? '')),
        // New Vision responses provide these directly. Older audited responses
        // remain usable through the truthful caption/slot fallback below.
        'visual_subject' => trim((string) ($assessment['visual_subject'] ?? $assessment['suggested_caption'] ?? $candidate['title'] ?? '')),
        'visual_function' => trim((string) ($assessment['visual_function'] ?? $plannedImage['expected_content'] ?? $plannedImage['visual_intent'] ?? '')),
        'contains_readable_text' => (bool) ($assessment['contains_readable_text'] ?? false),
        'detail_density' => strtolower(trim((string) ($assessment['detail_density'] ?? 'high'))),
        'visual_type' => strtolower(trim((string) ($assessment['visual_type'] ?? 'other'))),
        'safe_for_side_layout' => (bool) ($assessment['safe_for_side_layout'] ?? false),
    ];
    if ($assessment['semantic_relevance'] < 0 || $assessment['semantic_relevance'] > 10
        || $assessment['editorial_fit'] < 0 || $assessment['editorial_fit'] > 10
        || $assessment['hero_fit'] < 0 || $assessment['hero_fit'] > 10
        || !is_bool($assessment['depicts_required_subject'])
        || !is_bool($assessment['misleading'])
        || !is_bool($assessment['inappropriate'])
        || !in_array($assessment['decision'], ['accept', 'reject'], true)
        || !in_array($assessment['relationship_level'], ['direct', 'broader_direct', 'strong_related', 'contextual_related', 'domain_related', 'unrelated'], true)
        || !in_array($assessment['detail_density'], ['low', 'medium', 'high'], true)
        || !in_array($assessment['visual_type'], ['photo', 'illustration', 'diagram', 'chart', 'screenshot', 'other'], true)
        || $assessment['reason'] === '' || $assessment['visual_subject'] === '' || $assessment['visual_function'] === '') {
        throw new RuntimeException('Gemini Vision zwróciło niepełną albo nieprawidłową ocenę obrazu.');
    }

    return $assessment;
}

/**
 * A direct acquisition may not silently promote contextual fit to a direct
 * slot. Contextual assets are admitted only by the explicit recovery path,
 * which also persists the required source-backed context.
 */
function article_image_assessment_allows_planned_slot(array $assessment, array $plannedImage): bool
{
    if ((string) ($assessment['decision'] ?? '') !== 'accept'
        || empty($assessment['honest_caption_possible'])
        || !empty($assessment['misleading'])
        || !empty($assessment['inappropriate'])) {
        return false;
    }

    $level = (string) ($assessment['relationship_level'] ?? 'unrelated');
    if ((string) ($plannedImage['relationship_policy'] ?? '') === 'ww_contextual_v1') {
        return !empty($assessment['contextual_useful'])
            && in_array($level, ['direct', 'broader_direct', 'strong_related', 'contextual_related', 'domain_related'], true);
    }

    return in_array($level, ['direct', 'broader_direct'], true);
}

final class ArticleImageVisionRejectedException extends InvalidArgumentException
{
    public function __construct(public readonly array $assessment)
    {
        parent::__construct('Wszystkie kandydatury obrazów odrzucone przez multimodalną ocenę redakcyjną.');
    }
}

/** Prepare a derivative transport copy only; source identity and bytes remain untouched. */
function article_image_prepare_vision_copy(string $bytes, string $mime, int $maxBytes): array
{
    $originalHash = hash('sha256', $bytes);
    $originalSize = strlen($bytes);
    $compatible = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    if ($compatible && strlen($bytes) <= $maxBytes) {
        return ['bytes'=>$bytes, 'mime'=>$mime, 'preprocessed'=>false,
            'original_sha256'=>$originalHash, 'prepared_sha256'=>$originalHash,
            'original_format'=>$mime, 'original_size'=>$originalSize,
            'preprocess_attempted'=>false, 'preprocess_type'=>'none', 'preprocess_success'=>false,
            'prepared_format'=>$mime, 'prepared_size'=>$originalSize, 'final_local_reject_reason'=>''];
    }
    if (!$compatible && !in_array($mime, ['image/tiff', 'image/x-tiff'], true)) {
        throw new InvalidArgumentException('Vision wymaga rzeczywistego obrazu JPEG, PNG, WebP albo dekodowalnego TIFF.');
    }

    $input = tempnam(sys_get_temp_dir(), 'mamona-vision-source-');
    $output = tempnam(sys_get_temp_dir(), 'mamona-vision-copy-');
    if ($input === false || $output === false) throw new RuntimeException('Nie można utworzyć lokalnej kopii transportowej Vision.');
    @unlink($output);
    try {
        if (file_put_contents($input, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Nie można zapisać źródła do lokalnego preprocessingu Vision.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Lokalny preprocessor Vision nie jest dostępny na tym systemie.');
        }
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'prepare-vision-image.ps1';
        $command = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
            $script, '-InputPath', $input, '-OutputPath', $output, '-MaxBytes', (string) $maxBytes];
        $pipes = [];
        $process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Nie można uruchomić lokalnego preprocessingu Vision.');
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        $prepared = is_file($output) ? (string) file_get_contents($output) : '';
        $preparedInfo = $prepared !== '' ? @getimagesizefromstring($prepared) : false;
        $preparedMime = is_array($preparedInfo) ? strtolower((string) ($preparedInfo['mime'] ?? '')) : '';
        if ($exitCode !== 0 || $prepared === '' || strlen($prepared) > $maxBytes || $preparedMime !== 'image/jpeg') {
            throw new RuntimeException('Lokalne przygotowanie kopii Vision nie powiodło się: ' . trim((string) ($stderr ?: $stdout)));
        }
        return ['bytes'=>$prepared, 'mime'=>$preparedMime, 'preprocessed'=>true,
            'original_sha256'=>$originalHash, 'prepared_sha256'=>hash('sha256', $prepared),
            'original_format'=>$mime, 'original_size'=>$originalSize,
            'preprocess_attempted'=>true,
            'preprocess_type'=>in_array($mime, ['image/tiff', 'image/x-tiff'], true) ? 'tiff_to_jpeg' : 'resize_compress',
            'preprocess_success'=>true, 'prepared_format'=>$preparedMime, 'prepared_size'=>strlen($prepared),
            'final_local_reject_reason'=>''];
    } finally {
        if (is_file($input)) @unlink($input);
        if (is_file($output)) @unlink($output);
    }
}

function article_image_vision_input(array $candidate, ?callable $imageTransport = null): array
{
    $url = trim((string) ($candidate['source_file_url'] ?? ''));
    if ($url === '') {
        throw new InvalidArgumentException('Kandydat Vision nie ma adresu rzeczywistego obrazu.');
    }
    $maxBytes = (int) app_config('source_image_max_bytes');
    $preprocessInputLimit = $maxBytes * 8;
    $imageTransport ??= static fn (string $imageUrl): array => source_image_curl_once($imageUrl, $preprocessInputLimit);
    $response = $imageTransport($url);
    $status = (int) ($response['status'] ?? 0);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Źródło obrazu dla Vision zwróciło HTTP ' . $status . '.');
    }
    $bytes = (string) ($response['body'] ?? '');
    if ($bytes === '' || strlen($bytes) > $preprocessInputLimit) throw new InvalidArgumentException('Obraz dla Vision jest pusty albo przekracza bezpieczny limit wejściowy preprocessingu.');
    $info = @getimagesizefromstring($bytes);
    $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
    if ($mime === '') throw new InvalidArgumentException('Źródło Vision nie jest dekodowalnym obrazem.');
    return article_image_prepare_vision_copy($bytes, $mime, $maxBytes);
}

function article_image_local_reject_reason(Throwable $exception): string
{
    $message = mb_strtolower($exception->getMessage(), 'UTF-8');
    return match (true) {
        str_contains($message, 'licenc'), str_contains($message, 'rights'), str_contains($message, 'prawn'),
            str_contains($message, 'manifest'), str_contains($message, 'attribution'), str_contains($message, 'author') => 'rights_or_license',
        str_contains($message, 'duplicate'), str_contains($message, 'same_series') => 'duplicate',
        str_contains($message, '404'), str_contains($message, 'niedostęp'), str_contains($message, 'adres') => 'unavailable',
        str_contains($message, 'http'), str_contains($message, 'pobier') => 'download_failure',
        str_contains($message, 'format'), str_contains($message, 'jpeg, png, webp'), str_contains($message, 'tiff') => 'unsupported_format',
        str_contains($message, 'rozmiar'), str_contains($message, 'limit wejściowy') => 'oversized',
        str_contains($message, 'mały'), str_contains($message, 'small') => 'too_small',
        str_contains($message, 'dekodowal'), str_contains($message, 'corrupt') => 'corrupt',
        str_contains($message, 'logo'), str_contains($message, 'icon') => 'logo_or_icon',
        str_contains($message, 'placeholder') => 'placeholder',
        str_contains($message, 'dokument'), str_contains($message, 'page scan'), str_contains($message, 'djvu'), str_contains($message, 'pdf') => 'document_or_page_scan',
        default => 'other_hard_technical',
    };
}

/** Persist only reviewable Vision metadata; never source bytes or credentials. */
function article_image_vision_audit_sanitize(mixed $value): mixed
{
    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $key => $child) {
            $keyText = (string) $key;
            $sanitized[$keyText] = preg_match('/(?:api[_-]?key|authorization|token|secret|password)/i', $keyText) === 1
                ? '[REDACTED]'
                : article_image_vision_audit_sanitize($child);
        }
        return $sanitized;
    }
    if (is_string($value)) return preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[REDACTED]', $value) ?? '';
    return $value;
}

function article_image_vision_audit_response(array $response, string $providerText = ''): array
{
    $body = (string) ($response['body'] ?? '');
    $decoded = json_decode($body, true);
    return [
        'json' => is_array($decoded) ? generation_json(article_image_vision_audit_sanitize($decoded)) : '{}',
        'text' => article_image_vision_audit_sanitize($providerText !== '' ? $providerText : $body),
    ];
}

function article_image_vision_audit_topic_id(int $postId): ?int
{
    $statement = bueno_database()->prepare('SELECT id FROM editorial_topics WHERE primary_post_id=:post ORDER BY id DESC LIMIT 1');
    $statement->execute([':post' => $postId]);
    $topicId = (int) $statement->fetchColumn();
    return $topicId > 0 ? $topicId : null;
}

function article_image_vision_audit_record(array $record): void
{
    bueno_database()->prepare('INSERT INTO article_image_vision_audit (
        call_key,generation_operation_id,post_id,topic_id,budget_before,budget_after,operation_type,model,
        slot_identifier,candidate_identifier,source_page_identifier,source_file_identifier,outbound_prompt,
        image_sha256,image_mime,provider_response_json,provider_response_text,status,error_message,completed_at
    ) VALUES (
        :call_key,:generation_operation_id,:post_id,:topic_id,:budget_before,:budget_after,:operation_type,:model,
        :slot_identifier,:candidate_identifier,:source_page_identifier,:source_file_identifier,:outbound_prompt,
        :image_sha256,:image_mime,:provider_response_json,:provider_response_text,:status,:error_message,CURRENT_TIMESTAMP
    )')->execute($record);
}

function article_image_has_transparency(array $image): bool
{
    if (!empty($image['has_transparency'])) return true;
    $relativePath = trim(str_replace('\\', '/', (string) ($image['local_path'] ?? '')));
    $projectRoot = realpath(app_project_root());
    $absolutePath = realpath(app_path($relativePath));
    if ($projectRoot === false || $absolutePath === false
        || !str_starts_with(str_replace('\\', '/', $absolutePath), rtrim(str_replace('\\', '/', $projectRoot), '/') . '/')) {
        return false;
    }
    $bytes = @file_get_contents($absolutePath);
    if (!is_string($bytes)) return false;
    $mime = source_png_has_alpha_channel($bytes) || str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
        ? 'image/png'
        : (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' ? 'image/webp' : '');
    return $mime !== '' && source_image_has_actual_transparency($bytes, $mime);
}

/** Keep the rights and source evidence needed for later human review with the Vision audit. */
function article_image_review_candidate_snapshot(array $candidate): array
{
    return array_intersect_key($candidate, array_flip([
        'provider', 'provider_id', 'title', 'description', 'source_page_url', 'source_file_url',
        'author', 'license', 'license_url', 'attribution', 'rights_statement_raw', 'width', 'height',
        'asset_type', 'download_type', 'is_original_download', 'third_party_warning',
        'identifiable_people', 'trademarks_logos', 'license_normalized', 'rights_manifest',
    ]));
}

function article_image_vision_slot_identifier(array $plannedImage): string
{
    return trim((string) ($plannedImage['slot_id'] ?? ''))
        ?: ((string) ($plannedImage['role'] ?? '') . ':' . (string) ($plannedImage['section_id'] ?? ''));
}

/** Reuse a completed deterministic Vision response for the same slot and asset bytes. */
function article_image_completed_vision_assessment(
    int $articleId,
    array $candidate,
    array $plannedImage,
    string $imageSha256
): ?array {
    $statement = bueno_database()->prepare(
        'SELECT candidate_identifier,source_page_identifier,source_file_identifier,provider_response_text
         FROM article_image_vision_audit
         WHERE post_id=:post AND slot_identifier=:slot AND image_sha256=:hash AND status="completed"
         ORDER BY id DESC'
    );
    $statement->execute([
        ':post'=>$articleId,
        ':slot'=>article_image_vision_slot_identifier($plannedImage),
        ':hash'=>$imageSha256,
    ]);
    $candidateId = trim((string) ($candidate['provider_id'] ?? ''));
    $sourcePage = trim((string) ($candidate['source_page_url'] ?? ''));
    $sourceFile = trim((string) ($candidate['source_file_url'] ?? ''));
    foreach ($statement->fetchAll() as $row) {
        $sameIdentity = ($candidateId !== '' && hash_equals($candidateId, (string) ($row['candidate_identifier'] ?? '')))
            || ($sourceFile !== '' && hash_equals($sourceFile, (string) ($row['source_file_identifier'] ?? '')))
            || ($sourcePage !== '' && hash_equals($sourcePage, (string) ($row['source_page_identifier'] ?? '')));
        if (!$sameIdentity) continue;
        try {
            $stored = decode_generation_response((string) ($row['provider_response_text'] ?? ''));
            if ((string) ($plannedImage['relationship_policy'] ?? '') === 'ww_contextual_v1'
                && !array_key_exists('relationship_level', $stored)) continue;
            return article_image_multimodal_assess($candidate, $plannedImage, '', static fn (): array => $stored);
        } catch (Throwable) {
            continue;
        }
    }
    return null;
}

/** Production Gemini Vision adapter using the same central article budget as text generation. */
function article_image_gemini_vision_assess(
    int $articleId,
    array $candidate,
    array $plannedImage,
    string $articleContext,
    ?callable $geminiTransport = null,
    ?callable $imageTransport = null,
    ?string $apiKey = null,
    int $protectedClosureCalls = 0
): array {
    $database = bueno_database();
    if ($geminiTransport === null) {
        if (PHP_SAPI === 'cli' && trim((string) getenv('CMS_TEST_DATABASE_FILE')) !== ''
            && !(bool) app_config('allow_live_gemini_test')) {
            throw new RuntimeException('Testy nie mogą łączyć się z Gemini Vision bez CMS_ALLOW_LIVE_GEMINI_TEST=1.');
        }
        $apiKey = $apiKey ?? app_environment_value('GEMINI_API_KEY');
        if ($apiKey === null || trim($apiKey) === '') {
            throw new RuntimeException('Brakuje GEMINI_API_KEY dla oceny obrazu Gemini Vision.');
        }
        $geminiTransport = 'gemini_curl_transport';
    }
    $image = article_image_vision_input($candidate, $imageTransport);
    $imageSha256 = (string) ($image['prepared_sha256'] ?? hash('sha256', (string) $image['bytes']));
    $completedAssessment = article_image_completed_vision_assessment(
        $articleId,
        $candidate,
        $plannedImage,
        $imageSha256
    );
    if ($completedAssessment !== null) return $completedAssessment;
    $schema = [
        'type' => 'object',
        'properties' => [
            'semantic_relevance' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
            'editorial_fit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
            'hero_fit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
            'depicts_required_subject' => ['type' => 'boolean'],
            'misleading' => ['type' => 'boolean'],
            'inappropriate' => ['type' => 'boolean'],
            'decision' => ['type' => 'string', 'enum' => ['accept', 'reject']],
            'reason' => ['type' => 'string'],
            'relationship_level' => ['type'=>'string','enum'=>['direct','broader_direct','strong_related','contextual_related','domain_related','unrelated']],
            'contextual_useful' => ['type'=>'boolean'],
            'honest_caption_possible' => ['type'=>'boolean'],
            'suggested_caption' => ['type'=>'string'],
            'visual_subject' => ['type'=>'string','minLength'=>3,'maxLength'=>500],
            'visual_function' => ['type'=>'string','minLength'=>3,'maxLength'=>500],
            'contains_readable_text' => ['type'=>'boolean'],
            'detail_density' => ['type'=>'string','enum'=>['low','medium','high']],
            'visual_type' => ['type'=>'string','enum'=>['photo','illustration','diagram','chart','screenshot','other']],
            'safe_for_side_layout' => ['type'=>'boolean'],
        ],
        'required' => ['semantic_relevance', 'editorial_fit', 'hero_fit', 'depicts_required_subject', 'misleading', 'inappropriate', 'decision', 'reason', 'relationship_level', 'contextual_useful', 'honest_caption_possible', 'suggested_caption', 'visual_subject', 'visual_function', 'contains_readable_text', 'detail_density', 'visual_type', 'safe_for_side_layout'],
    ];
    $context = [
        'ww_policy_instruction' => 'Evaluate direct fit first. If direct fit is false, independently evaluate broader_direct, strong_related, contextual_related, or domain_related. Under ww_contextual_v1 accept any of those five levels when the image is useful, honest, legal, not misleading, and an honest contextual caption is possible. depicts_required_subject=false alone is not a rejection reason. suggested_caption must disclose contextual or illustrative use and must not claim this is the exact study or instrument. visual_subject must name what the image actually depicts; visual_function must name the distinct information this image contributes for this slot, not merely repeat the article topic. In the same response classify presentation metadata: readable embedded text, detail density, visual type, and whether the image remains clear at roughly half article width. safe_for_side_layout must be false for diagrams, charts, screenshots, high detail, or important readable text.',
        'article_context' => $articleContext,
        'section_context' => (string) ($plannedImage['section_id'] ?? ''),
        'visual_slot' => [
            'role' => (string) ($plannedImage['role'] ?? ''),
            'section_id' => (string) ($plannedImage['section_id'] ?? ''),
            'visual_intent' => (string) ($plannedImage['visual_intent'] ?? ''),
            'expected_content' => (string) ($plannedImage['expected_content'] ?? ''),
            'recovery_policy' => (string) ($plannedImage['recovery_policy'] ?? ''),
            'vision_phase' => (string) ($plannedImage['vision_phase'] ?? 'candidate_assessment'),
            'relationship_policy' => (string) ($plannedImage['relationship_policy'] ?? ''),
        ],
        'candidate_metadata' => [
            'provider' => (string) ($candidate['provider'] ?? ''),
            'provider_id' => (string) ($candidate['provider_id'] ?? ''),
            'title' => (string) ($candidate['title'] ?? ''),
            'source_page_url' => (string) ($candidate['source_page_url'] ?? ''),
        ],
    ];
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [
                ['text' => "Oceń rzeczywisty obraz jako ilustrację konkretnego artykułu i VisualSlot. Nie opieraj decyzji wyłącznie na metadanych.\n" . generation_json($context)],
                ['inlineData' => ['mimeType' => $image['mime'], 'data' => base64_encode($image['bytes'])]],
            ],
        ]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => $schema,
            'temperature' => 0.1,
        ],
    ];
    $callKey = ARTICLE_IMAGE_GEMINI_OPERATION_TYPE . '-' . $articleId . '-' . bin2hex(random_bytes(12));
    $claim = gemini_article_budget_claim(
        $database, $articleId, ARTICLE_IMAGE_GEMINI_OPERATION_TYPE, 'images', 1, $callKey, $protectedClosureCalls
    );
    $used = (int) ($claim['used_before'] ?? 0);
    $response = [];
    $providerText = '';
    $auditStatus = 'transport_error';
    $auditError = '';
    try {
        $response = $geminiTransport(
            $payload,
            (string) ($apiKey ?? 'mock-transport'),
            $callKey,
            (string) app_config('gemini_model')
        );
        $status = (int) ($response['status'] ?? 0);
        gemini_article_budget_reconcile_claim(
            $database, $articleId, (string) ($claim['claim_token'] ?? ''),
            $status > 0 ? ($status >= 200 && $status < 300 ? 'completed' : 'failed') : 'released'
        );
        if ($status < 200 || $status >= 300) {
            $details = gemini_error_details($response);
            $auditStatus = 'failed_http';
            throw new RuntimeException('Gemini Vision zwróciło HTTP ' . $status . ($details['message'] !== '' ? ': ' . $details['message'] : '.'));
        }
        $decoded = json_decode((string) ($response['body'] ?? ''), true, 128, JSON_THROW_ON_ERROR);
        $providerOutput = gemini_extract_output(is_array($decoded) ? $decoded : []);
        $providerText = (string) ($providerOutput['text'] ?? '');
        $assessment = decode_generation_response($providerText);
        $result = article_image_multimodal_assess(
            $candidate,
            $plannedImage,
            $articleContext,
            static fn (): array => $assessment
        );
        $auditStatus = 'completed';
        return $result;
    } catch (Throwable $exception) {
        if ($response === []) {
            gemini_article_budget_reconcile_claim($database, $articleId, (string) ($claim['claim_token'] ?? ''), 'released');
        }
        $auditError = $exception->getMessage();
        if ($auditStatus === 'transport_error' && $response !== []) $auditStatus = 'invalid_response';
        throw $exception;
    } finally {
        $responseAudit = article_image_vision_audit_response($response, $providerText);
        $responseAuditJson = json_decode((string) $responseAudit['json'], true);
        $responseAuditJson = is_array($responseAuditJson) ? $responseAuditJson : [];
        $responseAuditJson['_vision_transport_copy'] = [
            'preprocessed'=>(bool) ($image['preprocessed'] ?? false),
            'original_sha256'=>(string) ($image['original_sha256'] ?? $imageSha256),
            'prepared_sha256'=>$imageSha256,
            'prepared_mime'=>(string) $image['mime'],
            'original_format'=>(string) ($image['original_format'] ?? ''),
            'original_size'=>(int) ($image['original_size'] ?? 0),
            'preprocess_attempted'=>(bool) ($image['preprocess_attempted'] ?? false),
            'preprocess_type'=>(string) ($image['preprocess_type'] ?? 'none'),
            'preprocess_success'=>(bool) ($image['preprocess_success'] ?? false),
            'prepared_format'=>(string) ($image['prepared_format'] ?? $image['mime']),
            'prepared_size'=>(int) ($image['prepared_size'] ?? strlen((string) ($image['bytes'] ?? ''))),
            'final_local_reject_reason'=>(string) ($image['final_local_reject_reason'] ?? ''),
            'source_file_url'=>(string) ($candidate['source_file_url'] ?? ''),
        ];
        $responseAuditJson['_candidate'] = article_image_review_candidate_snapshot($candidate);
        $budgetAfter = gemini_article_budget_state($articleId);
        article_image_vision_audit_record([
            ':call_key' => $callKey, ':generation_operation_id' => null, ':post_id' => $articleId,
            ':topic_id' => article_image_vision_audit_topic_id($articleId), ':budget_before' => $used,
            ':budget_after' => (int) ($budgetAfter['used_calls'] ?? $used), ':operation_type' => ARTICLE_IMAGE_GEMINI_OPERATION_TYPE,
            ':model' => (string) app_config('gemini_model'),
            ':slot_identifier' => article_image_vision_slot_identifier($plannedImage),
            ':candidate_identifier' => (string) ($candidate['provider_id'] ?? ''),
            ':source_page_identifier' => (string) ($candidate['source_page_url'] ?? ''),
            ':source_file_identifier' => (string) ($candidate['source_file_url'] ?? ''),
            ':outbound_prompt' => (string) ($payload['contents'][0]['parts'][0]['text'] ?? ''),
            ':image_sha256' => $imageSha256, ':image_mime' => (string) $image['mime'],
            ':provider_response_json' => generation_json($responseAuditJson), ':provider_response_text' => (string) $responseAudit['text'],
            ':status' => $auditStatus, ':error_message' => (string) article_image_vision_audit_sanitize($auditError),
        ]);
    }
}

function select_source_image_from_results(
    array $plannedImage,
    array $results,
    string $providerId,
    ?callable $geminiVisionCallback = null,
    string $articleContext = '',
    bool $allowContextualRecovery = false
): array {
    validate_planned_article_image(
        $plannedImage,
        (string) ($plannedImage['role'] ?? ''),
        [(string) ($plannedImage['section_id'] ?? '')]
    );

    /* Phase 1: Collect candidates that pass deterministic filters, scored by token overlap. */
    $scoredCandidates = [];
    foreach ($results as $result) {
        if (!is_array($result) || (string) ($result['provider_id'] ?? '') !== $providerId) {
            continue;
        }
        $priorVisionAssessment = is_array($result['_prior_vision_assessment'] ?? null)
            ? $result['_prior_vision_assessment']
            : null;
        $candidate = validate_source_image_candidate($result);
        if (!source_image_candidate_is_suitable_for_role($candidate, $plannedImage)) {
            throw new InvalidArgumentException(
                ($plannedImage['role'] ?? '') === 'hero'
                    ? 'Wybrany obraz nie spełnia wymagań atrakcyjnej grafiki głównej.'
                    : 'Wybrany obraz nie pasuje do roli ilustracji.'
            );
        }

        /*
         * Token overlap ranks hard-eligible candidates only. Sparse or
         * multilingual metadata must not discard a legal candidate before the
         * multimodal semantic assessment sees it.
         */
        $semanticScore = article_image_semantic_gate_score($candidate, $plannedImage);
        $scoredCandidates[] = [$semanticScore, $candidate, $priorVisionAssessment];
    }

    if ($scoredCandidates === []) {
        throw new InvalidArgumentException('Wybrany obraz nie występuje w rzeczywistych wynikach źródła.');
    }

    /* Sort by preselection score descending — best overlap first. */
    usort($scoredCandidates, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

    /* Phase 2: Multimodal assessment — the final publication gate. */
    foreach ($scoredCandidates as [$score, $candidate, $priorVisionAssessment]) {
        $assessmentPlan = $allowContextualRecovery
            ? [...$plannedImage, 'relationship_policy'=>'ww_contextual_v1']
            : $plannedImage;
        $assessmentCallback = $priorVisionAssessment === null
            ? $geminiVisionCallback
            : static fn (array $_candidate, array $_planned, string $_context): array => $priorVisionAssessment;
        $assessment = article_image_multimodal_assess(
            $candidate,
            $assessmentPlan,
            $articleContext,
            $assessmentCallback
        );

        if (article_image_assessment_allows_planned_slot($assessment, $assessmentPlan)) {
            $manifest = $candidate['rights_manifest'];
            $manifest['topic_role'] = (string) $plannedImage['role'];
            $manifest = validate_image_rights_manifest($manifest);
            $caption = trim((string) ($assessment['suggested_caption'] ?? ''));
            return array_merge($plannedImage, [
                'source_page_url' => $candidate['source_page_url'],
                'source_file_url' => $candidate['source_file_url'],
                'author' => $candidate['author'],
                'license' => $candidate['license'],
                'license_url' => $candidate['license_url'],
                'attribution' => $candidate['attribution'],
                'status' => $candidate['status'],
                'provider' => $candidate['provider'],
                'provider_id' => $candidate['provider_id'],
                'width' => (int) $candidate['width'],
                'height' => (int) $candidate['height'],
                'rights_manifest' => $manifest,
                'multimodal_assessment' => $assessment,
                'multimodal_accepted' => 1,
                'caption' => $caption !== '' ? $caption : (string) ($plannedImage['caption'] ?? ''),
                'alt' => $caption !== '' ? $caption : (string) ($plannedImage['alt'] ?? ''),
            ]);
        }
    }

    /* All candidates rejected by multimodal assessment. */
    throw new ArticleImageVisionRejectedException($assessment ?? []);
}

function source_image_candidate_is_legacy_role_heuristic(array $candidate, array $plannedImage): bool
{
    $sourcePath = mb_strtolower(rawurldecode(
        (string) parse_url((string) ($candidate['source_page_url'] ?? ''), PHP_URL_PATH)
    ));
    if (str_ends_with($sourcePath, '.pdf')) {
        return false;
    }
    if ((string) ($plannedImage['role'] ?? '') !== 'hero') {
        return true;
    }
    $width = max(1, (int) ($candidate['width'] ?? 0));
    $height = max(1, (int) ($candidate['height'] ?? 0));
    if (($width / $height) < 1.35) {
        return false;
    }
    $title = mb_strtolower(
        (string) ($candidate['title'] ?? '') . ' '
        . rawurldecode((string) ($candidate['source_page_url'] ?? '')),
        'UTF-8'
    );
    if (preg_match(
        '/\b(?:diagram|schematic|infographic|flow-?chart|graph|chart|plot|components|presentation|slide|poster|screenshot|equation)\b/u',
        $title
    ) === 1) {
        return false;
    }

    return true;
}

/**
 * Hard pre-Vision eligibility.  Semantic and aesthetic choices deliberately do
 * not appear here: Vision decides whether a portrait, diagram or title fits a
 * concrete slot after the legal/technical gate has passed.
 */
function source_image_candidate_hard_reject_reason(array $candidate, array $plannedImage): ?string
{
    $source = mb_strtolower(rawurldecode(implode(' ', [
        (string) ($candidate['source_page_url'] ?? ''),
        (string) ($candidate['source_file_url'] ?? ''),
        (string) ($candidate['format'] ?? ''),
        (string) ($candidate['mime'] ?? ''),
    ])), 'UTF-8');
    if (preg_match('/(?:\\.(?:pdf|djvu)(?:$|[?#\\s])|application\\/(?:pdf|postscript)|\\b(?:pdf|document|djvu)\\b)/u', $source) === 1
        || !empty($candidate['is_page_scan'])) return 'document_or_page_scan';
    if (preg_match('/(?:\\.svg(?:$|[?#\\s])|image\\/svg\\+xml)/u', $source) === 1) return 'unsupported_format';
    if (!empty($candidate['is_logo']) || !empty($candidate['is_icon'])) return 'logo_or_icon';
    if (!empty($candidate['is_placeholder'])) return 'placeholder';
    $title = mb_strtolower((string) ($candidate['title'] ?? ''), 'UTF-8');
    if (preg_match('/\\b(?:logo|icon)\\b/u', $title) === 1) return 'logo_or_icon';
    if (preg_match('/\\bplaceholder\\b/u', $title) === 1) return 'placeholder';
    if (preg_match('/\\b(?:page[ -]?(?:scan|scanned)|scanned[ -]?page)\\b/u', $title) === 1) return 'document_or_page_scan';
    $width = (int) ($candidate['width'] ?? 0);
    $height = (int) ($candidate['height'] ?? 0);
    $minWidth = function_exists('app_config') ? (int) app_config('source_image_min_width') : 800;
    $minHeight = function_exists('app_config') ? (int) app_config('source_image_min_height') : 450;
    $maxBytes = function_exists('app_config') ? (int) app_config('source_image_max_bytes') : 12582912;
    if (($width > 0 && $width < $minWidth) || ($height > 0 && $height < $minHeight)) return 'too_small';
    $bytes = (int) ($candidate['bytes'] ?? $candidate['file_size'] ?? 0);
    if ($bytes > 0 && $bytes > $maxBytes) {
        $transformable = preg_match('/(?:\\.(?:jpe?g|png|webp|tiff?)(?:$|[?#\\s])|image\\/(?:jpeg|png|webp|tiff?))/u', $source) === 1;
        if (!$transformable) return 'oversized';
    }
    return null;
}

function source_image_candidate_is_suitable_for_role(array $candidate, array $plannedImage): bool
{
    return source_image_candidate_hard_reject_reason($candidate, $plannedImage) === null;
}

function source_image_candidate_matches_query(array $candidate, string $query, int $minimumDistinctiveMatches = 1): bool
{
    $stopWords = [
        'the', 'a', 'an', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by',
        'from', 'and', 'or', 'is', 'it', 'as', 'that', 'this', 'image', 'photo',
        'picture', 'file', 'view', 'illustration', 'commons', 'wikimedia',
        'wikipedia', 'over', 'under', 'into', 'near', 'above', 'below',
    ];
    $queryTokens = article_image_semantic_gate_tokenize($query, $stopWords);
    if ($queryTokens === []) {
        return false;
    }
    $candidateText = (string) ($candidate['title'] ?? '') . ' '
        . rawurldecode((string) ($candidate['source_page_url'] ?? ''));
    $candidateTokens = article_image_semantic_gate_tokenize($candidateText, $stopWords);

    $matches = [];
    foreach ($queryTokens as $qt) {
        foreach ($candidateTokens as $ct) {
            if (article_image_semantic_tokens_match($qt, $ct)) {
                $matches[] = $qt;
                break;
            }
        }
    }

    $generic = ['feedback', 'climate', 'change', 'mechanism', 'model', 'dynamic'];
    $distinctiveMatches = array_values(array_filter(
        $matches,
        static fn (string $match): bool => !in_array($match, $generic, true)
    ));

    return count($distinctiveMatches) >= max(1, $minimumDistinctiveMatches);
}

function article_image_ip_is_public(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function article_image_resolve_public_ips(string $host, ?callable $resolver = null): array
{
    $ips = $resolver !== null
        ? (array) $resolver($host)
        : array_values(array_unique(array_filter([
            gethostbyname($host) !== $host ? gethostbyname($host) : null,
            ...array_map(
                static fn (array $record): string => (string) ($record['ipv6'] ?? $record['ip'] ?? ''),
                function_exists('dns_get_record') ? (dns_get_record($host, DNS_A | DNS_AAAA) ?: []) : []
            ),
        ])));
    if ($ips === []) {
        throw new InvalidArgumentException('Nie można rozwiązać hosta obrazu źródłowego.');
    }
    foreach ($ips as $ip) {
        if (!is_string($ip) || !article_image_ip_is_public($ip)) {
            throw new InvalidArgumentException('Adres obrazu wskazuje sieć lokalną, prywatną lub zastrzeżoną.');
        }
    }

    return $ips;
}

function validate_remote_image_url(string $url, ?callable $resolver = null): void
{
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null
        || parse_url($url, PHP_URL_PASS) !== null) {
        throw new InvalidArgumentException('Obraz źródłowy musi używać bezpiecznego adresu HTTPS.');
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
        throw new InvalidArgumentException('Adres lokalny obrazu jest niedozwolony.');
    }
    article_image_resolve_public_ips($host, $resolver);
}

function source_image_curl_once(string $url, ?int $maxBytes = null): array
{
    validate_remote_image_url($url);
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $ips = article_image_resolve_public_ips($host);
    $pinnedIp = str_contains((string) $ips[0], ':') ? '[' . $ips[0] . ']' : (string) $ips[0];
    $body = '';
    $headers = [];
    $tooLarge = false;
    $maxBytes = $maxBytes ?? (int) app_config('source_image_max_bytes');
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić downloadera obrazu.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => (int) app_config('source_image_timeout_seconds'),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $pinnedIp],
        CURLOPT_USERAGENT => 'MamonaSourceImageFetcher/1.0',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $mime = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    $error = curl_error($curl);
    curl_close($curl);
    if ($tooLarge) {
        throw new InvalidArgumentException('Obraz źródłowy przekracza maksymalny rozmiar.');
    }
    if ($success === false) {
        throw new RuntimeException($error !== '' ? $error : 'Błąd pobierania obrazu.');
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'mime' => $mime];
}

function download_source_image(
    array $selectedImage,
    ?callable $transport = null,
    ?callable $resolver = null,
    ?string $directory = null
): array {
    if (($selectedImage['status'] ?? '') !== 'selected') {
        throw new InvalidArgumentException('Automatycznie można pobrać wyłącznie obraz z zaakceptowaną licencją.');
    }
    $manifest = image_rights_manifest_from_record($selectedImage);
    if ($manifest === null) throw new InvalidArgumentException('Obraz nie ma kompletnego, jednoznacznego manifestu praw.');
    $transport ??= 'source_image_curl_once';
    $url = (string) ($selectedImage['source_file_url'] ?? '');
    $redirects = 0;
    do {
        validate_remote_image_url($url, $resolver);
        $response = $transport($url);
        $status = (int) ($response['status'] ?? 0);
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            if ($redirects >= (int) app_config('source_image_max_redirects')) {
                throw new InvalidArgumentException('Przekroczono limit przekierowań obrazu.');
            }
            $location = trim((string) ($response['headers']['location'] ?? ''));
            if ($location === '' || !str_starts_with($location, 'https://')) {
                throw new InvalidArgumentException('Niedozwolone przekierowanie obrazu źródłowego.');
            }
            $url = $location;
            $redirects++;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Źródło obrazu zwróciło HTTP ' . $status . '.');
        }
        break;
    } while (true);

    $bytes = (string) ($response['body'] ?? '');
    if ($bytes === '' || strlen($bytes) > (int) app_config('source_image_max_bytes')) {
        throw new InvalidArgumentException('Obraz jest pusty albo przekracza maksymalny rozmiar.');
    }
    $info = @getimagesizefromstring($bytes);
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $detectedMime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
    $reportedMime = strtolower(trim(explode(';', (string) ($response['mime'] ?? ''))[0]));
    if (!isset($allowedMimes[$detectedMime]) || ($reportedMime !== '' && $reportedMime !== $detectedMime)) {
        throw new InvalidArgumentException('MIME obrazu nie zgadza się z rzeczywistą zawartością.');
    }
    $urlExtension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ($urlExtension !== '') {
        $extensionMime = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp',
        ][$urlExtension] ?? null;
        if ($extensionMime === null || $extensionMime !== $detectedMime) {
            throw new InvalidArgumentException('Rozszerzenie obrazu nie zgadza się z rzeczywistym MIME.');
        }
    }
    if ((int) $info[0] < (int) app_config('source_image_min_width')
        || (int) $info[1] < (int) app_config('source_image_min_height')) {
        throw new InvalidArgumentException('Obraz ma zbyt małą rozdzielczość.');
    }
    $watermarkReason = source_image_raster_watermark_reason($bytes, $detectedMime);
    if ($watermarkReason !== null) throw new InvalidArgumentException('watermark_rejected: ' . $watermarkReason);
    $hasTransparency = source_image_has_actual_transparency($bytes, $detectedMime);
    $hash = hash('sha256', $bytes);
    $directory ??= app_post_image_path('sources');
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu obrazów źródłowych.');
    }
    $filename = 'source-' . $hash . '.' . $allowedMimes[$detectedMime];
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new RuntimeException('Nie udało się zapisać obrazu źródłowego.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    create_article_image_variants($path);
    $relative = str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', app_project_root()) . '/')
        ? substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', app_project_root())) + 1)
        : $path;

    return array_merge($selectedImage, [
        'source_file_url' => $url,
        'local_path' => $relative,
        'status' => 'downloaded',
        'downloaded_at' => gmdate(DATE_ATOM),
        'sha256' => $hash,
        'mime' => $detectedMime,
        'width' => (int) $info[0],
        'height' => (int) $info[1],
        'has_transparency' => $hasTransparency ? 1 : 0,
        'watermark_status' => 'clear',
    ]);
}

function persist_article_image(int $postId, array $image, string $query = ''): int
{
    $statement = bueno_database()->prepare(
        'INSERT INTO article_images (
            post_id, role, section_id, visual_intent, expected_content, search_queries_json,
            source_page_url, source_file_url, local_path, author, license,
            license_url, attribution, alt, caption, layout, status,
            width, height, downloaded_at, relationship, search_audit_json, rights_manifest_json,
            has_transparency, watermark_status, is_fallback,
            multimodal_assessment_json, multimodal_accepted, acceptance_source
         ) VALUES (
            :post_id, :role, :section_id, :visual_intent, :expected_content, :search_queries_json,
            :source_page_url, :source_file_url, :local_path, :author, :license,
            :license_url, :attribution, :alt, :caption, :layout, :status,
            :width, :height, :downloaded_at, :relationship, :search_audit_json, :rights_manifest_json,
            :has_transparency, :watermark_status, :is_fallback,
            :multimodal_assessment_json, :multimodal_accepted, :acceptance_source
         )
         ON CONFLICT(post_id, role, section_id) DO UPDATE SET
            visual_intent = excluded.visual_intent,
            expected_content = excluded.expected_content,
            search_queries_json = excluded.search_queries_json,
            source_page_url = excluded.source_page_url,
            source_file_url = excluded.source_file_url,
            local_path = excluded.local_path,
            author = excluded.author,
            license = excluded.license,
            license_url = excluded.license_url,
            attribution = excluded.attribution,
            alt = excluded.alt,
            caption = excluded.caption,
            layout = excluded.layout,
            status = excluded.status,
            width = excluded.width,
            height = excluded.height,
            downloaded_at = excluded.downloaded_at,
            relationship = excluded.relationship,
            search_audit_json = excluded.search_audit_json,
            rights_manifest_json = excluded.rights_manifest_json,
            has_transparency = excluded.has_transparency,
            watermark_status = excluded.watermark_status,
            is_fallback = excluded.is_fallback,
            multimodal_assessment_json = excluded.multimodal_assessment_json,
            multimodal_accepted = excluded.multimodal_accepted,
            acceptance_source = excluded.acceptance_source,
            updated_at = CURRENT_TIMESTAMP'
    );
    $queries = (array) ($image['search_queries'] ?? []);
    if ($query !== '' && !in_array($query, $queries, true)) {
        $queries[] = $query;
    }
    $statement->execute([
        ':post_id' => $postId,
        ':role' => $image['role'],
        ':section_id' => $image['section_id'],
        ':visual_intent' => $image['visual_intent'],
        ':expected_content' => $image['expected_content'] ?? $image['visual_intent'],
        ':search_queries_json' => generation_json($queries),
        ':source_page_url' => $image['source_page_url'] ?? '',
        ':source_file_url' => $image['source_file_url'] ?? '',
        ':local_path' => $image['local_path'] ?? '',
        ':author' => $image['author'] ?? '',
        ':license' => $image['license'] ?? '',
        ':license_url' => $image['license_url'] ?? '',
        ':attribution' => $image['attribution'] ?? '',
        ':alt' => $image['alt'],
        ':caption' => $image['caption'],
        ':layout' => $image['layout'],
        ':status' => $image['status'],
        ':width' => $image['width'] ?? null,
        ':height' => $image['height'] ?? null,
        ':downloaded_at' => $image['downloaded_at'] ?? null,
        ':relationship' => in_array((string) ($image['relationship'] ?? ''), ARTICLE_IMAGE_RELATIONS, true)
            ? (string) $image['relationship'] : 'exact_subject',
        ':search_audit_json' => generation_json((array) ($image['search_audit'] ?? [])),
        ':rights_manifest_json' => generation_json((array) ($image['rights_manifest'] ?? [])),
        ':has_transparency' => !empty($image['has_transparency']) ? 1 : 0,
        ':watermark_status' => (string) ($image['watermark_status'] ?? ''),
        ':is_fallback' => !empty($image['is_fallback']) ? 1 : 0,
        ':multimodal_assessment_json' => generation_json((array) ($image['multimodal_assessment'] ?? [])),
        ':multimodal_accepted' => !empty($image['multimodal_accepted']) ? 1 : 0,
        ':acceptance_source' => (string) ($image['acceptance_source'] ?? '') === 'operator_manual'
            ? 'operator_manual' : 'automatic',
    ]);

    $idStatement = bueno_database()->prepare(
        'SELECT id FROM article_images
         WHERE post_id = :post_id AND role = :role AND section_id = :section_id'
    );
    $idStatement->execute([
        ':post_id' => $postId,
        ':role' => $image['role'],
        ':section_id' => $image['section_id'],
    ]);

    return (int) $idStatement->fetchColumn();
}

function list_article_images(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_images WHERE post_id = :post_id ORDER BY id'
    );
    $statement->execute([':post_id' => $postId]);

    return $statement->fetchAll();
}

/** Preview only the canonical FinalVisualPlan slots; stale image rows stay auditable. */
function article_image_required_records(int $postId, ?int $topicId = null): array
{
    $coverage = article_image_coverage_state($postId, $topicId);
    $required = [];
    foreach ((array) ($coverage['required_slots'] ?? []) as $slot) {
        $required[(string) ($slot['role'] ?? '') . ':' . (string) ($slot['section_anchor'] ?? '')] = true;
    }
    $images = list_article_images($postId);
    if ($required === []) return $images;
    return array_values(array_filter($images, static fn (array $image): bool => isset(
        $required[(string) ($image['role'] ?? '') . ':' . (string) ($image['section_id'] ?? '')]
    )));
}

/** Resolve a retained candidate snapshot, or the existing local provider cache for older audits. */
function article_image_review_candidate_from_audit(array $audit): ?array
{
    $response = json_decode((string) ($audit['provider_response_json'] ?? '{}'), true);
    $candidate = is_array($response) ? (array) ($response['_candidate'] ?? []) : [];
    if ($candidate === []) {
        $statement = bueno_database()->query('SELECT response_json FROM image_provider_cache ORDER BY updated_at DESC LIMIT 100');
        $wanted = [(string) ($audit['candidate_identifier'] ?? ''), (string) ($audit['source_page_identifier'] ?? ''), (string) ($audit['source_file_identifier'] ?? '')];
        $find = static function (mixed $value) use (&$find, $wanted): ?array {
            if (!is_array($value)) return null;
            $identity = [(string) ($value['provider_id'] ?? ''), (string) ($value['source_page_url'] ?? ''), (string) ($value['source_file_url'] ?? '')];
            if (array_filter($identity) !== [] && array_intersect($wanted, $identity) !== []) return $value;
            foreach ($value as $child) {
                $found = $find($child);
                if ($found !== null) return $found;
            }
            return null;
        };
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $cached) {
            $candidate = $find(json_decode((string) $cached, true));
            if ($candidate !== null) break;
        }
    }
    if (!is_array($candidate) || $candidate === []) return null;
    try {
        return validate_source_image_candidate($candidate);
    } catch (InvalidArgumentException) {
        return null;
    }
}

/** Existing Vision rejects which remain source- and rights-verifiable for operator review. */
function article_image_rejected_review_candidates(int $postId, ?int $topicId = null): array
{
    $coverage = article_image_coverage_state($postId, $topicId, false);
    $missing = array_fill_keys(array_column((array) ($coverage['missing_slots'] ?? []), 'slot_id'), true);
    $effective = article_image_effective_visual_plan($postId, $topicId);
    $slots = [];
    foreach ([($effective['hero_slot'] ?? null), ...(array) ($effective['inline_slots'] ?? [])] as $slot) {
        if (is_array($slot) && !empty($slot['required']) && trim((string) ($slot['slot_id'] ?? '')) !== '') {
            $slots[(string) $slot['slot_id']] = $slot;
        }
    }
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_image_vision_audit WHERE post_id=:post AND status="completed" ORDER BY id DESC'
    );
    $statement->execute([':post' => $postId]);
    $items = [];
    $hardRejected = 0;
    foreach ($statement->fetchAll() as $audit) {
        $assessment = json_decode((string) ($audit['provider_response_text'] ?? ''), true);
        $slotId = (string) ($audit['slot_identifier'] ?? '');
        if (!is_array($assessment) || (string) ($assessment['decision'] ?? '') !== 'reject' || !isset($slots[$slotId])) continue;
        $candidate = article_image_review_candidate_from_audit($audit);
        if ($candidate === null || !article_image_license_is_auto_safe((string) ($candidate['license'] ?? ''))) {
            $hardRejected++;
            continue;
        }
        $relationship = (string) ($assessment['relationship_level'] ?? 'unrelated');
        $manualEligible = isset($missing[$slotId])
            && in_array($relationship, ['direct', 'broader_direct'], true)
            && empty($assessment['misleading'])
            && empty($assessment['inappropriate']);
        $items[] = ['audit' => $audit, 'assessment' => $assessment, 'candidate' => $candidate,
            'slot' => $slots[$slotId], 'manual_eligible' => $manualEligible];
    }
    return ['items' => $items, 'reviewable_count' => count(array_filter($items,
        static fn (array $item): bool => !empty($item['manual_eligible']))), 'hard_rejected_count' => $hardRejected];
}

/** Operator-only acceptance of a legal, technically valid, directly related Vision reject. */
function article_image_manual_accept_rejected_candidate(
    int $postId,
    int $auditId,
    ?callable $transport = null,
    ?callable $resolver = null,
    ?string $directory = null,
    ?callable $downloader = null
): int {
    $review = article_image_rejected_review_candidates($postId);
    $item = null;
    foreach ($review['items'] as $candidate) {
        if ((int) ($candidate['audit']['id'] ?? 0) === $auditId) { $item = $candidate; break; }
    }
    if (!is_array($item) || empty($item['manual_eligible'])) {
        throw new RuntimeException('Ten kandydat nie kwalifikuje się do ręcznej akceptacji. Blokady prawne, techniczne i mylące obrazy pozostają bez obejścia.');
    }
    $slot = (array) $item['slot'];
    $assessment = (array) $item['assessment'];
    $assessment['acceptance_source'] = 'operator_manual';
    $assessment['vision_rejected_before_manual_accept'] = true;
    $level = (string) ($assessment['relationship_level'] ?? 'direct');
    $selected = array_merge((array) $item['candidate'], [
        'role' => (string) ($slot['role'] ?? 'inline'),
        'section_id' => (string) ($slot['section_anchor'] ?? 'article'),
        'visual_intent' => (string) ($slot['visual_need'] ?? $slot['expected_content'] ?? 'Ilustracja artykułu'),
        'expected_content' => (string) ($slot['expected_content'] ?? $slot['visual_need'] ?? 'Ilustracja artykułu'),
        'search_queries' => (array) ($slot['search_queries_direct'] ?? $slot['search_queries'] ?? []),
        'layout' => (string) ($slot['layout'] ?? 'full'),
        'alt' => trim((string) ($assessment['suggested_caption'] ?? '')) ?: (string) ($item['candidate']['title'] ?? 'Ilustracja źródłowa'),
        'caption' => trim((string) ($assessment['suggested_caption'] ?? '')) ?: (string) ($item['candidate']['title'] ?? 'Ilustracja źródłowa'),
        'relationship' => 'exact_subject',
        'status' => 'selected',
    ]);
    $downloader ??= static fn (array $image): array => download_source_image($image, $transport, $resolver, $directory);
    $downloaded = $downloader($selected);
    $downloaded['multimodal_assessment'] = $assessment;
    $downloaded['multimodal_accepted'] = 1;
    $downloaded['acceptance_source'] = 'operator_manual';
    $downloaded['search_audit'] = [[
        'result' => 'selected', 'decision' => 'operator_manual_vision_reject',
        'level' => $level === 'broader_direct' ? 'broader_direct' : 'exact_direct',
        'audit_id' => $auditId,
    ]];
    $imageId = persist_article_image($postId, $downloaded);
    refresh_article_image_rendering($postId);
    return $imageId;
}

const ARTICLE_RELATED_CONTEXT_TYPES = ['sidebar', 'context', 'explainer', 'comparison', 'why_it_matters', 'related_background'];

final class ArticleRecoveryPreflightException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($reasonCode . ': ' . $message);
    }
}

function article_recovery_preflight_fail(string $reasonCode, string $message): never
{
    throw new ArticleRecoveryPreflightException($reasonCode, $message);
}

/** Deterministic P07 guard: a related image requires additive, source-backed context. */
function validate_article_related_context_block(array $block, array $verifiedSources): void
{
    foreach (['module_id', 'target_slot_id', 'placement_after_section', 'type', 'heading', 'body'] as $field) {
        if (trim((string) ($block[$field] ?? '')) === '') throw new InvalidArgumentException('Related context block nie ma pola: ' . $field);
    }
    if (!in_array((string) $block['type'], ARTICLE_RELATED_CONTEXT_TYPES, true)) {
        throw new InvalidArgumentException('Related context block ma niedozwolony typ.');
    }
    $claims = array_values(array_unique(array_filter(array_map('strval', (array) ($block['source_claim_ids'] ?? [])))));
    if ($claims === []) throw new InvalidArgumentException('Related context block wymaga source_claim_ids.');
    $available = [];
    foreach ($verifiedSources as $source) {
        foreach ((array) ($source['claim_ids'] ?? []) as $claimId) $available[(string) $claimId] = true;
    }
    foreach ($claims as $claimId) {
        if (!isset($available[$claimId])) throw new InvalidArgumentException('Related context block wskazuje niezweryfikowany claim źródłowy.');
    }
}

function article_additive_module_schema(): array
{
    $string = ['type'=>'string'];
    return ['type'=>'object','properties'=>[
        'module_id'=>$string,'target_slot_id'=>$string,'placement_after_section'=>$string,
        'type'=>['type'=>'string','enum'=>ARTICLE_RELATED_CONTEXT_TYPES],
        'heading'=>$string,'body'=>$string,'caption'=>$string,'reader_attention_note'=>$string,
        'source_claim_ids'=>['type'=>'array','items'=>$string,'minItems'=>1],
    ],'required'=>['module_id','target_slot_id','placement_after_section','type','heading','body','caption','reader_attention_note','source_claim_ids'],'additionalProperties'=>false];
}

/** Convert the immutable approved-research map into the P07 source allowlist. */
function article_image_additive_sources_from_approved_map(array $sourceMap): array
{
    if ($sourceMap === []) throw new RuntimeException('Additive module wymaga source mapy zatwierdzonego researchu.');
    $sources = [];
    foreach ($sourceMap as $source) {
        if (!is_array($source) || trim((string) ($source['source_id'] ?? '')) === '') {
            throw new RuntimeException('Additive module otrzymał niepoprawny source map zatwierdzonego researchu.');
        }
        $sources[] = [
            'id' => (string) $source['source_id'],
            'title' => (string) ($source['title'] ?? ''),
            'excerpt' => (string) ($source['excerpt'] ?? ''),
            'url' => (string) ($source['url'] ?? ''),
            'claim_ids' => array_values((array) ($source['claim_ids'] ?? [])),
            'claim_trace' => (array) ($source['claim_trace'] ?? []),
        ];
    }
    return $sources;
}

/** Create one bounded Gemini operation for an additive module; it receives no mutable core body. */
function prepare_article_additive_module_operation(int $postId, int $topicId, int $imageId, string $slotId, string $moduleId, string $placement, string $recoveryPolicy = ''): int
{
    $image = bueno_database()->prepare('SELECT * FROM article_images WHERE id=:id AND post_id=:post');
    $image->execute([':id'=>$imageId, ':post'=>$postId]);
    $image = $image->fetch();
    if (!is_array($image) || (string) ($image['relationship'] ?? '') === 'exact_subject') throw new RuntimeException('Additive module wymaga grafiki pokrewnej.');
    $sources = article_image_additive_sources_from_approved_map(
        article_image_approved_research_source_map($postId, $topicId)
    );
    $draftStatement = bueno_database()->prepare('SELECT id FROM article_draft_versions WHERE post_id=:post ORDER BY is_active DESC,id DESC LIMIT 1');
    $draftStatement->execute([':post'=>$postId]);
    $draftId = (int) $draftStatement->fetchColumn();
    $lock = $draftId > 0 ? core_text_lock_state($draftId) : [];
    $plan = find_narrative_plan_for_post($postId, $topicId);
    if (!is_array($plan) || empty($lock['core_text_locked'])) {
        article_recovery_preflight_fail('recovery_preflight_failed', 'Additive recovery requires a locked core and persisted NarrativePlan.');
    }
    return prepare_generation_operation('additive_module', [
        'post_id'=>$postId,'topic_id'=>$topicId,'target_image_id'=>$imageId,'target_slot_id'=>$slotId,
        'module_id'=>$moduleId,'placement_after_section'=>$placement,
        'recovery_policy'=>$recoveryPolicy,
        'draft_version_id'=>$draftId,'locked_core_hash'=>(string) ($lock['core_hash'] ?? ''),
        'narrative_plan_id'=>(int) ($plan['id'] ?? 0),
        'image'=>['source_page_url'=>(string)$image['source_page_url'],'relationship'=>(string)$image['relationship'],'caption'=>(string)$image['caption']],
        'verified_sources'=>$sources,
        'instruction'=>'Write only an additive context module. Do not rewrite, quote, or replace core article text.',
    ], article_additive_module_schema(), $postId, $topicId);
}

function complete_article_additive_module_operation(int $operationId): int
{
    $operation = find_generation_operation($operationId);
    if (!is_array($operation) || (string)$operation['operation_type'] !== 'additive_module' || (string)$operation['status'] !== 'completed') throw new RuntimeException('Brak ukończonej operacji additive_module.');
    $input = json_decode((string)$operation['input_json'], true) ?: [];
    $output = json_decode((string)$operation['output_json'], true) ?: [];
    foreach (['module_id','target_slot_id','placement_after_section'] as $field) if ((string)($input[$field] ?? '') !== (string)($output[$field] ?? '')) throw new RuntimeException('Additive module zmienił immutable recovery target.');
    return persist_article_related_context_block((int)$operation['post_id'], (int)$input['target_image_id'], $output, (array)($input['verified_sources'] ?? []));
}

/** Persist an approved additive module without replacing the locked article body. */
function persist_article_related_context_block(int $postId, int $imageId, array $block, array $verifiedSources): int
{
    validate_article_related_context_block($block, $verifiedSources);
    $draftStatement = bueno_database()->prepare('SELECT id FROM article_draft_versions WHERE post_id=:post ORDER BY is_active DESC,id DESC LIMIT 1');
    $draftStatement->execute([':post'=>$postId]);
    $draftId = (int) $draftStatement->fetchColumn();
    if (!function_exists('core_text_lock_state') || $draftId <= 0 || empty(core_text_lock_state($draftId)['core_text_locked'])) {
        throw new RuntimeException('Related context block wymaga locked core text.');
    }
    $imageStatement = bueno_database()->prepare('SELECT * FROM article_images WHERE id=:id AND post_id=:post');
    $imageStatement->execute([':id'=>$imageId, ':post'=>$postId]);
    $image = $imageStatement->fetch();
    if (!is_array($image) || (string) ($image['relationship'] ?? '') === 'exact_subject' || (string) ($image['status'] ?? '') !== 'downloaded'
        || (int) ($image['multimodal_accepted'] ?? 0) !== 1) {
        throw new RuntimeException('Related context block wymaga pobranej grafiki pokrewnej.');
    }
    $statement = bueno_database()->prepare('INSERT INTO article_related_context_blocks
        (post_id,image_id,slot_id,module_id,placement_after_section,block_type,heading,body,caption,reader_attention_note,source_claim_ids_json,status)
        VALUES (:post,:image,:slot,:module,:placement,:type,:heading,:body,:caption,:note,:claims,"approved")
        ON CONFLICT(post_id,image_id,slot_id) DO UPDATE SET module_id=excluded.module_id,placement_after_section=excluded.placement_after_section,block_type=excluded.block_type,heading=excluded.heading,body=excluded.body,caption=excluded.caption,reader_attention_note=excluded.reader_attention_note,source_claim_ids_json=excluded.source_claim_ids_json,status="approved",updated_at=CURRENT_TIMESTAMP');
    $statement->execute([':post'=>$postId, ':image'=>$imageId, ':slot'=>(string)$block['target_slot_id'], ':module'=>(string)$block['module_id'], ':placement'=>(string)$block['placement_after_section'], ':type'=>(string)$block['type'], ':heading'=>(string)$block['heading'], ':body'=>(string)$block['body'], ':caption'=>(string)($block['caption'] ?? ''), ':note'=>(string)($block['reader_attention_note'] ?? ''), ':claims'=>generation_json(array_values($block['source_claim_ids']))]);
    $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true) ?: [];
    $assessment['related_supported'] = true;
    $assessment['related_context_block'] = ['slot_id'=>(string)$block['target_slot_id'], 'module_id'=>(string)$block['module_id']];
    bueno_database()->prepare('UPDATE article_images SET multimodal_assessment_json=:assessment,multimodal_accepted=1,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':assessment'=>generation_json($assessment), ':id'=>$imageId]);
    $id = bueno_database()->prepare('SELECT id FROM article_related_context_blocks WHERE post_id=:post AND image_id=:image AND slot_id=:slot');
    $id->execute([':post'=>$postId, ':image'=>$imageId, ':slot'=>(string)$block['target_slot_id']]);
    return (int) $id->fetchColumn();
}

function refresh_article_image_rendering(int $postId): void
{
    $post = find_post($postId);
    if ($post === null) {
        throw new RuntimeException('Nie znaleziono posta do odświeżenia obrazów.');
    }
    $images = article_image_required_records($postId);
    $blocks = json_decode((string) ($post['content_blocks'] ?? '[]'), true);
    $blocks = is_array($blocks) ? $blocks : [];
    $layoutAudit = [];
    $layoutPlan = article_layout_plan_for_post($postId, $layoutAudit);
    $content = $blocks !== []
        ? render_article_blocks_with_layout($blocks, $images, $layoutPlan, article_related_context_blocks_for_post($postId), $layoutAudit)
        : (string) $post['content'];
    $heroPath = '';
    $heroAlt = (string) ($post['image_alt'] ?? '');
    foreach ($images as $image) {
        if ((string) $image['role'] === 'hero' && (string) $image['status'] === 'downloaded') {
            $heroPath = (string) $image['local_path'];
            $heroAlt = (string) $image['alt'];
            break;
        }
    }
    bueno_database()->prepare(
        'UPDATE posts
         SET content = :content, image_path = :image_path, image_alt = :image_alt,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute([
        ':id' => $postId,
        ':content' => $content,
        ':image_path' => $heroPath,
        ':image_alt' => $heroAlt,
    ]);
}

function reject_article_source_image(int $imageId): int
{
    $statement = bueno_database()->prepare('SELECT * FROM article_images WHERE id = :id');
    $statement->execute([':id' => $imageId]);
    $image = $statement->fetch();
    if (!is_array($image)) {
        throw new RuntimeException('Nie znaleziono obrazu źródłowego.');
    }
    $postId = (int) $image['post_id'];
    $localPath = trim((string) $image['local_path']);
    bueno_database()->prepare(
        'UPDATE article_images
         SET source_page_url = "", source_file_url = "", local_path = "",
             author = "", license = "", license_url = "", attribution = "", rights_manifest_json = "{}",
             status = "missing", width = NULL, height = NULL, downloaded_at = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute([':id' => $imageId]);
    refresh_article_image_rendering($postId);

    if ($localPath !== '') {
        $references = bueno_database()->prepare(
            'SELECT COUNT(*) FROM article_images
             WHERE id != :id AND local_path = :path AND status = "downloaded"'
        );
        $references->execute([':id' => $imageId, ':path' => $localPath]);
        $absolute = realpath(app_path($localPath));
        $allowedRoot = realpath(app_post_image_path('sources'));
        if ((int) $references->fetchColumn() === 0
            && is_string($absolute)
            && is_string($allowedRoot)
            && str_starts_with($absolute, $allowedRoot . DIRECTORY_SEPARATOR)
            && is_file($absolute)) {
            unlink($absolute);
        }
    }

    return $postId;
}

function article_image_provider_search_with_retry(
    string $provider,
    string $query,
    callable $providerSearch,
    array &$rateLimitState,
    ?callable $sleeper = null
): array {
    $sleeper ??= static fn (int $milliseconds): mixed => usleep(max(0, $milliseconds) * 1000);
    if ($provider === 'wikimedia' && !empty($rateLimitState['wikimedia_throttled'])) {
        throw new SourceImageProviderRateLimitException(0);
    }
    $maximumAttempts = $provider === 'wikimedia' ? 3 : 1;
    for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
        try {
            $results = (array) $providerSearch($provider, $query);
            if ($provider === 'wikimedia') $rateLimitState['wikimedia_consecutive_429'] = 0;
            return $results;
        } catch (SourceImageProviderRateLimitException $exception) {
            if ($provider !== 'wikimedia') throw $exception;
            $rateLimitState['wikimedia_consecutive_429'] = (int) ($rateLimitState['wikimedia_consecutive_429'] ?? 0) + 1;
            if ((int) $rateLimitState['wikimedia_consecutive_429'] >= 3) {
                $rateLimitState['wikimedia_throttled'] = true;
                throw new SourceImageProviderRateLimitException($exception->retryAfterSeconds);
            }
            if ($attempt >= $maximumAttempts) throw $exception;
            $milliseconds = $exception->retryAfterSeconds > 0
                ? $exception->retryAfterSeconds * 1000
                : 200 * $attempt;
            $sleeper(min(5000, $milliseconds));
        }
    }
    return [];
}

/** Deferred Vision or retriable source failures are not evidence that a slot is exhausted. */
function article_image_search_audit_has_pending_recovery(array $audit): bool
{
    $lastTerminal = -1;
    foreach ($audit as $index => $entry) {
        if (!is_array($entry)) continue;
        $result = (string) ($entry['result'] ?? '');
        $reason = mb_strtolower((string) ($entry['reason'] ?? ''));
        if ($result === 'exhausted' || ($result === 'missing' && str_contains($reason, 'all_legal_candidates_exhausted'))) {
            $lastTerminal = $index;
        }
    }
    foreach (array_slice($audit, $lastTerminal + 1) as $entry) {
        if (!is_array($entry)) continue;
        $result = (string) ($entry['result'] ?? '');
        $reason = mb_strtolower((string) ($entry['reason'] ?? ''));
        if ($result === 'deferred' && in_array($reason, [
            'vision_shortlist_limit_per_missing_slot',
            'vision_budget_reserved_for_p06_p07_p08_p09',
            'local_candidate_check_limit_per_missing_slot',
        ], true)) return true;
        if ($result === 'search_error' && (str_contains($reason, 'provider_rate_limited')
            || str_contains($reason, 'http 429') || str_contains($reason, 'timeout'))) return true;
        if ($result === 'rejected' && !empty($entry['local_reject'])
            && (str_contains($reason, 'http 429') || str_contains($reason, 'provider_rate_limited')
                || str_contains($reason, 'timeout'))) return true;
    }
    return false;
}

/** Keep retryable transports available, but do not spend another shortlist slot on known-bad bytes. */
function article_image_search_audit_local_hard_rejected_urls(array $audit): array
{
    $urls = [];
    foreach ($audit as $entry) {
        if (!is_array($entry) || empty($entry['local_reject'])) continue;
        $url = trim((string) ($entry['url'] ?? ''));
        $reason = mb_strtolower((string) ($entry['final_local_reject_reason'] ?? $entry['reason'] ?? ''));
        if ($url === '' || str_contains($reason, 'download_failure')
            || str_contains($reason, 'http 429') || str_contains($reason, 'provider_rate_limited')
            || str_contains($reason, 'timeout')) continue;
        if (str_contains($reason, 'too_small') || str_contains($reason, 'other_hard_technical')
            || str_contains($reason, 'rights_or_license') || str_contains($reason, 'mime')) {
            $urls[$url] = true;
        }
    }
    return $urls;
}

function article_image_has_pending_recovery(int $postId): bool
{
    $statement = bueno_database()->prepare('SELECT search_audit_json FROM article_images WHERE post_id=:post AND status IN ("missing","planned","manual_review")');
    $statement->execute([':post'=>$postId]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $auditJson) {
        $audit = json_decode((string) $auditJson, true);
        if (is_array($audit) && article_image_search_audit_has_pending_recovery($audit)) return true;
    }
    return false;
}

/** Provider search is unmetered; GeminiBudget starts only at Vision/planner calls. */
function article_image_default_searcher(?callable $providerSearch = null, ?callable $sleeper = null): callable
{
    $providerSearch ??= static fn (string $provider, string $query): array => search_source_images($query, $provider);
    $rateLimitState = ['wikimedia_consecutive_429'=>0, 'wikimedia_throttled'=>false];
    return static function (string $query) use ($providerSearch, $sleeper, &$rateLimitState): array {
        $preferred = (string) app_config('source_image_provider');
        $providers = array_values(array_unique([
            $preferred, 'smithsonian', 'europeana', 'eso', 'nasa', 'usgs', 'nci', 'wikimedia', 'openverse', 'pexels',
        ]));
        $results = [];
        $errors = [];
        foreach ($providers as $provider) {
            try {
                array_push($results, ...article_image_provider_search_with_retry(
                    $provider, $query, $providerSearch, $rateLimitState, $sleeper
                ));
            } catch (SourceImageProviderRateLimitException) {
                $errors[] = $provider . ': provider_rate_limited';
            } catch (Throwable $exception) {
                $errors[] = $provider . ': ' . $exception->getMessage();
            }
        }
        $onlyRateLimits = $errors !== [] && count(array_filter(
            $errors,
            static fn (string $error): bool => !str_ends_with($error, 'provider_rate_limited')
        )) === 0;
        if ($results === [] && $errors !== [] && !$onlyRateLimits) {
            throw new RuntimeException(implode(' | ', $errors));
        }
        return $results;
    };
}

/**
 * Preserve the calls needed to close an incomplete visual pipeline before the
 * legacy direct-search waterfall can spend them on a single missing slot.
 *
 * Per missing slot reserve one Vision validation and one possible P07 additive
 * module, plus the shared P06 planner and P08/P09 closure calls. This is
 * deliberately conservative: when the remaining budget cannot cover the
 * bounded recovery path, direct acquisition must yield to that path.
 */
function article_image_direct_vision_limit_from_budget(
    int $usedCalls,
    int $maxCalls,
    int $missingSlots,
    ?array $futureStages = null
): array
{
    $remaining = max(0, $maxCalls - $usedCalls);
    if ($futureStages === null) {
        $plannerCalls = $missingSlots > 0 ? 1 : 0;
        $replanCalls = 0;
        $relatedRecoveryCalls = $missingSlots > 0 ? $missingSlots * 2 : 0;
        $layoutCalls = $missingSlots > 0 ? 1 : 0;
        $finalQcCalls = $missingSlots > 0 ? 1 : 0;
    } else {
        $plannerCalls = !empty($futureStages['p06_pending']) ? 1 : 0;
        $replanCalls = !empty($futureStages['replan_pending']) ? 1 : 0;
        $relatedRecoveryCalls = max(0, (int) ($futureStages['p07_pending_calls'] ?? 0));
        $layoutCalls = !empty($futureStages['p08_pending']) ? 1 : 0;
        $finalQcCalls = !empty($futureStages['p09_pending']) ? 1 : 0;
    }
    $closureCalls = $layoutCalls + $finalQcCalls;
    $reserved = $plannerCalls + $replanCalls + $relatedRecoveryCalls + $closureCalls;
    return [
        'remaining_calls' => $remaining,
        'reserved_for_p06_planner' => $plannerCalls,
        'reserved_for_recovery_replan' => $replanCalls,
        'reserved_for_p07_recovery' => $relatedRecoveryCalls,
        'reserved_for_p08_p09' => $closureCalls,
        'reserved_for_closure' => $reserved,
        'direct_vision_limit' => max(0, $remaining - $reserved),
    ];
}

function article_image_operation_completed(int $postId, string $operationType): bool
{
    $statement = bueno_database()->prepare(
        'SELECT COUNT(*) FROM generation_operations
         WHERE post_id=:post AND operation_type=:type AND status="completed"'
    );
    $statement->execute([':post' => $postId, ':type' => $operationType]);
    return (int) $statement->fetchColumn() > 0;
}

/** A completed P06 already exhausted its bounded source-backed shortlist; retry the retrieval/replan path instead. */
function article_image_shortage_recovery_needed(array $coverage, bool $p06Completed): bool
{
    return (array) ($coverage['missing_slots'] ?? []) !== [] && !$p06Completed;
}

/** Count only concrete related recoveries that still target an unfilled slot. */
function article_image_pending_additive_module_calls(int $postId, array $coverage): int
{
    $missing = array_fill_keys(array_map(
        static fn (array $slot): string => (string) ($slot['slot_id'] ?? ''),
        (array) ($coverage['missing_slots'] ?? [])
    ), true);
    unset($missing['']);
    if ($missing === []) return 0;

    $statement = bueno_database()->prepare(
        'SELECT output_json FROM generation_operations
         WHERE post_id=:post AND operation_type="image_recovery" AND status="completed"
         ORDER BY completed_at DESC,id DESC LIMIT 1'
    );
    $statement->execute([':post' => $postId]);
    $output = json_decode((string) ($statement->fetchColumn() ?: '{}'), true) ?: [];
    $pending = [];
    foreach ((array) ($output['recoveries'] ?? []) as $recovery) {
        $slotId = (string) ($recovery['slot_id'] ?? '');
        $relationship = (string) (($recovery['candidate'] ?? [])['relationship'] ?? '');
        if (isset($missing[$slotId]) && !in_array($relationship, ['exact_subject', 'direct'], true)) {
            $pending[$slotId] = true;
        }
    }
    return count($pending);
}

function article_image_direct_vision_budget_plan(int $postId, ?int $topicId = null): array
{
    $coverage = article_image_coverage_state($postId, $topicId);
    $budget = gemini_article_budget_state($postId);
    $missingSlots = count((array) ($coverage['missing_slots'] ?? []));
    $p06Completed = article_image_operation_completed($postId, 'image_recovery');
    $replanCount = article_image_valid_recovery_replan_count($postId, $topicId);
    $p06Pending = article_image_shortage_recovery_needed($coverage, $p06Completed);
    return article_image_direct_vision_limit_from_budget(
        (int) ($budget['used_calls'] ?? 0),
        (int) ($budget['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT),
        $missingSlots,
        [
            'p06_pending' => $p06Pending,
            'replan_pending' => $missingSlots > 0 && $replanCount < ARTICLE_IMAGE_RECOVERY_REPLAN_MAX_ATTEMPTS,
            'p07_pending_calls' => $p06Pending ? article_image_pending_additive_module_calls($postId, $coverage) : 0,
            'p08_pending' => !article_image_operation_completed($postId, 'layout_plan'),
            'p09_pending' => !article_image_operation_completed($postId, 'final_multimodal_qc'),
        ]
    );
}

/** Deterministic coarse photographic-series key; no provider call required. */
function article_image_candidate_series_key(array $candidate): string
{
    $providerId = rawurldecode(trim((string) ($candidate['provider_id'] ?? '')));
    $title = preg_match('/(?:[-_][a-z0-9]+){2,}[-_]\d{2,}$/i', $providerId) === 1
        ? $providerId
        : rawurldecode(trim((string) ($candidate['title'] ?? '')));
    if ($title === '') $title = basename((string) parse_url((string) ($candidate['source_file_url'] ?? ''), PHP_URL_PATH));
    $stem = mb_strtolower($title, 'UTF-8');
    $stem = preg_replace('/^file\s*:\s*/u', '', $stem) ?? $stem;
    $stem = preg_replace('/\.(?:jpe?g|png|webp|tiff?)$/u', '', $stem) ?? $stem;
    $stem = preg_replace('/(?:[-_ ](?:\d{1,4}|thumb|small|medium|large|orig(?:inal)?))+$/u', '', $stem) ?? $stem;
    $stem = preg_replace('/\b\d{2,4}\s*[x×]\s*\d{2,4}\b/u', '', $stem) ?? $stem;
    $stem = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $stem) ?? $stem;
    $stem = trim(preg_replace('/\s+/u', ' ', $stem) ?? $stem);
    return $stem === '' ? '' : hash('sha256', $stem);
}

/** Reuse severe Vision rejects across batches so another image from the same
 * photographic series never consumes a fresh call in a later closure pass. */
function article_image_persisted_rejected_series(int $postId, string $slotIdentifier): array
{
    $statement = bueno_database()->prepare(
        'SELECT candidate_identifier,source_file_identifier,provider_response_text
         FROM article_image_vision_audit
         WHERE post_id=:post AND slot_identifier=:slot AND status="completed"'
    );
    $statement->execute([':post'=>$postId, ':slot'=>$slotIdentifier]);
    $series = [];
    foreach ($statement->fetchAll() as $row) {
        $assessment = json_decode((string) ($row['provider_response_text'] ?? ''), true);
        if (!is_array($assessment) || !article_image_assessment_is_severe_reject($assessment)) continue;
        $key = article_image_candidate_series_key([
            'provider_id'=>(string) ($row['candidate_identifier'] ?? ''),
            'source_file_url'=>(string) ($row['source_file_identifier'] ?? ''),
        ]);
        if ($key !== '') $series[$key] = true;
    }
    return $series;
}

function article_image_assessment_is_severe_reject(array $assessment): bool
{
    return (string) ($assessment['decision'] ?? '') === 'reject'
        && (int) ($assessment['semantic_relevance'] ?? 10) <= 3
        && (int) ($assessment['editorial_fit'] ?? 10) <= 3;
}

function article_image_fair_vision_allowances(array $slotKeys, int $callLimit, int $perSlotLimit = 3): array
{
    $slotKeys = array_values(array_unique(array_filter(array_map('strval', $slotKeys))));
    $allowances = array_fill_keys($slotKeys, 0);
    $remaining = max(0, $callLimit);
    for ($round = 0; $round < max(1, min(3, $perSlotLimit)) && $remaining > 0; $round++) {
        foreach ($slotKeys as $slotKey) {
            if ($remaining <= 0) break;
            $allowances[$slotKey]++;
            $remaining--;
        }
    }
    return $allowances;
}

function article_image_candidate_deferred_for_rejected_series(array $candidate, array $rejectedSeries): bool
{
    $key = article_image_candidate_series_key($candidate);
    return $key !== '' && isset($rejectedSeries[$key]);
}

function fulfill_article_source_images(
    int $postId,
    ?callable $searcher = null,
    ?callable $downloader = null,
    ?callable $geminiVisionCallback = null,
    string $acquisitionMode = 'direct',
    ?int $visionCallLimit = null,
    int $visionCandidateLimitPerSlot = 3
): array {
    $post = find_post($postId);
    if ($post === null) {
        throw new RuntimeException('Nie znaleziono posta do uzupełnienia obrazami.');
    }
    $searcher ??= article_image_default_searcher();
    $downloader ??= static fn (array $selected): array => download_source_image($selected);
    $articleContext = trim(implode("\n", array_filter([
        (string) ($post['title'] ?? ''),
        (string) ($post['excerpt'] ?? ''),
        mb_substr(strip_tags((string) ($post['content'] ?? '')), 0, 6000, 'UTF-8'),
    ], static fn (string $value): bool => trim($value) !== '')));
    $directBudgetPlan = article_image_direct_vision_budget_plan($postId);
    $geminiVisionCallback ??= static fn (array $candidate, array $plannedImage, string $context): array =>
        article_image_gemini_vision_assess($postId, $candidate, $plannedImage, $context, null, null, null, (int) $directBudgetPlan['reserved_for_closure']);
    $summary = [
        'downloaded' => 0,
        'manual_review' => 0,
        'missing' => 0,
        'skipped' => 0,
        'errors' => [],
        'local_candidate_checks' => 0,
        'vision_calls_attempted' => 0,
        'vision_call_limit' => $visionCallLimit,
        'vision_calls_reserved' => 0,
    ];
    $imageRows = list_article_images($postId);
    $coverage = article_image_coverage_state($postId);
    $diversityRecoveryKeys = [];
    foreach ((array) ($coverage['diversity_rejected_slots'] ?? []) as $rejectedSlot) {
        $diversityRecoveryKeys[(string) ($rejectedSlot['role'] ?? '') . ':' . (string) ($rejectedSlot['section_anchor'] ?? '')] = $rejectedSlot;
    }
    $usedUrls = [];
    foreach ($imageRows as $existing) {
        if (in_array((string) $existing['status'], ['selected', 'downloaded', 'manual_review'], true)
            && trim((string) $existing['source_file_url']) !== '') {
            $usedUrls[(string) $existing['source_file_url']] = true;
        }
    }
    $visualSlots = [];
    $narrativePlan = function_exists('find_narrative_plan_for_post') ? find_narrative_plan_for_post($postId) : null;
    $visualPlan = is_array($narrativePlan) ? article_image_effective_visual_plan($postId, null, $narrativePlan) : [];
    foreach ([($visualPlan['hero_slot'] ?? null), ...(array) ($visualPlan['inline_slots'] ?? [])] as $slot) {
        if (!is_array($slot)) continue;
        $visualSlots[(string) ($slot['role'] ?? '') . ':' . (string) ($slot['section_anchor'] ?? '')] = $slot;
    }

    $slotVisionAllowances = null;
    if ($visionCallLimit !== null) {
        $eligibleSlotKeys = [];
        foreach ($imageRows as $row) {
            $rowKey = (string) ($row['role'] ?? '') . ':' . (string) ($row['section_id'] ?? '');
            $downloadedAndPresent = (string) $row['status'] === 'downloaded'
                && trim((string) $row['local_path']) !== ''
                && is_file(app_path((string) $row['local_path']));
            if (($downloadedAndPresent && !isset($diversityRecoveryKeys[$rowKey]))
                || !in_array((string) $row['status'], ['planned','missing','manual_review','downloaded'], true)) continue;
            $eligibleSlotKeys[] = (string) $row['role'] . ':' . (string) $row['section_id'];
        }
        $eligibleSlotKeys = array_values(array_unique($eligibleSlotKeys));
        $slotVisionAllowances = article_image_fair_vision_allowances(
            $eligibleSlotKeys, $visionCallLimit, $visionCandidateLimitPerSlot
        );
    }

    foreach ($imageRows as $image) {
        $imageKey = (string) ($image['role'] ?? '') . ':' . (string) ($image['section_id'] ?? '');
        $diversityRecovery = (array) ($diversityRecoveryKeys[$imageKey] ?? []);
        if ($diversityRecovery !== []) {
            $priorAudit = json_decode((string) ($image['search_audit_json'] ?? '[]'), true);
            $priorAudit = is_array($priorAudit) ? $priorAudit : [];
            $priorAudit[] = ['query'=>'', 'level'=>'diversity_recovery', 'result'=>'missing',
                'reason'=>(string) ($diversityRecovery['status'] ?? 'semantic_duplicate'),
                'duplicate_against_image_id'=>(int) ($diversityRecovery['duplicate_against_image_id'] ?? 0)];
            $image['search_audit_json'] = generation_json($priorAudit);
            $image['status'] = 'missing';
        }
        if ((string) $image['status'] === 'downloaded'
            && trim((string) $image['local_path']) !== ''
            && is_file(app_path((string) $image['local_path']))) {
            $summary['skipped']++;
            continue;
        }
        if ((string) $image['status'] === 'downloaded') {
            unset($usedUrls[(string) $image['source_file_url']]);
            $staleAudit = json_decode((string) ($image['search_audit_json'] ?? '[]'), true);
            $staleAudit = is_array($staleAudit) ? $staleAudit : [];
            $staleAudit[] = ['query' => '', 'level' => 'recovery', 'source' => '', 'result' => 'missing', 'reason' => 'downloaded_record_without_local_file'];
            persist_article_image($postId, [
                'role' => (string) $image['role'], 'section_id' => (string) $image['section_id'],
                'visual_intent' => (string) $image['visual_intent'],
                'expected_content' => trim((string) ($image['expected_content'] ?? '')) ?: (string) $image['visual_intent'],
                'search_queries' => json_decode((string) ($image['search_queries_json'] ?? '[]'), true) ?: [],
                'source_page_url' => '', 'source_file_url' => '', 'local_path' => '',
                'author' => '', 'license' => '', 'license_url' => '', 'attribution' => '',
                'alt' => (string) $image['alt'], 'caption' => (string) $image['caption'],
                'layout' => (string) $image['layout'], 'status' => 'missing',
                'relationship' => (string) ($image['relationship'] ?? 'exact_subject'),
                'search_audit' => $staleAudit,
            ]);
            $image['status'] = 'missing';
        }
        if (!in_array((string) $image['status'], ['planned', 'missing', 'manual_review'], true)) {
            $summary['skipped']++;
            continue;
        }

        $planned = [
            'role' => (string) $image['role'],
            'section_id' => (string) $image['section_id'],
            'visual_intent' => (string) $image['visual_intent'],
            'search_queries' => json_decode((string) $image['search_queries_json'], true) ?: [],
            'expected_content' => trim((string) ($image['expected_content'] ?? ''))
                ?: (string) $image['visual_intent'],
            'source_page_url' => '',
            'source_file_url' => '',
            'local_path' => '',
            'author' => '',
            'license' => '',
            'license_url' => '',
            'attribution' => '',
            'alt' => (string) $image['alt'],
            'caption' => (string) $image['caption'],
            'layout' => (string) $image['layout'],
            'status' => 'planned',
            'article_title' => (string) ($post['title'] ?? ''),
        ];
        $visualSlot = $visualSlots[$planned['role'] . ':' . $planned['section_id']] ?? [];
        if (is_array($visualSlot)) {
            $planned['slot_id'] = (string) ($visualSlot['slot_id'] ?? '');
            $planned['topic_source'] = (string) ($visualSlot['topic_source'] ?? 'A');
            $planned['search_queries'] = array_values((array) ($visualSlot['search_queries_direct'] ?? $planned['search_queries']));
            $planned['search_queries_related'] = array_values((array) ($visualSlot['search_queries_related'] ?? []));
            $planned['acceptable_related'] = (bool) ($visualSlot['acceptable_related'] ?? false);
            $planned['query_origin'] = (string) ($visualSlot['query_origin'] ?? 'canonical_visual_plan');
            $planned['recovery_relationship_policy'] = (string) ($visualSlot['recovery_relationship_policy'] ?? '');
        }
        if ($acquisitionMode !== 'direct') {
            $planned['relationship_policy'] = 'ww_contextual_v1';
        }
        $audit = $diversityRecovery !== []
            ? (json_decode((string) ($image['search_audit_json'] ?? '[]'), true) ?: [])
            : [];
        $completed = false;
        $slotKey = $planned['role'] . ':' . $planned['section_id'];
        $visionShortlistRemaining = $slotVisionAllowances === null
            ? max(1, min(3, $visionCandidateLimitPerSlot))
            : (int) ($slotVisionAllowances[$slotKey] ?? 0);
        $visionShortlistLimit = $visionShortlistRemaining;
        $localCandidateChecks = 0;
        $localCandidateCheckLimit = max(4, min(20, $visionShortlistLimit * 3));
        $severelyRejectedSeries = article_image_persisted_rejected_series(
            $postId,
            (string) ($planned['slot_id'] ?? $planned['section_id'])
        );
        $attempts = match ($acquisitionMode) {
            'direct' => article_image_direct_queries($planned),
            'contextual' => article_image_contextual_queries($planned),
            default => article_image_semantic_queries($planned),
        };
        $priorAudit = json_decode((string) ($image['search_audit_json'] ?? '[]'), true);
        $slotUsedUrls = $usedUrls + article_image_search_audit_local_hard_rejected_urls(
            is_array($priorAudit) ? $priorAudit : []
        );
        $pool = article_image_ranked_candidate_pool($planned, $attempts, $searcher, $slotUsedUrls);
        $priorVisionLevels = $acquisitionMode === 'direct'
            ? ['direct', 'broader_direct']
            : ['direct', 'broader_direct', 'strong_related', 'contextual_related', 'domain_related'];
        if ($pool['ranked'] === [] && ($acquisitionMode === 'direct' || !empty($planned['acceptable_related']))
            && trim((string) ($planned['slot_id'] ?? '')) !== '') {
            $priorCandidates = article_image_prior_vision_accepted_candidates(
                $postId,
                (string) $planned['slot_id'],
                $priorVisionLevels
            );
            if ($priorCandidates !== []) {
                $pool = article_image_ranked_candidate_pool(
                    $planned,
                    [['query'=>'accepted vision recovery candidate','relation'=>'related_context','level'=>'vision_feedback','query_origin'=>'vision_feedback']],
                    static fn (string $_): array => $priorCandidates,
                    $slotUsedUrls
                );
            }
        }
        array_push($audit, ...(array) $pool['audit']);
        foreach ((array) $pool['errors'] as $error) $summary['errors'][] = $image['role'] . '/' . $image['section_id'] . ': wyszukiwanie: ' . $error;
        foreach ((array) $pool['ranked'] as $rankedCandidate) {
                $score = (int) $rankedCandidate['score'];
                $result = (array) $rankedCandidate['candidate'];
                $query = (string) $rankedCandidate['query'];
                $relation = (string) $rankedCandidate['relation'];
                $level = (string) $rankedCandidate['level'];
                $queryOrigin = (string) ($rankedCandidate['query_origin'] ?? 'canonical_visual_plan');
                $hasPriorVisionAssessment = is_array($result['_prior_vision_assessment'] ?? null);
                $seriesKey = article_image_candidate_series_key($result);
                if (article_image_candidate_deferred_for_rejected_series($result, $severelyRejectedSeries)) {
                    $audit[] = ['query'=>$query, 'level'=>$level, 'query_origin'=>$queryOrigin,
                        'source'=>(string) ($result['provider'] ?? ''), 'url'=>(string) ($result['source_file_url'] ?? ''),
                        'result'=>'deferred', 'reason'=>'same_series_after_severe_vision_reject',
                        'candidate_checked'=>true, 'local_reject'=>true, 'local_reject_reason'=>'same_series_after_severe_vision_reject',
                        'vision_transport_attempted'=>false, 'vision_response_received'=>false];
                    continue;
                }
                if ($localCandidateChecks >= $localCandidateCheckLimit) {
                    $audit[] = ['query' => $query, 'level' => $level, 'query_origin'=>$queryOrigin, 'source' => (string) ($result['provider'] ?? ''),
                        'url' => (string) ($result['source_file_url'] ?? ''), 'result' => 'deferred',
                        'reason' => 'local_candidate_check_limit_per_missing_slot'];
                    break;
                }
                if (!$hasPriorVisionAssessment && $visionShortlistRemaining <= 0) {
                    $audit[] = ['query' => $query, 'level' => $level, 'query_origin'=>$queryOrigin, 'source' => (string) ($result['provider'] ?? ''),
                        'url' => (string) ($result['source_file_url'] ?? ''), 'result' => 'deferred',
                        'reason' => 'vision_shortlist_limit_per_missing_slot'];
                    break;
                }
                if (!$hasPriorVisionAssessment && $visionCallLimit !== null && $summary['vision_calls_attempted'] >= $visionCallLimit) {
                    $audit[] = ['query' => $query, 'level' => $level, 'query_origin'=>$queryOrigin, 'source' => (string) ($result['provider'] ?? ''),
                        'url' => (string) ($result['source_file_url'] ?? ''), 'result' => 'deferred',
                        'reason' => 'vision_budget_reserved_for_p06_p07_p08_p09'];
                    $summary['vision_calls_reserved']++;
                    break;
                }
                $localCandidateChecks++;
                $summary['local_candidate_checks']++;
                $visionCallsBefore = (int) (gemini_article_budget_state($postId)['used_calls'] ?? 0);
                try {
                    $canonicalPlanned = article_image_canonical_payload($planned);
                    $selected = select_source_image_from_results(
                        $canonicalPlanned,
                        [$result],
                        (string) ($result['provider_id'] ?? ''),
                        $geminiVisionCallback,
                        $articleContext
                    );
                    $visionCallsAfter = (int) (gemini_article_budget_state($postId)['used_calls'] ?? $visionCallsBefore);
                    $realVisionCalls = max(0, $visionCallsAfter - $visionCallsBefore);
                    $summary['vision_calls_attempted'] += $realVisionCalls;
                    $visionShortlistRemaining = max(0, $visionShortlistRemaining - $realVisionCalls);
                    $selected = article_image_honest_copy($selected, $relation, $result);
                    article_image_assert_selected_diversity($postId, $selected);
                    $assessment = (array) ($selected['multimodal_assessment'] ?? []);
                    if ($acquisitionMode !== 'direct'
                        && in_array((string) ($assessment['relationship_level'] ?? ''), ['direct','broader_direct','strong_related','contextual_related','domain_related'], true)
                        && !empty($assessment['contextual_useful']) && !empty($assessment['honest_caption_possible'])
                        && empty($assessment['misleading']) && empty($assessment['inappropriate'])) {
                        $assessment['related_supported'] = true;
                        $assessment['contextual_policy'] = 'ww_contextual_v1';
                        if ((string) $planned['role'] === 'hero') {
                            $assessment['hero_recovery'] = [
                                'policy'=>'ww_contextual_v1','status'=>'validated','final_vision'=>$assessment,
                            ];
                        }
                        $selected['multimodal_assessment'] = $assessment;
                        $caption = trim((string) ($assessment['suggested_caption'] ?? ''));
                        if ($caption !== '') $selected['caption'] = $caption;
                    }
                    $downloaded = $downloader($selected);
                    $audit[] = ['query' => $query, 'level' => $level, 'query_origin'=>$queryOrigin, 'topic_source'=>(string) ($planned['topic_source'] ?? 'A'), 'source' => (string) ($result['provider'] ?? ''), 'provider_id' => (string) ($result['provider_id'] ?? ''), 'result' => 'selected', 'score' => $score, 'relationship' => $relation,
                        'candidate_checked'=>true, 'local_reject'=>false, 'vision_transport_attempted'=>$realVisionCalls > 0,
                        'vision_response_received'=>$realVisionCalls > 0, 'vision_candidates_attempted'=>$visionShortlistLimit-$visionShortlistRemaining];
                    $downloaded['search_audit'] = $audit;
                    persist_article_image($postId, $downloaded, $query);
                    $usedUrls[(string) $downloaded['source_file_url']] = true;
                    $summary['downloaded']++;
                    $completed = true;
                    break;
                } catch (Throwable $exception) {
                    $visionCallsAfter = (int) (gemini_article_budget_state($postId)['used_calls'] ?? $visionCallsBefore);
                    $realVisionCalls = max(0, $visionCallsAfter - $visionCallsBefore);
                    $summary['vision_calls_attempted'] += $realVisionCalls;
                    $visionShortlistRemaining = max(0, $visionShortlistRemaining - $realVisionCalls);
                    $watermarkRejected = str_starts_with($exception->getMessage(), 'watermark_rejected:');
                    $diversity = $exception instanceof ArticleImageSemanticDuplicateException ? $exception->diversity : null;
                    if ($realVisionCalls > 0 && $seriesKey !== '' && $exception instanceof ArticleImageVisionRejectedException
                        && article_image_assessment_is_severe_reject($exception->assessment)) {
                        $severelyRejectedSeries[$seriesKey] = true;
                    }
                    $audit[] = ['query' => $query, 'level' => $level, 'query_origin'=>$queryOrigin, 'source' => (string) ($result['provider'] ?? ''),
                        'url' => (string) ($result['source_file_url'] ?? ''),
                        'result' => $watermarkRejected ? 'watermark_rejected' : 'rejected',
                        'reason' => $diversity['code'] ?? $exception->getMessage(),
                        'diversity' => $diversity,
                        'candidate_checked'=>true, 'local_reject'=>$realVisionCalls === 0,
                        'local_reject_reason'=>$realVisionCalls === 0 ? $exception->getMessage() : '',
                        'original_format'=>(string) ($result['mime'] ?? $result['format'] ?? pathinfo((string) parse_url((string) ($result['source_file_url'] ?? ''), PHP_URL_PATH), PATHINFO_EXTENSION)),
                        'original_size'=>(int) ($result['bytes'] ?? $result['file_size'] ?? 0),
                        'preprocess_attempted'=>false,
                        'preprocess_type'=>'', 'preprocess_success'=>false, 'prepared_format'=>'', 'prepared_size'=>0,
                        'final_local_reject_reason'=>$realVisionCalls === 0 ? article_image_local_reject_reason($exception) : '',
                        'vision_transport_attempted'=>$realVisionCalls > 0, 'vision_response_received'=>$realVisionCalls > 0];
                    $summary['errors'][] = $image['role'] . '/' . $image['section_id']
                        . ': kandydat: ' . $exception->getMessage();
                }
        }
        if ($completed) {
            continue;
        }
        $audit[] = ['query' => '', 'level' => 'exhausted', 'source' => '', 'result' => 'missing',
            'reason' => !empty($planned['acceptable_related']) && (array) ($planned['search_queries_related'] ?? []) !== []
                ? 'direct_and_broader_exhausted; related_recovery_pending'
                : 'all_legal_candidates_exhausted; local_fallback_required',
            'local_candidate_checks'=>$localCandidateChecks,
            'vision_candidates_attempted'=>$visionShortlistLimit-$visionShortlistRemaining,
            'number_of_provider_candidates'=>(int) ($pool['number_of_provider_candidates'] ?? 0),
            'hard_reject_count'=>(int) ($pool['hard_reject_count'] ?? 0),
            'hard_reject_reasons'=>(array) ($pool['hard_reject_reasons'] ?? []),
            'ranked_candidate_count'=>(int) ($pool['ranked_candidate_count'] ?? 0)];
        persist_article_image($postId, [...$planned, 'status' => 'missing', 'search_audit' => $audit]);
        $summary['missing']++;
    }

    refresh_article_image_rendering($postId);

    return $summary;
}

/** Re-evaluate an already downloaded legal related asset under the W/W contextual policy. */
function article_image_revalidate_downloaded_contextual_candidates(int $postId, int $topicId, int $limit): array
{
    $coverage = article_image_coverage_state($postId, $topicId);
    $missing = [];
    foreach ((array) ($coverage['missing_slots'] ?? []) as $slot) {
        $missing[(string) ($slot['role'] ?? '') . ':' . (string) ($slot['section_anchor'] ?? '')] = (string) ($slot['slot_id'] ?? '');
    }
    $checked = 0; $accepted = 0; $rejected = 0;
    $post = find_post($postId) ?: [];
    $context = trim((string) (($post['title'] ?? '') . "\n" . ($post['excerpt'] ?? '') . "\n" . mb_substr(strip_tags((string) ($post['content'] ?? '')), 0, 6000)));
    foreach (list_article_images($postId) as $image) {
        if ($checked >= max(0, $limit)) break;
        $key = (string) ($image['role'] ?? '') . ':' . (string) ($image['section_id'] ?? '');
        if (!isset($missing[$key]) || (string) ($image['status'] ?? '') !== 'downloaded'
            || trim((string) ($image['local_path'] ?? '')) === '' || !is_file(app_path((string) $image['local_path']))
            || !article_image_license_is_auto_safe((string) ($image['license'] ?? ''))) continue;
        $manifest = image_rights_manifest_from_record($image);
        $candidate = [...$image, 'provider'=>(string) ($manifest['provider'] ?? ''),
            'provider_id'=>(string) ($manifest['asset_id'] ?? $image['id'])];
        $planned = ['slot_id'=>$missing[$key], 'role'=>(string)$image['role'], 'section_id'=>(string)$image['section_id'],
            'visual_intent'=>(string)$image['visual_intent'], 'expected_content'=>(string)$image['expected_content'],
            'relationship_policy'=>'ww_contextual_v1', 'vision_phase'=>'ww_contextual_revalidation'];
        $checked++;
        try {
            $assessment = article_image_gemini_vision_assess($postId, $candidate, $planned, $context, null, null, null, 2);
            if (!in_array((string) ($assessment['relationship_level'] ?? ''), ['direct','broader_direct','strong_related','contextual_related','domain_related'], true)
                || empty($assessment['contextual_useful']) || empty($assessment['honest_caption_possible'])
                || !empty($assessment['misleading']) || !empty($assessment['inappropriate'])
                || (string) ($assessment['decision'] ?? '') !== 'accept') {
                $rejected++;
                continue;
            }
            $assessment['related_supported'] = true;
            $assessment['contextual_policy'] = 'ww_contextual_v1';
            if ((string) $image['role'] === 'hero') {
                $assessment['hero_recovery'] = ['policy'=>'ww_contextual_v1','status'=>'validated','final_vision'=>$assessment];
            }
            $caption = trim((string) ($assessment['suggested_caption'] ?? '')) ?: (string) $image['caption'];
            bueno_database()->prepare('UPDATE article_images SET multimodal_assessment_json=:assessment,multimodal_accepted=1,caption=:caption,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute([':assessment'=>generation_json($assessment), ':caption'=>$caption, ':id'=>(int)$image['id']]);
            $accepted++;
        } catch (Throwable) {
            $rejected++;
        }
    }
    if ($accepted > 0) refresh_article_image_rendering($postId);
    return ['checked'=>$checked,'accepted'=>$accepted,'rejected'=>$rejected];
}

function article_image_overlay_recovery_replan(array $visualPlan, array $output, int $operationId): array
{
    foreach ((array) ($output['slots'] ?? []) as $change) {
        $slotId = (string) ($change['slot_id'] ?? '');
        foreach (['hero_slot','inline_slots'] as $group) {
            $indexes = $group === 'hero_slot' ? [null] : array_keys((array) ($visualPlan[$group] ?? []));
            foreach ($indexes as $index) {
                if ($group === 'hero_slot') $slot =& $visualPlan[$group];
                else $slot =& $visualPlan[$group][$index];
                if ((string) ($slot['slot_id'] ?? '') !== $slotId) { unset($slot); continue; }
                $slot['visual_need'] = (string) $change['revised_visual_need'];
                $slot['search_queries_direct'] = array_values((array) $change['search_queries_direct']);
                $slot['search_queries_related'] = array_values((array) $change['search_queries_related']);
                $slot['acceptable_related'] = (string) $change['allowed_relationship'] === 'controlled_related';
                $slot['recovery_relationship_policy'] = (string) $change['allowed_relationship'];
                $slot['recovery_replan_operation_id'] = $operationId;
                $slot['query_origin'] = 'recovery_replan';
                unset($slot);
                break 2;
            }
        }
    }
    return $visualPlan;
}

/** Classify stored replans against the current contract; stale rows remain audit-only. */
function article_image_recovery_replan_states(int $postId, ?int $topicId = null): array
{
    $sql = 'SELECT * FROM generation_operations WHERE post_id=:post AND operation_type="image_recovery_replan" AND status="completed"';
    $params = [':post'=>$postId];
    if ($topicId !== null && $topicId > 0) { $sql .= ' AND topic_id=:topic'; $params[':topic'] = $topicId; }
    $sql .= ' ORDER BY id ASC';
    $statement = bueno_database()->prepare($sql);
    $statement->execute($params);
    $states = [];
    foreach ($statement->fetchAll() as $operation) {
        try {
            $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
            $output = json_decode((string) ($operation['output_json'] ?? '{}'), true) ?: [];
            $analysis = article_image_recovery_replan_analysis($input, $output);
            if ($analysis['valid']) {
                $states[] = ['status'=>'current_contract','operation'=>$operation,'output'=>$output,'reason'=>''];
            } elseif ($analysis['valid_slots'] !== []) {
                $states[] = ['status'=>'partial_contract','operation'=>$operation,
                    'output'=>['slots'=>$analysis['valid_slots']],'reason'=>generation_json(array_intersect_key($analysis, array_flip([
                        'missing_slot_ids', 'duplicate_slot_ids', 'unexpected_slot_ids', 'invalid_slots',
                    ])))];
            } else {
                $states[] = ['status'=>'stale_replan_contract','operation'=>$operation,'output'=>[],
                    'reason'=>generation_json(array_intersect_key($analysis, array_flip([
                        'missing_slot_ids', 'duplicate_slot_ids', 'unexpected_slot_ids', 'invalid_slots',
                    ])))];
            }
        } catch (Throwable $exception) {
            $states[] = ['status'=>'stale_replan_contract','operation'=>$operation,'output'=>[],
                'reason'=>$exception->getMessage()];
        }
    }
    return $states;
}

function article_image_valid_recovery_replans(int $postId, ?int $topicId = null): array
{
    return array_values(array_filter(
        article_image_recovery_replan_states($postId, $topicId),
        static fn (array $state): bool => (string) ($state['status'] ?? '') === 'current_contract'
    ));
}

function article_final_visual_plan_schema(array $sectionIds, int $visualTarget): array
{
    $sectionIds = array_values(array_unique(array_filter(array_map('strval', $sectionIds))));
    if ($sectionIds === [] || $visualTarget < 3 || $visualTarget > 4) {
        throw new InvalidArgumentException('FinalVisualPlan wymaga finalnych sekcji i targetu 3–4.');
    }
    $slot = ['type'=>'object','properties'=>[
        'slot_id'=>['type'=>'string','minLength'=>1,'maxLength'=>100],
        'role'=>['type'=>'string','enum'=>['hero','inline']],
        'section_anchor'=>['type'=>'string','enum'=>$sectionIds],
        'topic_source'=>['type'=>'string','enum'=>['A','B','C']],
        'visual_need'=>['type'=>'string','minLength'=>10,'maxLength'=>500],
        'must_be_direct'=>['type'=>'boolean'],
        'acceptable_related'=>['type'=>'boolean'],
        'search_queries_direct'=>['type'=>'array','items'=>['type'=>'string','minLength'=>2],'minItems'=>1,'maxItems'=>5],
        'search_queries_related'=>['type'=>'array','items'=>['type'=>'string','minLength'=>2],'minItems'=>0,'maxItems'=>6],
        'required'=>['type'=>'boolean','enum'=>[true]],
    ],'required'=>['slot_id','role','section_anchor','topic_source','visual_need','must_be_direct','acceptable_related','search_queries_direct','search_queries_related','required'],'additionalProperties'=>false];
    $hero = $slot;
    $hero['properties']['role'] = ['type'=>'string','enum'=>['hero']];
    $hero['properties']['section_anchor'] = ['type'=>'string','enum'=>['article']];
    $hero['properties']['must_be_direct'] = ['type'=>'boolean','enum'=>[true]];
    $inline = $slot;
    $inline['properties']['role'] = ['type'=>'string','enum'=>['inline']];
    return ['type'=>'object','properties'=>[
        'hero_slot'=>$hero,
        'inline_slots'=>['type'=>'array','items'=>$inline,'minItems'=>$visualTarget-1,'maxItems'=>$visualTarget-1],
    ],'required'=>['hero_slot','inline_slots'],'additionalProperties'=>false];
}

/** FinalVisualPlan must assign complementary illustrative functions, not synonyms to two slots. */
function article_final_visual_plan_redundant_slot_pairs(array $slots): array
{
    $duplicates = [];
    foreach ($slots as $index => $slot) {
        if (!is_array($slot)) continue;
        for ($otherIndex = $index + 1; $otherIndex < count($slots); $otherIndex++) {
            $other = (array) $slots[$otherIndex];
            $needSimilarity = article_image_semantic_text_similarity(
                (string) ($slot['visual_need'] ?? ''),
                (string) ($other['visual_need'] ?? '')
            );
            $querySimilarity = article_image_semantic_text_similarity(
                implode(' ', [...(array) ($slot['search_queries_direct'] ?? []), ...(array) ($slot['search_queries_related'] ?? [])]),
                implode(' ', [...(array) ($other['search_queries_direct'] ?? []), ...(array) ($other['search_queries_related'] ?? [])])
            );
            if ($needSimilarity >= 0.72 && $querySimilarity >= 0.55) {
                $duplicates[] = [
                    'first_slot_id' => (string) ($slot['slot_id'] ?? ''),
                    'second_slot_id' => (string) ($other['slot_id'] ?? ''),
                    'need_similarity' => round($needSimilarity, 3),
                    'query_similarity' => round($querySimilarity, 3),
                ];
            }
        }
    }
    return $duplicates;
}

function article_final_visual_plan_validate(array $input, array $output): array
{
    $draftId = (int)($input['draft_version_id'] ?? 0);
    $lock = $draftId > 0 ? core_text_lock_state($draftId) : [];
    if (empty($lock['core_text_locked']) || !hash_equals((string)($lock['core_hash'] ?? ''), (string)($input['locked_core_hash'] ?? ''))) {
        throw new RuntimeException('FinalVisualPlan nie odpowiada aktualnemu locked core text.');
    }
    $target = (int) ($input['visual_target_total'] ?? 0);
    $sections = array_values((array) ($input['dynamic_sections'] ?? []));
    $sectionIds = array_values(array_filter(array_map(static fn(array $section): string => (string)($section['id'] ?? ''), $sections)));
    validate_generation_value($output, article_final_visual_plan_schema($sectionIds, $target), '$');
    $hero = (array) ($output['hero_slot'] ?? []);
    $inline = array_values((array) ($output['inline_slots'] ?? []));
    $slotIds = [];
    $anchors = [];
    foreach ([$hero, ...$inline] as $slot) {
        $slotId = (string) ($slot['slot_id'] ?? '');
        if (isset($slotIds[$slotId])) throw new InvalidArgumentException('FinalVisualPlan zawiera duplicate slot_id.');
        if (!empty($slot['acceptable_related']) && (array) ($slot['search_queries_related'] ?? []) === []) {
            throw new InvalidArgumentException('FinalVisualPlan dopuszcza related bez zapytań related: ' . $slotId . '.');
        }
        $slotIds[$slotId] = true;
        if (($slot['role'] ?? '') === 'inline') {
            $anchor = (string) ($slot['section_anchor'] ?? '');
            if (isset($anchors[$anchor])) throw new InvalidArgumentException('FinalVisualPlan przypisuje dwa wymagane sloty do tej samej sekcji.');
            $anchors[$anchor] = true;
        }
    }
    if (1 + count($inline) !== $target) throw new InvalidArgumentException('FinalVisualPlan nie odpowiada finalnemu visual target.');
    $duplicates = article_final_visual_plan_redundant_slot_pairs([$hero, ...$inline]);
    if ($duplicates !== []) {
        $first = $duplicates[0];
        throw new InvalidArgumentException('FinalVisualPlan ma redundantne funkcjonalnie sloty: '
            . $first['first_slot_id'] . ' i ' . $first['second_slot_id'] . '.');
    }
    return ['visual_target_total'=>$target,'slot_count'=>1+count($inline),'section_anchors'=>array_keys($anchors)];
}

function prepare_article_final_visual_plan_operation(int $postId, int $topicId): int
{
    $statement = bueno_database()->prepare('SELECT * FROM article_draft_versions WHERE post_id=:post AND topic_id=:topic ORDER BY is_active DESC,id DESC LIMIT 1');
    $statement->execute([':post'=>$postId, ':topic'=>$topicId]);
    $draft = $statement->fetch();
    if (!is_array($draft)) throw new RuntimeException('FinalVisualPlan wymaga finalnego szkicu.');
    $lock = core_text_lock_state((int) $draft['id']);
    if (empty($lock['core_text_locked'])) throw new RuntimeException('FinalVisualPlan może powstać dopiero po core locku.');
    $draftJson = json_decode((string) ($draft['draft_json'] ?? '{}'), true) ?: [];
    $sections = article_section_blocks($draftJson);
    $sectionIds = array_column($sections, 'id');
    $characters = article_draft_main_content_length($draftJson);
    $targetState = editorial_v2_visual_target_state($characters, 0);
    $narrative = find_narrative_plan_for_post($postId, $topicId);
    if (!is_array($narrative)) throw new RuntimeException('FinalVisualPlan wymaga NarrativePlan.');
    $research = find_research_package((int) $draft['research_package_id']);
    if (!is_array($research) || (string) ($research['status'] ?? '') !== 'approved') throw new RuntimeException('FinalVisualPlan wymaga zatwierdzonego researchu.');
    $input = [
        'workflow_version'=>2,'visual_phase'=>'final','post_id'=>$postId,'topic_id'=>$topicId,
        'draft_version_id'=>(int)$draft['id'],'locked_core_hash'=>(string)$lock['core_hash'],
        'final_article_chars'=>$characters,'visual_target_total'=>(int)$targetState['visual_target'],
        'publication_floor'=>(int)$targetState['publication_visual_floor'],
        'final_locked_article'=>$draftJson,'dynamic_sections'=>$sections,
        'abc_roles'=>narrative_plan_editorial_payload($narrative),
        'approved_research'=>json_decode((string)($research['package_json'] ?? '{}'), true) ?: [],
        'source_claim_map'=>article_image_approved_research_source_map($postId, $topicId),
        'preliminary_visual_directions'=>json_decode((string)($narrative['visual_plan_json'] ?? '{}'), true) ?: [],
        'instructions'=>[
            'Assign every slot a complementary illustrative function; never return variants of the same subject, context, visual need, or query family.',
            'Return exactly one required hero plus visual_target_total-1 required inline slots.',
            'Use only existing dynamic section ids as inline section_anchor and distribute slots across the final article and A/B/C where supported.',
            'Direct queries require at least 1; prefer 3–5 diverse queries. Do not invent article sections.',
        ],
    ];
    return prepare_generation_operation('final_visual_plan', $input, article_final_visual_plan_schema($sectionIds, (int)$targetState['visual_target']), $postId, $topicId);
}

function article_final_visual_plan_for_post(int $postId, ?int $topicId = null): ?array
{
    $sql = 'SELECT * FROM generation_operations WHERE post_id=:post AND operation_type="final_visual_plan" AND status="completed"';
    $params = [':post'=>$postId];
    if ($topicId !== null && $topicId > 0) { $sql .= ' AND topic_id=:topic'; $params[':topic']=$topicId; }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $statement = bueno_database()->prepare($sql);
    $statement->execute($params);
    $operation = $statement->fetch();
    if (!is_array($operation)) return null;
    $input = json_decode((string)$operation['input_json'], true) ?: [];
    $output = json_decode((string)$operation['output_json'], true) ?: [];
    $draftId = (int)($input['draft_version_id'] ?? 0);
    $lock = $draftId > 0 ? core_text_lock_state($draftId) : [];
    if (empty($lock['core_text_locked']) || !hash_equals((string)($lock['core_hash'] ?? ''), (string)($input['locked_core_hash'] ?? ''))) return null;
    article_final_visual_plan_validate($input, $output);
    return $output;
}

function article_final_visual_plan_mock_generation_value(array $operation): array
{
    $input = json_decode((string)($operation['input_json'] ?? '{}'), true) ?: [];
    $sections = array_values((array)($input['dynamic_sections'] ?? []));
    $target = (int)($input['visual_target_total'] ?? 3);
    $directions = (array)($input['preliminary_visual_directions'] ?? []);
    $donors = [(array)($directions['hero_slot'] ?? []), ...(array)($directions['inline_slots'] ?? [])];
    $make = static function(string $role, string $anchor, string $topic, int $index) use ($donors): array {
        $donor = (array)($donors[$index % max(1, count($donors))] ?? []);
        $need = (string)($donor['visual_need'] ?? ('Bezpośrednia ilustracja finalnej sekcji ' . $anchor));
        $relatedQueries = array_values((array)($donor['search_queries_related'] ?? []));
        if ($role !== 'hero' && !empty($donor['acceptable_related']) && $relatedQueries === []) $relatedQueries = ['related scientific context'];
        return ['slot_id'=>$role === 'hero' ? 'hero-main' : 'final-inline-'.$index,'role'=>$role,'section_anchor'=>$anchor,
            'topic_source'=>in_array($topic,['A','B','C'],true)?$topic:'A','visual_need'=>$need,'must_be_direct'=>$role==='hero' ? true : (bool)($donor['must_be_direct'] ?? true),
            'acceptable_related'=>$role==='hero' ? false : (bool)($donor['acceptable_related'] ?? false),
            'search_queries_direct'=>array_values((array)($donor['search_queries_direct'] ?? ['final article subject image'])),
            'search_queries_related'=>$role==='hero' ? [] : $relatedQueries,
            'required'=>true];
    };
    $output = ['hero_slot'=>$make('hero','article','A',0),'inline_slots'=>[]];
    foreach (array_slice($sections, 0, max(0,$target-1)) as $index=>$section) {
        $output['inline_slots'][] = $make('inline',(string)$section['id'],(string)($section['topic_role'] ?? 'A'),$index+1);
    }
    return $output;
}

/** Final locked VisualPlan plus every validated, persisted recovery override. */
function article_image_effective_visual_plan(int $postId, ?int $topicId = null, ?array $plan = null): array
{
    $plan ??= find_narrative_plan_for_post($postId, $topicId);
    $final = article_final_visual_plan_for_post($postId, $topicId);
    if (is_array($final)) {
        $visual = $final;
    } else {
        $operationId = is_array($plan) ? (int)($plan['batch_stage_ref'] ?? 0) : 0;
        $operation = $operationId > 0 ? find_generation_operation($operationId) : null;
        $operationInput = is_array($operation) ? (json_decode((string)($operation['input_json'] ?? '{}'), true) ?: []) : [];
        $visual = (int)($operationInput['workflow_version'] ?? 1) >= 2
            ? []
            : (is_array($plan) ? (json_decode((string) ($plan['visual_plan_json'] ?? '{}'), true) ?: []) : []);
    }
    foreach (article_image_effective_recovery_replans($postId, $topicId) as $replan) {
        $visual = article_image_overlay_recovery_replan(
            $visual,
            (array) $replan['output'],
            (int) ($replan['operation']['id'] ?? 0)
        );
    }
    return $visual;
}

function article_image_valid_recovery_replan_count(int $postId, ?int $topicId = null): int
{
    return count(article_image_valid_recovery_replans($postId, $topicId));
}

function article_image_recovery_replan_retry_state(
    int $postId,
    int $topicId,
    array $coverage,
    array $budget,
    bool $normalPathsExhausted
): array {
    $states = article_image_recovery_replan_states($postId, $topicId);
    $currentCount = count(array_filter($states, static fn (array $state): bool => ($state['status'] ?? '') === 'current_contract'));
    $staleCount = count($states) - $currentCount;
    return [...article_image_recovery_replan_eligibility($coverage, $budget, $normalPathsExhausted, $currentCount),
        'current_contract_replans'=>$currentCount, 'stale_replan_contracts'=>$staleCount];
}

function article_image_merge_recovery_slot_policy(array $coverageSlots, array $visualPlan): array
{
    $definitions = [];
    foreach ([($visualPlan['hero_slot'] ?? null), ...(array) ($visualPlan['inline_slots'] ?? [])] as $slot) {
        if (is_array($slot) && trim((string) ($slot['slot_id'] ?? '')) !== '') {
            $definitions[(string) $slot['slot_id']] = $slot;
        }
    }
    return array_values(array_map(static function (array $coverage) use ($definitions): array {
        $definition = (array) ($definitions[(string) ($coverage['slot_id'] ?? '')] ?? []);
        return [...$definition, ...$coverage];
    }, $coverageSlots));
}

/** Build the bounded, auditable input for P06 recovery without touching locked core text. */
function article_image_shortage_recovery_input(int $postId, int $topicId): array
{
    if (!function_exists('article_image_coverage_state') || !function_exists('core_text_lock_state')) {
        throw new LogicException('Image shortage recovery requires the quality and core-lock services.');
    }
    $draftStatement = bueno_database()->prepare(
        'SELECT id, draft_json FROM article_draft_versions WHERE post_id = :post_id '
        . 'ORDER BY is_active DESC, id DESC LIMIT 1'
    );
    $draftStatement->execute([':post_id' => $postId]);
    $draft = $draftStatement->fetch();
    if (!is_array($draft) || empty(core_text_lock_state((int) $draft['id'])['core_text_locked'])) {
        throw new RuntimeException('Recovery obrazów wymaga locked core text.');
    }
    $coverage = article_image_coverage_state($postId, $topicId);
    if (!empty($coverage['coverage_complete'])) {
        throw new LogicException('Recovery obrazów nie uruchamia się przy kompletnym direct coverage.');
    }
    $plan = find_narrative_plan_for_post($postId, $topicId);
    if (!is_array($plan)) {
        throw new RuntimeException('Recovery obrazów wymaga NarrativePlan właściwego dla posta i tematu.');
    }
    $visualPlan = article_image_effective_visual_plan($postId, $topicId, $plan);
    $draftJson = json_decode((string) ($draft['draft_json'] ?? '{}'), true) ?: [];
    $selection = narrative_plan_editorial_payload($plan);
    $modules = json_decode((string) ($plan['expansion_modules_json'] ?? '[]'), true);
    $modules = is_array($modules) ? $modules : [];
    $heroAuditStatement = bueno_database()->prepare(
        'SELECT search_audit_json,multimodal_assessment_json FROM article_images WHERE post_id=:post AND role="hero" ORDER BY id DESC LIMIT 1'
    );
    $heroAuditStatement->execute([':post' => $postId]);
    $heroRow = $heroAuditStatement->fetch();
    $heroAudit = json_decode((string) ($heroRow['search_audit_json'] ?? '[]'), true);
    $heroAudit = is_array($heroAudit) ? $heroAudit : [];
    $heroAssessment = json_decode((string) ($heroRow['multimodal_assessment_json'] ?? '{}'), true);
    $preservedExhaustion = (array) (($heroAssessment['hero_recovery']['direct_exhaustion'] ?? null) ?: []);
    if ($heroAudit === [] && (array) ($preservedExhaustion['evidence'] ?? []) !== []) {
        $heroAudit = (array) $preservedExhaustion['evidence'];
    }
    $directExhausted = (bool) array_filter($heroAudit, static fn (array $entry): bool =>
        (string) ($entry['level'] ?? '') === 'exhausted'
        && (string) ($entry['result'] ?? '') === 'missing'
    ) || !empty($preservedExhaustion['confirmed']);
    $missing = article_image_merge_recovery_slot_policy(
        array_values((array) ($coverage['missing_slots'] ?? [])),
        $visualPlan
    );
    $heroRecoveryTerminal = false;
    foreach (list_article_images($postId) as $image) {
        if ((string) ($image['role'] ?? '') !== 'hero') continue;
        $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true) ?: [];
        if ((string) ($assessment['hero_recovery']['status'] ?? '') === 'final_rejected') {
            $heroSlot = (array) ($visualPlan['hero_slot'] ?? []);
            $remainingCandidates = array_values(array_filter(
                article_image_related_candidate_shortlist(
                    $heroSlot,
                    article_image_prior_related_candidates($postId, $topicId, (string) ($heroSlot['slot_id'] ?? ''))
                ),
                static fn (array $candidate): bool => !article_image_candidate_has_completed_vision_reject(
                    $postId,
                    (string) ($heroSlot['slot_id'] ?? ''),
                    $candidate
                )
            ));
            $heroRecoveryTerminal = $remainingCandidates === [];
            break;
        }
    }
    $missing = array_map(static function (array $slot) use ($directExhausted, $heroAudit, $heroRecoveryTerminal): array {
        if ((string) ($slot['role'] ?? '') === 'hero') {
            $slot['hero_recovery_required'] = true;
            $slot['hero_recovery_policy'] = 'source_backed_related_hero_v1';
            $slot['direct_exhaustion'] = [
                'confirmed' => $directExhausted,
                'evidence' => $heroAudit,
            ];
            $slot['hero_recovery_terminal'] = $heroRecoveryTerminal;
        }
        return $slot;
    }, $missing);
    $sourceMap = article_image_approved_research_source_map($postId, $topicId);
    $budget = gemini_article_budget_state($postId);
    return [
        'post_id' => $postId,
        'topic_id' => $topicId,
        'draft_version_id' => (int) $draft['id'],
        'locked_core_hash' => core_text_lock_state((int) $draft['id'])['core_hash'],
        'narrative_plan_id' => (int) ($plan['id'] ?? 0),
        'visual_plan' => $visualPlan,
        'final_article_length'=>(int) ($coverage['final_article_length'] ?? article_draft_main_content_length($draftJson)),
        'visual_target_total'=>(int) ($coverage['visual_target'] ?? 0),
        'visual_slot_count'=>(int) ($coverage['visual_slot_count'] ?? 0),
        'visual_deficit'=>(int) ($coverage['visual_deficit'] ?? 0),
        'publication_visual_floor'=>(int) ($coverage['publication_visual_floor'] ?? 0),
        'image_plan_expansion_required'=>!empty($coverage['image_plan_expansion_required']),
        'section_structure'=>array_values((array) ($selection['sections'] ?? [])),
        'missing_slots' => $missing,
        'expansion_modules' => $modules,
        'research_source_map' => $sourceMap,
        'remaining_gemini_budget' => max(0, (int) ($budget['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT) - (int) ($budget['used_calls'] ?? 0)),
    ];
}

// A final stubborn slot can remain after four valid, whole-plan replans. Keep
// one last bounded replan available instead of routing an otherwise
// recoverable article to manual handling.
const ARTICLE_IMAGE_RECOVERY_REPLAN_MAX_ATTEMPTS = 5;

/** Bounded replans are allowed only while they preserve the P08/P09 closure budget. */
function article_image_recovery_replan_eligibility(array $coverage, array $budget, bool $normalPathsExhausted, int $priorReplans): array
{
    $floor = (int) ($coverage['publication_visual_floor'] ?? 0);
    $found = count((array) ($coverage['filled_slots'] ?? []));
    $remaining = max(0, (int) ($budget['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT) - (int) ($budget['used_calls'] ?? 0));
    $missing = count((array) ($coverage['missing_slots'] ?? [])) + (int) ($coverage['visual_deficit'] ?? 0);
    $required = max((int) ($coverage['visual_target'] ?? 0), count((array) ($coverage['required_slots'] ?? [])), $found + $missing);
    $closureReserve = 2;
    $safeThreshold = 1 + $closureReserve + ($missing > 0 ? 1 : 0);
    $eligible = $found < $required && $normalPathsExhausted
        && $priorReplans < ARTICLE_IMAGE_RECOVERY_REPLAN_MAX_ATTEMPTS
        && $remaining >= $safeThreshold;
    return [
        'eligible'=>$eligible,'found'=>$found,'floor'=>$floor,'required'=>$required,'missing'=>$missing,'remaining_budget'=>$remaining,
        'safe_recovery_threshold'=>$safeThreshold,'closure_reserve'=>$closureReserve,
        'max_replans'=>ARTICLE_IMAGE_RECOVERY_REPLAN_MAX_ATTEMPTS,
        'vision_candidate_limit_per_missing_slot'=>2,
    ];
}

/** Preserve useful Vision evidence so a replan can change visual strategy instead of replaying a rejected direct fit. */
function article_image_replan_vision_feedback_from_records(array $records): array
{
    $feedback = [];
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        $slotId = trim((string) ($record['slot_identifier'] ?? ''));
        $assessment = json_decode((string) ($record['provider_response_text'] ?? ''), true);
        if ($slotId === '' || !is_array($assessment)
            || (string) ($assessment['decision'] ?? '') !== 'accept'
            || empty($assessment['contextual_useful']) || empty($assessment['honest_caption_possible'])
            || !empty($assessment['misleading']) || !empty($assessment['inappropriate'])) {
            continue;
        }
        $level = (string) ($assessment['relationship_level'] ?? '');
        if (!in_array($level, ['strong_related', 'contextual_related', 'domain_related'], true)) continue;
        $feedback[$slotId] = [
            'relationship_level'=>$level,
            'reason'=>trim((string) ($assessment['reason'] ?? '')),
            'visual_type'=>(string) ($assessment['visual_type'] ?? ''),
            'suggested_caption'=>trim((string) ($assessment['suggested_caption'] ?? '')),
        ];
    }
    return $feedback;
}

function article_image_replan_vision_feedback(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT slot_identifier,provider_response_text FROM article_image_vision_audit
         WHERE post_id=:post AND status="completed" ORDER BY id ASC'
    );
    $statement->execute([':post'=>$postId]);
    return article_image_replan_vision_feedback_from_records($statement->fetchAll());
}

/** Reuse a previously accepted Vision candidate after a replan authorizes its relationship policy. */
function article_image_prior_vision_accepted_candidates(int $postId, string $slotId, array $allowedRelationshipLevels): array
{
    $statement = bueno_database()->prepare(
        'SELECT provider_response_text,provider_response_json FROM article_image_vision_audit
         WHERE post_id=:post AND slot_identifier=:slot AND status="completed" ORDER BY id DESC LIMIT 12'
    );
    $statement->execute([':post'=>$postId, ':slot'=>$slotId]);
    $candidates = [];
    foreach ($statement->fetchAll() as $record) {
        $assessment = json_decode((string) ($record['provider_response_text'] ?? ''), true);
        $response = json_decode((string) ($record['provider_response_json'] ?? ''), true);
        $candidate = is_array($response) ? (array) ($response['_candidate'] ?? []) : [];
        if (!is_array($assessment) || $candidate === []
            || (string) ($assessment['decision'] ?? '') !== 'accept'
            || empty($assessment['contextual_useful']) || empty($assessment['honest_caption_possible'])
            || !empty($assessment['misleading']) || !empty($assessment['inappropriate'])
            || !in_array((string) ($assessment['relationship_level'] ?? ''), $allowedRelationshipLevels, true)) continue;
        $key = trim((string) ($candidate['source_file_url'] ?? $candidate['provider_id'] ?? ''));
        if ($key !== '') $candidates[$key] = [...$candidate, '_prior_vision_assessment'=>$assessment];
    }
    return array_values($candidates);
}

function article_image_recovery_replan_input(int $postId, int $topicId): array
{
    $base = article_image_shortage_recovery_input($postId, $topicId);
    $coverage = article_image_coverage_state($postId, $topicId);
    $budget = gemini_article_budget_state($postId);
    $eligibility = article_image_recovery_replan_eligibility(
        $coverage,
        $budget,
        true,
        article_image_valid_recovery_replan_count($postId, $topicId)
    );
    if (empty($eligibility['eligible'])) article_recovery_preflight_fail('recovery_replan_not_eligible', 'Bounded visual recovery replan is not eligible.');
    $research = bueno_database()->prepare('SELECT package_json FROM research_packages WHERE post_id=:post AND topic_id=:topic AND status="approved" ORDER BY id DESC LIMIT 1');
    $research->execute([':post'=>$postId, ':topic'=>$topicId]);
    $researchPackage = json_decode((string) ($research->fetchColumn() ?: '{}'), true) ?: [];
    $attempted = []; $rejectSummary = []; $visionReasons = [];
    $images = bueno_database()->prepare('SELECT role,section_id,search_audit_json FROM article_images WHERE post_id=:post');
    $images->execute([':post'=>$postId]);
    foreach ($images->fetchAll() as $image) {
        $slot = (string) $image['role'] . ':' . (string) $image['section_id'];
        foreach ((array) (json_decode((string) ($image['search_audit_json'] ?? '[]'), true) ?: []) as $entry) {
            $query = trim((string) ($entry['query'] ?? ''));
            if ($query !== '') $attempted[$slot][] = $query;
            $result = (string) ($entry['result'] ?? '');
            $reason = trim((string) ($entry['reason'] ?? ''));
            if (in_array($result, ['rejected','watermark_rejected','missing'], true)) $rejectSummary[$slot][] = ['result'=>$result,'reason'=>$reason];
            if ($result === 'rejected' && $reason !== '') $visionReasons[$slot][] = $reason;
        }
    }
    $acceptedVisuals = [];
    $acceptedImages = bueno_database()->prepare(
        'SELECT role,section_id,caption,multimodal_assessment_json FROM article_images
         WHERE post_id=:post AND status="downloaded" AND multimodal_accepted=1'
    );
    $acceptedImages->execute([':post'=>$postId]);
    foreach ($acceptedImages->fetchAll() as $image) {
        $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true) ?: [];
        $acceptedVisuals[] = [
            'slot'=>(string) $image['role'] . ':' . (string) $image['section_id'],
            'caption'=>(string) ($image['caption'] ?? ''),
            'visual_subject'=>(string) ($assessment['visual_subject'] ?? ''),
            'visual_function'=>(string) ($assessment['visual_function'] ?? ''),
        ];
    }
    return [...$base,
        'workflow_version'=>3,
        'recovery_contract_version'=>2,
        'research_abc'=>array_intersect_key($researchPackage, array_flip(['primary_story','context_topics','curiosity_topics','claims'])),
        'current_coverage'=>$coverage,'attempted_queries'=>$attempted,'candidate_reject_summary'=>$rejectSummary,
        'vision_rejection_reasons'=>$visionReasons,'vision_feedback'=>article_image_replan_vision_feedback($postId),
        'accepted_visuals'=>$acceptedVisuals,
        'budget'=>$budget,'replan_policy'=>$eligibility,
        'instruction'=>'Replan only missing visual slots. Return exactly one slots[] entry for every current missing slot, copying each canonical slot_id verbatim: '
            . implode(', ', array_values(array_filter(array_map(static fn (array $slot): string => (string) ($slot['slot_id'] ?? ''), (array) ($base['missing_slots'] ?? [])))))
            . '. Do not omit, duplicate, rename, or add slot_ids. Preserve locked core text. Every replan must add value distinct from accepted_visuals: never reuse the same depicted subject, historical context, or visual function. Make hero represent the concrete primary story A, not the general field. Broaden direct queries first. If vision_feedback shows an honest, accepted mechanism/context illustration for a missing inline slot after direct fit failed, use controlled_related with a source-backed relationship instead of replaying the direct strategy. Related recovery must name an allowed source-backed relationship.',
    ];
}

function article_image_recovery_replan_schema(): array
{
    return ['type'=>'object','properties'=>['slots'=>['type'=>'array','minItems'=>0,'items'=>['type'=>'object','properties'=>[
        'slot_id'=>['type'=>'string','minLength'=>1],'revised_visual_need'=>['type'=>'string','minLength'=>20,'maxLength'=>500],
        'search_queries_direct'=>['type'=>'array','minItems'=>2,'maxItems'=>6,'items'=>['type'=>'string','minLength'=>3]],
        'search_queries_related'=>['type'=>'array','minItems'=>1,'maxItems'=>6,'items'=>['type'=>'string','minLength'=>3]],
        'allowed_relationship'=>['type'=>'string','enum'=>['direct','broader_direct','controlled_related']],
        'editorial_justification'=>['type'=>'string','minLength'=>20,'maxLength'=>700],
    ],'required'=>['slot_id','revised_visual_need','search_queries_direct','search_queries_related','allowed_relationship','editorial_justification'],'additionalProperties'=>false]]],
        'required'=>['slots'],'additionalProperties'=>false];
}

function article_image_recovery_replan_mock_generation_value(array $operation): array
{
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    $slots = [];
    foreach (array_values((array) ($input['missing_slots'] ?? [])) as $index => $missing) {
        $slotId = (string) ($missing['slot_id'] ?? '');
        $role = (string) ($missing['role'] ?? 'inline');
        $slots[] = [
            'slot_id' => $slotId,
            'revised_visual_need' => 'Szersza, nadal uczciwa ilustracja naukowego kontekstu wymaganego slotu ' . $slotId . '.',
            'search_queries_direct' => ['documentary science subject ' . ($index + 1), 'research equipment context ' . ($index + 1)],
            'search_queries_related' => ['scientific research environment ' . ($index + 1)],
            'allowed_relationship' => $role === 'hero' ? 'broader_direct' : 'controlled_related',
            'editorial_justification' => 'Kontrolowany mock poszerza funkcję ilustracyjną bez zmiany zamrożonego tekstu artykułu.',
        ];
    }
    return ['slots' => $slots];
}

function article_image_recovery_replan_analysis(array $input, array $output): array
{
    $expected = [];
    foreach ((array) ($input['missing_slots'] ?? []) as $slot) {
        $slotId = trim((string) ($slot['slot_id'] ?? ''));
        if ($slotId !== '') $expected[$slotId] = $slot;
    }
    $actual = [];
    foreach ((array) ($output['slots'] ?? []) as $slot) {
        $slotId = (string) ($slot['slot_id'] ?? '');
        $actual[$slotId][] = $slot;
    }
    $duplicateIds = [];
    $unexpectedIds = [];
    foreach ($actual as $slotId => $changes) {
        if (!isset($expected[$slotId])) $unexpectedIds[] = $slotId;
        if (count($changes) > 1) $duplicateIds[] = $slotId;
    }
    $missingIds = array_values(array_diff(array_keys($expected), array_keys($actual)));
    $invalidSlots = [];
    $validSlots = [];
    foreach ($expected as $slotId => $missing) {
        if (!isset($actual[$slotId]) || count($actual[$slotId]) !== 1) continue;
        $slot = $actual[$slotId][0];
        $relationship = (string) ($slot['allowed_relationship'] ?? '');
        if (!in_array($relationship, ['direct','broader_direct','controlled_related'], true)) {
            $invalidSlots[$slotId] = 'forbidden_relationship';
            continue;
        }
        if ((string) ($missing['role'] ?? '') === 'hero'
            && $relationship === 'controlled_related'
            && empty($missing['direct_exhaustion']['confirmed'])) {
            $invalidSlots[$slotId] = 'controlled_related_hero_without_direct_exhaustion';
            continue;
        }
        $validSlots[] = $slot;
    }
    sort($missingIds); sort($duplicateIds); sort($unexpectedIds); ksort($invalidSlots);
    return [
        'valid' => $missingIds === [] && $duplicateIds === [] && $unexpectedIds === [] && $invalidSlots === [],
        'expected_slot_ids' => array_keys($expected),
        'missing_slot_ids' => $missingIds,
        'duplicate_slot_ids' => $duplicateIds,
        'unexpected_slot_ids' => $unexpectedIds,
        'invalid_slots' => $invalidSlots,
        'valid_slots' => $validSlots,
    ];
}

function article_image_effective_recovery_replans(int $postId, ?int $topicId = null): array
{
    return array_values(array_filter(
        article_image_recovery_replan_states($postId, $topicId),
        static fn (array $state): bool => in_array((string) ($state['status'] ?? ''), ['current_contract', 'partial_contract'], true)
    ));
}

function article_image_validate_recovery_replan(array $input, array $output): array
{
    $analysis = article_image_recovery_replan_analysis($input, $output);
    if ($analysis['duplicate_slot_ids'] !== []) {
        throw new InvalidArgumentException('Recovery replan duplicate slot_id: ' . implode(', ', $analysis['duplicate_slot_ids']) . '.');
    }
    if ($analysis['unexpected_slot_ids'] !== []) {
        throw new InvalidArgumentException('Recovery replan unexpected slot_id: ' . implode(', ', $analysis['unexpected_slot_ids']) . '.');
    }
    if ($analysis['missing_slot_ids'] !== []) {
        throw new InvalidArgumentException('Recovery replan missing slot_id: ' . implode(', ', $analysis['missing_slot_ids']) . '.');
    }
    if ($analysis['invalid_slots'] !== []) {
        throw new InvalidArgumentException('Recovery replan invalid slot policy: ' . implode(', ', array_keys($analysis['invalid_slots'])) . '.');
    }
    return $output;
}

function prepare_article_image_recovery_replan_operation(int $postId, int $topicId): int
{
    return prepare_generation_operation('image_recovery_replan', article_image_recovery_replan_input($postId, $topicId), article_image_recovery_replan_schema(), $postId, $topicId);
}

function complete_article_image_recovery_replan_operation(int $operationId): array
{
    $operation = find_generation_operation($operationId);
    if (!is_array($operation) || (string) ($operation['operation_type'] ?? '') !== 'image_recovery_replan' || (string) ($operation['status'] ?? '') !== 'completed') {
        throw new RuntimeException('Recovery replan operation is not completed.');
    }
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    $output = json_decode((string) ($operation['output_json'] ?? '{}'), true) ?: [];
    return article_image_validate_recovery_replan($input, $output);
}

/** Apply only visual metadata; the frozen article core is never opened or rewritten. */
function article_image_apply_recovery_replan(int $operationId): array
{
    $operation = find_generation_operation($operationId);
    if (!is_array($operation) || (string) ($operation['operation_type'] ?? '') !== 'image_recovery_replan' || (string) ($operation['status'] ?? '') !== 'completed') {
        throw new RuntimeException('Recovery replan operation is not completed.');
    }
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    $output = json_decode((string) ($operation['output_json'] ?? '{}'), true) ?: [];
    $analysis = article_image_recovery_replan_analysis($input, $output);
    $postId = (int) ($operation['post_id'] ?? 0); $topicId = (int) ($operation['topic_id'] ?? 0);
    $plan = find_narrative_plan_for_post($postId, $topicId);
    if (!is_array($plan)) throw new RuntimeException('Recovery replan lost its NarrativePlan.');
    $visual = article_image_effective_visual_plan($postId, $topicId, $plan);
    $validOutput = ['slots' => $analysis['valid_slots']];
    $visual = article_image_overlay_recovery_replan($visual, $validOutput, $operationId);
    $revised = [];
    foreach ($analysis['valid_slots'] as $change) {
        $slotId = (string) $change['slot_id'];
        foreach (['hero_slot','inline_slots'] as $group) {
            if ($group === 'hero_slot') $indexes = [null]; else $indexes = array_keys((array) ($visual[$group] ?? []));
            foreach ($indexes as $index) {
                if ($group === 'hero_slot') $slot =& $visual[$group];
                else $slot =& $visual[$group][$index];
                if ((string) ($slot['slot_id'] ?? '') !== $slotId) { unset($slot); continue; }
                if ((int) ($slot['recovery_replan_operation_id'] ?? 0) !== $operationId) {
                    throw new RuntimeException('Validated recovery replan is not the effective VisualSlot state.');
                }
                $role = (string) ($slot['role'] ?? 'inline'); $anchor = (string) ($slot['section_anchor'] ?? '');
                $stale = bueno_database()->prepare('SELECT id FROM article_images WHERE post_id=:post AND role=:role AND section_id=:section');
                $stale->execute([':post'=>$postId, ':role'=>$role, ':section'=>$anchor]);
                $staleImageId = (int) $stale->fetchColumn();
                if ($staleImageId > 0) {
                    // A replan replaces the slot contract. Its next candidate must
                    // not inherit a P07 context block or a caption from the old
                    // asset, otherwise the renderer could pair new bytes with old
                    // source-backed context.
                    bueno_database()->prepare('DELETE FROM article_related_context_blocks WHERE post_id=:post AND image_id=:image')
                        ->execute([':post'=>$postId, ':image'=>$staleImageId]);
                    reject_article_source_image($staleImageId);
                }
                $image = bueno_database()->prepare('UPDATE article_images SET visual_intent=:need,expected_content=:need,search_queries_json=:queries,status="missing",updated_at=CURRENT_TIMESTAMP WHERE post_id=:post AND role=:role AND section_id=:section');
                $image->execute([':need'=>$slot['visual_need'],':queries'=>generation_json($slot['search_queries_direct']),':post'=>$postId,':role'=>$role,':section'=>$anchor]);
                $revised[] = $slotId; unset($slot); break 2;
            }
        }
    }
    return [
        'operation_id'=>$operationId,
        'status'=>$analysis['valid'] ? 'applied' : ($revised === [] ? 'canonical_fallback' : 'partial_fallback'),
        'revised_slot_ids'=>$revised,
        'validation'=>$analysis,
        'max_replans'=>ARTICLE_IMAGE_RECOVERY_REPLAN_MAX_ATTEMPTS,
    ];
}

/**
 * P06/P07 may cite only the immutable source set used by the approved research
 * package.  `verified_research_sources` is an enrichment cache and must not be
 * substituted here: it can drift after the accepted core text was frozen.
 */
function article_image_approved_research_source_map(int $postId, int $topicId): array
{
    $statement = bueno_database()->prepare(
        'SELECT packages.id AS package_id, packages.package_json, operations.input_json
         FROM research_packages AS packages
         INNER JOIN generation_operations AS operations ON operations.id = packages.generation_operation_id
         WHERE packages.post_id = :post_id AND packages.topic_id = :topic_id
           AND packages.status = "approved" AND operations.status = "completed"
         ORDER BY packages.approved_at DESC, packages.id DESC LIMIT 1'
    );
    $statement->execute([':post_id' => $postId, ':topic_id' => $topicId]);
    $package = $statement->fetch();
    if (!is_array($package)) {
        throw new RuntimeException('Recovery related image wymaga zatwierdzonego research package przypisanego do posta.');
    }
    $input = json_decode((string) ($package['input_json'] ?? '{}'), true);
    $output = json_decode((string) ($package['package_json'] ?? '{}'), true);
    if (!is_array($input) || !is_array($output)) {
        throw new RuntimeException('Recovery related image nie ma poprawnego audytowalnego source map zatwierdzonego researchu.');
    }
    $sources = [];
    foreach ((array) ($input['numbered_sources'] ?? []) as $source) {
        if (!is_array($source) || trim((string) ($source['source_id'] ?? '')) === '') {
            throw new RuntimeException('Recovery related image otrzymał niepoprawny numbered_sources w zatwierdzonym researchu.');
        }
        $sourceId = (string) $source['source_id'];
        $sources[$sourceId] = [
            'source_id' => $sourceId,
            'title' => (string) ($source['title'] ?? ''),
            'url' => (string) ($source['url'] ?? $source['canonical_url'] ?? ''),
            'excerpt' => (string) ($source['excerpt'] ?? ''),
            'claim_ids' => [],
        ];
    }
    if ($sources === []) {
        throw new RuntimeException('Recovery related image wymaga numbered_sources z zatwierdzonego researchu.');
    }
    foreach ((array) ($output['claims'] ?? []) as $claim) {
        if (!is_array($claim)) continue;
        $claimId = trim((string) ($claim['claim_id'] ?? ''));
        foreach ((array) ($claim['source_ids'] ?? []) as $sourceId) {
            $sourceId = (string) $sourceId;
            if (!isset($sources[$sourceId])) {
                throw new RuntimeException('Recovery related image odrzucił claim bez źródła z numbered_sources: ' . $sourceId . '.');
            }
            if ($claimId !== '') $sources[$sourceId]['claim_ids'][] = $claimId;
        }
    }
    foreach ($sources as &$source) {
        $source['claim_ids'] = array_values(array_unique($source['claim_ids']));
        $source['claim_trace'] = [
            'research_package_id' => (int) $package['package_id'],
            'claim_ids' => $source['claim_ids'],
        ];
    }
    unset($source);
    return array_values($sources);
}

function article_recovery_source_claim_ids(array $sourceMap): array
{
    $claims = [];
    foreach ($sourceMap as $source) {
        if (!is_array($source) || trim((string) ($source['source_id'] ?? '')) === '') {
            article_recovery_preflight_fail('recovery_missing_source_map', 'Invalid approved RSS source-map entry.');
        }
        foreach ((array) ($source['claim_ids'] ?? []) as $claimId) {
            $claimId = trim((string) $claimId);
            if ($claimId !== '') $claims[$claimId] = true;
        }
    }
    return array_keys($claims);
}

/** Classify every incomplete slot without allowing one empty slot to veto P06. */
function article_image_classify_shortage_recovery_slots(array $missingSlots): array
{
    $recoverable = [];
    $classifications = [];
    foreach ($missingSlots as $slot) {
        if (!is_array($slot)) continue;
        $slotId = trim((string) ($slot['slot_id'] ?? ''));
        if ($slotId === '') continue;
        $status = strtolower(trim((string) ($slot['status'] ?? $slot['final_slot_state'] ?? '')));
        $candidates = array_values(array_filter((array) ($slot['related_candidates'] ?? []), static fn ($candidate): bool =>
            is_array($candidate)
            && trim((string) ($candidate['provider_id'] ?? '')) !== ''
            && trim((string) ($candidate['source_file_url'] ?? '')) !== ''
            && trim((string) ($candidate['source_page_url'] ?? '')) !== ''
        ));
        $isHeroRecovery = (string) ($slot['role'] ?? '') === 'hero'
            && (string) ($slot['hero_recovery_policy'] ?? '') === 'source_backed_related_hero_v1'
            && !empty($slot['direct_exhaustion']['confirmed']);
        $allowsRelated = $isHeroRecovery || (!empty($slot['acceptable_related'])
            && (array) ($slot['search_queries_related'] ?? []) !== []);
        if (!empty($slot['hero_recovery_terminal'])) {
            $state = 'UNRECOVERABLE';
            $reason = 'hero_final_validation_rejected';
        } elseif (in_array($status, ['downloaded', 'selected', 'related_supported', 'complete', 'completed'], true)) {
            $state = 'ALREADY_COMPLETE';
            $reason = 'slot_already_complete';
        } elseif (!$allowsRelated) {
            $state = 'UNRECOVERABLE';
            $reason = 'related_recovery_not_authorized';
        } elseif ($candidates !== []) {
            $state = 'RECOVERABLE';
            $reason = 'legal_related_candidate_available';
            $recoverable[] = [...$slot, 'related_candidates' => $candidates, 'recovery_state' => $state];
        } else {
            $state = 'UNRECOVERABLE';
            $reason = 'no_legal_related_candidate';
        }
        $classifications[] = [
            'slot_id' => $slotId,
            'role' => (string) ($slot['role'] ?? ''),
            'state' => $state,
            'reason' => $reason,
            'related_candidate_count' => count($candidates),
        ];
    }
    return ['recoverable_slots' => $recoverable, 'slot_classifications' => $classifications];
}

/** Store all classifications for audit but expose only recoverable slots to P06. */
function article_image_apply_shortage_recovery_slot_classification(array $input): array
{
    $classified = article_image_classify_shortage_recovery_slots((array) ($input['missing_slots'] ?? []));
    return [...$input,
        'missing_slots' => $classified['recoverable_slots'],
        'slot_classifications' => $classified['slot_classifications'],
    ];
}

/** Central P06 contract. Persisted mode re-reads every mutable identity before transport. */
function article_image_shortage_recovery_preflight(array $input, bool $persisted = false, bool $requireCandidates = false): void
{
    $sourceMap = (array) ($input['research_source_map'] ?? []);
    if ($sourceMap === []) article_recovery_preflight_fail('recovery_missing_source_map', 'Approved RSS source map is missing.');
    $availableClaims = array_fill_keys(article_recovery_source_claim_ids($sourceMap), true);
    if ($availableClaims === []) article_recovery_preflight_fail('recovery_missing_source_map', 'Approved RSS source map has no claims.');

    $modules = (array) ($input['expansion_modules'] ?? []);
    $moduleIds = [];
    foreach ($modules as $module) {
        $moduleId = trim((string) ($module['module_id'] ?? ''));
        $claims = array_values(array_unique(array_filter(array_map('strval', (array) ($module['source_claim_ids'] ?? [])))));
        if ($moduleId === '' || isset($moduleIds[$moduleId]) || trim((string) ($module['topic'] ?? '')) === ''
            || trim((string) ($module['purpose'] ?? '')) === '' || (array) ($module['suitable_visual_types'] ?? []) === []
            || trim((string) ($module['preferred_placement'] ?? '')) === '' || $claims === []) {
            article_recovery_preflight_fail('recovery_no_supported_modules', 'Expansion module is incomplete, duplicated, or has no source claims.');
        }
        foreach ($claims as $claimId) {
            if (!isset($availableClaims[$claimId])) article_recovery_preflight_fail('recovery_no_supported_modules', 'Expansion module references an unapproved claim.');
        }
        $moduleIds[$moduleId] = true;
    }
    if ($moduleIds === []) article_recovery_preflight_fail('recovery_no_supported_modules', 'No source-backed expansion modules.');

    $visual = (array) ($input['visual_plan'] ?? []);
    $visualSlots = [];
    foreach ([($visual['hero_slot'] ?? null), ...(array) ($visual['inline_slots'] ?? [])] as $slot) {
        if (!is_array($slot)) continue;
        $slotId = trim((string) ($slot['slot_id'] ?? ''));
        if ($slotId === '' || isset($visualSlots[$slotId])) article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'VisualPlan has an empty or duplicate slot_id.');
        $visualSlots[$slotId] = $slot;
    }
    if ($visualSlots === []) article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'Persisted VisualPlan is missing.');

    $missingIds = [];
    foreach ((array) ($input['missing_slots'] ?? []) as $missing) {
        $slotId = trim((string) ($missing['slot_id'] ?? ''));
        $slot = $visualSlots[$slotId] ?? null;
        if ($slotId === '' || !is_array($slot)) article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'Missing slot is absent from VisualPlan.');
        $isHeroRecovery = (string) ($slot['role'] ?? '') === 'hero'
            && (string) ($missing['hero_recovery_policy'] ?? '') === 'source_backed_related_hero_v1'
            && !empty($missing['direct_exhaustion']['confirmed']);
        if (empty($slot['required']) || (!$isHeroRecovery && empty($slot['acceptable_related']))) continue;
        if (!$isHeroRecovery && (array) ($slot['search_queries_related'] ?? []) === []) continue;
        if (isset($missingIds[$slotId])) article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'Missing eligible slot is duplicated.');
        if ($requireCandidates && (array) ($missing['related_candidates'] ?? []) === []) {
            article_recovery_preflight_fail('recovery_no_supported_modules', 'No legal candidate exists for a recovery slot.');
        }
        $missingIds[$slotId] = true;
    }
    $classifications = (array) ($input['slot_classifications'] ?? []);
    if ($classifications !== []) {
        $classifiedIds = [];
        foreach ($classifications as $classification) {
            if (!is_array($classification)) article_recovery_preflight_fail('recovery_preflight_failed', 'Recovery slot classification is malformed.');
            $slotId = trim((string) ($classification['slot_id'] ?? ''));
            $state = (string) ($classification['state'] ?? '');
            if ($slotId === '' || isset($classifiedIds[$slotId])
                || !in_array($state, ['RECOVERABLE', 'UNRECOVERABLE', 'ALREADY_COMPLETE'], true)) {
                article_recovery_preflight_fail('recovery_preflight_failed', 'Recovery slot classification is malformed or duplicated.');
            }
            $classifiedIds[$slotId] = $state;
        }
        foreach ($missingIds as $slotId => $_) {
            if (($classifiedIds[$slotId] ?? '') !== 'RECOVERABLE') {
                article_recovery_preflight_fail('recovery_preflight_failed', 'Planner input includes a non-recoverable slot.');
            }
        }
    }
    if ($missingIds === []) {
        article_recovery_preflight_fail(
            $classifications === [] ? 'recovery_invalid_narrative_plan' : 'recovery_no_supported_modules',
            $classifications === [] ? 'No eligible missing slots.' : 'No recoverable slot has a legal candidate.'
        );
    }
    if (!$persisted) return;

    $postId = (int) ($input['post_id'] ?? 0);
    $topicId = (int) ($input['topic_id'] ?? 0);
    $post = $postId > 0 ? find_post($postId, true) : null;
    $topic = $topicId > 0 ? find_editorial_topic($topicId) : null;
    if (!is_array($post) || !is_array($topic)
        || ((int) ($topic['primary_post_id'] ?? 0) > 0 && (int) $topic['primary_post_id'] !== $postId)) {
        article_recovery_preflight_fail('recovery_preflight_failed', 'Post/topic identity mismatch.');
    }
    $draftId = (int) ($input['draft_version_id'] ?? 0);
    $lock = $draftId > 0 ? core_text_lock_state($draftId) : [];
    if (empty($lock['core_text_locked']) || !hash_equals((string) ($lock['core_hash'] ?? ''), (string) ($input['locked_core_hash'] ?? ''))) {
        article_recovery_preflight_fail('recovery_preflight_failed', 'Locked core hash changed before transport.');
    }
    $plan = find_narrative_plan_for_post($postId, $topicId);
    if (!is_array($plan) || (int) ($plan['id'] ?? 0) !== (int) ($input['narrative_plan_id'] ?? 0)
        || generation_json(article_image_effective_visual_plan($postId, $topicId, $plan)) !== generation_json($visual)
        || generation_json(json_decode((string) ($plan['expansion_modules_json'] ?? '[]'), true) ?: []) !== generation_json($modules)) {
        article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'Persisted NarrativePlan changed before transport.');
    }
    if (generation_json(article_image_approved_research_source_map($postId, $topicId)) !== generation_json($sourceMap)) {
        article_recovery_preflight_fail('recovery_missing_source_map', 'Approved source map changed before transport.');
    }
}

function article_recovery_protected_closure_calls(string $operationType): int
{
    // P06 preserves P07 + dedicated final hero Vision + P08/P09. P07 itself
    // preserves the final hero Vision + P08/P09; unused reservations cost zero.
    return match ($operationType) {
        'image_recovery' => 4,
        'image_recovery_replan' => 2,
        'additive_module' => 3,
        'layout_plan' => 1,
        default => 0,
    };
}

/** Second, operation-bound validation called by generation-service before claim/transport. */
function article_recovery_validate_generation_operation(array $operation): void
{
    $type = (string) ($operation['operation_type'] ?? '');
    if (!in_array($type, ['image_recovery', 'image_recovery_replan', 'additive_module'], true)) return;
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true);
    if (!is_array($input) || (int) ($operation['post_id'] ?? 0) !== (int) ($input['post_id'] ?? 0)
        || (int) ($operation['topic_id'] ?? 0) !== (int) ($input['topic_id'] ?? 0)) {
        article_recovery_preflight_fail('recovery_preflight_failed', 'Operation/input identity mismatch.');
    }
    $postId = (int) $input['post_id'];
    $protected = article_recovery_protected_closure_calls($type);
    $budget = gemini_article_budget_state($postId);
    $remaining = max(0, (int) ($budget['max_calls'] ?? GEMINI_ARTICLE_CALL_LIMIT) - (int) ($budget['used_calls'] ?? 0));
    if ($remaining <= $protected) {
        article_recovery_preflight_fail('recovery_insufficient_closure_budget', 'Recovery would consume the P08/P09 closure floor.');
    }
    if ($type === 'image_recovery') {
        article_image_shortage_recovery_preflight($input, true, true);
        return;
    }
    if ($type === 'image_recovery_replan') {
        // P08 replans direct-only slots as well as related-recovery slots.
        // Reusing the P06 preflight here made every direct-only missing slot
        // look ineligible before Gemini could revise its visual strategy.
        $draftId = (int) ($input['draft_version_id'] ?? 0);
        $lock = $draftId > 0 ? core_text_lock_state($draftId) : [];
        if (empty($lock['core_text_locked'])
            || !hash_equals((string) ($lock['core_hash'] ?? ''), (string) ($input['locked_core_hash'] ?? ''))) {
            article_recovery_preflight_fail('recovery_preflight_failed', 'Locked core hash changed before recovery replan transport.');
        }
        $plan = find_narrative_plan_for_post($postId, (int) $input['topic_id']);
        if (!is_array($plan) || (int) ($plan['id'] ?? 0) !== (int) ($input['narrative_plan_id'] ?? 0)
            || (array) ($input['missing_slots'] ?? []) === []) {
            article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'Recovery replan has no current missing slots or NarrativePlan.');
        }
        $currentCount = article_image_valid_recovery_replan_count($postId, (int) $input['topic_id']);
        if ($currentCount >= ARTICLE_IMAGE_RECOVERY_REPLAN_MAX_ATTEMPTS) {
            article_recovery_preflight_fail('recovery_replan_limit', 'Maximum current-contract visual recovery replans reached for this article.');
        }
        $policy = article_image_recovery_replan_retry_state(
            $postId,
            (int) $input['topic_id'],
            article_image_coverage_state($postId, (int) $input['topic_id']),
            $budget,
            !article_image_has_pending_recovery($postId)
        );
        if (empty($policy['eligible'])) article_recovery_preflight_fail('recovery_replan_not_eligible', 'Visual recovery replan no longer satisfies coverage or budget gates.');
        return;
    }

    $topicId = (int) $input['topic_id'];
    $post = find_post($postId, true);
    $topic = find_editorial_topic($topicId);
    if (!is_array($post) || !is_array($topic)
        || ((int) ($topic['primary_post_id'] ?? 0) > 0 && (int) $topic['primary_post_id'] !== $postId)) {
        article_recovery_preflight_fail('recovery_preflight_failed', 'Post/topic identity mismatch.');
    }
    $draftId = (int) ($input['draft_version_id'] ?? 0);
    $lock = $draftId > 0 ? core_text_lock_state($draftId) : [];
    if (empty($lock['core_text_locked']) || !hash_equals((string) ($lock['core_hash'] ?? ''), (string) ($input['locked_core_hash'] ?? ''))) {
        article_recovery_preflight_fail('recovery_preflight_failed', 'Locked core hash changed before additive transport.');
    }
    $plan = find_narrative_plan_for_post($postId, $topicId);
    if (!is_array($plan) || (int) ($plan['id'] ?? 0) !== (int) ($input['narrative_plan_id'] ?? 0)) {
        article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'Additive operation has no current persisted NarrativePlan.');
    }
    $visual = article_image_effective_visual_plan($postId, $topicId, $plan);
    $slot = null;
    foreach ([($visual['hero_slot'] ?? null), ...(array) ($visual['inline_slots'] ?? [])] as $candidate) {
        if (is_array($candidate) && (string) ($candidate['slot_id'] ?? '') === (string) ($input['target_slot_id'] ?? '')) $slot = $candidate;
    }
    $heroPolicy = (string) ($slot['role'] ?? '') === 'hero'
        && (string) ($input['recovery_policy'] ?? '') === 'source_backed_related_hero_v1';
    if (!is_array($slot) || (!$heroPolicy && empty($slot['acceptable_related']))) {
        article_recovery_preflight_fail('recovery_invalid_narrative_plan', 'Target slot does not permit related additive recovery.');
    }
    $module = null;
    foreach ((array) (json_decode((string) ($plan['expansion_modules_json'] ?? '[]'), true) ?: []) as $candidate) {
        if (is_array($candidate) && (string) ($candidate['module_id'] ?? '') === (string) ($input['module_id'] ?? '')) $module = $candidate;
    }
    $moduleClaims = array_values(array_unique(array_filter(array_map('strval', (array) ($module['source_claim_ids'] ?? [])))));
    $sourceMap = article_image_approved_research_source_map($postId, $topicId);
    $approvedClaims = array_fill_keys(article_recovery_source_claim_ids($sourceMap), true);
    if (!is_array($module) || $moduleClaims === []) article_recovery_preflight_fail('recovery_no_supported_modules', 'Selected expansion module is not source-backed.');
    foreach ($moduleClaims as $claimId) {
        if (!isset($approvedClaims[$claimId])) article_recovery_preflight_fail('recovery_no_supported_modules', 'Selected module claim is not approved.');
    }
    $verified = (array) ($input['verified_sources'] ?? []);
    $verifiedClaims = [];
    foreach ($verified as $source) foreach ((array) ($source['claim_ids'] ?? []) as $claimId) $verifiedClaims[(string) $claimId] = true;
    foreach ($moduleClaims as $claimId) {
        if (!isset($verifiedClaims[$claimId])) article_recovery_preflight_fail('recovery_missing_source_map', 'Additive input omitted a required approved claim.');
    }
    $imageStatement = bueno_database()->prepare('SELECT relationship FROM article_images WHERE id=:id AND post_id=:post');
    $imageStatement->execute([':id'=>(int) ($input['target_image_id'] ?? 0), ':post'=>$postId]);
    $relationship = (string) $imageStatement->fetchColumn();
    if ($relationship === '' || $relationship === 'exact_subject') article_recovery_preflight_fail('recovery_preflight_failed', 'Additive target is not a related image.');
}

/** Validate a planner result; this stage only authorizes recovery, never rewrites core text. */
function article_image_validate_shortage_recovery_plan(array $input, array $plan): array
{
    article_image_shortage_recovery_preflight($input);
    $slots = [];
    foreach ((array) ($input['missing_slots'] ?? []) as $slot) $slots[(string) ($slot['slot_id'] ?? '')] = $slot;
    $modules = [];
    foreach ((array) ($input['expansion_modules'] ?? []) as $module) $modules[(string) ($module['module_id'] ?? '')] = $module;
    $sourceMap = (array) ($input['research_source_map'] ?? []);
    if ($sourceMap === []) throw new RuntimeException('Recovery related image wymaga source mapy zatwierdzonego researchu.');
    $approved = [];
    $rejected = [];
    foreach ((array) ($plan['recoveries'] ?? []) as $recovery) {
        $slotId = (string) ($recovery['slot_id'] ?? '');
        $moduleId = (string) ($recovery['module_id'] ?? '');
        $candidate = (array) ($recovery['candidate'] ?? []);
        $reason = trim((string) ($recovery['editorial_reason'] ?? ''));
        $shortlisted = array_filter((array) (($slots[$slotId] ?? [])['related_candidates'] ?? []), static fn (array $item): bool =>
            (string) ($item['provider_id'] ?? '') === (string) ($candidate['provider_id'] ?? '')
            && (string) ($item['source_file_url'] ?? '') === (string) ($candidate['source_file_url'] ?? '')
        );
        $isHeroRecovery = (string) (($slots[$slotId] ?? [])['role'] ?? '') === 'hero';
        if ($isHeroRecovery && ((string) (($slots[$slotId] ?? [])['hero_recovery_policy'] ?? '') !== 'source_backed_related_hero_v1'
            || empty(($slots[$slotId] ?? [])['direct_exhaustion']['confirmed']))) {
            $rejected[] = ['slot_id' => $slotId, 'reason' => 'hero_recovery_not_eligible'];
            continue;
        }
        $allowedRelations = $isHeroRecovery
            ? ['mechanism', 'related_context']
            : ['mechanism', 'apparatus', 'analogy_scale', 'related_context'];
        if (!isset($slots[$slotId]) || !isset($modules[$moduleId]) || $reason === ''
            || trim((string) ($candidate['source_page_url'] ?? '')) === ''
            || trim((string) ($candidate['provider_id'] ?? '')) === ''
            || trim((string) ($candidate['source_file_url'] ?? '')) === ''
            || $shortlisted === []
            || !in_array((string) ($candidate['relationship'] ?? ''), $allowedRelations, true)) {
            $rejected[] = ['slot_id' => $slotId, 'reason' => 'missing_slot_module_or_source_support'];
            continue;
        }
        $approved[] = ['slot_id' => $slotId, 'module_id' => $moduleId,
            'placement' => (string) ($recovery['placement'] ?? ''), 'editorial_reason' => $reason,
            'candidate' => $candidate,
            'recovery_policy' => $isHeroRecovery ? 'source_backed_related_hero_v1' : 'source_backed_related_inline_v1'];
    }
    $heroMissing = array_filter($slots, static fn (array $slot): bool => (string) ($slot['role'] ?? '') === 'hero');
    foreach ((array) ($input['slot_classifications'] ?? []) as $classification) {
        if (is_array($classification) && (string) ($classification['role'] ?? '') === 'hero'
            && (string) ($classification['state'] ?? '') !== 'ALREADY_COMPLETE') {
            $heroMissing[(string) ($classification['slot_id'] ?? 'hero')] = $classification;
        }
    }
    $heroApproved = array_filter($approved, static fn (array $item) => (string) ($slots[$item['slot_id']]['role'] ?? '') === 'hero');
    return ['approved' => $approved, 'rejected' => $rejected,
        'manual_review_required' => $heroMissing !== [] && $heroApproved === [],
        'core_text_locked' => true];
}

/** One bounded Gemini planner response for P06; callers inject a transport in tests. */
function article_image_run_shortage_recovery_planner(int $postId, int $topicId, callable $transport): array
{
    $input = article_image_apply_shortage_recovery_slot_classification(article_image_shortage_recovery_input($postId, $topicId));
    article_image_shortage_recovery_preflight($input);
    $database = bueno_database();
    $claim = gemini_article_budget_claim(
        $database, $postId, 'image_recovery', 'images', 1,
        'recovery-' . $postId . '-' . bin2hex(random_bytes(8)),
        article_recovery_protected_closure_calls('image_recovery')
    );
    try {
        $response = $transport($input);
        gemini_article_budget_reconcile_claim($database, $postId, (string) ($claim['claim_token'] ?? ''), 'completed');
    } catch (Throwable $exception) {
        gemini_article_budget_reconcile_claim($database, $postId, (string) ($claim['claim_token'] ?? ''), 'released');
        throw $exception;
    }
    if (!is_array($response)) throw new RuntimeException('Recovery planner musi zwrócić obiekt decyzji.');
    return article_image_validate_shortage_recovery_plan($input, $response);
}

function article_image_recovery_planner_schema(array $input = []): array
{
    $slotIds = array_values(array_unique(array_filter(array_map(
        static fn (array $slot): string => trim((string) ($slot['slot_id'] ?? '')),
        (array) ($input['missing_slots'] ?? [])
    ))));
    $moduleIds = array_values(array_unique(array_filter(array_map(
        static fn (array $module): string => trim((string) ($module['module_id'] ?? '')),
        (array) ($input['expansion_modules'] ?? [])
    ))));
    $slotIdSchema = ['type'=>'string'];
    $moduleIdSchema = ['type'=>'string'];
    if ($slotIds !== []) $slotIdSchema['enum'] = $slotIds;
    if ($moduleIds !== []) $moduleIdSchema['enum'] = $moduleIds;
    return ['type'=>'object','properties'=>['recoveries'=>['type'=>'array','items'=>['type'=>'object','properties'=>[
        'slot_id'=>$slotIdSchema,'module_id'=>$moduleIdSchema,'placement'=>['type'=>'string'],'editorial_reason'=>['type'=>'string'],
        'candidate'=>['type'=>'object','properties'=>['provider_id'=>['type'=>'string'],'source_file_url'=>['type'=>'string'],'relationship'=>['type'=>'string'],'source_page_url'=>['type'=>'string'],'depicts_required_subject'=>['type'=>'boolean']],
            'required'=>['provider_id','source_file_url','relationship','source_page_url','depicts_required_subject'],'additionalProperties'=>false],
    ],'required'=>['slot_id','module_id','placement','editorial_reason','candidate'],'additionalProperties'=>false]],
    ],'required'=>['recoveries'],'additionalProperties'=>false];
}

/** Keep related recovery bounded and evidence-first before the Gemini planner sees candidates. */
function article_image_related_candidate_shortlist(array $slot, array $results, int $limit = 3): array
{
    $ranked = []; $seen = [];
    foreach ($results as $candidate) {
        if (!is_array($candidate) || !article_image_license_is_auto_safe((string) ($candidate['license'] ?? ''))) continue;
        $extension = strtolower(pathinfo((string) parse_url((string) ($candidate['source_file_url'] ?? ''), PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($extension !== '' && !in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
        $relation = (string) ($candidate['relationship'] ?? 'related_context');
        $allowedRelations = (string) ($slot['role'] ?? '') === 'hero'
            ? ['mechanism', 'related_context']
            : ['mechanism', 'apparatus', 'analogy_scale', 'related_context'];
        if (!in_array($relation, $allowedRelations, true)) continue;
        if (!source_image_candidate_is_suitable_for_role($candidate, ['role'=>(string)($slot['role'] ?? 'inline')])) continue;
        if (trim((string) ($candidate['source_page_url'] ?? '')) === '') continue;
        $key = trim((string) ($candidate['source_file_url'] ?? '')) ?: (string) ($candidate['provider'] ?? '') . ':' . (string) ($candidate['provider_id'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $planned = ['role'=>(string)($slot['role'] ?? 'inline'),'visual_intent'=>(string)($slot['visual_need'] ?? ''),
            'expected_content'=>(string)($slot['visual_need'] ?? ''),'search_queries'=>(array)($slot['search_queries_related'] ?? [])];
        $score = article_image_candidate_score($candidate, $planned, (string) ($candidate['chosen_query'] ?? ''), $relation);
        if ($score === PHP_INT_MIN) continue;
        $ranked[] = ['score'=>$score,'candidate'=>[...$candidate, 'relationship'=>$relation]];
    }
    usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_values(array_map(static fn (array $item): array => $item['candidate'], array_slice($ranked, 0, max(1, min(3, $limit)))));
}

/** Do not spend another recovery cycle on a candidate Vision already rejected for this slot. */
function article_image_candidate_has_completed_vision_reject(int $postId, string $slotId, array $candidate): bool
{
    $statement = bueno_database()->prepare(
        'SELECT provider_response_text FROM article_image_vision_audit
         WHERE post_id=:post AND slot_identifier=:slot AND status="completed"
           AND (candidate_identifier=:candidate OR source_file_identifier=:file OR source_page_identifier=:page)
         ORDER BY id DESC'
    );
    $statement->execute([
        ':post'=>$postId, ':slot'=>$slotId,
        ':candidate'=>(string) ($candidate['provider_id'] ?? ''),
        ':file'=>(string) ($candidate['source_file_url'] ?? ''),
        ':page'=>(string) ($candidate['source_page_url'] ?? ''),
    ]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $response) {
        try {
            $assessment = decode_generation_response((string) $response);
            if ((string) ($assessment['decision'] ?? '') === 'reject') return true;
        } catch (Throwable) {
        }
    }
    return false;
}

/** Reuse a recent auditable legal shortlist when an image provider is temporarily throttled. */
function article_image_prior_related_candidates(int $postId, int $topicId, string $slotId): array
{
    $statement = bueno_database()->prepare(
        'SELECT input_json FROM generation_operations
         WHERE post_id=:post AND topic_id=:topic AND operation_type="image_recovery"
         ORDER BY id DESC LIMIT 5'
    );
    $statement->execute([':post'=>$postId, ':topic'=>$topicId]);
    $collected = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $input = json_decode((string) $json, true);
        foreach ((array) ($input['missing_slots'] ?? []) as $missing) {
            if ((string) ($missing['slot_id'] ?? '') !== $slotId) continue;
            foreach ((array) ($missing['related_candidates'] ?? []) as $candidate) {
                if (!is_array($candidate)) continue;
                $key = (string) ($candidate['source_file_url'] ?? $candidate['provider_id'] ?? '');
                if ($key !== '') $collected[$key] = $candidate;
            }
        }
    }
    return array_values($collected);
}

function article_image_shortage_recovery_input_with_candidates(int $postId, int $topicId, callable $searcher): array
{
    $input = article_image_shortage_recovery_input($postId, $topicId);
    $plan = find_narrative_plan_for_post($postId, $topicId);
    $visual = is_array($plan) ? article_image_effective_visual_plan($postId, $topicId, $plan) : [];
    $slotDefinitions = [];
    foreach ([($visual['hero_slot'] ?? null), ...(array) ($visual['inline_slots'] ?? [])] as $slot) {
        if (is_array($slot)) $slotDefinitions[(string) ($slot['slot_id'] ?? '')] = $slot;
    }
    foreach ($input['missing_slots'] as &$missing) {
        $slot = $slotDefinitions[(string) ($missing['slot_id'] ?? '')] ?? null;
        $isHeroRecovery = is_array($slot)
            && (string) ($slot['role'] ?? '') === 'hero'
            && (string) ($missing['hero_recovery_policy'] ?? '') === 'source_backed_related_hero_v1'
            && !empty($missing['direct_exhaustion']['confirmed']);
        if (!is_array($slot) || (!$isHeroRecovery && empty($slot['acceptable_related']))) {
            $missing['related_candidates'] = [];
            continue;
        }
        $candidates = [];
        $queries = (array) ($slot['search_queries_related'] ?? []);
        if ($isHeroRecovery && $queries === []) {
            foreach ((array) ($input['expansion_modules'] ?? []) as $module) {
                $topic = trim((string) ($module['topic'] ?? ''));
                if ($topic !== '') $queries[] = $topic;
            }
        }
        foreach (array_values(array_unique($queries)) as $query) {
            $candidates = array_merge($candidates, (array) $searcher((string) $query));
        }
        $shortlist = article_image_related_candidate_shortlist($slot, $candidates);
        if ($shortlist === []) {
            $shortlist = article_image_related_candidate_shortlist(
                $slot,
                article_image_prior_related_candidates($postId, $topicId, (string) ($missing['slot_id'] ?? ''))
            );
        }
        $slotId = (string) ($missing['slot_id'] ?? '');
        $shortlist = array_values(array_filter($shortlist, static fn (array $candidate): bool =>
            !article_image_candidate_has_completed_vision_reject($postId, $slotId, $candidate)
        ));
        if ($shortlist === []) {
            $shortlist = array_values(array_filter(
                article_image_related_candidate_shortlist($slot, article_image_prior_related_candidates($postId, $topicId, $slotId)),
                static fn (array $candidate): bool => !article_image_candidate_has_completed_vision_reject($postId, $slotId, $candidate)
            ));
        }
        $missing['related_candidates'] = $shortlist;
    }
    unset($missing);
    return article_image_apply_shortage_recovery_slot_classification($input);
}

/** Persist planner intent through the standard operation audit trail. */
function prepare_article_image_recovery_operation(int $postId, int $topicId, callable $searcher): int
{
    $input = article_image_shortage_recovery_input_with_candidates($postId, $topicId, $searcher);
    return prepare_generation_operation(
        'image_recovery', $input,
        article_image_recovery_planner_schema($input), $postId, $topicId
    );
}

/**
 * Execute exactly one auditable recovery-planner operation. Candidate search stays
 * outside GeminiBudget; the generic operation path owns response counting.
 */
function article_image_execute_shortage_recovery(int $postId, int $topicId, callable $searcher, ?callable $transport = null): array
{
    $operationId = prepare_article_image_recovery_operation($postId, $topicId, $searcher);
    execute_generation_operation($operationId, $transport);
    return [...complete_article_image_recovery_operation($operationId), 'operation_id' => $operationId];
}

function complete_article_image_recovery_operation(int $operationId): array
{
    $operation = find_generation_operation($operationId);
    if (!is_array($operation) || (string) ($operation['operation_type'] ?? '') !== 'image_recovery') {
        throw new RuntimeException('Nie znaleziono operacji image_recovery.');
    }
    if ((string) ($operation['status'] ?? '') !== 'completed') {
        throw new RuntimeException('Recovery planner nie ma ukończonej decyzji.');
    }
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    $output = json_decode((string) ($operation['output_json'] ?? '{}'), true) ?: [];
    return article_image_validate_shortage_recovery_plan($input, $output);
}

/** Strict final evidence for the exceptional related-hero policy. */
function article_image_related_hero_assessment_is_strong(array $assessment): bool
{
    return in_array((string) ($assessment['relationship_level'] ?? ''), ['direct','broader_direct','strong_related','contextual_related','domain_related'], true)
        && !empty($assessment['contextual_useful'])
        && !empty($assessment['honest_caption_possible'])
        && empty($assessment['misleading'])
        && empty($assessment['inappropriate'])
        && (string) ($assessment['decision'] ?? '') === 'accept';
}

function article_image_related_hero_selected(array $candidate, array $planned): array
{
    $candidate = validate_source_image_candidate($candidate);
    if (!in_array((string) ($candidate['relationship'] ?? ''), ['mechanism', 'related_context'], true)
        || !source_image_candidate_is_suitable_for_role($candidate, ['role' => 'hero'])) {
        throw new RuntimeException('Related hero nie spełnia deterministycznych wymagań relacji i formatu hero.');
    }
    $manifest = validate_image_rights_manifest((array) ($candidate['rights_manifest'] ?? []));
    $manifest['topic_role'] = 'hero';
    $manifest = validate_image_rights_manifest($manifest);
    return array_merge($planned, $candidate, [
        'status' => 'selected',
        'rights_manifest' => $manifest,
        'multimodal_accepted' => 1,
        'multimodal_assessment' => [
            'related_supported' => false,
            'hero_recovery' => [
                'policy' => 'source_backed_related_hero_v1',
                'status' => 'context_pending',
                'direct_exhaustion' => (array) ($planned['direct_exhaustion'] ?? []),
            ],
        ],
    ]);
}

function article_image_finalize_related_hero(int $postId, int $imageId, int $blockId, array $assessment): void
{
    if (!article_image_related_hero_assessment_is_strong($assessment)) {
        throw new RuntimeException('Finalna ocena Vision nie potwierdziła silnego related hero.');
    }
    $statement = bueno_database()->prepare('SELECT * FROM article_images WHERE id=:id AND post_id=:post');
    $statement->execute([':id' => $imageId, ':post' => $postId]);
    $image = $statement->fetch();
    $assetPath = is_array($image) && trim((string) ($image['local_path'] ?? '')) !== ''
        ? app_path((string) $image['local_path']) : '';
    $actual = $assetPath !== '' && is_file($assetPath) ? @getimagesize($assetPath) : false;
    $actualMime = is_array($actual) ? strtolower((string) ($actual['mime'] ?? '')) : '';
    if (!is_array($image) || (string) ($image['role'] ?? '') !== 'hero'
        || !in_array((string) ($image['relationship'] ?? ''), ['mechanism', 'related_context'], true)
        || (string) ($image['status'] ?? '') !== 'downloaded'
        || !article_image_license_is_auto_safe((string) ($image['license'] ?? ''))
        || !is_array($actual) || !in_array($actualMime, ['image/jpeg', 'image/png', 'image/webp'], true)
        || (int) ($actual[0] ?? 0) < (int) app_config('source_image_min_width')
        || (int) ($actual[1] ?? 0) < (int) app_config('source_image_min_height')
        || ((int) ($actual[0] ?? 0) / max(1, (int) ($actual[1] ?? 0))) < 1.35) {
        throw new RuntimeException('Related hero nie ma prawidłowego lokalnego assetu, praw albo geometrii hero.');
    }
    $block = bueno_database()->prepare('SELECT source_claim_ids_json,status FROM article_related_context_blocks WHERE id=:id AND post_id=:post AND image_id=:image');
    $block->execute([':id' => $blockId, ':post' => $postId, ':image' => $imageId]);
    $block = $block->fetch();
    if (!is_array($block) || (string) ($block['status'] ?? '') !== 'approved'
        || (array) (json_decode((string) ($block['source_claim_ids_json'] ?? '[]'), true) ?: []) === []) {
        throw new RuntimeException('Related hero nie ma zatwierdzonego source-backed bloku P07.');
    }
    $stored = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true) ?: [];
    $stored['related_supported'] = true;
    $stored['hero_recovery'] = array_merge((array) ($stored['hero_recovery'] ?? []), [
        'policy' => 'source_backed_related_hero_v1',
        'status' => 'validated',
        'context_block_id' => $blockId,
        'final_vision' => $assessment,
    ]);
    bueno_database()->prepare('UPDATE article_images SET multimodal_assessment_json=:assessment,multimodal_accepted=1,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
        ->execute([':assessment' => generation_json($stored), ':id' => $imageId]);
    $caption = trim((string) ($assessment['suggested_caption'] ?? ''));
    if ($caption !== '') {
        bueno_database()->prepare('UPDATE article_images SET caption=:caption,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute([':caption'=>$caption, ':id'=>$imageId]);
    }
}

/** Resume an already downloaded/source-backed related hero at its sole missing gate. */
function article_image_resume_pending_related_hero(int $postId, int $topicId, ?callable $vision = null): array
{
    $statement = bueno_database()->prepare(
        'SELECT i.*,b.id context_block_id,b.heading context_heading,b.body context_body
         FROM article_images i INNER JOIN article_related_context_blocks b ON b.image_id=i.id AND b.post_id=i.post_id
         WHERE i.post_id=:post AND i.role="hero" AND i.status="downloaded" AND b.status="approved"
         ORDER BY i.id DESC,b.id DESC'
    );
    $statement->execute([':post'=>$postId]);
    $pending = null;
    foreach ($statement->fetchAll() as $row) {
        $assessment = json_decode((string) ($row['multimodal_assessment_json'] ?? '{}'), true) ?: [];
        if ((string) ($assessment['hero_recovery']['status'] ?? '') === 'context_pending') { $pending = $row; break; }
    }
    if (!is_array($pending)) return ['status'=>'not_pending'];
    $path = trim((string) ($pending['local_path'] ?? ''));
    $absolute = $path !== '' ? app_path($path) : '';
    if ($absolute === '' || !is_file($absolute)) return ['status'=>'pending_asset_missing','image_id'=>(int)$pending['id']];
    $visual = article_image_effective_visual_plan($postId, $topicId);
    $slot = (array) ($visual['hero_slot'] ?? []);
    $planned = [
        'slot_id'=>(string) ($slot['slot_id'] ?? 'hero-main'), 'role'=>'hero',
        'section_id'=>(string) ($slot['section_anchor'] ?? 'article'),
        'visual_intent'=>(string) ($slot['visual_need'] ?? $pending['visual_intent'] ?? ''),
        'expected_content'=>(string) ($slot['visual_need'] ?? $pending['expected_content'] ?? ''),
        'recovery_policy'=>'source_backed_related_hero_v1', 'vision_phase'=>'final_related_hero_validation',
        'relationship_policy'=>'ww_contextual_v1',
    ];
    $candidate = [...$pending, 'provider_id'=>(string) ($pending['provider_id'] ?? $pending['id'])];
    $post = find_post($postId, true) ?: [];
    $context = trim((string) (($post['title'] ?? '') . "\n" . ($post['content'] ?? '') . "\n"
        . ($pending['context_heading'] ?? '') . "\n" . ($pending['context_body'] ?? '')));
    $vision ??= static fn (array $candidate, array $planned, string $context): array =>
        article_image_gemini_vision_assess($postId, $candidate, $planned, $context, null,
            static fn () => ['status'=>200,'body'=>(string) file_get_contents($absolute)], null, 2);
    $assessment = $vision($candidate, $planned, $context);
    if (!article_image_related_hero_assessment_is_strong($assessment)) {
        $stored = json_decode((string) ($pending['multimodal_assessment_json'] ?? '{}'), true) ?: [];
        $stored['related_supported'] = false;
        $stored['hero_recovery'] = array_merge((array) ($stored['hero_recovery'] ?? []), [
            'policy'=>'source_backed_related_hero_v1', 'status'=>'final_rejected',
            'context_block_id'=>(int) $pending['context_block_id'], 'final_vision'=>$assessment,
            'reason'=>'Finalna ocena Vision nie potwierdziła silnego related hero.',
        ]);
        bueno_database()->prepare('UPDATE article_images SET multimodal_assessment_json=:assessment,multimodal_accepted=0,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
            ->execute([':assessment'=>generation_json($stored), ':id'=>(int)$pending['id']]);
        return ['status'=>'manual_review_required','image_id'=>(int)$pending['id'],
            'context_block_id'=>(int)$pending['context_block_id'],'reason'=>$stored['hero_recovery']['reason']];
    }
    article_image_finalize_related_hero($postId, (int) $pending['id'], (int) $pending['context_block_id'], $assessment);
    return ['status'=>'validated','image_id'=>(int)$pending['id'],'context_block_id'=>(int)$pending['context_block_id']];
}

/**
 * Apply an approved P06 decision. A related candidate is accepted only after the
 * normal Vision gate and is counted only after its bounded additive module passes.
 */
function article_image_apply_shortage_recovery(int $operationId, ?callable $downloader = null, ?callable $vision = null, ?callable $additiveTransport = null): array
{
    $operation = find_generation_operation($operationId);
    if (!is_array($operation) || (string) ($operation['operation_type'] ?? '') !== 'image_recovery') {
        throw new RuntimeException('Nie znaleziono operacji image_recovery do wykonania.');
    }
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true) ?: [];
    $decision = complete_article_image_recovery_operation($operationId);
    $postId = (int) ($operation['post_id'] ?? 0);
    $topicId = (int) ($operation['topic_id'] ?? 0);
    $missing = [];
    foreach ((array) ($input['missing_slots'] ?? []) as $slot) $missing[(string) ($slot['slot_id'] ?? '')] = $slot;
    $applied = [];
    $rejected = (array) ($decision['rejected'] ?? []);
    $post = find_post($postId);
    $context = trim((string) (($post['title'] ?? '') . "\n" . ($post['excerpt'] ?? '') . "\n" . mb_substr(strip_tags((string) ($post['content'] ?? '')), 0, 6000)));
    $vision ??= static fn (array $candidate, array $plannedImage, string $articleContext): array =>
        article_image_gemini_vision_assess($postId, $candidate, $plannedImage, $articleContext, null, null, null, 2);
    foreach ((array) ($decision['approved'] ?? []) as $recovery) {
        $slotId = (string) ($recovery['slot_id'] ?? '');
        $slot = (array) ($missing[$slotId] ?? []);
        $candidate = null;
        foreach ((array) ($slot['related_candidates'] ?? []) as $item) {
            if ((string) ($item['provider_id'] ?? '') === (string) (($recovery['candidate'] ?? [])['provider_id'] ?? '')
                && (string) ($item['source_file_url'] ?? '') === (string) (($recovery['candidate'] ?? [])['source_file_url'] ?? '')) {
                $candidate = $item;
                break;
            }
        }
        if (!is_array($candidate)) {
            $rejected[] = ['slot_id' => $slotId, 'reason' => 'approved_candidate_not_persisted'];
            continue;
        }
        $imageStatement = bueno_database()->prepare('SELECT * FROM article_images WHERE post_id=:post AND role=:role AND section_id=:section');
        $imageStatement->execute([':post' => $postId, ':role' => (string) ($slot['role'] ?? 'inline'), ':section' => (string) ($slot['section_anchor'] ?? '')]);
        $existing = $imageStatement->fetch();
        if (!is_array($existing)) {
            $rejected[] = ['slot_id' => $slotId, 'reason' => 'planned_slot_not_persisted'];
            continue;
        }
        $planned = ['role'=>(string)$existing['role'], 'section_id'=>(string)$existing['section_id'], 'slot_id'=>$slotId,
            'visual_intent'=>(string)$existing['visual_intent'], 'expected_content'=>(string)$existing['expected_content'],
            'search_queries'=>json_decode((string)$existing['search_queries_json'], true) ?: [], 'alt'=>(string)$existing['alt'],
            'caption'=>(string)$existing['caption'], 'layout'=>(string)$existing['layout'], 'status'=>'planned',
            // Recovery reconstructs the original provider-owned plan. Source
            // evidence is still empty here and may only be copied from the
            // shortlisted legal candidate after the plan validator passes.
            'source_page_url'=>'', 'source_file_url'=>'', 'local_path'=>'', 'author'=>'',
            'license'=>'', 'license_url'=>'', 'attribution'=>''];
        try {
            $isHeroRecovery = (string) ($slot['role'] ?? '') === 'hero'
                && (string) ($recovery['recovery_policy'] ?? '') === 'source_backed_related_hero_v1';
            $allowsContextualRecovery = !$isHeroRecovery
                && str_starts_with((string) ($recovery['recovery_policy'] ?? ''), 'source_backed_related_');
            $selected = $isHeroRecovery
                ? article_image_related_hero_selected($candidate, [...$planned,
                    'direct_exhaustion'=>(array) ($slot['direct_exhaustion'] ?? []),
                ])
                : select_source_image_from_results(
                    $planned,
                    [$candidate],
                    (string) $candidate['provider_id'],
                    $vision,
                    $context,
                    $allowsContextualRecovery
                );
            if ($allowsContextualRecovery) {
                $selected['multimodal_assessment']['contextual_policy'] = 'ww_contextual_v1';
            }
            $selected = article_image_honest_copy($selected, (string) $candidate['relationship'], $candidate);
            article_image_assert_selected_diversity($postId, $selected);
            $downloaded = ($downloader ?? static fn (array $image): array => download_source_image($image))($selected);
            $imageId = persist_article_image($postId, $downloaded, 'recovery:' . $slotId);
            $moduleOperation = prepare_article_additive_module_operation(
                $postId, $topicId, $imageId, $slotId, (string) $recovery['module_id'],
                (string) $recovery['placement'], (string) ($recovery['recovery_policy'] ?? '')
            );
            execute_generation_operation($moduleOperation, $additiveTransport);
            $blockId = complete_article_additive_module_operation($moduleOperation);
            if ($isHeroRecovery) {
                $finalAssessment = $vision($downloaded, [...$planned,
                    'recovery_policy'=>'source_backed_related_hero_v1',
                    'vision_phase'=>'final_post_context_validation',
                    'relationship_policy'=>'ww_contextual_v1',
                ], $context . "\nSource-backed context block #" . $blockId);
                $finalAssessment['hero_fit'] = (int) ($finalAssessment['hero_fit'] ?? $finalAssessment['editorial_fit'] ?? -1);
                article_image_finalize_related_hero($postId, $imageId, $blockId, $finalAssessment);
            }
            $applied[] = ['slot_id'=>$slotId, 'image_id'=>$imageId, 'context_block_id'=>$blockId, 'module_operation_id'=>$moduleOperation];
        } catch (Throwable $exception) {
            if (isset($imageId) && (int) $imageId > 0 && (string) ($slot['role'] ?? '') === 'hero') {
                bueno_database()->prepare('UPDATE article_images SET multimodal_accepted=0,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                    ->execute([':id' => (int) $imageId]);
            }
            $diversity = $exception instanceof ArticleImageSemanticDuplicateException ? $exception->diversity : null;
            $rejected[] = ['slot_id' => $slotId, 'reason' => $diversity['code'] ?? 'recovery_apply_failed',
                'diversity'=>$diversity, 'error' => mb_substr($exception->getMessage(), 0, 500)];
        }
    }
    refresh_article_image_rendering($postId);
    $coverage = article_image_coverage_state($postId, $topicId);
    return ['operation_id'=>$operationId, 'applied'=>$applied, 'rejected'=>$rejected, 'coverage'=>$coverage,
        'manual_review_required'=>!empty($decision['manual_review_required']) || empty($coverage['hero_is_allowed'])];
}

function validate_article_blocks(array $blocks): void
{
    $allowed = ['heading', 'paragraph', 'list', 'quote', 'section', 'illustration', 'gallery'];
    foreach ($blocks as $index => $block) {
        if (!is_array($block) || !in_array((string) ($block['type'] ?? ''), $allowed, true)) {
            throw new InvalidArgumentException("Blok artykułu #{$index} ma niedozwolony typ.");
        }
        $type = (string) $block['type'];
        if (in_array($type, ['heading', 'paragraph', 'quote'], true)
            && trim((string) ($block['text'] ?? '')) === '') {
            throw new InvalidArgumentException("Blok {$type} wymaga tekstu.");
        }
        if ($type === 'heading' && !in_array((int) ($block['level'] ?? 2), [2, 3], true)) {
            throw new InvalidArgumentException('Nagłówek może mieć wyłącznie poziom 2 lub 3.');
        }
        if ($type === 'list' && (array) ($block['items'] ?? []) === []) {
            throw new InvalidArgumentException('Lista wymaga elementów.');
        }
        if ($type === 'section') {
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', (string) ($block['id'] ?? '')) !== 1) {
                throw new InvalidArgumentException('Sekcja wymaga bezpiecznego identyfikatora.');
            }
            if (!in_array((string) ($block['variant'] ?? 'default'), ARTICLE_BLOCK_SECTION_VARIANTS, true)) {
                throw new InvalidArgumentException('Sekcja ma niedozwolony wariant wizualny.');
            }
            validate_article_blocks((array) ($block['blocks'] ?? []));
        }
        if ($type === 'illustration' && (int) ($block['image_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Blok ilustracji wymaga identyfikatora obrazu.');
        }
        if ($type === 'gallery' && (array) ($block['image_ids'] ?? []) === []) {
            throw new InvalidArgumentException('Galeria wymaga obrazów.');
        }
    }
}

function article_image_detail_inline_description(array $image): string
{
    $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true);
    if (!is_array($assessment)) $assessment = [];
    $visionCaption = trim((string) ($assessment['suggested_caption'] ?? ''));
    $visionAccepted = (string) ($assessment['decision'] ?? '') === 'accept'
        && ($assessment['honest_caption_possible'] ?? true) !== false;
    $candidates = $visionAccepted ? [$visionCaption] : [];
    $candidates = array_merge($candidates, [
        trim((string) ($image['caption'] ?? '')),
        trim((string) ($image['alt'] ?? '')),
        trim((string) ($assessment['expected_content'] ?? '')),
        trim((string) ($image['visual_intent'] ?? '')),
    ]);
    foreach ($candidates as $candidate) {
        $normalized = mb_strtolower(trim($candidate));
        if ($normalized === ''
            || str_starts_with($normalized, 'szczegółowa ilustracja uzupełniająca tekst artykułu')) {
            continue;
        }
        return $candidate;
    }

    $descriptor = mb_strtolower(implode(' ', [
        (string) ($image['caption'] ?? ''),
        (string) ($image['alt'] ?? ''),
        (string) ($image['visual_intent'] ?? ''),
        (string) ($image['source_page_url'] ?? ''),
    ]));
    $isAtomicOrbitals = preg_match('/atomic[ _-]?orbitals?|orbitale? atomow|orbital/i', $descriptor) === 1;
    if ($isAtomicOrbitals) {
        return 'Wizualizacja orbitali atomowych i stanów elektronowych. Pomaga zobaczyć, jak złożone są oddziaływania elektronów w materiałach kwantowych, o których mowa w sąsiednim akapicie. Nie przedstawia bezpośrednio opisywanego eksperymentu; pokazuje poziom złożoności modelowania wykorzystywanego w badaniach tych materiałów.';
    }

    $relationship = (string) ($image['relationship'] ?? 'exact_subject');
    $purpose = match ($relationship) {
        'related_context' => 'Nie przedstawia bezpośrednio opisywanego wydarzenia, lecz daje kontekst do wyjaśnienia zawartego w sąsiednim akapicie.',
        'mechanism' => 'Pomaga prześledzić mechanizm omawiany w sąsiednim akapicie.',
        'analogy_scale' => 'Pomaga czytelnikowi uchwycić skalę lub analogię omawianą w sąsiednim akapicie.',
        default => 'Pomaga czytelnikowi odnieść szczegóły widoczne na grafice do wyjaśnienia w sąsiednim akapicie.',
    };
    return 'Szczegółowa ilustracja uzupełniająca tekst artykułu. ' . $purpose;
}

function article_image_presentation_layout(array $image): array
{
    $requested = in_array((string) ($image['layout'] ?? ''), ARTICLE_IMAGE_LAYOUTS, true)
        ? (string) $image['layout']
        : 'full';
    $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true);
    if (!is_array($assessment)) $assessment = [];
    $containsText = (bool) ($assessment['contains_readable_text'] ?? false);
    $density = strtolower(trim((string) ($assessment['detail_density'] ?? 'high')));
    $type = strtolower(trim((string) ($assessment['visual_type'] ?? 'other')));
    $width = max(1, (int) ($image['width'] ?? 1));
    $height = max(1, (int) ($image['height'] ?? 1));
    $isTall = ($height / $width) >= 1.25;
    $hasTransparency = article_image_has_transparency($image);
    $detailInline = $isTall
        || $hasTransparency
        || $containsText
        || in_array($type, ['diagram', 'chart', 'illustration'], true)
        || ($density === 'high' && $type !== 'photo');
    $sideSafe = ($assessment['safe_for_side_layout'] ?? false) === true
        && !$containsText
        && in_array($type, ['photo', 'illustration'], true)
        && in_array($density, ['low', 'medium'], true);
    $sideRequested = in_array($requested, ['left', 'right'], true);

    return [
        'requested_layout' => $requested,
        'effective_layout' => $detailInline ? 'detail-inline' : ($sideRequested && !$sideSafe ? 'full' : $requested),
        'side_layout_allowed' => $detailInline || ($sideRequested && $sideSafe),
        'side_layout' => $detailInline ? ($requested === 'left' ? 'left' : 'right') : $requested,
        'overridden' => $sideRequested && !$sideSafe,
        'detail_inline' => $detailInline,
        'requires_neutral_media_card' => $detailInline && $hasTransparency,
        'has_transparency' => $hasTransparency,
        'is_tall' => $isTall,
        'contains_readable_text' => $containsText,
        'detail_density' => $density,
        'visual_type' => $type,
    ];
}

/** Contextual artwork can satisfy coverage without pretending to be a direct
 * opening visual. Present it once, after the first substantive section. */
function article_contextual_hero_should_be_inline(array $image): bool
{
    if ((string) ($image['role'] ?? '') !== 'hero') return false;
    return article_image_is_contextual_illustration($image);
}

function article_image_is_contextual_illustration(array $image): bool
{
    $assessment = json_decode((string) ($image['multimodal_assessment_json'] ?? '{}'), true);
    if (!is_array($assessment)) return false;
    $visualType = strtolower(trim((string) ($assessment['visual_type'] ?? '')));
    $relationship = strtolower(trim((string) ($assessment['relationship_level'] ?? '')));
    return $visualType === 'illustration'
        && in_array($relationship, ['strong_related', 'contextual_related', 'domain_related'], true);
}

function render_article_image_record(array $image, bool $hero = false): string
{
    if ((int) ($image['is_fallback'] ?? 0) === 1 || (int) ($image['editorial_rejected'] ?? 0) === 1) {
        return '';
    }
    if (($image['status'] ?? '') !== 'downloaded' || !article_image_license_is_auto_safe((string) ($image['license'] ?? ''))) {
        $section = htmlspecialchars((string) ($image['section_id'] ?? 'ilustracja'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = htmlspecialchars((string) ($image['caption'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = (string) ($image['status'] ?? 'missing');
        $message = $status === 'manual_review' || (($image['status'] ?? '') === 'downloaded')
            ? 'Kandydat wymaga weryfikacji źródła lub licencji i nie jest dopuszczony do publikacji.'
            : 'Brak zweryfikowanej grafiki — miejsce zachowano w kompozycji.';
        return '<figure class="article-illustration article-illustration--placeholder" data-image-status="'
            . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . '<div class="article-image-placeholder" role="img" aria-label="Placeholder ilustracji: ' . $section . '">'
            . '<strong>Ilustracja wymaga uwagi</strong><span>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></div>'
            . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
    }
    $path = ltrim(str_replace('\\', '/', (string) ($image['local_path'] ?? '')), '/');
    if ($path === '' || !is_file(app_path($path))) {
        return '';
    }
    $presentation = article_image_presentation_layout($image);
    $layout = (string) $presentation['effective_layout'];
    $alt = htmlspecialchars((string) $image['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $caption = htmlspecialchars((string) $image['caption'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attribution = htmlspecialchars((string) $image['attribution'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $source = htmlspecialchars((string) $image['source_page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $license = htmlspecialchars((string) $image['license'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $licenseUrl = htmlspecialchars((string) $image['license_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $contextNote = htmlspecialchars(article_image_context_note($image), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $detailInline = !empty($presentation['detail_inline']);
    $editorialDescription = $detailInline
        ? htmlspecialchars(article_image_detail_inline_description($image), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : $caption;
    $loading = $hero ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"';
    $responsive = article_image_responsive_attributes(
        $path,
        max(1, (int) ($image['width'] ?? 1)),
        $layout
    );
    $html = '<figure class="article-illustration article-illustration--' . $layout
        . ($hero ? ' article-illustration--hero' : '')
        . (!empty($presentation['overridden']) ? ' article-illustration--side-overridden' : '')
        . (!empty($presentation['has_transparency']) ? ' article-illustration--transparent' : '')
        . ($detailInline ? ' article-illustration--detail-inline' : '')
        . (!empty($presentation['is_tall']) ? ' article-illustration--portrait' : '') . '"'
        . (!empty($presentation['overridden']) ? ' data-requested-layout="' . htmlspecialchars((string) $presentation['requested_layout'], ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
    $imageTag = '<img src="../' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt
        . '" width="' . max(1, (int) ($image['width'] ?? 1))
        . '" height="' . max(1, (int) ($image['height'] ?? 1))
        . '" decoding="async"' . $responsive . $loading . '>';
    $zoomLabel = htmlspecialchars('Powiększ ilustrację: ' . (string) ($image['alt'] ?? 'grafika artykułu'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html .= '<div class="article-image-media-card' . (!empty($presentation['requires_neutral_media_card']) ? ' article-image-media-card--neutral' : '') . (!empty($presentation['is_tall']) ? ' article-image-media-card--portrait' : '') . '"><a class="article-image-zoom" href="../' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" data-article-detail-zoom data-article-zoom-caption="' . $editorialDescription . '" aria-label="' . $zoomLabel . '">' . $imageTag . '<span class="article-image-zoom__bar">Kliknij, aby powiększyć</span></a></div>';
    if ($detailInline) {
        $html .= '<figcaption><p class="article-image-description">' . $editorialDescription . '</p>';
        if ($contextNote !== '') $html .= '<p class="article-image-disclosure">' . $contextNote . '</p>';
        if ($attribution !== '') {
            $html .= '<details class="article-image-meta"><summary>Źródło i licencja</summary><p class="article-image-credit">' . $attribution;
            if ($source !== '') $html .= ' · <a href="' . $source . '" rel="noopener noreferrer">źródło</a>';
            if ($license !== '') $html .= ' · ' . ($licenseUrl !== '' ? '<a href="' . $licenseUrl . '" rel="license noopener noreferrer">' . $license . '</a>' : $license);
            $html .= '</p></details>';
        }
        $html .= '</figcaption>';
    } elseif ($caption !== '' || $contextNote !== '' || $attribution !== '') {
        $html .= '<figcaption>' . ($caption !== '' ? '<span class="article-image-caption">' . $caption . '</span>' : '');
        if ($contextNote !== '') {
            $html .= '<small class="article-image-context-note">' . $contextNote . '</small>';
        }
        if ($attribution !== '') {
            $html .= '<details class="article-image-meta"><summary>Źródło i licencja</summary><p class="article-image-credit">' . $attribution;
            if ($source !== '') {
                $html .= ' · <a href="' . $source . '" rel="noopener noreferrer">źródło</a>';
            }
            if ($license !== '') {
                $html .= ' · ' . ($licenseUrl !== ''
                    ? '<a href="' . $licenseUrl . '" rel="license noopener noreferrer">' . $license . '</a>'
                    : $license);
            }
            $html .= '</p></details>';
        }
        $html .= '</figcaption>';
    }

    return $html . '</figure>';
}

function article_image_variant_path(string $path, int $width): string
{
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    $suffixLength = $extension === '' ? 0 : strlen($extension) + 1;
    $stem = $suffixLength > 0 ? substr($path, 0, -$suffixLength) : $path;
    return $stem . '-' . $width . '.webp';
}

function create_article_image_variants(string $absolutePath): void
{
    if (!is_file($absolutePath)) return;
    $info = @getimagesize($absolutePath);
    $sourceWidth = max(0, (int) ($info[0] ?? 0));
    $sourceHeight = max(0, (int) ($info[1] ?? 0));
    if ($sourceWidth === 0 || $sourceHeight === 0) return;
    $canUseGd = function_exists('imagecreatefromstring') && function_exists('imagewebp');
    $source = false;
    if ($canUseGd) {
        $bytes = @file_get_contents($absolutePath);
        $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        $canUseGd = $source !== false;
    }
    $python = trim((string) app_config('image_processor_python'));

    foreach ([768, 1280] as $targetWidth) {
        if ($sourceWidth <= $targetWidth) continue;
        $targetPath = article_image_variant_path($absolutePath, $targetWidth);
        if (is_file($targetPath)) continue;
        if (!$canUseGd) {
            if ($python === '' || !is_file($python)) continue;
            $process = proc_open(
                [$python, app_path('scripts/process-responsive-image.py'), $absolutePath, $targetPath, (string) $targetWidth, '82'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                app_project_root(),
                null,
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) continue;
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            continue;
        }
        $targetHeight = max(1, (int) round($sourceHeight * $targetWidth / $sourceWidth));
        $target = imagescale($source, $targetWidth, $targetHeight, IMG_BICUBIC_FIXED);
        if ($target === false) continue;
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $temporary = $targetPath . '.tmp-' . bin2hex(random_bytes(4));
        try {
            if (@imagewebp($target, $temporary, 82)) @rename($temporary, $targetPath);
        } finally {
            imagedestroy($target);
            if (is_file($temporary)) @unlink($temporary);
        }
    }
    if ($source !== false) imagedestroy($source);
}

function article_image_responsive_attributes(string $relativePath, int $width, string $layout): string
{
    $candidates = [];
    foreach ([768, 1280] as $candidateWidth) {
        $candidatePath = article_image_variant_path($relativePath, $candidateWidth);
        if ($candidateWidth < $width && is_file(app_path($candidatePath))) {
            $candidates[] = '../' . htmlspecialchars($candidatePath, ENT_QUOTES, 'UTF-8') . ' ' . $candidateWidth . 'w';
        }
    }
    $candidates[] = '../' . htmlspecialchars($relativePath, ENT_QUOTES, 'UTF-8') . ' ' . $width . 'w';
    $sizes = in_array($layout, ['left', 'right', 'detail-inline'], true)
        ? '(max-width: 980px) 100vw, 28rem'
        : '(max-width: 980px) 100vw, 58rem';
    return ' srcset="' . implode(', ', $candidates) . '" sizes="' . $sizes . '"';
}

const ARTICLE_LAYOUT_FAMILIES = ['standard', 'visual_story', 'deep_dive', 'context_heavy'];

function article_safe_layout_plan(): array
{
    return ['template_family'=>'standard','hero_style'=>'full','section_layouts'=>[],'image_placements'=>[],'context_block_placements'=>[],'callouts'=>[],'text_presentation'=>[],'reading_rhythm'=>'balanced','caption_strategy'=>'standard'];
}

function validate_article_layout_plan(array $plan): void
{
    if (!in_array((string)($plan['template_family'] ?? ''), ARTICLE_LAYOUT_FAMILIES, true)) throw new InvalidArgumentException('Niedozwolona rodzina LayoutPlan.');
    $allowed = ['hero_style'=>['full','immersive','compact'], 'reading_rhythm'=>['balanced','compact','spacious'], 'caption_strategy'=>['standard','inline','minimal']];
    foreach ($allowed as $field => $values) if (!in_array((string)($plan[$field] ?? ''), $values, true)) throw new InvalidArgumentException('LayoutPlan ma niedozwolone pole: '.$field);
    $itemRules = [
        'section_layouts'=>['section_id','layout',['standard','feature','split','compact']],
        'image_placements'=>['image_id','placement',['before_section','after_section','inline']],
        'context_block_placements'=>['slot_id','placement',['after_image','after_section']],
        'callouts'=>['section_id','type',['fact','quote','takeaway']],
    ];
    foreach ($itemRules as $field => [$key, $value, $values]) foreach ((array)($plan[$field] ?? null) as $item) {
        if (!is_array($item) || trim((string)($item[$key] ?? '')) === '' || !in_array((string)($item[$value] ?? ''), $values, true)) throw new InvalidArgumentException('LayoutPlan ma nieprawidłowy element: '.$field);
    }
    foreach (array_keys($itemRules) as $field) if (!is_array($plan[$field] ?? null)) throw new InvalidArgumentException('LayoutPlan ma nieprawidłową listę: '.$field);
    if (isset($plan['text_presentation']) && !is_array($plan['text_presentation'])) throw new InvalidArgumentException('LayoutPlan ma nieprawidłowe text_presentation.');
    foreach ((array) ($plan['text_presentation'] ?? []) as $presentation) {
        if (!is_array($presentation) || trim((string) ($presentation['section_id'] ?? '')) === '') throw new InvalidArgumentException('Text presentation wymaga section_id.');
        $breaks = (array) ($presentation['paragraph_break_after_sentences'] ?? null);
        if (!is_array($presentation['paragraph_break_after_sentences'] ?? null)
            || array_filter($breaks, static fn (mixed $value): bool => !is_int($value) || $value < 1) !== []) throw new InvalidArgumentException('Text presentation ma nieprawidłowe granice akapitów.');
        if (!is_array($presentation['emphasis_phrases'] ?? null) || !is_array($presentation['list_groups'] ?? null)) throw new InvalidArgumentException('Text presentation ma nieprawidłowe listy prezentacyjne.');
        foreach ($presentation['list_groups'] as $group) if (!is_array($group) || count((array) ($group['items'] ?? [])) < 2) throw new InvalidArgumentException('Text presentation ma nieprawidłową grupę listy.');
    }
}

function article_layout_plan_or_default(array $plan, array &$audit = []): array
{
    try { validate_article_layout_plan($plan); $plan['text_presentation'] = array_values((array) ($plan['text_presentation'] ?? [])); return $plan; }
    catch (Throwable $exception) { $audit[] = ['code'=>'invalid_layout_plan','reason'=>$exception->getMessage()]; return article_safe_layout_plan(); }
}

function article_layout_plan_schema(): array
{
    $string = ['type' => 'string'];
    $items = static fn (array $properties): array => ['type'=>'array','items'=>['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false]];
    return ['type' => 'object', 'properties' => [
        'template_family' => ['type' => 'string', 'enum' => ARTICLE_LAYOUT_FAMILIES],
        'hero_style' => ['type'=>'string','enum'=>['full','immersive','compact']],
        'section_layouts' => $items(['section_id'=>$string,'layout'=>['type'=>'string','enum'=>['standard','feature','split','compact']]]),
        'image_placements' => $items(['image_id'=>['type'=>'integer'],'placement'=>['type'=>'string','enum'=>['before_section','after_section','inline']]]),
        'context_block_placements' => $items(['slot_id'=>$string,'placement'=>['type'=>'string','enum'=>['after_image','after_section']]]),
        'callouts' => $items(['section_id'=>$string,'type'=>['type'=>'string','enum'=>['fact','quote','takeaway']]]),
        'text_presentation' => ['type'=>'array','maxItems'=>16,'items'=>['type'=>'object','properties'=>[
            'section_id'=>['type'=>'string','minLength'=>1,'maxLength'=>81],
            'paragraph_break_after_sentences'=>['type'=>'array','items'=>['type'=>'integer','minimum'=>1],'maxItems'=>30],
            'emphasis_phrases'=>['type'=>'array','items'=>['type'=>'string','minLength'=>3,'maxLength'=>120],'maxItems'=>24],
            'list_groups'=>['type'=>'array','items'=>['type'=>'object','properties'=>[
                'items'=>['type'=>'array','items'=>['type'=>'string','minLength'=>2,'maxLength'=>500],'minItems'=>2,'maxItems'=>8],
            ],'required'=>['items'],'additionalProperties'=>false],'maxItems'=>4],
        ],'required'=>['section_id','paragraph_break_after_sentences','emphasis_phrases','list_groups'],'additionalProperties'=>false]],
        'reading_rhythm' => ['type'=>'string','enum'=>['balanced','compact','spacious']], 'caption_strategy' => ['type'=>'string','enum'=>['standard','inline','minimal']],
    ], 'required' => ['template_family','hero_style','section_layouts','image_placements','context_block_placements','callouts','text_presentation','reading_rhythm','caption_strategy'], 'additionalProperties' => false];
}

/** Gemini receives only structured layout choices; PHP owns the actual markup. */
function prepare_article_layout_plan_operation(int $postId, int $topicId): int
{
    $draft = bueno_database()->prepare('SELECT id FROM article_draft_versions WHERE post_id=:post AND is_active=1 ORDER BY id DESC LIMIT 1');
    $draft->execute([':post' => $postId]);
    $draftId = (int) $draft->fetchColumn();
    if ($draftId <= 0 || !function_exists('core_text_lock_state') || empty(core_text_lock_state($draftId)['core_text_locked'])) {
        throw new RuntimeException('LayoutPlan wymaga zaakceptowanego locked core textu.');
    }
    $images = array_map(static fn (array $image): array => ['id'=>(int)$image['id'], 'role'=>(string)$image['role'], 'section_id'=>(string)$image['section_id'], 'relationship'=>(string)$image['relationship'], 'visual_intent'=>(string)$image['visual_intent']], article_image_required_records($postId, $topicId));
    $plan = find_narrative_plan_for_post($postId, $topicId);
    $selection = is_array($plan) ? narrative_plan_editorial_payload($plan) : [];
    $visualPlan = is_array($plan) ? article_image_effective_visual_plan($postId, $topicId, $plan) : [];
    $draftRow = find_article_draft_by_id($draftId);
    $draftJson = is_array($draftRow) ? (json_decode((string) ($draftRow['draft_json'] ?? '{}'), true) ?: []) : [];
    return prepare_generation_operation('layout_plan', ['post_id'=>$postId, 'topic_id'=>$topicId, 'workflow_version'=>2,
        'allowed_template_families'=>ARTICLE_LAYOUT_FAMILIES, 'narrative_selection'=>$selection,
        'sections'=>$selection['sections'] ?? [], 'locked_text_sections'=>array_values(array_map(static fn (array $section): array => [
            'section_id'=>(string) ($section['section_id'] ?? ''), 'heading'=>(string) ($section['heading'] ?? ''), 'body'=>(string) ($section['body'] ?? ''),
        ], (array) ($draftJson['sections'] ?? []))), 'visual_plan'=>$visualPlan, 'images'=>$images,
        'article_length'=>article_draft_main_content_length($draftJson),
        'visual_floor'=>editorial_v2_required_image_count(article_draft_main_content_length($draftJson)),
        'available_images'=>$images, 'narrative_rhythm'=>$selection['reader_journey'] ?? '',
        'instruction'=>'Compose the dynamic sections using the allowlisted family and section layouts. Control image, callout, comparison and curiosity placement; distribute inline images through the article, preferably every 1500–2500 characters. In text_presentation use only exact locked text: place paragraph breaks only after sentence numbers, select exact 2–7 word emphasis phrases (0–2 per paragraph), and create list groups only from exact consecutive sentences that form a natural parallel set. Do not rewrite, summarize, remove, reorder or add any wording. Never create a card wall or arbitrary HTML/CSS, and preserve NarrativePlan section order and text.'], article_layout_plan_schema(), $postId, $topicId);
}

function article_layout_plan_for_post(int $postId, array &$audit = []): array
{
    $statement = bueno_database()->prepare('SELECT output_json FROM generation_operations WHERE post_id=:post AND operation_type="layout_plan" AND status="completed" ORDER BY completed_at DESC,id DESC LIMIT 1');
    $statement->execute([':post' => $postId]);
    $plan = json_decode((string) $statement->fetchColumn(), true);
    return article_layout_plan_or_default(is_array($plan) ? $plan : [], $audit);
}

/** A completed allowlisted layout can be reused after a missing planned row is
 * filled when it already places every stable planned inline image id. */
function article_layout_reusable_operation_id(int $postId): ?int
{
    $requiredInline = [];
    foreach (article_image_required_records($postId) as $image) {
        if ((string) ($image['role'] ?? '') !== 'inline') continue;
        $requiredInline[(int) $image['id']] = true;
    }
    if ($requiredInline === []) return null;
    $statement = bueno_database()->prepare(
        'SELECT id,output_json FROM generation_operations
         WHERE post_id=:post AND operation_type="layout_plan" AND status="completed"
         ORDER BY completed_at DESC,id DESC'
    );
    $statement->execute([':post'=>$postId]);
    foreach ($statement->fetchAll() as $row) {
        $plan = json_decode((string) ($row['output_json'] ?? '{}'), true);
        try { validate_article_layout_plan(is_array($plan) ? $plan : []); }
        catch (Throwable) { continue; }
        $placed = [];
        foreach ((array) ($plan['image_placements'] ?? []) as $placement) $placed[(int) ($placement['image_id'] ?? 0)] = true;
        if (array_diff_key($requiredInline, $placed) === []) return (int) $row['id'];
    }
    return null;
}

function article_related_context_blocks_for_post(int $postId): array
{
    $statement = bueno_database()->prepare('SELECT * FROM article_related_context_blocks WHERE post_id=:post AND status="approved" ORDER BY id ASC');
    $statement->execute([':post' => $postId]);
    return $statement->fetchAll();
}

function article_layout_render_context_block(array $block): string
{
    $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Caption and reader_attention_note describe an illustration. This block is
    // rendered independently of the figure, so showing them here creates an orphaned caption.
    return '<aside class="article-context-block article-context-block--' . $escape((string)$block['block_type']) . '" data-slot-id="' . $escape((string)$block['slot_id']) . '"><h2>' . $escape((string)$block['heading']) . '</h2><p>' . nl2br($escape((string)$block['body'])) . '</p></aside>';
}

function article_text_presentation_sentences(string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/u', $text) ?: []), static fn (string $sentence): bool => $sentence !== ''));
}

function article_text_presentation_emphasis(array $phrases, string $text): array
{
    $accepted = [];
    foreach ($phrases as $phrase) {
        $phrase = trim((string) $phrase);
        $words = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 2 || count($words) > 7 || !str_contains($text, $phrase)) continue;
        $accepted[$phrase] = true;
    }
    return array_slice(array_keys($accepted), 0, 2);
}

function article_text_presentation_blocks(string $text, array $presentation): array
{
    $sentences = article_text_presentation_sentences($text);
    if (count($sentences) < 2) return [['type'=>'paragraph','text'=>$text,'emphasis_phrases'=>article_text_presentation_emphasis((array) ($presentation['emphasis_phrases'] ?? []), $text)]];
    $requestedBreaks = [];
    foreach ((array) ($presentation['paragraph_break_after_sentences'] ?? []) as $after) {
        $after = (int) $after;
        if ($after > 0 && $after < count($sentences)) $requestedBreaks[$after - 1] = true;
    }
    $listStarts = [];
    foreach ((array) ($presentation['list_groups'] ?? []) as $group) {
        $items = array_values(array_map(static fn (mixed $item): string => trim((string) $item), (array) ($group['items'] ?? [])));
        if (count($items) < 2 || in_array('', $items, true)) continue;
        for ($start = 0; $start <= count($sentences) - count($items); $start++) {
            if (array_slice($sentences, $start, count($items)) === $items) { $listStarts[$start] = $items; break; }
        }
    }
    $emphasis = (array) ($presentation['emphasis_phrases'] ?? []);
    $blocks = []; $paragraph = [];
    $flushParagraph = static function () use (&$paragraph, &$blocks, $emphasis): void {
        if ($paragraph === []) return;
        $value = implode(' ', $paragraph);
        $blocks[] = ['type'=>'paragraph','text'=>$value,'emphasis_phrases'=>article_text_presentation_emphasis($emphasis, $value)];
        $paragraph = [];
    };
    $hasRequestedBreaks = $requestedBreaks !== [];
    for ($index = 0; $index < count($sentences); $index++) {
        if (isset($listStarts[$index])) {
            $flushParagraph();
            $items = $listStarts[$index];
            $blocks[] = ['type'=>'list','items'=>$items,'presentation_list'=>true,'emphasis_phrases'=>article_text_presentation_emphasis($emphasis, implode(' ', $items))];
            $index += count($items) - 1;
            continue;
        }
        $paragraph[] = $sentences[$index];
        $paragraphLength = mb_strlen(implode(' ', $paragraph));
        $nextLength = isset($sentences[$index + 1]) ? mb_strlen($sentences[$index + 1]) + 1 : 0;
        $fallbackBreak = !$hasRequestedBreaks && mb_strlen($text) > 900 && $paragraphLength >= 400
            && ($paragraphLength + $nextLength > 700 || $paragraphLength >= 700);
        if (isset($requestedBreaks[$index]) || $fallbackBreak) $flushParagraph();
    }
    $flushParagraph();
    return $blocks;
}

function article_apply_text_presentation(array $section, array $presentation): array
{
    $blocks = [];
    foreach ((array) ($section['blocks'] ?? []) as $block) {
        if ((string) ($block['type'] ?? '') !== 'paragraph') { $blocks[] = $block; continue; }
        array_push($blocks, ...article_text_presentation_blocks((string) ($block['text'] ?? ''), $presentation));
    }
    $section['blocks'] = $blocks;
    return $section;
}

function article_render_text_with_emphasis(string $text, array $phrases): string
{
    $phrases = article_text_presentation_emphasis($phrases, $text);
    if ($phrases === []) return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    usort($phrases, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
    $pattern = '/(' . implode('|', array_map(static fn (string $phrase): string => preg_quote($phrase, '/'), $phrases)) . ')/u';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $lookup = array_fill_keys($phrases, true);
    $html = '';
    foreach ($parts as $part) {
        $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html .= isset($lookup[$part]) ? '<strong>' . $escaped . '</strong>' : $escaped;
    }
    return $html;
}

/** Deterministic, mobile-safe composition. Each approved image is emitted once. */
function article_section_is_semantic_callout(array $block): bool
{
    return in_array((string) ($block['content_type'] ?? ''), [
        'short_callout',
        'takeaway',
        'summary',
        'conclusion',
        'highlight',
        'key_insight',
        'key-insight',
    ], true);
}

function render_article_blocks_with_layout(array $blocks, array $images, array $plan, array $contextBlocks = [], array &$audit = [], array $afterSectionHtml = []): string
{
    $plan = article_layout_plan_or_default($plan, $audit);
    $renderable = array_values(array_filter($images, static fn (array $image): bool => (int)($image['editorial_rejected'] ?? 0) !== 1 && (int)($image['is_fallback'] ?? 0) !== 1));
    $hero = null; $contextualHero = null; $inlineBySection = [];
    foreach ($renderable as $image) {
        if ((string)$image['role'] === 'hero' && $hero === null && $contextualHero === null) {
            if (article_contextual_hero_should_be_inline($image)) $contextualHero = $image;
            else $hero = $image;
            continue;
        }
        $inlineBySection[(string)($image['section_id'] ?? '')][] = $image;
    }
    $withoutImages = static function (array $items) use (&$withoutImages): array {
        $kept = [];
        foreach ($items as $item) {
            if (in_array((string)($item['type'] ?? ''), ['illustration','gallery'], true)) continue;
            if ((string)($item['type'] ?? '') === 'section') $item['blocks'] = $withoutImages((array)($item['blocks'] ?? []));
            $kept[] = $item;
        }
        return $kept;
    };
    $sectionLayouts = [];
    foreach ($plan['section_layouts'] as $item) $sectionLayouts[(string)$item['section_id']] = (string)$item['layout'];
    $imagePlacements = [];
    foreach ($plan['image_placements'] as $item) $imagePlacements[(int)$item['image_id']] = (string)$item['placement'];
    $contextPlacements = [];
    foreach ($plan['context_block_placements'] as $item) $contextPlacements[(string)$item['slot_id']] = (string)$item['placement'];
    $callouts = [];
    foreach ($plan['callouts'] as $item) $callouts[(string)$item['section_id']] = (string)$item['type'];
    $textPresentations = [];
    foreach ((array) ($plan['text_presentation'] ?? []) as $item) $textPresentations[(string) ($item['section_id'] ?? '')] = $item;
    $contextsBySection = [];
    foreach ($contextBlocks as $block) {
        $contextImageId = (int) ($block['image_id'] ?? 0);
        foreach ($renderable as $image) {
            if ((int) ($image['id'] ?? 0) !== $contextImageId || empty(article_image_presentation_layout($image)['detail_inline'])) continue;
            $audit[] = ['code'=>'detail_inline_context_integrated','image_id'=>$contextImageId,'context_block_id'=>(int) ($block['id'] ?? 0)];
            continue 2;
        }
        $section = (string)($block['placement_after_section'] ?? '');
        $placement = $contextPlacements[(string)($block['slot_id'] ?? '')] ?? 'after_section';
        $contextsBySection[$section][$placement][] = $block;
    }
    $renderImage = static function (array $image, string $section, string $placement) use ($plan): string {
        $presentation = article_image_presentation_layout($image);
        $sideClass = !empty($presentation['side_layout_allowed'])
            ? ' article-layout__image--side article-layout__image--side-' . (string) $presentation['side_layout']
            : '';
        $classes = 'article-layout__image article-layout__image--' . $placement
            . ' article-layout__image--caption-' . (string)$plan['caption_strategy']
            . (!empty($presentation['detail_inline']) ? ' article-layout__image--detail-inline' : '') . $sideClass;
        return '<div class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" data-section="'
            . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '">' . render_article_image_record($image) . '</div>';
    };
    $renderContexts = static function (array $items): string {
        $html = '';
        foreach ($items as $context) $html .= article_layout_render_context_block($context);
        return $html;
    };
    $html = '<div class="article-layout article-layout--' . htmlspecialchars((string)$plan['template_family'], ENT_QUOTES, 'UTF-8') . '" data-reading-rhythm="' . htmlspecialchars((string)$plan['reading_rhythm'], ENT_QUOTES, 'UTF-8') . '">';
    if ($hero !== null) $html .= '<div class="article-layout__hero article-layout__hero--' . htmlspecialchars((string)$plan['hero_style'], ENT_QUOTES, 'UTF-8') . '">' . render_article_image_record($hero, true) . '</div>';
    $contentBlocks = $withoutImages($blocks);
    $sectionOrder = array_values(array_map(
        static fn (array $block): string => (string) (($block['type'] ?? '') === 'section' ? ($block['id'] ?? '') : ''),
        $contentBlocks
    ));
    $sectionOrder = array_values(array_filter($sectionOrder, static fn (string $section): bool => $section !== ''));
    if ($hero !== null && count($sectionOrder) >= 2) {
        $firstSection = $sectionOrder[0];
        $secondSection = $sectionOrder[1];
        foreach ((array) ($inlineBySection[$firstSection] ?? []) as $index => $image) {
            if (!article_image_is_contextual_illustration($image)) continue;
            unset($inlineBySection[$firstSection][$index]);
            $inlineBySection[$secondSection][] = $image;
            $audit[] = ['code'=>'contextual_illustration_spaced_from_hero','image_id'=>(int)($image['id'] ?? 0),'from_section'=>$firstSection,'to_section'=>$secondSection];
        }
        $inlineBySection[$firstSection] = array_values((array) ($inlineBySection[$firstSection] ?? []));
    }
    $consecutiveCards = 0;
    $renderedSectionCount = 0;
    foreach ($contentBlocks as $block) {
        $section = (string)($block['type'] ?? '') === 'section' ? (string)$block['id'] : '';
        if ($section !== '') $block = article_apply_text_presentation($block, (array) ($textPresentations[$section] ?? []));
        $layout = $sectionLayouts[$section] ?? 'standard';
        $callout = $callouts[$section] ?? '';
        $isDynamicV2 = isset($block['content_type']);
        $contentType = (string) ($block['content_type'] ?? 'prose');
        $sectionText = '';
        foreach ((array) ($block['blocks'] ?? []) as $inner) if ((string) ($inner['type'] ?? '') === 'paragraph') $sectionText .= (string) ($inner['text'] ?? '');
        $semanticCallout = article_section_is_semantic_callout($block);
        $cardProposed = $callout !== '' || in_array($layout, ['feature','compact'], true);
        $cardEligible = !$isDynamicV2 || ($callout !== '' && $semanticCallout)
            || ($cardProposed && in_array($contentType, ['short_callout','curiosity','comparison','unknowns'], true)
            && mb_strlen($sectionText) <= 500 && $consecutiveCards < 2);
        if (!$cardEligible) {
            if ($callout !== '' || in_array($layout, ['feature','compact'], true)) $audit[] = ['code'=>'card_to_prose_fallback','section_id'=>$section];
            $callout = '';
            if (in_array($layout, ['feature','compact'], true)) $layout = 'standard';
            $consecutiveCards = 0;
        } else {
            $consecutiveCards++;
        }
        $sectionImages = $inlineBySection[$section] ?? [];
        $sideImageIds = [];
        $detailInlineHeading = false;
        foreach ($sectionImages as $image) {
            $presentation = article_image_presentation_layout($image);
            if (!empty($presentation['side_layout_allowed']) && !empty($presentation['detail_inline'])) {
                $detailInlineHeading = true;
                break;
            }
        }
        $headingBlocks = [];
        $renderBlock = $block;
        if ($detailInlineHeading && $callout === '' && (string) ($block['type'] ?? '') === 'section') {
            $bodyBlocks = [];
            $headingLifted = false;
            foreach ((array) ($block['blocks'] ?? []) as $inner) {
                if (!$headingLifted && (string) ($inner['type'] ?? '') === 'heading') {
                    $headingBlocks[] = $inner;
                    $headingLifted = true;
                    continue;
                }
                $bodyBlocks[] = $inner;
            }
            $renderBlock['blocks'] = $bodyBlocks;
        }
        $html .= '<div class="article-layout__section article-layout__section--' . htmlspecialchars($layout, ENT_QUOTES, 'UTF-8')
            . ($detailInlineHeading ? ' article-layout__section--detail-inline-heading' : '')
            . ($callout !== '' ? ' article-layout__section--callout-' . htmlspecialchars($callout, ENT_QUOTES, 'UTF-8') : '') . '" data-section="' . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '">';
        if ($headingBlocks !== []) $html .= render_article_blocks($headingBlocks, []);
        foreach ($sectionImages as $image) {
            $presentation = article_image_presentation_layout($image);
            if (empty($presentation['side_layout_allowed'])) continue;
            $imageId = (int) ($image['id'] ?? 0);
            $sideImageIds[$imageId] = true;
            $html .= $renderImage($image, $section, 'side_wrap');
            $audit[] = ['code'=>'side_image_wrapped_with_section','section_id'=>$section,'image_id'=>$imageId];
        }
        foreach ($sectionImages as $image) if (!isset($sideImageIds[(int)($image['id'] ?? 0)]) && ($imagePlacements[(int)($image['id'] ?? 0)] ?? 'inline') === 'before_section') $html .= $renderImage($image, $section, 'before_section');
        $sectionHtml = render_article_blocks([$renderBlock], []);
        if ($callout !== '') {
            $html .= '<aside class="article-layout__callout article-layout__callout--' . htmlspecialchars($callout, ENT_QUOTES, 'UTF-8')
                . '" data-callout-source="semantic">' . $sectionHtml . '</aside>';
        } else {
            $html .= $sectionHtml;
        }
        foreach ($sectionImages as $image) if (!isset($sideImageIds[(int)($image['id'] ?? 0)]) && ($imagePlacements[(int)($image['id'] ?? 0)] ?? 'inline') === 'inline') $html .= $renderImage($image, $section, 'inline');
        $html .= $renderContexts($contextsBySection[$section]['after_image'] ?? []);
        foreach ($sectionImages as $image) if (!isset($sideImageIds[(int)($image['id'] ?? 0)]) && ($imagePlacements[(int)($image['id'] ?? 0)] ?? 'inline') === 'after_section') $html .= $renderImage($image, $section, 'after_section');
        $html .= $renderContexts($contextsBySection[$section]['after_section'] ?? []);
        $html .= (string) ($afterSectionHtml[$section] ?? '');
        $html .= '</div>';
        if ($section !== '') $renderedSectionCount++;
        if ($contextualHero !== null && $renderedSectionCount === 1) {
            $html .= '<div class="article-layout__contextual-hero" data-placement="between-sections">'
                . render_article_image_record($contextualHero) . '</div>';
            $audit[] = ['code'=>'contextual_hero_moved_inline','image_id'=>(int)($contextualHero['id'] ?? 0),'after_section'=>$section];
            $contextualHero = null;
        }
        unset($inlineBySection[$section], $contextsBySection[$section]);
    }
    if ($contextualHero !== null) {
        $html .= '<div class="article-layout__contextual-hero" data-placement="after-content">'
            . render_article_image_record($contextualHero) . '</div>';
        $audit[] = ['code'=>'contextual_hero_moved_inline','image_id'=>(int)($contextualHero['id'] ?? 0),'after_section'=>''];
    }
    foreach ($inlineBySection as $section => $sectionImages) foreach ($sectionImages as $image) $html .= $renderImage($image, (string)$section, $imagePlacements[(int)($image['id'] ?? 0)] ?? 'inline');
    foreach ($contextsBySection as $sectionContexts) foreach ($sectionContexts as $placementContexts) $html .= $renderContexts($placementContexts);
    return $html . '</div>';
}

function article_section_dom_id(string $sectionId): string
{
    $normalized = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($sectionId)) ?? '';
    return 'article-section-' . trim($normalized, '-');
}

function render_article_blocks(array $blocks, array $images): string
{
    validate_article_blocks($blocks);
    $byId = [];
    foreach ($images as $image) {
        $byId[(int) ($image['id'] ?? 0)] = $image;
    }
    $escape = static fn (string $text): string => htmlspecialchars(
        $text,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $html = '';
    foreach ($blocks as $block) {
        $type = (string) $block['type'];
        if ($type === 'heading') {
            $level = (int) ($block['level'] ?? 2);
            $html .= "<h{$level}>" . $escape((string) $block['text']) . "</h{$level}>";
        } elseif ($type === 'paragraph') {
            $html .= '<p>' . nl2br(article_render_text_with_emphasis((string) $block['text'], (array) ($block['emphasis_phrases'] ?? []))) . '</p>';
        } elseif ($type === 'quote') {
            $html .= '<blockquote><p>' . $escape((string) $block['text']) . '</p></blockquote>';
        } elseif ($type === 'list') {
            $presentationList = !empty($block['presentation_list']);
            if ($presentationList) $html .= '<div class="article-layout__inset-list" data-text-presentation="list-group">';
            $html .= '<ul>';
            foreach ((array) $block['items'] as $item) {
                $html .= '<li>' . article_render_text_with_emphasis((string) $item, (array) ($block['emphasis_phrases'] ?? [])) . '</li>'
                    . (!empty($block['presentation_list']) ? "\n" : '');
            }
            $html .= '</ul>';
            if ($presentationList) $html .= '</div>';
        } elseif ($type === 'section') {
            $variant = (string) ($block['variant'] ?? 'default');
            $sectionId = (string) $block['id'];
            $html .= '<section id="' . $escape(article_section_dom_id($sectionId))
                . '" data-article-section-id="' . $escape($sectionId)
                . '" class="article-section article-section--' . $escape($variant) . '">'
                . render_article_blocks((array) $block['blocks'], $images) . '</section>';
        } elseif ($type === 'illustration') {
            $html .= isset($byId[(int) $block['image_id']])
                ? render_article_image_record($byId[(int) $block['image_id']])
                : '';
        } elseif ($type === 'gallery') {
            $gallery = '';
            foreach ((array) $block['image_ids'] as $imageId) {
                if (isset($byId[(int) $imageId])) {
                    $gallery .= render_article_image_record($byId[(int) $imageId]);
                }
            }
            if ($gallery !== '') {
                $html .= '<div class="article-mini-gallery">' . $gallery . '</div>';
            }
        }
    }

    return $html;
}
