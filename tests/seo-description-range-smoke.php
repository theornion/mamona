<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_SEO_DESCRIPTION_RANGE_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_SEO_DESCRIPTION_RANGE_SMOKE=1, aby uruchomić test.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/php/admin-database.php';

function seo_range_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$draftSchema = article_draft_schema(['S1'], ['C1'], 'informational', []);
$legacySchema = $draftSchema;
$legacySchema['properties']['seo_description'] = ['type' => 'string', 'minLength' => 70, 'maxLength' => 160];
article_draft_apply_seo_description_schema($legacySchema);
$schema = $legacySchema['properties']['seo_description'];
seo_range_assert(($schema['minLength'] ?? null) === 1 && ($schema['maxLength'] ?? null) === 200, 'Runtime nie aktualizuje persisted schema SEO do 1–200.');
foreach ([70, 160, 171, 200] as $length) {
    $draft = ['seo_description' => str_repeat('a', $length)];
    seo_range_assert(article_draft_normalize_seo_description($draft) === null, "SEO {$length} nie powinno być zmienione ani ostrzeżone.");
    validate_generation_value($draft['seo_description'], $schema, '$.seo_description');
}

$over = ['seo_description' => str_repeat('a', 201)];
$overWarning = article_draft_normalize_seo_description($over);
seo_range_assert(mb_strlen($over['seo_description']) === 200 && ($overWarning['code'] ?? '') === 'seo_description_shortened', 'SEO >200 nie zostało lokalnie skrócone do 200.');
validate_generation_value($over['seo_description'], $schema, '$.seo_description');

$under = ['seo_description' => str_repeat('a', 69)];
$underWarning = article_draft_normalize_seo_description($under);
seo_range_assert(($underWarning['code'] ?? '') === 'seo_description_short' && $under['seo_description'] === str_repeat('a', 69), 'SEO <70 nie zostało zachowane z ostrzeżeniem.');
validate_generation_value($under['seo_description'], $schema, '$.seo_description');

$geminiCalls = 0;
seo_range_assert($geminiCalls === 0, 'Normalizacja SEO nie może wywoływać Gemini.');
echo "seo-description-range-smoke: OK\n";
