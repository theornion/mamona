<?php

declare(strict_types=1);

/* P3-C: Image Vision Gate — generalization test across 3 science topics */

putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');
require_once dirname(__DIR__) . '/php/admin-database.php';

$passed = 0;
$failed = 0;
$failures = [];

function assert_test(bool $condition, string $label): void {
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        printf("  PASS: %s\n", $label);
    } else {
        $failed++;
        $failures[] = $label;
        printf("  FAIL: %s\n", $label);
    }
}

/* ========================================================================== */
/* Helper — build topic fixture                                               */
/* ========================================================================== */
function build_topic_fixture(string $topic): array {
    /* Real UTF-8 fixtures: tokenizer behavior must not depend on iconv or platform. */
    static $topics = [
        'neuroscience' => [
            'planned_visual_intent'  => 'mikrofotografia neuronów i synaps ilustrująca neuroplastyczność',
            'planned_expected'       => 'neurony synapsy mózg',
            'good_title'             => 'Mikrofotografia neuronów i synaps mózgu',
            'good_source_page'       => 'https://commons.wikimedia.org/wiki/File:Neuron_synapse.jpg',
            'good_source_file'       => 'https://upload.wikimedia.org/neuron-synapse.jpg',
            'good_provider_id'       => 'neuro-001',
            'bad_title'              => 'Ilustracja neuronów i synaps w mózgu',
            'bad_source_page'        => 'https://commons.wikimedia.org/wiki/File:Brain_neurons.jpg',
            'bad_source_file'        => 'https://upload.wikimedia.org/brain-neurons.jpg',
            'bad_provider_id'        => 'neuro-bad-001',
            'unrelated_title'        => 'Plate of pasta carbonara',
            'unrelated_source_page'  => 'https://commons.wikimedia.org/wiki/File:Pasta_carbonara.jpg',
            'unrelated_source_file'  => 'https://upload.wikimedia.org/pasta-carbonara.jpg',
            'unrelated_provider_id'  => 'food-001',
            'article_context'        => 'Neuroplastyczność to zdolność mózgu do zmian strukturalnych.',
        ],
        'astronomy' => [
            'planned_visual_intent'  => 'zdjęcie egzoplanety i gwiazdy macierzystej z teleskopu kosmicznego',
            'planned_expected'       => 'egzoplaneta gwiazda orbita',
            'good_title'             => 'Zdjęcie egzoplanety krążącej wokół gwiazdy',
            'good_source_page'       => 'https://commons.wikimedia.org/wiki/File:Exoplanet_artist_conception.jpg',
            'good_source_file'       => 'https://upload.wikimedia.org/exoplanet-concept.jpg',
            'good_provider_id'       => 'astro-001',
            'bad_title'              => 'Egzoplaneta i gwiazda na orbicie',
            'bad_source_page'        => 'https://commons.wikimedia.org/wiki/File:Exoplanet_orbit.jpg',
            'bad_source_file'        => 'https://upload.wikimedia.org/exoplanet-orbit.jpg',
            'bad_provider_id'        => 'astro-bad-001',
            'unrelated_title'        => 'Plate of spaghetti bolognese',
            'unrelated_source_page'  => 'https://commons.wikimedia.org/wiki/File:Spaghetti.jpg',
            'unrelated_source_file'  => 'https://upload.wikimedia.org/spaghetti.jpg',
            'unrelated_provider_id'  => 'food-002',
            'article_context'        => 'Egzoplanety to planety krążące wokół gwiazd poza naszym Układem Słonecznym.',
        ],
        'biology' => [
            'planned_visual_intent'  => 'ilustracja ewolucji i adaptacji gatunków w przyrodzie',
            'planned_expected'       => 'ewolucja adaptacja gatunki',
            'good_title'             => 'Ewolucja i adaptacja gatunków w dzikiej przyrodzie',
            'good_source_page'       => 'https://commons.wikimedia.org/wiki/File:Evolution_tree.jpg',
            'good_source_file'       => 'https://upload.wikimedia.org/evolution-tree.jpg',
            'good_provider_id'       => 'bio-001',
            'bad_title'              => 'Adaptacja i ewolucja gatunków w przyrodzie',
            'bad_source_page'        => 'https://commons.wikimedia.org/wiki/File:Species_adaptation.jpg',
            'bad_source_file'        => 'https://upload.wikimedia.org/species-adaptation.jpg',
            'bad_provider_id'        => 'bio-bad-001',
            'unrelated_title'        => 'Plate of lasagna al forno',
            'unrelated_source_page'  => 'https://commons.wikimedia.org/wiki/File:Lasagna.jpg',
            'unrelated_source_file'  => 'https://upload.wikimedia.org/lasagna.jpg',
            'unrelated_provider_id'  => 'food-003',
            'article_context'        => 'Ewolucja i adaptacja to kluczowe mechanizmy zmian w przyrodzie.',
        ],
    ];

    $t = $topics[$topic] ?? null;
    if ($t === null) {
        throw new InvalidArgumentException("Nieznany temat: {$topic}");
    }

    return [
        'planned' => [
            'role'             => 'hero',
            'section_id'       => 'article',
            'visual_intent'    => $t['planned_visual_intent'],
            'search_queries'   => [$t['good_title']],
            'expected_content' => $t['planned_expected'],
            'source_page_url'  => '',
            'source_file_url'  => '',
            'local_path'       => '',
            'author'           => '',
            'license'          => '',
            'license_url'      => '',
            'attribution'      => '',
            'alt'              => $t['good_title'],
            'caption'          => $t['good_title'],
            'layout'           => 'full',
            'status'           => 'planned',
        ],
        'good_candidate' => [
            'title'                => $t['good_title'],
            'source_page_url'      => $t['good_source_page'],
            'source_file_url'      => $t['good_source_file'],
            'author'               => 'Science Lab',
            'license'              => 'CC BY 4.0',
            'license_url'          => 'https://creativecommons.org/licenses/by/4.0/',
            'attribution'          => 'Science Lab, CC BY 4.0',
            'width'                => 2400,
            'height'               => 1600,
            'provider'             => 'wikimedia',
            'provider_id'          => $t['good_provider_id'],
            'third_party_warning'  => false,
            'identifiable_people'  => false,
            'trademarks_logos'     => false,
        ],
        'bad_candidate' => [
            'title'                => $t['bad_title'],
            'source_page_url'      => $t['bad_source_page'],
            'source_file_url'      => $t['bad_source_file'],
            'author'               => 'Bad Artist',
            'license'              => 'CC BY 4.0',
            'license_url'          => 'https://creativecommons.org/licenses/by/4.0/',
            'attribution'          => 'Bad Artist, CC BY 4.0',
            'width'                => 1600,
            'height'               => 900,
            'provider'             => 'wikimedia',
            'provider_id'          => $t['bad_provider_id'],
            'third_party_warning'  => false,
            'identifiable_people'  => false,
            'trademarks_logos'     => false,
        ],
        'unrelated_candidate' => [
            'title'                => $t['unrelated_title'],
            'source_page_url'      => $t['unrelated_source_page'],
            'source_file_url'      => $t['unrelated_source_file'],
            'author'               => 'Food Photographer',
            'license'              => 'CC0 1.0',
            'license_url'          => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'attribution'          => 'Food Photographer, CC0',
            'width'                => 2000,
            'height'               => 1333,
            'provider'             => 'wikimedia',
            'provider_id'          => $t['unrelated_provider_id'],
            'third_party_warning'  => false,
            'identifiable_people'  => false,
            'trademarks_logos'     => false,
        ],
        'article_context' => $t['article_context'],
    ];
}

