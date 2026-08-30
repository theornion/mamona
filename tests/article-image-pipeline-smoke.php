<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_ARTICLE_IMAGE_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_ARTICLE_IMAGE_SMOKE=1, aby uruchomić test obrazów bez sieci.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');
putenv('CMS_TEST_DATABASE_FILE=:memory:');
require_once dirname(__DIR__) . '/php/admin-database.php';

function image_pipeline_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function image_pipeline_expect(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        image_pipeline_assert(
            str_contains($exception->getMessage(), $message),
            'Nieoczekiwany błąd: ' . $exception->getMessage()
        );
        return;
    }
    throw new RuntimeException('Oczekiwany wyjątek nie został zgłoszony.');
}

image_pipeline_assert(article_inline_image_target_count(0) === 0, 'Pusty tekst ma miejsca na obrazy.');
image_pipeline_assert(article_inline_image_target_count(2000) === 2, 'Dla 2000 znaków oczekiwano 2 ilustracji inline.');
image_pipeline_assert(article_inline_image_target_count(3000) === 3, 'Dla 3000 znaków oczekiwano 3 ilustracji inline.');
image_pipeline_assert(article_inline_image_target_count(3200) === 3, 'Dla 3200 znaków oczekiwano 3 ilustracji inline i hero osobno.');
image_pipeline_assert(article_inline_image_target_count(4000) === 3, 'Dla 4000 znaków obowiązuje limit 3 ilustracji inline.');
image_pipeline_assert(article_inline_image_target_count(5000) === 3, 'Dla 5000 znaków obowiązuje limit 3 ilustracji inline.');
image_pipeline_assert(article_image_license_is_auto_safe('CC0 1.0'), 'CC0 nie zostało zaakceptowane.');
image_pipeline_assert(article_image_license_is_auto_safe('CC BY 4.0'), 'CC BY nie zostało zaakceptowane.');
image_pipeline_assert(article_image_license_is_auto_safe('by-4.0'), 'Zapis licencji CC BY z Openverse nie został zaakceptowany.');
image_pipeline_assert(article_image_license_is_auto_safe('Public Domain'), 'Public Domain nie zostało zaakceptowane.');
image_pipeline_assert(article_image_license_is_auto_safe('CC BY-SA 4.0'), 'CC BY-SA nie zostało zaakceptowane.');
image_pipeline_assert(!article_image_license_is_auto_safe('royalty-free'), 'Royalty-free uznano za wystarczającą licencję.');
image_pipeline_assert(!(bool) app_config('ai_image_generation_enabled'), 'Generator obrazów AI nie jest domyślnie wyłączony.');
$closureReservation = article_image_direct_vision_limit_from_budget(14, 20, 2);
image_pipeline_assert(
    $closureReservation['direct_vision_limit'] === 0
    && $closureReservation['reserved_for_p06_planner'] === 1
    && $closureReservation['reserved_for_p07_recovery'] === 4
    && $closureReservation['reserved_for_p08_p09'] === 2,
    'Przy budżecie 14/20 direct Vision może zużyć wywołania zarezerwowane dla P06/P07/P08/P09.'
);
$singleMissingReservation = article_image_direct_vision_limit_from_budget(14, 20, 1);
image_pipeline_assert(
    $singleMissingReservation['direct_vision_limit'] === 1,
    'Pojedynczy brakujący slot nie otrzymał ograniczonego budżetu direct Vision po rezerwacji closure.'
);
$stageAwareReservation = article_image_direct_vision_limit_from_budget(24, 30, 3, [
    'p06_pending' => false,
    'replan_pending' => false,
    'p07_pending_calls' => 0,
    'p08_pending' => true,
    'p09_pending' => true,
]);
image_pipeline_assert(
    $stageAwareReservation['reserved_for_p06_planner'] === 0
    && $stageAwareReservation['reserved_for_recovery_replan'] === 0
    && $stageAwareReservation['reserved_for_p07_recovery'] === 0
    && $stageAwareReservation['reserved_for_p08_p09'] === 2
    && $stageAwareReservation['direct_vision_limit'] === 4,
    'Stage-aware reserve ponownie rezerwuje zakończone P06/replan.'
);
$pendingP07Reservation = article_image_direct_vision_limit_from_budget(24, 30, 3, [
    'p06_pending' => false,
    'replan_pending' => false,
    'p07_pending_calls' => 1,
    'p08_pending' => true,
    'p09_pending' => true,
]);
image_pipeline_assert(
    $pendingP07Reservation['reserved_for_p07_recovery'] === 1
    && $pendingP07Reservation['reserved_for_closure'] === 3
    && $pendingP07Reservation['direct_vision_limit'] === 3,
    'Konkretny przyszły moduł P07 nie zachował chronionego wywołania.'
);
$renderedHero = render_article_image_record([
    'status' => 'downloaded',
    'local_path' => 'images/digital_rain.png',
    'layout' => 'full',
    'alt' => 'Fotografia testowa',
    'caption' => 'Podpis fotografii testowej.',
    'attribution' => 'Fixture Author, CC BY 4.0',
    'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Fixture.jpg',
    'license' => 'CC BY 4.0',
    'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
    'width' => 1600,
    'height' => 900,
], true);
image_pipeline_assert(
    str_contains($renderedHero, 'article-illustration--hero')
    && str_contains($renderedHero, 'Fixture Author')
    && str_contains($renderedHero, '>źródło</a>')
    && str_contains($renderedHero, 'rel="license noopener noreferrer"'),
    'Hero nie pokazuje podpisu, źródła i licencji.'
);
image_pipeline_assert(
    source_image_candidate_matches_query(
        [
            'title' => 'Illustrative components of cloud feedback',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Cloud_feedback.png',
        ],
        'climate change cloud feedback'
    ),
    'Trafny kandydat nie przeszedł filtra semantycznego.'
);
image_pipeline_assert(
    !source_image_candidate_matches_query(
        [
            'title' => 'Wikidata for Education final report',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Wikidata_for_Education.pdf',
        ],
        'cloud feedback loop diagram'
    ),
    'Nietrafny kandydat przeszedł filtr semantyczny.'
);
image_pipeline_assert(
    !source_image_candidate_matches_query(
        [
            'title' => 'Ecological feedback on diffusion dynamics',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Ecological_feedback.pdf',
        ],
        'cloud feedback loop diagram'
    ),
    'Pojedyncze ogólne słowo przepuściło nietrafny obraz.'
);
image_pipeline_assert(
    !source_image_candidate_matches_query(
        [
            'title' => 'Climate vegetation feedback model',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Climate_vegetation_feedback.pdf',
        ],
        'climate feedback mechanism'
    ),
    'Same ogólne słowa przepuściły nietrafny obraz.'
);
image_pipeline_assert(
    !source_image_candidate_matches_query(
        [
            'title' => 'View of ocean over cliffs on Kauai Island',
            'source_page_url' => 'https://example.test/ocean-cliffs',
        ],
        'thinning clouds over ocean',
        2
    ),
    'Pojedyncza zgodność słowa „ocean” przepuściła nietrafną ilustrację inline.'
);
image_pipeline_assert(
    source_image_candidate_matches_query(
        [
            'title' => 'Low clouds over the Pacific Ocean',
            'source_page_url' => 'https://example.test/pacific-clouds',
        ],
        'thinning clouds over ocean',
        2
    ),
    'Dwie konkretne zgodności nie przepuściły trafnej ilustracji inline.'
);
image_pipeline_assert(
    source_image_candidate_is_suitable_for_role(
        [
            'title' => 'Illustrative components of cloud feedback diagram',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Cloud_feedback.png',
            'width' => 1600,
            'height' => 900,
        ],
        ['role' => 'hero']
    ),
    'Techniczny diagram został zaakceptowany jako hero.'
);
image_pipeline_assert(
    !source_image_candidate_is_suitable_for_role(
        [
            'title' => 'Institution logo',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Institution_logo.svg',
            'width' => 1600,
            'height' => 900,
            'is_logo' => true,
        ],
        ['role' => 'hero']
    ),
    'Oczywiste logo nie zostaÅ‚o odrzucone przez hard prefilter.'
);
image_pipeline_assert(
    source_image_candidate_is_suitable_for_role(
        [
            'title' => 'Low clouds over the Pacific Ocean aerial photograph',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Clouds_over_Pacific.jpg',
            'width' => 1600,
            'height' => 900,
        ],
        ['role' => 'hero']
    ),
    'Pozioma fotografia dokumentalna nie została zaakceptowana jako hero.'
);
image_pipeline_assert(
    !source_image_candidate_is_suitable_for_role(
        [
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Cloud_analysis.pdf',
            'width' => 1200,
            'height' => 900,
        ],
        ['role' => 'inline']
    ),
    'Podgląd dokumentu PDF został zaakceptowany jako ilustracja artykułu.'
);

