<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_ARTICLE_IMAGE_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_ARTICLE_IMAGE_SMOKE=1, aby uruchomić test obrazów bez sieci.\n");
    exit(2);
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');
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
image_pipeline_assert(article_inline_image_target_count(2000) === 3, 'Dla 2000 znaków oczekiwano 3 ilustracji.');
image_pipeline_assert(article_inline_image_target_count(3000) === 4, 'Dla 3000 znaków oczekiwano 4 ilustracji.');
image_pipeline_assert(article_inline_image_target_count(4000) === 5, 'Dla 4000 znaków oczekiwano 5 ilustracji.');
image_pipeline_assert(article_image_license_is_auto_safe('CC0 1.0'), 'CC0 nie zostało zaakceptowane.');
image_pipeline_assert(article_image_license_is_auto_safe('CC BY 4.0'), 'CC BY nie zostało zaakceptowane.');
image_pipeline_assert(article_image_license_is_auto_safe('Public Domain'), 'Public Domain nie zostało zaakceptowane.');
image_pipeline_assert(!article_image_license_is_auto_safe('CC BY-SA 4.0'), 'CC BY-SA nie trafiło do przeglądu.');
image_pipeline_assert(!article_image_license_is_auto_safe('royalty-free'), 'Royalty-free uznano za wystarczającą licencję.');
image_pipeline_assert(!(bool) app_config('ai_image_generation_enabled'), 'Generator obrazów AI nie jest domyślnie wyłączony.');

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
validate_article_illustration_plan($plan, article_section_blocks($draft), article_draft_main_content_length($draft));
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
    'nie może wypełniać pola źródłowego'
);

$wikimediaFixture = [
    'query' => [
        'pages' => [[
            'pageid' => 123,
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
$selected = select_source_image_from_results($plan['hero'], $results, '123');
image_pipeline_assert($selected['author'] === 'Fixture Author', 'Nie zapisano autora z rzeczywistego wyniku.');
image_pipeline_expect(
    static fn () => select_source_image_from_results($plan['hero'], $results, 'invented'),
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
    $firstImageId = persist_article_image($fixturePostId, $selected, 'fixture query');
    $secondImageId = persist_article_image($fixturePostId, $selected, 'fixture query');
    image_pipeline_assert($firstImageId === $secondImageId, 'Ponowny zapis utworzył duplikat slotu obrazu.');
    image_pipeline_assert(
        (int) $database->query(
            'SELECT COUNT(*) FROM article_images WHERE post_id = ' . $fixturePostId
        )->fetchColumn() === 1,
        'Idempotentny zapis pozostawił duplikaty.'
    );
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
        render_article_image_record(array_merge($downloaded, ['status' => 'missing'])) === '',
        'Brak obrazu nie jest obsługiwany bezpiecznie.'
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

echo "ARTICLE_IMAGE_PIPELINE_SMOKE_OK\n";