$topics = ['neuroscience', 'astronomy', 'biology'];

/* ========================================================================== */
/* 1. PREFILTER — general properties                                          */
/* ========================================================================== */
echo "\n=== 1. PREFILTER: general properties ===\n";

$ref = new ReflectionFunction('article_image_semantic_gate_score');
$source = file_get_contents($ref->getFileName());

/* No blacklist regex for satire, zombie, gore, Trump in this function */
assert_test(
    !preg_match('/(?:satire|zombie|gore|trump)/i', $ref->getDocComment() ?? ''),
    'Brak blacklist slow w komentarzu funkcji'
);

/* Score is bounded 0-100 (use neuroscience fixture) */
$neuro = build_topic_fixture('neuroscience');
$scoreGood = article_image_semantic_gate_score($neuro['good_candidate'], $neuro['planned']);
assert_test($scoreGood >= 0 && $scoreGood <= 100, sprintf('Score dobry kandydat w zakresie 0-100 (score=%d)', $scoreGood));

/* Unrelated candidate gets low score */
$scoreBad = article_image_semantic_gate_score($neuro['unrelated_candidate'], $neuro['planned']);
assert_test($scoreBad >= 0 && $scoreBad <= 100, sprintf('Score niezwiązany kandydat w zakresie 0-100 (score=%d)', $scoreBad));

