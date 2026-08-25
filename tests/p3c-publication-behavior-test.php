<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_AI_IMAGE_GENERATION_ENABLED=false');
require_once dirname(__DIR__) . '/php/admin-database.php';

$passed = 0;
$failed = 0;

function p3c_behavior_assert(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
}

function p3c_behavior_expect_block(callable $callback, string $needle, string $label): void
{
    try {
        $callback();
        p3c_behavior_assert(false, $label . ' (brak wyjątku)');
    } catch (RuntimeException $exception) {
        p3c_behavior_assert(
            str_contains($exception->getMessage(), $needle)
                || str_contains($exception->getMessage(), 'brak prawidłowego hero'),
            $label
        );
    }
}

$database = bueno_database();
$database->exec('PRAGMA foreign_keys = OFF');
$database->prepare(
    'INSERT INTO post_categories (title, description, slug, sort_order) VALUES ("P3-C", "", "p3-c", 0)'
)->execute();
$categoryId = (int) $database->lastInsertId();
$database->prepare(
    'INSERT INTO posts (category_id, title, excerpt, content, slug, status, is_published)
     VALUES (:category, "P3-C behavioral", "Kontekst", "Treść", "p3-c-behavioral", "draft", 0)'
)->execute([':category' => $categoryId]);
$postId = (int) $database->lastInsertId();

$database->prepare(
    'INSERT INTO article_draft_versions (
        research_package_id, topic_id, post_id, generation_operation_id, version_number,
        composition_mode, execution_mode, status, draft_json, is_active
     ) VALUES (1, 1, :post, 1001, 1, "informational", "manual", "frozen", "{}", 1)'
)->execute([':post' => $postId]);
$draftId = (int) $database->lastInsertId();
$database->prepare(
    'INSERT INTO quality_check_runs (
        draft_version_id, post_id, generation_operation_id, check_number, execution_mode,
        status, model_score, final_score, passed, model_result_json, hard_blocks_json
     ) VALUES (:draft, :post, 1002, 1, "manual", "completed", 90, 90, 1, "{}", "[]")'
)->execute([':draft' => $draftId, ':post' => $postId]);
$database->prepare(
    'INSERT INTO final_multimodal_qc_runs (
        post_id, draft_version_id, status, decision, result_json,
        deterministic_gates_json, completed_at
     ) VALUES (:post, :draft, "completed", "PASS", "{}", "[]", CURRENT_TIMESTAMP)'
)->execute([':post' => $postId, ':draft' => $draftId]);

$assetPath = app_path('images/posts/2c48fdab2b5b333c482d6a940cf767ca.jpg');
if (!is_dir(dirname($assetPath))) mkdir(dirname($assetPath), 0777, true);
file_put_contents($assetPath, 'p3c test asset');
$image = [
    'role' => 'hero',
    'section_id' => 'article',
    'visual_intent' => 'ogólna ilustracja naukowa',
    'expected_content' => 'rzeczywisty przedmiot artykułu',
    'search_queries' => ['science'],
    'source_page_url' => 'https://example.test/source',
    'source_file_url' => 'https://example.test/image.jpg',
    'local_path' => 'images/posts/2c48fdab2b5b333c482d6a940cf767ca.jpg',
    'author' => 'Test Author',
    'license' => 'CC0 1.0',
    'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
    'attribution' => 'Test Author, CC0',
    'alt' => 'Ilustracja naukowa',
    'caption' => 'Ilustracja testowa',
    'layout' => 'full',
    'status' => 'downloaded',
    'width' => 1200,
    'height' => 800,
    'is_fallback' => 0,
    'multimodal_accepted' => 1,
    'multimodal_assessment' => [
        'semantic_relevance' => 9,
        'editorial_fit' => 9,
        'depicts_required_subject' => true,
        'misleading' => false,
        'inappropriate' => false,
        'decision' => 'accept',
        'reason' => 'Mock Vision ACCEPT.',
    ],
];
$imageId = persist_article_image($postId, $image, 'science');