$draft = [
    'lead' => ['text' => str_repeat('Lead semantyczny. ', 25)],
    'why_important' => ['text' => str_repeat('Znaczenie wyniku. ', 25)],
    'key_facts' => [
        ['text' => str_repeat('Fakt pierwszy. ', 25)],
        ['text' => str_repeat('Fakt drugi. ', 25)],
    ],
    'comparison_context' => ['text' => str_repeat('Kontekst. ', 20)],
    'unknowns' => [['text' => str_repeat('Niewiadoma. ', 20)]],
    'narrative' => [],
    'practical_takeaway' => ['text' => str_repeat('Wniosek. ', 30)],
];
$plan = build_planned_illustration_fixture($draft);
$planSchema = article_illustration_plan_schema();
$threeInlineSchema = article_illustration_plan_schema(
    3,
    ['lead', 'why-important', 'fact-1']
);
image_pipeline_assert(
    ($threeInlineSchema['properties']['inline']['minItems'] ?? null) === 3
    && ($threeInlineSchema['properties']['inline']['maxItems'] ?? null) === 3,
    'Formalny schemat nie wymusza wymaganej liczby ilustracji inline.'
);
image_pipeline_assert(
    ($threeInlineSchema['properties']['inline']['items']['properties']['section_id']['enum'] ?? [])
        === ['lead', 'why-important', 'fact-1'],
    'Formalny schemat szkicu nie wymusza istniejących sekcji inline.'
);
image_pipeline_assert(
    ($planSchema['properties']['hero']['properties']['section_id']['enum'] ?? []) === ['article'],
    'Formalny schemat nie wymusza powiązania hero z całym artykułem.'
);
image_pipeline_assert(
    ($planSchema['properties']['hero']['properties']['role']['enum'] ?? []) === ['hero']
    && ($planSchema['properties']['inline']['items']['properties']['role']['enum'] ?? []) === ['inline'],
    'Formalny schemat nie rozdziela roli hero od inline.'
);
image_pipeline_assert(
    ($planSchema['properties']['inline']['items']['properties']['section_id']['enum'] ?? [])
        === ARTICLE_IMAGE_CANONICAL_SECTION_IDS,
    'Formalny schemat pozwala modelowi wskazać nieistniejącą sekcję inline.'
);
image_pipeline_assert(
    ($planSchema['properties']['hero']['properties']['source_page_url']['enum'] ?? null) === [''],
    'Formalny schemat pozwala modelowi wymyślać źródło hero.'
);
validate_article_illustration_plan($plan, article_section_blocks($draft), article_draft_main_content_length($draft));
$replannedHero = [...$plan['hero'], 'query_origin' => 'recovery_replan'];
$replannedQueries = article_image_direct_queries($replannedHero, 1);
$canonicalReplannedHero = article_image_canonical_payload($replannedHero);
image_pipeline_assert(
    ($replannedQueries[0]['query_origin'] ?? '') === 'recovery_replan'
    && !array_key_exists('query_origin', $canonicalReplannedHero),
    'query_origin nie został zachowany w audycie albo wyciekł do canonical payload.'
);
validate_planned_article_image($canonicalReplannedHero, 'hero', ['article']);
image_pipeline_assert($plan['hero']['section_id'] === 'article', 'Hero nie jest oddzielone od inline.');
image_pipeline_assert(
    count(array_unique(array_column($plan['inline'], 'section_id'))) === count($plan['inline']),
    'Ilustracje nie zostały przypisane do odrębnych sekcji.'
);
$fabricated = $plan;
$fabricated['hero']['source_page_url'] = 'https://invented.example/image';
image_pipeline_expect(
    static fn () => validate_article_illustration_plan(
        $fabricated,
        article_section_blocks($draft),
        article_draft_main_content_length($draft)
    ),
    'spoza dozwolonej listy'
);