/* Empty planned image returns neutral 50 */
$emptyPlanned = ['visual_intent' => '', 'expected_content' => ''];
$scoreEmpty = article_image_semantic_gate_score($neuro['good_candidate'], $emptyPlanned);
assert_test($scoreEmpty === 50, sprintf('Pusty plan zwraca 50 (otrzymano %d)', $scoreEmpty));

/* Bonus for 2+ hits: Polish-on-Polish comparison */
$bonusCandidate = [
    'title'             => 'Neurony synapsy mozg mikrofotografia',
    'source_page_url'   => 'https://commons.wikimedia.org/wiki/File:Neuron.jpg',
    'source_file_url'   => '',
    'author'            => 'Lab',
    'license'           => 'CC0 1.0',
    'license_url'       => 'https://creativecommons.org/publicdomain/zero/1.0/',
    'attribution'       => 'Lab, CC0',
    'width'             => 2400,
    'height'            => 1600,
    'provider'          => 'wikimedia',
    'provider_id'       => 'bonus-001',
    'third_party_warning'  => false,
    'identifiable_people'  => false,
    'trademarks_logos'     => false,
];
$scoreBonus = article_image_semantic_gate_score($bonusCandidate, $neuro['planned']);
assert_test($scoreBonus >= 60, sprintf('Kandydat z 2+ trafieniami przechodzi prefilter (score=%d)', $scoreBonus));

/* ========================================================================== */
/* 2. PREFILTER — per topic                                                   */
/* ========================================================================== */
echo "\n=== 2. PREFILTER: per topic ===\n";

foreach ($topics as $topic) {
    printf("  --- Topic: %s ---\n", $topic);
    $fixture = build_topic_fixture($topic);

    /* Good candidate passes prefilter */
    $score = article_image_semantic_gate_score($fixture['good_candidate'], $fixture['planned']);
    assert_test(
        $score >= 60,
        sprintf('[%s] dobry kandydat ma score=%d (>=60)', $topic, $score)
    );

    /* Unrelated candidate fails prefilter */
    $scoreUnrelated = article_image_semantic_gate_score($fixture['unrelated_candidate'], $fixture['planned']);
    assert_test(
        $scoreUnrelated < 60,
        sprintf('[%s] niezwiązany kandydat ma score=%d (<60)', $topic, $scoreUnrelated)
    );

    /* Bad candidate (on-topic but semantically wrong) passes prefilter — reaches multimodal */
    $scoreBad = article_image_semantic_gate_score($fixture['bad_candidate'], $fixture['planned']);
    assert_test(
        $scoreBad >= 60,
        sprintf('[%s] zly kandydat (on-topic) ma score=%d (>=60, przechodzi prefilter)', $topic, $scoreBad)
    );
}

/* ========================================================================== */
/* 3. MULTIMODAL ASSESSMENT CONTRACT                                          */
/* ========================================================================== */
echo "\n=== 3. MULTIMODAL ASSESSMENT CONTRACT ===\n";

$refAssess = new ReflectionFunction('article_image_multimodal_assess');
$params = $refAssess->getParameters();
assert_test(count($params) === 4, sprintf('Funkcja ma 4 parametry (ma %d)', count($params)));
assert_test($params[0]->getName() === 'candidate', 'Parametr 1: candidate');
assert_test($params[1]->getName() === 'plannedImage', 'Parametr 2: plannedImage');
assert_test($params[2]->getName() === 'articleContext', 'Parametr 3: articleContext');
assert_test($params[3]->getName() === 'geminiVisionCallback', 'Parametr 4: geminiVisionCallback');
assert_test($params[3]->allowsNull(), 'Parametr 4 jest nullable');

/* With callback — returns structured result */
$mockAccept = static function (array $candidate, array $plannedImage, string $articleContext): array {
    return [
        'semantic_relevance' => 9,
        'editorial_fit' => 8,
        'depicts_required_subject' => true,
        'misleading' => false,
        'inappropriate' => false,
        'decision' => 'accept',
        'reason' => 'mock: dobry obraz',
    ];
};

