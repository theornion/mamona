<?php

declare(strict_types=1);

require_once __DIR__ . '/image-rights-service.php';

const ARTICLE_IMAGE_ROLES = ['hero', 'inline'];
const ARTICLE_IMAGE_LAYOUTS = ['full', 'left', 'right', 'breakout'];
const ARTICLE_IMAGE_STATUSES = ['planned', 'selected', 'downloaded', 'manual_review', 'missing'];
const ARTICLE_IMAGE_SAFE_LICENSES = ['cc0', 'public-domain', 'cc-by', 'cc-by-sa', 'pexels-license', 'local-editorial'];
const ARTICLE_IMAGE_RELATIONS = ['exact_subject', 'mechanism', 'apparatus', 'analogy_scale', 'related_context'];
const ARTICLE_BLOCK_SECTION_VARIANTS = [
    'default', 'lead', 'importance', 'facts', 'fact', 'context',
    'unknowns', 'unknown', 'narrative', 'takeaway',
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

    return max(1, (int) floor(($characterCount + 100) / 1000));
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
    $seed = trim((string) ($base[0] ?? $intent));
    $levels = [
        'exact_subject' => $base,
        'mechanism' => [$seed . ' mechanism diagram', $seed . ' phenomenon illustration'],
        'apparatus' => [$seed . ' scientific apparatus laboratory', $seed . ' experiment equipment'],
        'analogy_scale' => [$seed . ' scale spectrum nanostructure educational illustration'],
        'related_context' => [$seed . ' research context science'],
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
            $queries[] = ['query' => mb_substr($query, 0, 200), 'relation' => $relation];
            if (count($queries) >= max(1, $budget)) {
                return $queries;
            }
        }
    }
    return $queries;
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
        if ($inlineCount < 0 || $inlineCount > 20) {
            throw new InvalidArgumentException('Nieprawidłowa liczba ilustracji inline w schemacie.');
        }
        $inlineSchema['minItems'] = $inlineCount;
        $inlineSchema['maxItems'] = $inlineCount;
    }
    if ($inlineSectionIds !== null) {
        $inlineSectionIds = array_values(array_unique($inlineSectionIds));
        if ($inlineSectionIds === []
            || array_diff($inlineSectionIds, ARTICLE_IMAGE_CANONICAL_SECTION_IDS) !== []) {
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

function source_image_has_actual_transparency(string $bytes, string $mime): bool
{
    if (!in_array($mime, ['image/png','image/webp'], true) || !function_exists('imagecreatefromstring')) return false;
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

function source_image_json_transport(string $url): array
{
    validate_remote_image_url($url);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić klienta źródła obrazów.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => (int) app_config('source_image_timeout_seconds'),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'MamonaSourceImageSearch/1.0',
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
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
        . '&gsrnamespace=6&gsrlimit=20&prop=imageinfo&iiprop=url%7Csize%7Cextmetadata'
        . '&iiurlwidth=1600&format=json&origin=*&gsrsearch=' . rawurlencode($query);
    $response = $transport($url);
    $results = [];
    foreach ((array) ($response['query']['pages'] ?? []) as $page) {
        $info = (array) ($page['imageinfo'][0] ?? []);
        $meta = (array) ($info['extmetadata'] ?? []);
        $value = static fn (string $key): string => trim(strip_tags((string) ($meta[$key]['value'] ?? '')));
        $fileUrl = (string) ($info['url'] ?? '');
        $pageUrl = (string) ($info['descriptionurl'] ?? '');
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
            $results[] = validate_source_image_candidate([
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
            ]);
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
    $cached = image_provider_cache_get($provider, $query);
    if ($cached !== null) return $cached;
    $results = $loader();
    if ($results !== []) image_provider_cache_put($provider, $query, $results);
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

function select_source_image_from_results(array $plannedImage, array $results, string $providerId): array
{
    validate_planned_article_image(
        $plannedImage,
        (string) ($plannedImage['role'] ?? ''),
        [(string) ($plannedImage['section_id'] ?? '')]
    );
    foreach ($results as $result) {
        if (!is_array($result) || (string) ($result['provider_id'] ?? '') !== $providerId) {
            continue;
        }
        $candidate = validate_source_image_candidate($result);
        if (!source_image_candidate_is_suitable_for_role($candidate, $plannedImage)) {
            throw new InvalidArgumentException(
                ($plannedImage['role'] ?? '') === 'hero'
                    ? 'Wybrany obraz nie spełnia wymagań atrakcyjnej grafiki głównej.'
                    : 'Wybrany obraz nie pasuje do roli ilustracji.'
            );
        }

        $manifest = $candidate['rights_manifest'];
        $manifest['topic_role'] = (string) $plannedImage['role'];
        $manifest = validate_image_rights_manifest($manifest);
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
        ]);
    }
    throw new InvalidArgumentException('Wybrany obraz nie występuje w rzeczywistych wynikach źródła.');
}

function source_image_candidate_is_suitable_for_role(array $candidate, array $plannedImage): bool
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
        . rawurldecode((string) ($candidate['source_page_url'] ?? ''))
    );
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    $title = strtolower(is_string($ascii) ? $ascii : $title);
    if (preg_match(
        '/(?:^|[^a-z])(?:diagram|schematic|infographic|flow-?chart|graph|chart|plot|components|presentation|slide|poster|screenshot|equation)(?:[^a-z]|$)/',
        $title
    ) === 1) {
        return false;
    }

    return true;
}

function source_image_candidate_matches_query(array $candidate, string $query, int $minimumDistinctiveMatches = 1): bool
{
    $tokens = static function (string $value): array {
        $value = mb_strtolower($value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $parts = preg_split(
            '/[^a-z0-9]+/',
            strtolower(is_string($ascii) ? $ascii : $value),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
        $stop = [
            'image', 'photo', 'picture', 'view', 'diagram', 'graph', 'chart',
            'illustration', 'file', 'commons', 'wikimedia', 'wikipedia',
            'over', 'under', 'with', 'from', 'into', 'near', 'above', 'below',
        ];
        $result = [];
        foreach ($parts as $part) {
            if (strlen($part) < 4 || in_array($part, $stop, true)) {
                continue;
            }
            if (strlen($part) > 5 && str_ends_with($part, 's')) {
                $part = substr($part, 0, -1);
            }
            $result[$part] = true;
        }

        return array_keys($result);
    };
    $queryTokens = $tokens($query);
    if ($queryTokens === []) {
        return false;
    }
    $candidateText = (string) ($candidate['title'] ?? '') . ' '
        . rawurldecode((string) ($candidate['source_page_url'] ?? ''));
    $candidateTokens = $tokens($candidateText);
    $matches = array_values(array_intersect($queryTokens, $candidateTokens));
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

function source_image_curl_once(string $url): array
{
    validate_remote_image_url($url);
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $ips = article_image_resolve_public_ips($host);
    $pinnedIp = str_contains((string) $ips[0], ':') ? '[' . $ips[0] . ']' : (string) $ips[0];
    $body = '';
    $headers = [];
    $tooLarge = false;
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
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > (int) app_config('source_image_max_bytes')) {
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
            has_transparency, watermark_status
         ) VALUES (
            :post_id, :role, :section_id, :visual_intent, :expected_content, :search_queries_json,
            :source_page_url, :source_file_url, :local_path, :author, :license,
            :license_url, :attribution, :alt, :caption, :layout, :status,
            :width, :height, :downloaded_at, :relationship, :search_audit_json, :rights_manifest_json,
            :has_transparency, :watermark_status
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

function refresh_article_image_rendering(int $postId): void
{
    $post = find_post($postId);
    if ($post === null) {
        throw new RuntimeException('Nie znaleziono posta do odświeżenia obrazów.');
    }
    $images = list_article_images($postId);
    $blocks = json_decode((string) ($post['content_blocks'] ?? '[]'), true);
    $blocks = is_array($blocks) ? $blocks : [];
    $content = $blocks !== [] ? render_article_blocks($blocks, $images) : (string) $post['content'];
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

function fulfill_article_source_images(
    int $postId,
    ?callable $searcher = null,
    ?callable $downloader = null
): array {
    $post = find_post($postId);
    if ($post === null) {
        throw new RuntimeException('Nie znaleziono posta do uzupełnienia obrazami.');
    }
    $searcher ??= static function (string $query): array {
        $preferred = (string) app_config('source_image_provider');
        $providers = array_values(array_unique([
            $preferred, 'smithsonian', 'europeana', 'eso', 'nasa', 'usgs', 'nci', 'wikimedia', 'openverse', 'pexels',
        ]));
        $results = [];
        $errors = [];
        foreach ($providers as $provider) {
            try {
                array_push($results, ...search_source_images($query, $provider));
            } catch (Throwable $exception) {
                $errors[] = $provider . ': ' . $exception->getMessage();
            }
        }
        if ($results === [] && $errors !== []) {
            throw new RuntimeException(implode(' | ', $errors));
        }

        return $results;
    };
    $downloader ??= static fn (array $selected): array => download_source_image($selected);
    $summary = [
        'downloaded' => 0,
        'manual_review' => 0,
        'missing' => 0,
        'skipped' => 0,
        'errors' => [],
    ];
    $usedUrls = [];
    foreach (list_article_images($postId) as $existing) {
        if (in_array((string) $existing['status'], ['selected', 'downloaded', 'manual_review'], true)
            && trim((string) $existing['source_file_url']) !== '') {
            $usedUrls[(string) $existing['source_file_url']] = true;
        }
    }

    foreach (list_article_images($postId) as $image) {
        if ((string) $image['status'] === 'downloaded'
            && trim((string) $image['local_path']) !== ''
            && is_file(app_path((string) $image['local_path']))) {
            $summary['skipped']++;
            continue;
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
        ];
        $audit = [];
        $completed = false;
        foreach (article_image_semantic_queries($planned) as $attempt) {
            $query = (string) $attempt['query'];
            $relation = (string) $attempt['relation'];
            try {
                $results = $searcher($query);
            } catch (Throwable $exception) {
                $audit[] = ['query' => $query, 'level' => $relation, 'source' => '', 'result' => 'search_error', 'reason' => $exception->getMessage()];
                $summary['errors'][] = $image['role'] . '/' . $image['section_id']
                    . ': wyszukiwanie: ' . $exception->getMessage();
                continue;
            }
            $ranked = [];
            foreach (array_slice($results, 0, (int) app_config('source_image_candidate_budget_per_query')) as $result) {
                if (!is_array($result)) continue;
                $url = (string) ($result['source_file_url'] ?? '');
                $provider = (string) ($result['provider'] ?? 'unknown');
                if (isset($usedUrls[$url])) {
                    $audit[] = ['query' => $query, 'level' => $relation, 'source' => $provider, 'result' => 'rejected', 'reason' => 'duplicate'];
                    continue;
                }
                $score = article_image_candidate_score($result, $planned, $query, $relation);
                if ($score === PHP_INT_MIN) {
                    $reason = article_image_license_is_auto_safe((string) ($result['license'] ?? '')) ? 'role_or_quality' : 'unsafe_or_incomplete_license';
                    $audit[] = ['query' => $query, 'level' => $relation, 'source' => $provider, 'result' => 'rejected', 'reason' => $reason];
                    continue;
                }
                $ranked[] = [$score, $result];
            }
            usort($ranked, static fn (array $a, array $b): int => $b[0] <=> $a[0]
                ?: strcmp((string) ($a[1]['provider_id'] ?? ''), (string) ($b[1]['provider_id'] ?? '')));
            foreach ($ranked as [$score, $result]) {
                try {
                    $selected = select_source_image_from_results(
                        $planned,
                        [$result],
                        (string) ($result['provider_id'] ?? '')
                    );
                    $selected = article_image_honest_copy($selected, $relation, $result);
                    $downloaded = $downloader($selected);
                    $audit[] = ['query' => $query, 'level' => $relation, 'source' => (string) ($result['provider'] ?? ''), 'provider_id' => (string) ($result['provider_id'] ?? ''), 'result' => 'selected', 'score' => $score, 'relationship' => $relation];
                    $downloaded['search_audit'] = $audit;
                    persist_article_image($postId, $downloaded, $query);
                    $usedUrls[(string) $downloaded['source_file_url']] = true;
                    $summary['downloaded']++;
                    $completed = true;
                    break 2;
                } catch (Throwable $exception) {
                    $watermarkRejected = str_starts_with($exception->getMessage(), 'watermark_rejected:');
                    $audit[] = ['query' => $query, 'level' => $relation, 'source' => (string) ($result['provider'] ?? ''),
                        'url' => (string) ($result['source_file_url'] ?? ''),
                        'result' => $watermarkRejected ? 'watermark_rejected' : 'rejected', 'reason' => $exception->getMessage()];
                    $summary['errors'][] = $image['role'] . '/' . $image['section_id']
                        . ': kandydat: ' . $exception->getMessage();
                }
            }
        }
        if ($completed) {
            continue;
        }
        $audit[] = ['query' => '', 'level' => 'exhausted', 'source' => '', 'result' => 'missing', 'reason' => 'all_legal_candidates_exhausted; local_fallback_required'];
        persist_article_image($postId, [...$planned, 'status' => 'missing', 'search_audit' => $audit]);
        $summary['missing']++;
    }

    refresh_article_image_rendering($postId);

    return $summary;
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

function render_article_image_record(array $image, bool $hero = false): string
{
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
    $layout = in_array((string) ($image['layout'] ?? ''), ARTICLE_IMAGE_LAYOUTS, true)
        ? (string) $image['layout']
        : 'full';
    $alt = htmlspecialchars((string) $image['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $caption = htmlspecialchars((string) $image['caption'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attribution = htmlspecialchars((string) $image['attribution'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $source = htmlspecialchars((string) $image['source_page_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $license = htmlspecialchars((string) $image['license'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $licenseUrl = htmlspecialchars((string) $image['license_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $contextNote = htmlspecialchars(article_image_context_note($image), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $loading = $hero ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"';
    $responsive = article_image_responsive_attributes(
        $path,
        max(1, (int) ($image['width'] ?? 1)),
        $layout
    );
    $html = '<figure class="article-illustration article-illustration--' . $layout
        . ($hero ? ' article-illustration--hero' : '')
        . (!empty($image['has_transparency']) ? ' article-illustration--transparent' : '') . '">';
    $html .= '<img src="../' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt
        . '" width="' . max(1, (int) ($image['width'] ?? 1))
        . '" height="' . max(1, (int) ($image['height'] ?? 1))
        . '" decoding="async"' . $responsive . $loading . '>';
    if ($caption !== '' || $contextNote !== '' || $attribution !== '') {
        $html .= '<figcaption>' . ($caption !== '' ? '<span class="article-image-caption">' . $caption . '</span>' : '');
        if ($contextNote !== '') {
            $html .= '<small class="article-image-context-note">' . $contextNote . '</small>';
        }
        if ($attribution !== '') {
            $html .= '<small class="article-image-credit">' . $attribution;
            if ($source !== '') {
                $html .= ' · <a href="' . $source . '" rel="noopener noreferrer">źródło</a>';
            }
            if ($license !== '') {
                $html .= ' · ' . ($licenseUrl !== ''
                    ? '<a href="' . $licenseUrl . '" rel="license noopener noreferrer">' . $license . '</a>'
                    : $license);
            }
            $html .= '</small>';
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
    $sizes = in_array($layout, ['left', 'right'], true)
        ? '(max-width: 980px) 100vw, 28rem'
        : '(max-width: 980px) 100vw, 58rem';
    return ' srcset="' . implode(', ', $candidates) . '" sizes="' . $sizes . '"';
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
            $html .= '<p>' . nl2br($escape((string) $block['text'])) . '</p>';
        } elseif ($type === 'quote') {
            $html .= '<blockquote><p>' . $escape((string) $block['text']) . '</p></blockquote>';
        } elseif ($type === 'list') {
            $html .= '<ul>';
            foreach ((array) $block['items'] as $item) {
                $html .= '<li>' . $escape((string) $item) . '</li>';
            }
            $html .= '</ul>';
        } elseif ($type === 'section') {
            $variant = (string) ($block['variant'] ?? 'default');
            $html .= '<section id="' . $escape((string) $block['id'])
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