$wikimediaFixture = [
    'query' => [
        'pages' => [[
            'pageid' => 123,
            'title' => 'File:Artykuł_popularnonaukowy_nauka.jpg',
            'imageinfo' => [[
                'url' => 'https://upload.wikimedia.org/example.png',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Example.png',
                'width' => 1600,
                'height' => 900,
                'extmetadata' => [
                    'Artist' => ['value' => 'Fixture Author'],
                    'LicenseShortName' => ['value' => 'CC BY 4.0'],
                    'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by/4.0/'],
                    'Attribution' => ['value' => 'Fixture Author, CC BY 4.0'],
                ],
            ]],
        ]],
    ],
];
$results = search_wikimedia_commons_images(
    'fixture query',
    static fn (string $url): array => $wikimediaFixture
);
image_pipeline_assert(count($results) === 1 && $results[0]['status'] === 'selected', 'Fixture Wikimedia nie została znormalizowana.');
$visionMock = static function (array $candidate, array $plannedImage, string $articleContext): array {
    return [
        'semantic_relevance' => 9,
        'editorial_fit' => 8,
        'depicts_required_subject' => true,
        'misleading' => false,
        'inappropriate' => false,
        'decision' => 'accept',
        'reason' => 'test mock: always accept',
    ];
};
$selected = select_source_image_from_results($plan['hero'], $results, '123', $visionMock);
image_pipeline_assert($selected['author'] === 'Fixture Author', 'Nie zapisano autora z rzeczywistego wyniku.');
$weakMetadataCandidate = [...$results[0],
    'provider_id' => 'weak-semantic-fixture',
    'title' => 'File:Abstract archival photograph.png',
    'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Abstract-archival-photograph.png',
    'source_file_url' => 'https://upload.wikimedia.org/weak-semantic-fixture.png',
    'chosen_query' => 'miscellaneous archival material',
];
image_pipeline_assert(
    article_image_semantic_gate_score($weakMetadataCandidate, $plan['hero']) < 60,
    'Fixture slabego metadata overlap nie testuje dawnego progu semantycznego.'
);
$weakMetadataVisionCalls = 0;
$weakMetadataSelected = select_source_image_from_results(
    $plan['hero'],
    [$weakMetadataCandidate],
    'weak-semantic-fixture',
    static function () use (&$weakMetadataVisionCalls): array {
        $weakMetadataVisionCalls++;
        return [
            'semantic_relevance' => 9, 'editorial_fit' => 9, 'depicts_required_subject' => true,
            'misleading' => false, 'inappropriate' => false, 'decision' => 'accept',
            'reason' => 'Vision accepts hard-eligible candidate with sparse metadata.',
        ];
    }
);
image_pipeline_assert(
    $weakMetadataVisionCalls === 1 && (string) $weakMetadataSelected['provider_id'] === 'weak-semantic-fixture',
    'Legalny kandydat o slabym metadata overlap nie dotarl do Vision.'
);
$hardInvalidVisionCalls = 0;
$hardInvalidCandidate = [...$weakMetadataCandidate, 'provider_id' => 'hard-invalid-fixture', 'is_logo' => true];
image_pipeline_expect(
    static fn () => select_source_image_from_results(
        $plan['hero'],
        [$hardInvalidCandidate],
        'hard-invalid-fixture',
        static function () use (&$hardInvalidVisionCalls): array {
            $hardInvalidVisionCalls++;
            return ['decision' => 'accept'];
        }
    ),
    'atrakcyjnej grafiki'
);
image_pipeline_assert($hardInvalidVisionCalls === 0, 'Hard-invalid asset dotarl do Vision.');
image_pipeline_expect(
    static fn () => select_source_image_from_results($plan['hero'], $results, 'invented', $visionMock),
    'nie występuje'
);
$database = bueno_database();
$database->beginTransaction();
try {
    $database->exec(
        'INSERT INTO post_categories (title, description, slug, sort_order)
         VALUES ("Image fixture", "", "image-fixture-' . bin2hex(random_bytes(5)) . '", 999999)'
    );
    $categoryId = (int) $database->lastInsertId();
    $database->prepare(
        'INSERT INTO posts (category_id, title, excerpt, content, image_path, slug, is_published)
         VALUES (:category_id, "Image fixture", "", "", "", :slug, 0)'
    )->execute([':category_id' => $categoryId, ':slug' => 'image-post-' . bin2hex(random_bytes(5))]);
    $fixturePostId = (int) $database->lastInsertId();
    $visionAuditCandidate = [...$results[0],
        'provider_id' => 'vision-audit-fixture',
        'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Vision-audit.png',
        'source_file_url' => 'https://upload.wikimedia.org/vision-audit.png'];
    $visionAuditAssessment = article_image_gemini_vision_assess(
        $fixturePostId,
        $visionAuditCandidate,
        $plan['hero'],
        'Fixture article context for Vision audit.',
        static fn (): array => ['status' => 200, 'body' => generation_json(['responseId' => 'vision-audit-response', 'candidates' => [[
            'content' => ['parts' => [['text' => generation_json([
                'semantic_relevance' => 9, 'editorial_fit' => 9, 'depicts_required_subject' => true,
                'misleading' => false, 'inappropriate' => false, 'decision' => 'accept', 'reason' => 'Fixture accepted.',
            ])]]], 'finishReason' => 'STOP',
        ]]]), 'headers' => [], 'network_error' => ''],
        static fn (): array => ['status' => 200, 'body' => thumbnail_mock_image_bytes(), 'headers' => []],
        'fixture-api-key-not-stored'
    );
    $visionAudit = $database->prepare('SELECT * FROM article_image_vision_audit WHERE post_id=:post ORDER BY id DESC LIMIT 1');
    $visionAudit->execute([':post' => $fixturePostId]);
    $visionAuditRow = $visionAudit->fetch();
    image_pipeline_assert(is_array($visionAuditRow) && (string) $visionAuditRow['status'] === 'completed'
        && (int) $visionAuditRow['budget_before'] === 0 && (int) $visionAuditRow['budget_after'] === 1
        && (string) $visionAuditRow['candidate_identifier'] === 'vision-audit-fixture'
        && (string) $visionAuditRow['image_sha256'] !== '' && (string) $visionAuditRow['provider_response_text'] !== '',
        'Audyt Gemini Vision nie zapisał trwałych metadanych wywołania.');
    image_pipeline_assert(!str_contains((string) $visionAuditRow['outbound_prompt'], 'fixture-api-key-not-stored')
        && !str_contains((string) $visionAuditRow['provider_response_json'], base64_encode(thumbnail_mock_image_bytes())),
        'Audyt Gemini Vision zapisał sekret albo binarne dane obrazu.');
    image_pipeline_assert((string) $visionAuditAssessment['decision'] === 'accept', 'Fixture Gemini Vision nie zwrócił akceptacji.');
    $firstImageId = persist_article_image($fixturePostId, $selected, 'fixture query');
    $secondImageId = persist_article_image($fixturePostId, $selected, 'fixture query');
    image_pipeline_assert($firstImageId === $secondImageId, 'Ponowny zapis utworzył duplikat slotu obrazu.');
    image_pipeline_assert(
        (int) $database->query(
            'SELECT COUNT(*) FROM article_images WHERE post_id = ' . $fixturePostId
        )->fetchColumn() === 1,
        'Idempotentny zapis pozostawił duplikaty.'
    );
    $inlineImageId = persist_article_image($fixturePostId, $plan['inline'][0], 'fixture inline');
    $fixtureBlocks = [[
        'type' => 'section',
        'id' => 'lead',
        'blocks' => [
            ['type' => 'paragraph', 'text' => 'Treść fixture'],
            ['type' => 'illustration', 'image_id' => $inlineImageId],
        ],
    ]];
    $database->prepare('UPDATE posts SET content_blocks = :blocks WHERE id = :id')->execute([
        ':id' => $fixturePostId,
        ':blocks' => generation_json($fixtureBlocks),
    ]);
    $inlineResult = [
        ...$results[0],
        'title' => 'Konkretna ilustracja treści sekcji lead',
        'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Example-inline.png',
        'source_file_url' => 'https://upload.wikimedia.org/example-inline.png',
        'provider_id' => '124',
    ];
    $fulfillment = fulfill_article_source_images(
        $fixturePostId,
        static fn (string $query): array => [$inlineResult],
        static fn (array $candidate): array => [
            ...$candidate,
            'status' => 'downloaded',
            'local_path' => 'images/posts/sources/fixture-inline.png',
            'downloaded_at' => gmdate(DATE_ATOM),
        ],
        static fn (): array => [
            'semantic_relevance' => 9,
            'editorial_fit' => 9,
            'depicts_required_subject' => true,
            'misleading' => false,
            'inappropriate' => false,
            'decision' => 'accept',
            'reason' => 'Kontrolowany mock Vision dla testu pipeline.',
        ]
    );
    image_pipeline_assert(
        $fulfillment['downloaded'] === 1
        && (string) $database->query(
            'SELECT status FROM article_images WHERE id = ' . $inlineImageId
        )->fetchColumn() === 'downloaded',
        'Orkiestracja nie zapisała legalnego obrazu z kontrolowanego wyniku: ' . generation_json($fulfillment)
    );
    $recovered = fulfill_article_source_images(
        $fixturePostId,
        static fn (string $query): array => [$inlineResult],
        static fn (array $candidate): array => [
            ...$candidate,
            'status' => 'downloaded',
            'local_path' => 'images/posts/sources/fixture-inline-recovered.png',
            'downloaded_at' => gmdate(DATE_ATOM),
        ],
        static fn (): array => [
            'semantic_relevance' => 9, 'editorial_fit' => 9, 'depicts_required_subject' => true,
            'misleading' => false, 'inappropriate' => false, 'decision' => 'accept', 'reason' => 'Recovery fixture.',
        ]
    );
    image_pipeline_assert(
        $recovered['downloaded'] === 1
        && (string) $database->query('SELECT status FROM article_images WHERE id = ' . $inlineImageId)->fetchColumn() === 'downloaded',
        'Downloaded record without a local file was skipped instead of recovered.'
    );
    $database->prepare('UPDATE article_images SET status="planned", source_file_url="", local_path="" WHERE id=:id')->execute([':id' => $inlineImageId]);
    $acceptedCandidate = [...$inlineResult, 'provider_id' => '999', 'source_file_url' => 'https://upload.wikimedia.org/accepted-inline.png'];
    $rejectedCandidate = [...$inlineResult, 'provider_id' => '001', 'source_file_url' => 'https://upload.wikimedia.org/rejected-inline.png'];
    $visionCalls = 0;
    $boundedNames = [1=>'alpha apparatus', 2=>'beta laboratory', 3=>'gamma observatory', 4=>'delta instrument'];
    $boundedCandidates = array_map(static fn (int $i): array => [...$inlineResult,
        'provider_id' => 'bounded-' . $i,
        'title' => $boundedNames[$i],
        'source_file_url' => 'https://upload.wikimedia.org/bounded-inline-' . $i . '.png',
    ], range(1, 4));
    $boundedVisionCalls = 0;
    $bounded = fulfill_article_source_images(
        $fixturePostId,
        static fn (string $query): array => $boundedCandidates,
        static fn (array $candidate): array => [...$candidate, 'status'=>'downloaded', 'local_path'=>'images/posts/sources/fixture-bounded.png', 'downloaded_at'=>gmdate(DATE_ATOM)],
        static function () use (&$boundedVisionCalls, $database, $fixturePostId): array {
            $boundedVisionCalls++;
            $claim = gemini_article_budget_claim($database, $fixturePostId, ARTICLE_IMAGE_GEMINI_OPERATION_TYPE, 'images', 1, 'bounded-' . $boundedVisionCalls);
            gemini_article_budget_reconcile_claim($database, $fixturePostId, (string) $claim['claim_token'], 'completed');
            return ['semantic_relevance'=>0,'editorial_fit'=>0,'depicts_required_subject'=>false,
                'misleading'=>true,'inappropriate'=>false,'decision'=>'reject','reason'=>'Fixture rejects candidate.'];
        }
    );
    image_pipeline_assert($bounded['missing'] === 1 && $boundedVisionCalls === 3,
        'Hard-eligible direct candidates are capped at three Vision calls per missing slot.');
    $database->prepare('UPDATE article_images SET status="planned", source_file_url="", local_path="" WHERE id=:id')->execute([':id' => $inlineImageId]);
    $retry = fulfill_article_source_images(
        $fixturePostId,
        static fn (string $query): array => [$rejectedCandidate, $acceptedCandidate],
        static fn (array $candidate): array => [...$candidate, 'status' => 'downloaded', 'local_path' => 'images/posts/sources/fixture-inline-retry.png', 'downloaded_at' => gmdate(DATE_ATOM)],
        static function (array $candidate) use (&$visionCalls): array {
            $visionCalls++;
            $accept = (string) ($candidate['provider_id'] ?? '') === '999';
            return ['semantic_relevance'=>9, 'editorial_fit'=>9, 'depicts_required_subject'=>$accept,
                'misleading'=>false, 'inappropriate'=>false, 'decision'=>$accept ? 'accept' : 'reject',
                'reason'=>$accept ? 'Drugi kandydat pasuje.' : 'Pierwszy kandydat odrzucony.'];
        }
    );
    image_pipeline_assert($retry['downloaded'] === 1 && $visionCalls === 2, 'P05: reject pierwszego direct candidate uruchamia bounded próbę kolejnego.');
} finally {
    $database->rollBack();
}