$neuro = build_topic_fixture('neuroscience');
$result = article_image_multimodal_assess(
    $neuro['good_candidate'],
    $neuro['planned'],
    $neuro['article_context'],
    $mockAccept
);

$requiredFields = ['semantic_relevance', 'editorial_fit', 'depicts_required_subject', 'misleading', 'inappropriate', 'decision', 'reason'];
foreach ($requiredFields as $field) {
    assert_test(array_key_exists($field, $result), sprintf('Wynik zawiera pole: %s', $field));
}
assert_test((int) $result['semantic_relevance'] >= 0 && (int) $result['semantic_relevance'] <= 10, 'semantic_relevance w 0-10');
assert_test((int) $result['editorial_fit'] >= 0 && (int) $result['editorial_fit'] <= 10, 'editorial_fit w 0-10');
assert_test(is_bool($result['depicts_required_subject']), 'depicts_required_subject jest bool');
assert_test(is_bool($result['misleading']), 'misleading jest bool');
assert_test(is_bool($result['inappropriate']), 'inappropriate jest bool');
assert_test(in_array($result['decision'], ['accept', 'reject'], true), sprintf("decision to '%s' (accept|reject)", $result['decision']));
assert_test(is_string($result['reason']) && strlen($result['reason']) > 0, 'reason jest niepusty string');

/* Without callback — throws RuntimeException */
$threw = false;
try {
    article_image_multimodal_assess(
        $neuro['good_candidate'],
        $neuro['planned'],
        'context',
        null
    );
} catch (RuntimeException $e) {
    $threw = true;
    assert_test(str_contains($e->getMessage(), 'Gemini Vision'), 'Wiadomosc bledu wspomina Gemini Vision');
}
assert_test($threw, 'Brak callbacku rzuca RuntimeException');

/* ========================================================================== */
/* 4. POSITIVE FIXTURE — per topic                                            */
/* ========================================================================== */
echo "\n=== 4. POSITIVE FIXTURE: per topic ===\n";

foreach ($topics as $topic) {
    printf("  --- Topic: %s ---\n", $topic);
    $fixture = build_topic_fixture($topic);

    $positiveMock = static function (array $candidate, array $plannedImage, string $articleContext): array {
        return [
            'semantic_relevance' => 9,
            'editorial_fit' => 8,
            'depicts_required_subject' => true,
            'misleading' => false,
            'inappropriate' => false,
            'decision' => 'accept',
            'reason' => 'Obraz trafnie ilustruje temat.',
        ];
    };

    try {
        $selected = select_source_image_from_results(
            $fixture['planned'],
            [$fixture['good_candidate']],
            $fixture['good_candidate']['provider_id'],
            $positiveMock,
            $fixture['article_context']
        );
        assert_test($selected['status'] === 'selected', sprintf('[%s] status=selected', $topic));
        assert_test($selected['author'] === 'Science Lab', sprintf('[%s] autor zapisany', $topic));
        assert_test($selected['license'] === 'CC BY 4.0', sprintf('[%s] licencja zapisana', $topic));
    } catch (Throwable $e) {
        assert_test(false, sprintf('[%s] positive fixture rzuca wyjątek: %s', $topic, $e->getMessage()));
    }
}

/* ========================================================================== */
/* 5. NEGATIVE FIXTURE — per topic (bad candidate passes prefilter, REJECT by multimodal) */
/* ========================================================================== */
echo "\n=== 5. NEGATIVE FIXTURE: per topic ===\n";

foreach ($topics as $topic) {
    printf("  --- Topic: %s ---\n", $topic);
    $fixture = build_topic_fixture($topic);

    $negativeMock = static function (array $candidate, array $plannedImage, string $articleContext): array {
        return [
            'semantic_relevance' => 1,
            'editorial_fit' => 0,
            'depicts_required_subject' => false,
            'misleading' => true,
            'inappropriate' => true,
            'decision' => 'reject',
            'reason' => 'Obraz jest semantycznie nieadekwatny do tematu.',
        ];
    };

    $threwNegative = false;
    try {
        select_source_image_from_results(
            $fixture['planned'],
            [$fixture['bad_candidate']],
            $fixture['bad_candidate']['provider_id'],
            $negativeMock,
            $fixture['article_context']
        );
    } catch (InvalidArgumentException $e) {
        $threwNegative = true;
        assert_test(
            str_contains($e->getMessage(), 'odrzuc') || str_contains($e->getMessage(), 'multimodal'),
            sprintf('[%s] reject message: %s', $topic, $e->getMessage())
        );
    } catch (Throwable $e) {
        $threwNegative = true;
        assert_test(false, sprintf('[%s] nieoczekiwany wyjątek: %s (%s)', $topic, get_class($e), $e->getMessage()));
    }
    assert_test($threwNegative, sprintf('[%s] multimodal REJECT powoduje wyjątek', $topic));
}

