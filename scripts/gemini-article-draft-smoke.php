<?php

declare(strict_types=1);

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

$options = getopt('', [
    'list',
    'topics',
    'topic::',
    'package::',
    'mode::',
    'promote::',
    'images::',
    'list-images::',
    'reject-image::',
]);
$packages = list_approved_research_packages(100);

$rejectImageId = isset($options['reject-image']) ? (int) $options['reject-image'] : 0;
if ($rejectImageId > 0) {
    $postId = reject_article_source_image($rejectImageId);
    echo "ARTICLE_SOURCE_IMAGE_REJECTED\n";
    echo "IMAGE_ID={$rejectImageId}\n";
    echo "POST_DRAFT_ID={$postId}\n";
    exit(0);
}

$listImagesPostId = isset($options['list-images']) ? (int) $options['list-images'] : 0;
if ($listImagesPostId > 0) {
    foreach (list_article_images($listImagesPostId) as $image) {
        echo (int) $image['id']
            . "\t" . (string) $image['role']
            . "\t" . (string) $image['section_id']
            . "\t" . (string) $image['status']
            . "\t" . (string) $image['local_path']
            . "\t" . (string) $image['source_page_url']
            . "\t" . (string) $image['visual_intent']
            . "\t" . (string) $image['search_queries_json']
            . "\n";
    }
    exit(0);
}

$imagesPostId = isset($options['images']) ? (int) $options['images'] : 0;
if ($imagesPostId > 0) {
    $summary = fulfill_article_source_images($imagesPostId);
    echo "ARTICLE_SOURCE_IMAGES_COMPLETE\n";
    echo "POST_DRAFT_ID={$imagesPostId}\n";
    echo "DOWNLOADED=" . (int) $summary['downloaded'] . "\n";
    echo "MANUAL_REVIEW=" . (int) $summary['manual_review'] . "\n";
    echo "MISSING=" . (int) $summary['missing'] . "\n";
    echo "SKIPPED=" . (int) $summary['skipped'] . "\n";
    foreach ((array) $summary['errors'] as $error) {
        echo "IMAGE_WARNING=" . str_replace(["\r", "\n"], ' ', (string) $error) . "\n";
    }
    exit(0);
}

$promoteDraftId = isset($options['promote']) ? (int) $options['promote'] : 0;
if ($promoteDraftId > 0) {
    $postId = promote_article_draft_to_post($promoteDraftId);
    echo "ARTICLE_DRAFT_PROMOTED\n";
    echo "DRAFT_VERSION_ID={$promoteDraftId}\n";
    echo "POST_DRAFT_ID={$postId}\n";
    exit(0);
}

if (isset($options['topics'])) {
    $topics = list_editorial_topics(30);
    if ($topics === []) {
        echo "BRAK_AKTYWNYCH_TEMATOW\n";
        exit(0);
    }
    foreach ($topics as $topic) {
        echo (int) $topic['id']
            . "\t" . (string) $topic['title']
            . "\tźródła=" . (int) $topic['source_count']
            . "\tmateriały=" . (int) $topic['item_count']
            . "\twynik=" . ($topic['score'] === null ? '-' : (string) $topic['score'])
            . "\n";
    }
    exit(0);
}

if (isset($options['list'])) {
    if ($packages === []) {
        echo "BRAK_ZATWIERDZONYCH_PACZEK_RESEARCHOWYCH\n";
        exit(0);
    }
    foreach ($packages as $package) {
        echo (int) $package['id'] . "\t" . (string) $package['topic_title'] . "\n";
    }
    exit(0);
}

if (app_environment_value('CMS_ALLOW_REAL_GEMINI_ARTICLE') !== '1') {
    fwrite(STDERR, "Ten test wykonuje prawdziwe zapytanie do Gemini.\n");
    fwrite(STDERR, "Ustaw CMS_ALLOW_REAL_GEMINI_ARTICLE=1, aby uruchomić go świadomie.\n");
    exit(2);
}

if ((string) app_config('generation_provider') !== 'gemini') {
    fwrite(STDERR, "CMS_GENERATION_PROVIDER musi mieć wartość gemini.\n");
    exit(2);
}

if ((bool) app_config('gemini_mock')) {
    fwrite(STDERR, "GEMINI_API_MOCK musi być wyłączone dla rzeczywistego testu.\n");
    exit(2);
}

