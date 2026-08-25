<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_BATCH_NO_SPAWN=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function image_fairness_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$firstRound = article_image_fair_vision_allowances(['hero:lead', 'inline:one', 'inline:two'], 3, 3);
image_fairness_assert($firstRound === ['hero:lead'=>1, 'inline:one'=>1, 'inline:two'=>1],
    'Three available calls were not distributed one per unresolved required slot.');
$secondRound = article_image_fair_vision_allowances(['hero:lead', 'inline:one', 'inline:two'], 5, 3);
image_fairness_assert($secondRound === ['hero:lead'=>2, 'inline:one'=>2, 'inline:two'=>1],
    'Second-round allocation is not deterministic round-robin with hero first.');

$tiff = base64_decode('SUkqAK4AAACAGRKu1/gCDQeEQmFQuGQ2HQ+IRGJROEQKCRSMRmNRuORGLQWOyGRSOSQePyWUSmVQ6TyuXS+Sy2YTOaRiZTWcTmFzedT2czyfUGYUChUWU0SjUmRUilU2NUynVGJVCpVWG1SrVmKwOQVqvVeuV+xTuw2OzQasWenWm1Uq2W2jW+4UK5XOfXW7Tq8XmcXu+TS/X+h2XBVHA4WV4fEUfCYuk4rHSSAgEAD+AAQAAQAAAAAAAAAAAQQAAQAAACAAAAABAQQAAQAAACAAAAACAQMABAAAAHQBAAADAQMAAQAAAAUAAAAGAQMAAQAAAAIAAAARAQQAAQAAAAgAAAAVAQMAAQAAAAQAAAAWAQQAAQAAACAAAAAXAQQAAQAAAKYAAAAaAQUAAQAAAHwBAAAbAQUAAQAAAIQBAAAcAQMAAQAAAAEAAAAoAQMAAQAAAAIAAAA9AQMAAQAAAAIAAABSAQMAAQAAAAIAAAAAAAAACAAIAAgACAAAdwEA6AMAAAB3AQDoAwAA', true);
image_fairness_assert(is_string($tiff), 'TIFF fixture is invalid.');
$candidate = [
    'source_file_url'=>'https://example.org/original-science-image.tif',
    'source_page_url'=>'https://example.org/source-record',
    'author'=>'Fixture Author', 'license'=>'CC BY 4.0',
];
$candidateBefore = $candidate;
$tiffPrepared = article_image_vision_input($candidate, static fn (): array => [
    'status'=>200, 'body'=>$tiff, 'headers'=>[], 'mime'=>'image/tiff',
]);
image_fairness_assert($tiffPrepared['preprocessed'] === true && $tiffPrepared['mime'] === 'image/jpeg'
    && $tiffPrepared['preprocess_type'] === 'tiff_to_jpeg'
    && $tiffPrepared['prepared_size'] === strlen($tiffPrepared['bytes']),
    'Valid TIFF did not produce a compatible local Vision copy.');
image_fairness_assert($tiffPrepared['original_sha256'] === hash('sha256', $tiff)
    && $tiffPrepared['prepared_sha256'] === hash('sha256', $tiffPrepared['bytes'])
    && $candidate === $candidateBefore,
    'TIFF preprocessing did not preserve source identity/rights or auditable hashes.');

$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAAwAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDCooor+Tz/AEICiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA//2Q==', true);
image_fairness_assert(is_string($jpeg), 'JPEG fixture is invalid.');
$oversizedJpeg = $jpeg . str_repeat("\0", 4096);
$jpegPrepared = article_image_prepare_vision_copy($oversizedJpeg, 'image/jpeg', 1024);
image_fairness_assert($jpegPrepared['preprocessed'] === true && $jpegPrepared['mime'] === 'image/jpeg'
    && strlen($jpegPrepared['bytes']) <= 1024
    && $jpegPrepared['original_sha256'] === hash('sha256', $oversizedJpeg)
    && $jpegPrepared['preprocess_type'] === 'resize_compress'
    && $jpegPrepared['preprocess_success'] === true,
    'Oversized valid JPEG did not produce an in-limit derivative Vision copy.');

$candidateBase = [
    'title'=>'Quantum sensor apparatus.jpg',
    'source_page_url'=>'https://example.org/quantum-sensor-record',
    'source_file_url'=>'https://example.org/quantum-sensor-apparatus.jpg',
    'author'=>'Fixture Author', 'license'=>'CC BY 4.0',
    'license_url'=>'https://creativecommons.org/licenses/by/4.0/',
    'attribution'=>'Fixture Author, CC BY 4.0', 'provider'=>'wikimedia', 'provider_id'=>'fixture-1',
    'width'=>1600, 'height'=>900,
    'third_party_warning'=>false, 'identifiable_people'=>false, 'trademarks_logos'=>false,
];
$oversizedMetadata = [...$candidateBase, 'bytes'=>(int) app_config('source_image_max_bytes') + 1];
$oversizedPool = article_image_ranked_candidate_pool(
    ['role'=>'inline', 'topic_source'=>'A'],
    [['query'=>'quantum sensor apparatus','relation'=>'exact_subject','level'=>'exact_direct']],
    static fn (): array => [$oversizedMetadata]
);
image_fairness_assert($oversizedPool['ranked_candidate_count'] === 1 && $oversizedPool['hard_reject_count'] === 0,
    'Transformable oversized JPEG was rejected before local preprocessing.');