/* ========================================================================== */
/* 6. PUBLICATION CONTRACT                                                    */
/* ========================================================================== */
echo "\n=== 6. PUBLICATION CONTRACT ===\n";

/* Use neuroscience as regression fixture for publication contract */
$pubFixture = build_topic_fixture('neuroscience');

/* 6a. prefilter PASS + multimodal REJECT = BLOCKED */
$rejectMock = static function (): array {
    return [
        'semantic_relevance' => 2,
        'editorial_fit' => 1,
        'depicts_required_subject' => false,
        'misleading' => true,
        'inappropriate' => false,
        'decision' => 'reject',
        'reason' => 'Semantycznie nieadekwatne.',
    ];
};

$passPrefilterResults = [
    [
        'title'             => 'Neurony synapsy mozg mikrofotografia',
        'source_page_url'   => 'https://commons.wikimedia.org/wiki/File:Brain.jpg',
        'source_file_url'   => 'https://upload.wikimedia.org/brain.jpg',
        'author'            => 'Lab',
        'license'           => 'CC0 1.0',
        'license_url'       => 'https://creativecommons.org/publicdomain/zero/1.0/',
        'attribution'       => 'Lab, CC0',
        'width'             => 2000,
        'height'            => 1333,
        'provider'          => 'wikimedia',
        'provider_id'       => 'brain-001',
        'third_party_warning'  => false,
        'identifiable_people'  => false,
        'trademarks_logos'     => false,
    ],
];

$blockedByMultimodal = false;
try {
    select_source_image_from_results(
        $pubFixture['planned'],
        $passPrefilterResults,
        'brain-001',
        $rejectMock,
        'context'
    );
} catch (InvalidArgumentException) {
    $blockedByMultimodal = true;
}
assert_test($blockedByMultimodal, 'prefilter PASS + multimodal REJECT = BLOCKED');

/* 6b. prefilter PASS + multimodal ACCEPT + legal PASS = moze przejsc */
$acceptMock = static function (): array {
    return [
        'semantic_relevance' => 9,
        'editorial_fit' => 8,
        'depicts_required_subject' => true,
        'misleading' => false,
        'inappropriate' => false,
        'decision' => 'accept',
        'reason' => 'Dobry fit.',
    ];
};

try {
    $ok = select_source_image_from_results(
        $pubFixture['planned'],
        $passPrefilterResults,
        'brain-001',
        $acceptMock,
        'context'
    );
    assert_test($ok['status'] === 'selected', 'prefilter PASS + multimodal ACCEPT + legal PASS = OK');
} catch (Throwable $e) {
    assert_test(false, sprintf('Powinno przejsc: %s', $e->getMessage()));
}

/* 6c. brak multimodal assessment = BLOCKED (throws RuntimeException) */
$noCallbackBlocked = false;
try {
    select_source_image_from_results(
        $pubFixture['planned'],
        $passPrefilterResults,
        'brain-001',
        null, /* no callback */
        'context'
    );
} catch (RuntimeException $e) {
    $noCallbackBlocked = true;
    assert_test(str_contains($e->getMessage(), 'Gemini Vision'), 'Brak callbacku -> RuntimeException z Gemini Vision');
}
assert_test($noCallbackBlocked, 'Brak multimodal assessment = BLOCKED');

/* 6d. placeholder/fallback/editorial_rejected status = BLOCKED niezależnie od token score */
$inlinePlanned = [
    'role'             => 'inline',
    'section_id'       => 'lead',
    'visual_intent'    => 'neurony synapsy mozg',
    'search_queries'   => ['neurons'],
    'expected_content' => 'neurony synapsy',
    'source_page_url'  => '',
    'source_file_url'  => '',
    'local_path'       => '',
    'author'           => '',
    'license'          => '',
    'license_url'      => '',
    'attribution'      => '',
    'alt'              => 'neurony',
    'caption'          => 'neurony',
    'layout'           => 'full',
    'status'           => 'planned',
];

