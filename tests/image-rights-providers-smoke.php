<?php

declare(strict_types=1);

$databaseFile = sys_get_temp_dir() . '/mamona-image-rights-' . bin2hex(random_bytes(5)) . '.sqlite';
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_PUBLIC_URL=https://example.test');
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('SMITHSONIAN_API_KEY=');
putenv('EUROPEANA_API_KEY=');
putenv('PEXELS_API_KEY=');
require_once dirname(__DIR__) . '/php/admin-database.php';

function rights_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rights_reject(callable $callback, string $label): void
{
    try { $callback(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException($label . ' nie został odrzucony.');
}

function rights_candidate(string $license, string $licenseUrl, array $extra = []): array
{
    return [...[
        'title' => 'Scientific landscape', 'source_page_url' => 'https://example.test/asset',
        'source_file_url' => 'https://example.test/asset.jpg', 'author' => 'Institution',
        'license' => $license, 'license_url' => $licenseUrl, 'attribution' => 'Institution, ' . $license,
        'rights_statement_raw' => $license, 'width' => 1600, 'height' => 900,
        'provider' => 'fixture', 'provider_id' => 'asset-1', 'chosen_query' => 'science', 'topic_role' => 'hero',
        'third_party_warning' => false, 'identifiable_people' => false, 'trademarks_logos' => false,
    ], ...$extra];
}

foreach ([
    ['CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/'],
    ['Public Domain', 'https://creativecommons.org/publicdomain/mark/1.0/'],
    ['CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/'],
    ['CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/'],
] as [$license, $url]) {
    $accepted = validate_source_image_candidate(rights_candidate($license, $url));
    rights_assert($accepted['status'] === 'selected'
        && array_diff(IMAGE_RIGHTS_REQUIRED_FIELDS, array_keys($accepted['rights_manifest'])) === [],
        $license . ' nie ma kompletnego manifestu.');
}
foreach (['CC BY-NC 4.0', 'CC BY-ND 4.0', 'unknown', 'all rights reserved'] as $license) {
    rights_reject(static fn () => validate_source_image_candidate(rights_candidate($license, 'https://example.test/license')), $license);
}
rights_reject(static fn () => validate_source_image_candidate(rights_candidate('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', ['third_party_warning' => true])), 'third-party');
rights_reject(static fn () => validate_source_image_candidate(rights_candidate('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', ['identifiable_people' => true])), 'identifiable people');
rights_reject(static fn () => validate_source_image_candidate(rights_candidate('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', ['trademarks_logos' => true])), 'logo');
$missingFlag = rights_candidate('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/');
unset($missingFlag['third_party_warning']);
rights_reject(static fn () => validate_source_image_candidate($missingFlag), 'missing rights flag');

$smithsonianCc0 = ['response' => ['rows' => [[
    'id' => 's1', 'title' => 'Meteorite', 'url' => 'https://www.si.edu/object/s1',
    'content' => ['descriptiveNonRepeating' => ['online_media' => ['media' => [[
        'ids' => ['id' => 'sm1'], 'usage' => ['access' => 'CC0'],
        'resources' => [['url' => 'https://ids.si.edu/ids/deliveryService/id/sm1', 'width' => 1600, 'height' => 900]],
    ]]]], 'freetext' => ['name' => [['content' => 'Smithsonian']]]],
]]]];
rights_assert(count(search_smithsonian_images('meteorite', static fn (): array => $smithsonianCc0)) === 1, 'Smithsonian CC0 nie przeszedł.');
$smithsonianRestricted = $smithsonianCc0;
$smithsonianRestricted['response']['rows'][0]['content']['descriptiveNonRepeating']['online_media']['media'][0]['usage']['access'] = 'Usage conditions apply';
rights_assert(search_smithsonian_images('meteorite', static fn (): array => $smithsonianRestricted) === [], 'Smithsonian bez CC0 przeszedł.');

$europeana = ['items' => [[
    'id' => '/1/a', 'title' => ['Microscope'], 'edmIsShownAt' => ['https://europeana.eu/item/1/a'],
    'edmIsShownBy' => ['https://images.example.test/a.jpg'], 'dcCreator' => ['Museum'],
    'rights' => ['http://creativecommons.org/licenses/by-sa/4.0/'],
]]];
rights_assert(count(search_europeana_images('microscope', static fn (): array => $europeana)) === 1, 'Europeana BY-SA nie przeszła.');
foreach (['http://rightsstatements.org/vocab/InC/1.0/', 'http://rightsstatements.org/vocab/NoC-NC/1.0/', 'https://creativecommons.org/licenses/by-nd/4.0/'] as $rights) {
    $fixture = $europeana; $fixture['items'][0]['rights'] = [$rights];
    rights_assert(search_europeana_images('microscope', static fn (): array => $fixture) === [], 'Europeana niedozwolone rights przeszło.');
}

$pexels = ['photos' => [[
    'id' => 42, 'url' => 'https://www.pexels.com/photo/42/', 'photographer' => 'Ada',
    'alt' => 'Laboratory glassware', 'width' => 1600, 'height' => 900,
    'src' => ['large2x' => 'https://images.pexels.com/photos/42/pexels-photo-42.jpeg'],
]]];
$pexelsResult = search_pexels_images('laboratory', static fn (): array => $pexels);
rights_assert(count($pexelsResult) === 1 && $pexelsResult[0]['attribution'] === 'Photo by Ada on Pexels', 'Credit Pexels jest nieprawidłowy.');
$pexels['photos'][0]['alt'] = 'Portrait of scientist with logo';
rights_assert(search_pexels_images('scientist', static fn (): array => $pexels) === [], 'Pexels z osobą/logo przeszedł full-auto.');

rights_reject(static fn () => validate_institutional_image_candidate(rights_candidate('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', ['rights_statement_raw' => 'ESO CC BY 4.0 exception: not covered']), 'eso'), 'ESO exception');
rights_reject(static fn () => validate_institutional_image_candidate(rights_candidate('Public Domain', 'https://www.nasa.gov/nasa-brand-center/images-and-media/', ['rights_statement_raw' => 'NASA courtesy of third-party copyright owner']), 'nasa'), 'NASA third party');
rights_reject(static fn () => validate_institutional_image_candidate(rights_candidate('Public Domain', 'https://www.cancer.gov/policies/copyright-reuse', ['rights_statement_raw' => 'Copyright Protected']), 'nci'), 'NCI protected');
$eso = validate_institutional_image_candidate(rights_candidate('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', [
    'provider_id' => 'eso-1', 'attribution' => 'ESO/L. Calçada — CC BY 4.0',
    'rights_statement_raw' => 'ESO default CC BY 4.0',
]), 'eso');
rights_assert($eso['rights_manifest']['exact_credit_line'] === 'ESO/L. Calçada — CC BY 4.0', 'ESO nie zachował pełnego credit line.');
$usgs = validate_institutional_image_candidate(rights_candidate('Public Domain', 'https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits', [
    'provider_id' => 'usgs-1', 'rights_statement_raw' => 'USGS-produced Public Domain media',
]), 'usgs');
rights_assert($usgs['status'] === 'selected', 'Własny asset USGS Public Domain nie przeszedł.');
$nci = validate_institutional_image_candidate(rights_candidate('Public Domain', 'https://www.cancer.gov/policies/copyright-reuse', [
    'provider_id' => 'nci-1', 'rights_statement_raw' => 'Public Domain',
]), 'nci');
rights_assert($nci['status'] === 'selected', 'NCI Public Domain per item nie przeszedł.');
$esoCatalogCandidate = rights_candidate('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', [
    'provider_id' => 'eso-catalog-1', 'attribution' => 'ESO/A. Example — CC BY 4.0',
    'rights_statement_raw' => 'ESO default CC BY 4.0',
]);
$catalog = search_institutional_catalog_images('eso', 'nebula', static fn (): array => ['results' => [$esoCatalogCandidate]]);
rights_assert(count($catalog) === 1, 'Katalog ESO nie zwrócił zwalidowanego per-item assetu.');
rights_assert(search_source_images('science', 'eso') === [] && search_source_images('science', 'usgs') === [] && search_source_images('science', 'nci') === [], 'Brak katalogu instytucjonalnego blokuje waterfall.');

$diagnostics = image_provider_diagnostics();
rights_assert(($diagnostics['unsplash']['mode'] ?? '') === 'manual_only' && ($diagnostics['pixabay']['mode'] ?? '') === 'manual_only', 'Manual-only provider jest aktywny w full-auto.');
rights_assert(search_smithsonian_images('science') === [] && search_europeana_images('science') === [] && search_pexels_images('science') === [], 'Brak klucza providera blokuje lub uruchamia sieć.');
rights_reject(static fn () => search_source_images('science', 'unsplash', static fn (): array => []), 'Unsplash full-auto');
image_provider_cache_put('unsplash', 'science', [['provider_id' => 'must-not-run']]);
rights_reject(static fn () => search_source_images('science', 'unsplash'), 'Cached Unsplash full-auto');
$cacheCalls = 0;
$loader = static function () use (&$cacheCalls): array { $cacheCalls++; return [['id' => 'cached']]; };
source_image_search_cached('fixture-cache', 'same query', null, $loader);
source_image_search_cached('fixture-cache', 'same query', null, $loader);
rights_assert($cacheCalls === 1, 'Cache providera nie ogranicza powtarzanych żądań.');
image_provider_rate_limit_acquire('fixture-rate', 2);
image_provider_rate_limit_acquire('fixture-rate', 2);
try {
    image_provider_rate_limit_acquire('fixture-rate', 2);
    throw new RuntimeException('Limit providera nie zadziałał.');
} catch (RuntimeException $exception) {
    rights_assert(str_contains($exception->getMessage(), 'limit zapytań'), 'Nieoczekiwany błąd limitu providera.');
}

$database = bueno_database();
$fixtureId = 900000 + random_int(1, 90000);
$database->prepare('INSERT INTO post_categories(id,title,description,slug,sort_order) VALUES(:id,"Rights fixture","",:slug,999999)')
    ->execute([':id' => $fixtureId, ':slug' => 'rights-fixture-' . $fixtureId]);
$database->prepare('INSERT INTO posts(id,category_id,title,excerpt,content,image_path,slug,is_published) VALUES(:id,:category,"Rights fixture","","","",:slug,0)')
    ->execute([':id' => $fixtureId, ':category' => $fixtureId, ':slug' => 'rights-post-' . $fixtureId]);
persist_article_image($fixtureId, [
    'role' => 'hero', 'section_id' => 'article', 'visual_intent' => 'neutralny schemat naukowy',
    'expected_content' => 'neutralny schemat naukowy', 'search_queries' => ['science'],
    'alt' => 'Neutralny schemat naukowy', 'caption' => 'Neutralny schemat naukowy',
    'layout' => 'full', 'status' => 'missing',
]);
$fallbackPaths = salvage_local_editorial_images($fixtureId);
$fallback = list_article_images($fixtureId)[0] ?? [];
rights_assert(count($fallbackPaths) === 1 && image_rights_manifest_from_record($fallback) !== null, 'Końcowy fallback SVG nie ma manifestu praw.');
$fallbackHtml = render_article_image_record($fallback, true);
rights_assert(str_contains($fallbackHtml, 'article-image-context-note')
    && strpos($fallbackHtml, 'article-image-context-note') < strpos($fallbackHtml, 'article-image-credit'),
    'Informacja o charakterze ilustracyjnym nie jest oddzielona nad źródłem.');
rights_assert(!str_contains((string) ($fallback['caption'] ?? ''), 'nie jest zdjęciem'), 'Notatka kontekstowa nadal jest częścią głównego podpisu.');
$watermarkReason = source_image_watermark_preflight([
    'provider'=>'rawpixel','source_file_url'=>'https://images.rawpixel.com/preview/watermarked-stock-comp.jpg',
    'title'=>'Stock preview','is_original_download'=>false,
]);
rights_assert($watermarkReason !== null, 'Rawpixel watermarked preview was not rejected.');
if (function_exists('imagecreatetruecolor')) {
    $transparent=imagecreatetruecolor(32,32);imagealphablending($transparent,false);imagesavealpha($transparent,true);
    imagefill($transparent,0,0,imagecolorallocatealpha($transparent,255,255,255,127));
    imageline($transparent,2,2,29,29,imagecolorallocatealpha($transparent,20,20,20,0));
    ob_start();imagepng($transparent);$transparentBytes=(string)ob_get_clean();imagedestroy($transparent);
    rights_assert(source_image_has_actual_transparency($transparentBytes,'image/png'), 'Actual PNG transparency was not detected.');
    $opaque=imagecreatetruecolor(32,32);imagefill($opaque,0,0,imagecolorallocate($opaque,255,255,255));
    ob_start();imagepng($opaque);$opaqueBytes=(string)ob_get_clean();ob_start();imagejpeg($opaque);$jpegBytes=(string)ob_get_clean();imagedestroy($opaque);
    rights_assert(!source_image_has_actual_transparency($opaqueBytes,'image/png') && !source_image_has_actual_transparency($jpegBytes,'image/jpeg'), 'Opaque PNG/JPEG was marked transparent.');
}
$publicCss = (string) file_get_contents(dirname(__DIR__) . '/assets/css/main.css');
$adminCss = (string) file_get_contents(dirname(__DIR__) . '/assets/css/admin.css');
rights_assert(str_contains($publicCss, '.article-image-context-note')
    && str_contains($publicCss, 'color: #8a949d')
    && str_contains($adminCss, '.proposal-image-context-note'),
    'Notatka kontekstowa nie ma dyskretnego, wyszarzonego stylu w podglądzie.');
rights_assert(str_contains($publicCss, '.article-illustration--transparent > img') && str_contains($publicCss, 'background: #fff'), 'Transparent asset has no scoped white background.');
foreach ($fallbackPaths as $relativePath) {
    $absolute = realpath(app_path($relativePath));
    $allowed = realpath(app_post_image_path('editorial-fallback'));
    if (is_string($absolute) && is_string($allowed) && str_starts_with($absolute, $allowed . DIRECTORY_SEPARATOR) && is_file($absolute)) unlink($absolute);
}

echo "IMAGE_RIGHTS_PROVIDERS_SMOKE_OK\n";
@unlink($databaseFile);