$tooSmallPool = article_image_ranked_candidate_pool(
    ['role'=>'inline', 'topic_source'=>'A'],
    [['query'=>'quantum sensor apparatus','relation'=>'exact_subject','level'=>'exact_direct']],
    static fn (): array => [[...$candidateBase, 'provider_id'=>'fixture-small', 'width'=>320, 'height'=>200]]
);
image_fairness_assert(($tooSmallPool['hard_reject_reasons']['hard_technical_ineligible:too_small'] ?? 0) === 1,
    'Granular hard reject reason was not written to the existing search audit.');
foreach ([
    'rights_or_license'=>'Brak licencji.', 'unavailable'=>'Adres obrazu jest niedostępny.',
    'download_failure'=>'Źródło obrazu zwróciło HTTP 500.', 'unsupported_format'=>'Niedozwolony format TIFF.',
    'oversized'=>'Obraz przekracza maksymalny rozmiar.', 'corrupt'=>'Źródło nie jest dekodowalnym obrazem.',
    'logo_or_icon'=>'Kandydat jest logo.', 'placeholder'=>'Kandydat jest placeholder.',
    'document_or_page_scan'=>'Kandydat jest dokumentem PDF.', 'other_hard_technical'=>'Nieznany błąd techniczny.',
] as $reason=>$message) {
    image_fairness_assert(article_image_local_reject_reason(new RuntimeException($message)) === $reason,
        'Local reject taxonomy does not classify ' . $reason . '.');
}

$providerCalls = [];
$sleepCalls = [];
$searcher = article_image_default_searcher(
    static function (string $provider, string $query) use (&$providerCalls): array {
        $providerCalls[$provider] = ($providerCalls[$provider] ?? 0) + 1;
        if ($provider === 'wikimedia') throw new SourceImageProviderRateLimitException(1);
        return $provider === 'openverse' ? [['provider'=>'openverse','query'=>$query]] : [];
    },
    static function (int $milliseconds) use (&$sleepCalls): void { $sleepCalls[] = $milliseconds; }
);
$firstProviderResult = $searcher('first query');
$secondProviderResult = $searcher('second query');
image_fairness_assert(($providerCalls['wikimedia'] ?? 0) === 3
    && ($providerCalls['openverse'] ?? 0) === 2
    && $sleepCalls === [1000, 1000]
    && count($firstProviderResult) === 1 && count($secondProviderResult) === 1,
    'Wikimedia 429 retry/circuit breaker did not remain bounded or blocked other providers.');
$rateLimitedOnly = article_image_default_searcher(
    static function (string $provider, string $query): array {
        throw new SourceImageProviderRateLimitException(1);
    },
    static function (int $milliseconds): void {}
);
image_fairness_assert($rateLimitedOnly('rate-limited query') === [],
    'Provider-only 429 exhaustion must degrade to an empty image pool instead of failing the whole article as a Gemini error.');

$seriesA = ['title'=>'Minister Clark laboratory visit -4.jpg'];
$seriesB = ['title'=>'Minister Clark laboratory visit -6.jpg'];
$diverse = ['title'=>'High-speed neural microscopy apparatus.jpg'];
$seriesKey = article_image_candidate_series_key($seriesA);
$rejectedSeries = [$seriesKey=>true];
image_fairness_assert(article_image_assessment_is_severe_reject([
    'decision'=>'reject', 'semantic_relevance'=>1, 'editorial_fit'=>2,
]), 'Severe semantic reject was not classified locally.');
image_fairness_assert(article_image_candidate_deferred_for_rejected_series($seriesB, $rejectedSeries)
    && !article_image_candidate_deferred_for_rejected_series($diverse, $rejectedSeries),
    'Same-series candidate was not deferred in favor of a diverse candidate.');
$nasaSeriesA = ['provider_id'=>'KSC-20201028-PH-JBS02_0011', 'title'=>'Creative Photography - Nature/Wildlife'];
$nasaSeriesB = ['provider_id'=>'KSC-20201028-PH-JBS02_0139', 'title'=>'Creative Photography - Nature/Wildlife'];
$nasaDiverse = ['provider_id'=>'KSC-20210413-PH-JBS01_0002', 'title'=>'Creative Photography - Nature/Wildlife'];
image_fairness_assert(
    article_image_candidate_series_key($nasaSeriesA) === article_image_candidate_series_key($nasaSeriesB)
    && article_image_candidate_series_key($nasaSeriesA) !== article_image_candidate_series_key($nasaDiverse),
    'Structured provider ids do not produce a stable cross-batch photographic-series key.'
);

image_fairness_assert((int) gemini_article_budget_state(999999)['used_calls'] === 0,
    'Local preprocessing or allocation unexpectedly consumed Gemini budget.');

echo "IMAGE_FAIRNESS_PREPROCESSING_SMOKE_OK\n";