/* Test render: fallback is_fallback=1 renders empty */
$renderedFallback = render_article_image_record([
    'status'       => 'downloaded',
    'local_path'   => 'images/posts/placeholder.jpg',
    'layout'       => 'full',
    'alt'          => 'placeholder',
    'caption'      => '',
    'attribution'  => '',
    'source_page_url' => '',
    'license'      => 'cc0',
    'license_url'  => '',
    'width'        => 800,
    'height'       => 600,
    'is_fallback'  => 1,
]);
assert_test($renderedFallback === '', 'Fallback is_fallback=1 renderuje pusty string');

/* Test render: missing status renders placeholder */
$renderedMissing = render_article_image_record([
    'status'       => 'missing',
    'local_path'   => '',
    'layout'       => 'full',
    'alt'          => 'brak',
    'caption'      => '',
    'attribution'  => '',
    'source_page_url' => '',
    'license'      => '',
    'license_url'  => '',
    'width'        => 0,
    'height'       => 0,
]);
assert_test(str_contains($renderedMissing, 'article-illustration--placeholder'), 'Status missing renderuje placeholder');

/* ========================================================================== */
/* 7. GEMINI BUDGET — deterministic                                           */
/* ========================================================================== */
echo "\n=== 7. GEMINI BUDGET ===\n";

$database = bueno_database();
$database->beginTransaction();