foreach ([
    'https://127.0.0.1/image.png',
    'https://10.0.0.1/image.png',
    'https://localhost/image.png',
] as $blockedUrl) {
    image_pipeline_expect(
        static fn () => validate_remote_image_url($blockedUrl, static fn (): array => ['127.0.0.1']),
        'lokal'
    );
}

$bytes = thumbnail_mock_image_bytes();
$downloadCalls = 0;
$transport = static function (string $url) use (&$downloadCalls, $bytes): array {
    $downloadCalls++;
    return ['status' => 200, 'headers' => [], 'body' => $bytes, 'mime' => 'image/png'];
};
$resolver = static fn (string $host): array => ['93.184.216.34'];
$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-image-smoke-' . bin2hex(random_bytes(5));
try {
    $downloaded = download_source_image($selected, $transport, $resolver, $temporaryDirectory);
    $downloadedAgain = download_source_image($selected, $transport, $resolver, $temporaryDirectory);
    image_pipeline_assert($downloaded['status'] === 'downloaded', 'Nie zapisano pobranego obrazu.');
    image_pipeline_assert($downloaded['sha256'] === $downloadedAgain['sha256'], 'Deduplikacja skrótem jest niestabilna.');
    image_pipeline_assert(count(glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: []) === 1, 'Powstał duplikat pliku.');
    image_pipeline_assert($downloadCalls === 2, 'Fixture transportu nie został wykonany przewidywalnie.');

    image_pipeline_expect(
        static fn () => download_source_image(
            $selected,
            static fn (): array => ['status' => 200, 'headers' => [], 'body' => '<html>', 'mime' => 'image/png'],
            $resolver,
            $temporaryDirectory
        ),
        'MIME'
    );
    image_pipeline_expect(
        static fn () => download_source_image(
            $selected,
            static fn (): array => [
                'status' => 302,
                'headers' => ['location' => 'http://127.0.0.1/private'],
                'body' => '',
                'mime' => '',
            ],
            $resolver,
            $temporaryDirectory
        ),
        'przekierowanie'
    );
    image_pipeline_assert(
        str_contains(render_article_image_record(array_merge($downloaded, ['status' => 'missing'])), 'article-illustration--placeholder'),
        'Brak obrazu nie zachowuje placeholdera w kompozycji.'
    );
} finally {
    if (is_dir($temporaryDirectory)) {
        foreach (glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($temporaryDirectory);
    }
}

// ---- Polish UTF-8 and mixed-language Unicode tests ----

// (1) Pure Polish: query and title share meaningful Unicode tokens
image_pipeline_assert(
    source_image_candidate_matches_query(
        [
            'title' => 'Neuroplastyczność mózgu i synapsy',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Neuroplasticity_brain.svg',
        ],
        'neuroplastyczność mózgu synapsy'
    ),
    'Polish UTF-8 tokens neuroplastyczność/mózg/synapsy nie przeszły filtra semantycznego.'
);

// (2) Polish diacritics: token integrity — zażółć gęślą jaźń
image_pipeline_assert(
    source_image_candidate_matches_query(
        [
            'title' => 'Zażółć gęślą jaźń — test polskich znaków',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Polish_test.png',
        ],
        'zażółć gęślą jaźń'
    ),
    'Polish diacritic tokens zażółć/gęślą/jaźń nie przeszły filtra semantycznego.'
);

// (3) Mixed-language: shared scientific tokens between Polish and English
image_pipeline_assert(
    source_image_candidate_matches_query(
        [
            'title' => 'Synaptic plasticity in the mózg cortex',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Synaptic_plasticity_mózg.png',
        ],
        'synaptic plasticity mózg synapsy'
    ),
    'Mixed-language tokens (English+Polish) nie przeszły filtra semantycznego.'
);

// (4) Unrelated Polish vs English must still be rejected
image_pipeline_assert(
    !source_image_candidate_matches_query(
        [
            'title' => 'Zażółć gęślą jaźń — polski test',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Polish_test.png',
        ],
        'quantum entanglement photon'
    ),
    'Niezwiązane polskie i angielskie tokeny przeszły filtr semantyczny.'
);

// (5) Diagram semantics are intentionally deferred to Vision.
image_pipeline_assert(
    source_image_candidate_is_suitable_for_role(
        [
            'title' => 'Diagram mechanizmu neuroplastyczności w mózgu',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Neuroplasticity_diagram.png',
            'width' => 1600,
            'height' => 900,
        ],
        ['role' => 'hero']
    ),
    'Diagram w polskim tekście został zaakceptowany jako hero.'
);

// (6) Polish photograph title accepted as hero
image_pipeline_assert(
    source_image_candidate_is_suitable_for_role(
        [
            'title' => 'Fotografia mózgu pod mikroskopem',
            'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Brain_microscope.jpg',
            'width' => 1600,
            'height' => 900,
        ],
        ['role' => 'hero']
    ),
    'Polska fotografia nie została zaakceptowana jako hero.'
);

$directQueries = article_image_direct_queries([
    'search_queries' => ['specific direct subject'],
    'search_queries_related' => ['related context only'],
    'expected_content' => 'direct subject detail',
]);
image_pipeline_assert(
    array_column($directQueries, 'relation') === ['exact_subject', 'exact_subject']
        && !in_array('related context only', array_column($directQueries, 'query'), true),
    'P05 direct acquisition nie przechodzi do related query przed recovery.'
);

$accountingPlans = [
    [...$plan['hero'], 'visual_intent'=>'Controlled accounting hero',
        'expected_content'=>'Controlled accounting hero', 'search_queries'=>['controlled accounting hero']],
    [...$plan['inline'][0], 'section_id'=>'why-important', 'visual_intent'=>'Controlled accounting slot one',
        'expected_content'=>'Controlled accounting slot one', 'search_queries'=>['controlled accounting one']],
    [...$plan['inline'][0], 'section_id'=>'fact-1', 'visual_intent'=>'Controlled accounting slot two',
        'expected_content'=>'Controlled accounting slot two', 'search_queries'=>['controlled accounting two']],
];
$database->exec('INSERT INTO post_categories (title,description,slug,sort_order) VALUES ("Vision accounting","","vision-accounting",999998)');
$accountingCategoryId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO posts (category_id,title,excerpt,content,image_path,slug,is_published) VALUES (:category,"Vision accounting","","","","vision-accounting-post",0)')
    ->execute([':category'=>$accountingCategoryId]);
$accountingPostId = (int) $database->lastInsertId();
foreach ($accountingPlans as $accountingPlan) persist_article_image($accountingPostId, $accountingPlan, 'vision-accounting-fixture');
$accountingChecks = [];
$accountingTransportCalls = 0;
$budgetBeforeAccounting = (int) gemini_article_budget_state($accountingPostId)['used_calls'];
$accountingSummary = fulfill_article_source_images(
    $accountingPostId,
    static function (string $query) use ($results): array {
        $candidates = [];
        for ($index = 1; $index <= 4; $index++) {
            $suffix = substr(hash('sha256', $query), 0, 8) . '-' . $index;
            $candidates[] = [...$results[0],
                'provider_id'=>'accounting-' . $suffix,
                'title'=>$query . ' controlled candidate ' . $index,
                'source_page_url'=>'https://commons.wikimedia.org/wiki/File:Accounting-' . $suffix . '.jpg',
                'source_file_url'=>'https://upload.wikimedia.org/accounting-' . $suffix . '.jpg',
            ];
        }
        return $candidates;
    },
    static fn (array $candidate): array => [...$candidate,
        'status'=>'downloaded',
        'local_path'=>'images/posts/sources/' . hash('sha256', (string) $candidate['source_file_url']) . '.jpg',
        'downloaded_at'=>gmdate(DATE_ATOM),
    ],
    static function (array $candidate, array $plannedImage) use (&$accountingChecks, &$accountingTransportCalls, $database, $accountingPostId): array {
        $slot = (string) $plannedImage['section_id'];
        $accountingChecks[$slot] = ($accountingChecks[$slot] ?? 0) + 1;
        if ($accountingChecks[$slot] <= 3) throw new RuntimeException('local preflight fixture reject');
        $accountingTransportCalls++;
        $claim = gemini_article_budget_claim($database, $accountingPostId, ARTICLE_IMAGE_GEMINI_OPERATION_TYPE, 'images', 1, 'accounting-' . $slot . '-' . $accountingTransportCalls);
        gemini_article_budget_reconcile_claim($database, $accountingPostId, (string) $claim['claim_token'], 'completed');
        return ['semantic_relevance'=>9,'editorial_fit'=>9,'hero_fit'=>5,'depicts_required_subject'=>true,
            'misleading'=>false,'inappropriate'=>false,'decision'=>'accept','reason'=>'controlled real transport fixture'];
    },
    'direct',
    3,
    3
);
$budgetAfterAccounting = (int) gemini_article_budget_state($accountingPostId)['used_calls'];
image_pipeline_assert(
    $accountingSummary['local_candidate_checks'] === 12
    && $accountingSummary['vision_calls_attempted'] === 3
    && $accountingTransportCalls === 3
    && $budgetAfterAccounting - $budgetBeforeAccounting === 3,
    'Lokalne odrzucenia nadal zużywają real Vision allowance lub GeminiBudget: ' . generation_json([
        'summary'=>$accountingSummary, 'checks'=>$accountingChecks, 'transport_calls'=>$accountingTransportCalls,
        'budget_delta'=>$budgetAfterAccounting-$budgetBeforeAccounting,
    ])
);
image_pipeline_assert(
    $accountingSummary['downloaded'] === 3
    && count(array_filter($accountingChecks, static fn (int $checks): bool => $checks === 4)) === 3,
    'Lokalne odrzucenia pierwszego slotu zablokowały następne kandydatury albo drugi slot.'
);
$accountingRows = $database->prepare('SELECT search_audit_json FROM article_images WHERE post_id=:post AND section_id IN (:one,:two,:three)');
$accountingRows->execute([':post'=>$accountingPostId, ':one'=>$accountingPlans[0]['section_id'], ':two'=>$accountingPlans[1]['section_id'], ':three'=>$accountingPlans[2]['section_id']]);
foreach ($accountingRows->fetchAll(PDO::FETCH_COLUMN) as $auditJson) {
    $accountingAudit = json_decode((string) $auditJson, true) ?: [];
    image_pipeline_assert(
        count(array_filter($accountingAudit, static fn (array $entry): bool => !empty($entry['local_reject']))) === 3
        && count(array_filter($accountingAudit, static fn (array $entry): bool => !empty($entry['vision_transport_attempted']))) === 1,
        'Audit nie rozróżnia local reject od real Vision transport.'
    );
}

echo "ARTICLE_IMAGE_PIPELINE_SMOKE_OK\n";