try {
    assert_post_quality_allows_publication($postId);
    p3c_behavior_assert(true, 'technical/legal PASS + persisted multimodal ACCEPT pozwala przejść publication gate');
} catch (Throwable $exception) {
    p3c_behavior_assert(false, 'publication gate odrzucił poprawny obraz: ' . $exception->getMessage());
}

$database->prepare(
    'UPDATE final_multimodal_qc_runs SET decision="FAIL" WHERE post_id=:post AND draft_version_id=:draft'
)->execute([':post' => $postId, ':draft' => $draftId]);
p3c_behavior_expect_block(
    static fn () => assert_post_quality_allows_publication($postId),
    'finalnego QC multimodalnego',
    'brak pozytywnego finalnego QC multimodalnego blokuje publikację'
);
$database->prepare(
    'UPDATE final_multimodal_qc_runs SET decision="PASS" WHERE post_id=:post AND draft_version_id=:draft'
)->execute([':post' => $postId, ':draft' => $draftId]);

$database->prepare(
    'INSERT INTO generation_batches (batch_key, request_key, status, item_count)
     VALUES ("p3-c-batch", "p3-c-request", "running", 1)'
)->execute();
$batchId = (int) $database->lastInsertId();
$database->prepare(
    'INSERT INTO generation_batch_items (batch_id, topic_id, post_id, status, stage)
     VALUES (:batch, 1, :post, "manual_review", "quality_check")'
)->execute([':batch' => $batchId, ':post' => $postId]);
p3c_behavior_expect_block(
    static fn () => assert_post_quality_allows_publication($postId),
    'przeglądu redakcyjnego',
    'manual_review behavioral gate blokuje publikację'
);
$database->prepare('DELETE FROM generation_batch_items WHERE post_id=:post')->execute([':post' => $postId]);

$database->prepare('UPDATE article_images SET multimodal_accepted=0 WHERE id=:id')->execute([':id' => $imageId]);
p3c_behavior_expect_block(
    static fn () => assert_post_quality_allows_publication($postId),
    'prawidłowych grafik',
    'brak multimodal ACCEPT blokuje publikację'
);
$database->prepare('UPDATE article_images SET multimodal_accepted=1, editorial_rejected=1 WHERE id=:id')->execute([':id' => $imageId]);
p3c_behavior_expect_block(
    static fn () => assert_post_quality_allows_publication($postId),
    'prawidłowych grafik',
    'editorial_rejected blokuje publikację'
);
$database->prepare('UPDATE article_images SET editorial_rejected=0, is_fallback=1 WHERE id=:id')->execute([':id' => $imageId]);
p3c_behavior_expect_block(
    static fn () => assert_post_quality_allows_publication($postId),
    'fallback',
    'fallback blokuje publikację'
);
$database->prepare('UPDATE article_images SET is_fallback=0, status="missing" WHERE id=:id')->execute([':id' => $imageId]);
p3c_behavior_expect_block(
    static fn () => assert_post_quality_allows_publication($postId),
    'prawidłowych grafik',
    'missing/placeholder blokuje publikację'
);

$renderable = [...$image, 'id' => $imageId];
try {
    $galleryHtml = render_article_blocks([
        ['type' => 'gallery', 'image_ids' => [$imageId]],
    ], [$renderable]);
    p3c_behavior_assert(
        str_contains($galleryHtml, 'article-mini-gallery') && str_contains($galleryHtml, '<img '),
        'valid gallery renderuje zweryfikowany asset'
    );
    p3c_behavior_assert(
        render_article_image_record([...$renderable, 'is_fallback' => 1]) === '',
        'fallback nie trafia do finalnego renderu'
    );
    p3c_behavior_assert(
        render_article_image_record([...$renderable, 'editorial_rejected' => 1]) === '',
        'editorial_rejected nie trafia do finalnego renderu'
    );
    p3c_behavior_assert(
        render_article_blocks([['type' => 'gallery', 'image_ids' => [999999]]], [$renderable]) === '',
        'galeria bez istniejących assetów nie renderuje finalnego wrappera'
    );
} finally {
    if (isset($assetPath) && is_file($assetPath)) unlink($assetPath);
}

echo sprintf("P3-C publication behavior: %d PASS, %d FAIL\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