if (app_environment_value('GEMINI_API_KEY') === null) {
    fwrite(STDERR, "Brakuje GEMINI_API_KEY w środowisku procesu.\n");
    exit(2);
}

$packageId = isset($options['package']) ? (int) $options['package'] : 0;
$topicId = isset($options['topic']) ? (int) $options['topic'] : 0;

if ($packageId > 0 && $topicId > 0) {
    fwrite(STDERR, "Użyj tylko jednego parametru: --topic albo --package.\n");
    exit(2);
}

if ($topicId > 0) {
    $previousMode = generation_mode();
    try {
        update_generation_mode('api');
        $researchOperationId = prepare_research_package_operation($topicId);
    } finally {
        update_generation_mode($previousMode);
    }

    echo "GEMINI_RESEARCH_OPERATION={$researchOperationId}\n";
    echo "GEMINI_RESEARCH_TOPIC={$topicId}\n";

    try {
        $research = execute_generation_operation($researchOperationId);
        $researchPackage = find_research_package_by_operation($researchOperationId);
        $packageId = (int) ($researchPackage['id'] ?? 0);
        if (($research['recommendation']['decision'] ?? '') !== 'continue') {
            echo "GEMINI_RESEARCH_REJECTED\n";
            echo "REASON=" . (string) ($research['recommendation']['reason'] ?? '') . "\n";
            exit(4);
        }
        approve_research_package($packageId);
        echo "GEMINI_RESEARCH_APPROVED package={$packageId}\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, "GEMINI_RESEARCH_FAILED operation={$researchOperationId}\n");
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(str_contains($exception->getMessage(), 'Limit Free Tier') ? 3 : 1);
    }
}

if ($packageId <= 0 && $packages !== []) {
    $packageId = (int) $packages[0]['id'];
}
if ($packageId <= 0) {
    fwrite(STDERR, "Brak zatwierdzonej paczki researchowej. Najpierw przygotuj i zatwierdź research.\n");
    exit(2);
}

$package = find_research_package($packageId);
if ($package === null || (string) $package['status'] !== 'approved') {
    fwrite(STDERR, "Paczka #{$packageId} nie istnieje albo nie jest zatwierdzona.\n");
    exit(2);
}

$mode = trim((string) ($options['mode'] ?? 'informational'));
$previousMode = generation_mode();

try {
    update_generation_mode('api');
    $operationId = prepare_article_draft_operation($packageId, $mode);
} finally {
    update_generation_mode($previousMode);
}

echo "GEMINI_ARTICLE_OPERATION={$operationId}\n";
echo "GEMINI_ARTICLE_PACKAGE={$packageId}\n";
echo "GEMINI_ARTICLE_MODEL=" . (string) app_config('gemini_model') . "\n";
echo "GEMINI_ARTICLE_AI_IMAGES=SKIPPED\n";

try {
    $draft = execute_generation_operation($operationId);
    $stored = find_article_draft_by_operation($operationId);
    $validation = json_decode((string) ($stored['validation_json'] ?? ''), true);
    $inline = (array) ($draft['illustration_plan']['inline'] ?? []);

    echo "GEMINI_ARTICLE_DRAFT_OK\n";
    echo "TITLE=" . (string) $draft['title'] . "\n";
    echo "TITLE_VARIANTS=" . generation_json((array) ($draft['title_variants'] ?? [])) . "\n";
    echo "TITLE_SELECTION_REASON=" . str_replace(
        ["\r", "\n"],
        ' ',
        (string) ($draft['title_selection_reason'] ?? '')
    ) . "\n";
    echo "CHARACTERS=" . (int) ($validation['main_content_character_count'] ?? 0) . "\n";
    echo "HERO_STATUS=" . (string) ($draft['illustration_plan']['hero']['status'] ?? '') . "\n";
    echo "INLINE_PLANNED=" . count($inline) . "\n";
    echo "DRAFT_VERSION_ID=" . (int) ($stored['id'] ?? 0) . "\n";
    $postId = promote_article_draft_to_post((int) ($stored['id'] ?? 0));
    echo "ARTICLE_DRAFT_PROMOTED\n";
    echo "POST_DRAFT_ID={$postId}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "GEMINI_ARTICLE_DRAFT_FAILED operation={$operationId}\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(str_contains($exception->getMessage(), 'Limit Free Tier') ? 3 : 1);
}