try {
    /* Create a test article budget row */
    $testArticleId = 999999;
    gemini_article_budget_ensure($database, $testArticleId);

    $state = gemini_article_budget_ensure($database, $testArticleId);
    assert_test((int) ($state['used_calls'] ?? 0) === 0, 'Budzet startuje od 0');
    assert_test((int) ($state['max_calls'] ?? 0) === 20, sprintf('Max calls = %d (oczekiwano 20)', $state['max_calls'] ?? 'null'));

    /* Increment 20 times — all should succeed */
    $budgetOk = true;
    for ($i = 1; $i <= 20; $i++) {
        try {
            gemini_article_budget_increment(
                $database,
                $testArticleId,
                ARTICLE_IMAGE_GEMINI_OPERATION_TYPE,
                'images',
                $i,
                'completed'
            );
        } catch (GeminiArticleBudgetException) {
            $budgetOk = false;
            assert_test(false, sprintf('Call %d powinien przejsc (max=20)', $i));
            break;
        }
    }
    assert_test($budgetOk, '20 callow przeszlo pomyslnie');

    $stateAfter20 = gemini_article_budget_ensure($database, $testArticleId);
    assert_test((int) ($stateAfter20['used_calls'] ?? 0) === 20, sprintf('Po 20 callach used_calls=%d', $stateAfter20['used_calls'] ?? 'null'));

    /* 21st call should throw GeminiArticleBudgetException */
    $call21Blocked = false;
    try {
        gemini_article_budget_increment(
            $database,
            $testArticleId,
            ARTICLE_IMAGE_GEMINI_OPERATION_TYPE,
            'images',
            21,
            'completed'
        );
    } catch (GeminiArticleBudgetException $e) {
        $call21Blocked = true;
        assert_test($e->articleId === $testArticleId, 'Exception ma poprawny articleId');
        assert_test($e->usedCalls === 20, sprintf('Exception: usedCalls=%d (oczekiwano 20)', $e->usedCalls));
    }
    assert_test($call21Blocked, 'Call 21 zablokowany przez GeminiArticleBudgetException');

    /* Verify operation type constant */
    assert_test(
        defined('ARTICLE_IMAGE_GEMINI_OPERATION_TYPE'),
        'Stala ARTICLE_IMAGE_GEMINI_OPERATION_TYPE jest zdefiniowana'
    );
    assert_test(
        ARTICLE_IMAGE_GEMINI_OPERATION_TYPE === 'image_vision_assessment',
        sprintf('Stala ma wartosc "%s"', ARTICLE_IMAGE_GEMINI_OPERATION_TYPE)
    );

    /* Verify calls log contains vision assessment entries */
    $log = json_decode((string) ($stateAfter20['calls_log_json'] ?? '[]'), true) ?: [];
    $visionEntries = array_filter($log, static fn (array $entry): bool => ($entry['operation_type'] ?? '') === 'image_vision_assessment');
    assert_test(count($visionEntries) === 20, sprintf('Log zawiera %d wpisow image_vision_assessment', count($visionEntries)));

    /* Production adapter: actual image + context + shared article budget, without live API. */
    $adapterArticleId = 999998;
    gemini_article_budget_ensure($database, $adapterArticleId);
    $capturedPayload = [];
    $transportCalls = 0;
    $visionTransport = static function (array $payload) use (&$capturedPayload, &$transportCalls): array {
        $capturedPayload = $payload;
        $transportCalls++;
        return [
            'status' => 200,
            'body' => generation_json([
                'responseId' => 'vision-mock-response',
                'candidates' => [[
                    'content' => ['parts' => [['text' => generation_json([
                        'semantic_relevance' => 9,
                        'editorial_fit' => 8,
                        'depicts_required_subject' => true,
                        'misleading' => false,
                        'inappropriate' => false,
                        'decision' => 'accept',
                        'reason' => 'Mock ocenił rzeczywisty obraz i kontekst VisualSlot.',
                    ])]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['totalTokenCount' => 1],
            ]),
            'headers' => [],
            'network_error' => '',
        ];
    };
    $imageTransport = static fn (): array => [
        'status' => 200,
        'headers' => [],
        'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        'mime' => 'image/png',
    ];
    $adapterResult = article_image_gemini_vision_assess(
        $adapterArticleId,
        $neuro['good_candidate'],
        $neuro['planned'],
        'Kontekst artykułu UTF-8: neuroplastyczność, mózg i synapsy.',
        $visionTransport,
        $imageTransport,
        'mock-key'
    );
    assert_test($adapterResult['decision'] === 'accept', 'Produkcyjny adapter zwraca strukturalne Vision ACCEPT');
    assert_test(
        isset($capturedPayload['contents'][0]['parts'][1]['inlineData']['data']),
        'Produkcyjny adapter przekazuje rzeczywiste bajty obrazu jako inlineData'
    );
    $promptText = (string) ($capturedPayload['contents'][0]['parts'][0]['text'] ?? '');
    assert_test(
        str_contains($promptText, 'neuroplastyczność')
        && str_contains($promptText, 'visual_intent')
        && str_contains($promptText, 'section_context'),
        'Produkcyjny adapter przekazuje article, section i VisualSlot context w UTF-8'
    );
    $adapterBudget = gemini_article_budget_ensure($database, $adapterArticleId);
    assert_test((int) $adapterBudget['used_calls'] === 1, 'Vision zużywa ten sam centralny GeminiBudget');
    $adapterLog = json_decode((string) $adapterBudget['calls_log_json'], true) ?: [];
    assert_test(
        ($adapterLog[0]['operation_type'] ?? '') === ARTICLE_IMAGE_GEMINI_OPERATION_TYPE,
        'Centralny budget loguje image_vision_assessment'
    );
    $database->prepare(
        'UPDATE article_generation_budget SET used_calls=20, is_exhausted=1 WHERE article_id=:id'
    )->execute([':id' => $adapterArticleId]);
    $blockedBeforeTransport = false;
    try {
        article_image_gemini_vision_assess(
            $adapterArticleId,
            $neuro['good_candidate'],
            $neuro['planned'],
            'context',
            $visionTransport,
            $imageTransport,
            'mock-key'
        );
    } catch (GeminiArticleBudgetException) {
        $blockedBeforeTransport = true;
    }
    assert_test($blockedBeforeTransport && $transportCalls === 1, 'Call 21 Vision jest blokowany przed provider transport');

} finally {
    $database->rollBack();
}

/* ========================================================================== */
/* SUMMARY                                                                    */
/* ========================================================================== */
echo "\n=== WYNIKI ===\n";
printf("Przeszlo: %d\n", $passed);
printf("Nieprzeszlo: %d\n", $failed);

if ($failures) {
    echo "\nNieudane testy:\n";
    foreach ($failures as $f) {
        printf("  - %s\n", $f);
    }
}

if ($failed === 0) {
    echo "P3-C-GENERAL-IMAGE-GATE: WSZYSTKIE TESTY PRZESZLY\n";
    exit(0);
} else {
    printf("P3-C-GENERAL-IMAGE-GATE: %d TESTOW NIE PRZESZLO\n", $failed);
    exit(1);
}
