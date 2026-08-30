<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

$passed = 0;
$failed = 0;
function p8_assert(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$label}\n"; return; }
    $failed++; echo "FAIL: {$label}\n";
}

$asset = app_path('images/posts/p8-layout-test.jpg');
if (!is_dir(dirname($asset))) mkdir(dirname($asset), 0777, true);
file_put_contents($asset, 'fixture');
$image = static function (int $id, string $role, string $section) use ($asset): array {
    return ['id'=>$id,'role'=>$role,'section_id'=>$section,'status'=>'downloaded','editorial_rejected'=>0,'is_fallback'=>0,
        'license'=>'cc0','local_path'=>'images/posts/p8-layout-test.jpg','layout'=>'full','alt'=>'test','caption'=>'caption',
        'attribution'=>'fixture','source_page_url'=>'https://example.test/source','license_url'=>'https://example.test/license',
        'width'=>1200,'height'=>675,'relationship'=>$role === 'hero' ? 'exact_subject' : 'related_context'];
};
$images = [$image(101, 'hero', 'article'), $image(102, 'inline', 'lead'), $image(103, 'inline', 'fact-1'), $image(104, 'inline', 'takeaway')];
$blocks = [
    ['type'=>'section','id'=>'lead','variant'=>'default','blocks'=>[['type'=>'paragraph','text'=>'Lead.'],['type'=>'illustration','image_id'=>102]]],
    ['type'=>'section','id'=>'fact-1','variant'=>'default','blocks'=>[['type'=>'paragraph','text'=>'Fact.'],['type'=>'illustration','image_id'=>103]]],
    ['type'=>'section','id'=>'takeaway','variant'=>'default','blocks'=>[['type'=>'paragraph','text'=>'Takeaway.'],['type'=>'illustration','image_id'=>104]]],
];
$standard = ['template_family'=>'standard','hero_style'=>'full','section_layouts'=>[],'image_placements'=>[],'context_block_placements'=>[],'callouts'=>[],'reading_rhythm'=>'balanced','caption_strategy'=>'standard'];
$visual = [...$standard, 'template_family'=>'visual_story','hero_style'=>'immersive','reading_rhythm'=>'spacious',
    'section_layouts'=>[['section_id'=>'lead','layout'=>'feature'],['section_id'=>'fact-1','layout'=>'split']],
    'image_placements'=>[['image_id'=>102,'placement'=>'before_section'],['image_id'=>103,'placement'=>'after_section']],
    'context_block_placements'=>[['slot_id'=>'inline-lead','placement'=>'after_image']],
    'callouts'=>[['section_id'=>'fact-1','type'=>'fact']], 'caption_strategy'=>'inline'];
$contexts = [['slot_id'=>'inline-lead','placement_after_section'=>'lead','block_type'=>'explainer','heading'=>'Context','body'=>'Source-backed context.','caption'=>'Source note','reader_attention_note'=>'Read carefully']];
$audit = [];
$html = render_article_blocks_with_layout($blocks, $images, $visual, $contexts, $audit);
p8_assert(str_contains($html, 'article-layout--visual_story') && str_contains($html, 'article-layout__hero--immersive'), 'allowlisted visual_story maps to deterministic renderer classes');
p8_assert(substr_count($html, 'article-illustration--hero') === 1 && substr_count($html, '<div class="article-layout__image ') === 3, 'hero is first and every inline image occurs once');
p8_assert(str_contains($html, 'article-layout__section--feature') && str_contains($html, 'article-layout__section--split') && str_contains($html, 'article-layout__section--callout-fact'), 'section layouts and callouts materially affect deterministic components');
p8_assert(str_contains($html, 'article-layout__image--caption-inline') && str_contains($html, 'article-layout__image--detail-inline') && str_contains($html, 'article-layout__section--detail-inline-heading'), 'detail-inline composition preserves deterministic caption and heading treatment');
p8_assert(str_contains($html, 'article-context-block') && strpos($html, 'Source-backed context.') > strpos($html, 'article-layout__image--before_section'), 'related context block respects after-image placement');
p8_assert(!str_contains($html, 'Source note') && !str_contains($html, 'Read carefully'), 'illustration-only context copy is never rendered as an orphaned paragraph');
p8_assert(str_contains($html, 'sizes="(max-width: 980px) 100vw') && !str_contains($html, '<style'), 'renderer remains mobile-safe and Gemini supplies no CSS');
$invalidAudit = [];
$fallback = article_layout_plan_or_default([...$standard, 'template_family'=>'arbitrary_html'], $invalidAudit);
p8_assert($fallback === article_safe_layout_plan() && ($invalidAudit[0]['code'] ?? '') === 'invalid_layout_plan', 'invalid LayoutPlan falls back deterministically with audit note');
$htmlRejected = false;
try { validate_generation_value([...$standard, 'html'=>'<style>body{display:none}</style>'], article_layout_plan_schema()); } catch (InvalidArgumentException) { $htmlRejected = true; }
p8_assert($htmlRejected, 'LayoutPlan schema rejects arbitrary HTML/CSS field before renderer');
$alternate = render_article_blocks_with_layout($blocks, $images, $standard);
p8_assert($alternate !== $html && str_contains($alternate, 'article-layout--standard'), 'two valid fixtures produce different valid layouts');
$presentationAssessment = static fn (string $type, string $density, bool $containsText, bool $sideSafe): string => generation_json([
    'visual_type'=>$type, 'detail_density'=>$density, 'contains_readable_text'=>$containsText,
    'safe_for_side_layout'=>$sideSafe,
]);
$visionPresentation = article_image_multimodal_assess([], [], '', static fn (): array => [
    'semantic_relevance'=>9, 'editorial_fit'=>9, 'hero_fit'=>8, 'depicts_required_subject'=>true,
    'misleading'=>false, 'inappropriate'=>false, 'decision'=>'accept', 'reason'=>'fixture',
    'relationship_level'=>'direct', 'contextual_useful'=>true, 'honest_caption_possible'=>true,
    'suggested_caption'=>'Fixture caption.', 'contains_readable_text'=>false, 'detail_density'=>'low',
    'visual_type'=>'photo', 'safe_for_side_layout'=>true,
]);
p8_assert(($visionPresentation['visual_type'] ?? '') === 'photo'
    && ($visionPresentation['detail_density'] ?? '') === 'low'
    && ($visionPresentation['safe_for_side_layout'] ?? false) === true,
    'existing Vision result carries presentation metadata without an extra call');
$sidePhoto = $image(105, 'inline', 'lead');
$sidePhoto['layout'] = 'right';
$sidePhoto['multimodal_assessment_json'] = $presentationAssessment('photo', 'medium', false, true);
$diagram = $image(106, 'inline', 'fact-1');
$diagram['layout'] = 'right';
$diagram['multimodal_assessment_json'] = $presentationAssessment('diagram', 'high', true, false);
$sidePlan = [...$standard, 'image_placements'=>[
    ['image_id'=>105,'placement'=>'after_section'], ['image_id'=>106,'placement'=>'inline'],
]];
$sideAudit = [];
$sideHtml = render_article_blocks_with_layout($blocks, [$sidePhoto, $diagram], $sidePlan, [], $sideAudit);
p8_assert(str_contains($sideHtml, 'article-layout__image--side-right')
    && strpos($sideHtml, 'article-layout__image--side-right') < strpos($sideHtml, '<section id="lead"'),
    'simple side-safe photo is moved before its section prose so text can wrap it');
p8_assert(str_contains($sideHtml, 'article-illustration--detail-inline article-illustration--side-overridden')
    && str_contains($sideHtml, 'data-requested-layout="right"')
    && substr_count($sideHtml, 'article-layout__image--side-right') === 2,
    'detailed diagram keeps the right-side text wrap while its figure layout is safely overridden');
$themeCss = file_get_contents(app_path('assets/css/public-theme.css')) ?: '';
p8_assert(source_png_has_alpha_channel(hex2bin('89504e470d0a1a0a0000000d494844520000000100000001080600000000')),
    'PNG alpha-channel fallback works when the stored transparency flag predates detection');
p8_assert(str_contains($themeCss, '.article-layout__section') && str_contains($themeCss, 'display: flow-root'),
    'layout section clears its float context before the next section');
p8_assert(str_contains($themeCss, 'width: min(50%, 34rem)') && str_contains($themeCss, 'float: none !important;')
    && str_contains($themeCss, 'width: 100% !important;'),
    'side image is about half-width on desktop and collapses to full-width without float on mobile');
p8_assert(str_contains($themeCss, 'article-image-media-card--neutral') && str_contains($themeCss, 'rgba(255, 255, 255, 0.5)'),
    'transparent PNG receives a readable 50% white media mat');
$longSentences = [];
for ($index = 1; $index <= 24; $index++) {
    $longSentences[] = 'Zdanie ' . $index . ' zachowuje dokładne brzmienie źródłowego tekstu i opisuje kolejny element technicznego wyjaśnienia bez dodawania nowych twierdzeń.';
}
$longText = implode(' ', $longSentences);
$fallbackTextBlocks = article_text_presentation_blocks($longText, []);
p8_assert(mb_strlen($longText) >= 1800 && count(array_filter($fallbackTextBlocks, static fn (array $block): bool => $block['type'] === 'paragraph')) >= 3,
    'legacy 1800-character prose falls back to several paragraphs instead of one wall');
p8_assert(implode(' ', array_column($fallbackTextBlocks, 'text')) === $longText,
    'deterministic paragraph fallback preserves exact wording and sentence order');
$phrase = 'dokładne brzmienie źródłowego tekstu';
$semanticBlocks = article_text_presentation_blocks(implode(' ', array_slice($longSentences, 0, 6)), [
    'paragraph_break_after_sentences'=>[2,4], 'emphasis_phrases'=>[$phrase, 'fraza której nie ma'],
    'list_groups'=>[['items'=>array_slice($longSentences, 2, 2)]],
]);
$semanticHtml = render_article_blocks($semanticBlocks, []);
p8_assert(str_contains($semanticHtml, '<strong>' . $phrase . '</strong>') && !str_contains($semanticHtml, 'fraza której nie ma'),
    'only an exact existing emphasis phrase is rendered as strong');
p8_assert(str_contains($semanticHtml, '<ul>') && substr_count($semanticHtml, '<li>') === 2,
    'exact consecutive natural list group is rendered as a list');
$plainRendered = html_entity_decode(strip_tags(str_replace(['</p>', '</li>'], ' ', $semanticHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$plainRendered = trim(preg_replace('/\s+/u', ' ', $plainRendered) ?? $plainRendered);
$plainOriginal = implode(' ', array_slice($longSentences, 0, 6));
p8_assert($plainRendered === $plainOriginal, 'paragraph, emphasis and list presentation preserve the complete core text');
$shortText = 'Krótki normalny akapit pozostaje zwykłą narracją. Drugie zdanie domyka jego sens.';
p8_assert(count(article_text_presentation_blocks($shortText, [])) === 1
    && article_text_presentation_blocks($shortText, [
        'paragraph_break_after_sentences'=>[], 'emphasis_phrases'=>[], 'list_groups'=>[['items'=>['Nieistniejący element.', 'Drugi brak.']]],
    ])[0]['text'] === $shortText,
    'normal prose and a non-matching list proposal remain ordinary prose');
$legacyPlan = article_layout_plan_or_default($standard);
p8_assert(array_key_exists('text_presentation', $legacyPlan) && $legacyPlan['text_presentation'] === [],
    'old persisted LayoutPlan gains empty text presentation for deterministic renderer fallback');
$schemaAcceptsTextPresentation = true;
try {
    validate_generation_value([...$standard, 'text_presentation'=>[array_merge(
        ['section_id'=>'lead','paragraph_break_after_sentences'=>[2],'emphasis_phrases'=>[$phrase]],
        ['list_groups'=>[['items'=>array_slice($longSentences, 2, 2)]]]
    )]], article_layout_plan_schema());
} catch (Throwable) { $schemaAcceptsTextPresentation = false; }
p8_assert($schemaAcceptsTextPresentation, 'P08 schema accepts bounded per-section text_presentation contract');
$families = ['deep_dive'=>['hero_style'=>'compact','reading_rhythm'=>'compact'], 'context_heavy'=>['hero_style'=>'full','reading_rhythm'=>'spacious']];
foreach ($families as $family => $overrides) {
    $familyHtml = render_article_blocks_with_layout($blocks, $images, [...$standard, 'template_family'=>$family, ...$overrides]);
    p8_assert($familyHtml !== $alternate && str_contains($familyHtml, 'article-layout--' . $family), $family . ' is rendered as a distinct allowlisted layout family');
}
$v2Blocks = [
    ['type'=>'section','id'=>'opening','variant'=>'v2-prose','topic_role'=>'A','content_type'=>'prose','blocks'=>[['type'=>'heading','level'=>2,'text'=>'Otwarcie'],['type'=>'paragraph','text'=>str_repeat('Długi tekst. ', 60)]]],
    ['type'=>'section','id'=>'curiosity-1','variant'=>'v2-curiosity','topic_role'=>'C','content_type'=>'curiosity','blocks'=>[['type'=>'paragraph','text'=>'Krótka ciekawostka.']]],
    ['type'=>'section','id'=>'comparison-1','variant'=>'v2-comparison','topic_role'=>'B','content_type'=>'comparison','blocks'=>[['type'=>'paragraph','text'=>'Krótkie porównanie.']]],
    ['type'=>'section','id'=>'unknowns-1','variant'=>'v2-unknowns','topic_role'=>'A','content_type'=>'unknowns','blocks'=>[['type'=>'paragraph','text'=>'Krótka niewiadoma.']]],
];
$v2Plan = [...$standard,
    'section_layouts'=>array_map(static fn (string $id): array => ['section_id'=>$id,'layout'=>'compact'], ['opening','curiosity-1','comparison-1','unknowns-1']),
    'callouts'=>array_map(static fn (string $id): array => ['section_id'=>$id,'type'=>'fact'], ['opening','curiosity-1','comparison-1','unknowns-1']),
];
$v2Audit = [];
$v2Html = render_article_blocks_with_layout($v2Blocks, [], $v2Plan, [], $v2Audit);
$v2Schema = article_draft_v2_schema(['S1'], ['C1'], 'informational', [
    ['section_id'=>'opening'], ['section_id'=>'curiosity-1'], ['section_id'=>'comparison-1'],
]);
p8_assert(isset($v2Schema['properties']['sections']) && !isset($v2Schema['properties']['lead']) && in_array('sections', $v2Schema['required'], true), 'V2 draft schema is canonical dynamic sections without legacy shape');
p8_assert(preg_match('/class="[^"]*callout[^"]*" data-section="opening"/', $v2Html) !== 1, 'V2 long prose is rendered as normal flow, not a card');
p8_assert(substr_count($v2Html, 'article-layout__section--callout-') === 2, 'V2 deterministic renderer allows at most two consecutive cards');
p8_assert(editorial_v2_required_image_count(8750) === 4, 'V2 visual floor for 8750 characters is hero plus trzy grafiki inline');
$contextualHeroImages = [[
    'id'=>991, 'role'=>'hero', 'section_id'=>'opening', 'status'=>'downloaded', 'editorial_rejected'=>0, 'is_fallback'=>0,
    'local_path'=>'images/posts/p8-layout-test.jpg', 'alt'=>'Historyczna ilustracja', 'caption'=>'Ilustracja kontekstowa', 'attribution'=>'Public domain',
    'source_page_url'=>'https://example.test/context', 'license'=>'Public domain', 'license_url'=>'https://example.test/license',
    'width'=>1200, 'height'=>900, 'layout'=>'full', 'has_transparency'=>0,
    'multimodal_assessment_json'=>json_encode(['visual_type'=>'illustration','relationship_level'=>'contextual_related','detail_density'=>'high','safe_for_side_layout'=>false]),
]];
$contextualAudit = [];
$contextualHtml = render_article_blocks_with_layout($v2Blocks, $contextualHeroImages, $v2Plan, [], $contextualAudit);
$firstSectionAt = strpos($contextualHtml, 'data-section="opening"');
$contextualHeroAt = strpos($contextualHtml, 'article-layout__contextual-hero');
$secondSectionAt = strpos($contextualHtml, 'data-section="curiosity-1"');
p8_assert($firstSectionAt !== false && $contextualHeroAt > $firstSectionAt && $secondSectionAt > $contextualHeroAt
    && substr_count($contextualHtml, 'article-layout__contextual-hero') === 1
    && !str_contains($contextualHtml, 'article-illustration--hero'),
    'contextual historical illustration assigned as hero is shown once between the first and second sections');
$directHero = $contextualHeroImages[0];
$directHero['id'] = 992;
$directHero['caption'] = 'Bezpośrednie zdjęcie otwierające';
$directHero['alt'] = 'Bezpośrednie zdjęcie';
$directHero['relationship'] = 'exact_subject';
$directHero['multimodal_assessment_json'] = json_encode(['visual_type'=>'photo','relationship_level'=>'direct','detail_density'=>'medium','safe_for_side_layout'=>true]);
$contextualInline = $contextualHeroImages[0];
$contextualInline['id'] = 993;
$contextualInline['role'] = 'inline';
$contextualInline['section_id'] = 'opening';
$contextualInline['relationship'] = 'contextual_related';
$contextualInline['caption'] = 'CONTEXTUAL_INLINE_SPACED';
$spacingPlan = $v2Plan;
$spacingPlan['image_placements'] = [['image_id'=>993,'placement'=>'after_section']];
$spacingAudit = [];
$spacingHtml = render_article_blocks_with_layout($v2Blocks, [$directHero, $contextualInline], $spacingPlan, [], $spacingAudit);
$openingAt = strpos($spacingHtml, 'data-section="opening"');
$curiosityAt = strpos($spacingHtml, 'id="curiosity-1"');
$contextualInlineAt = strpos($spacingHtml, 'article-layout__image--detail-inline');
p8_assert($openingAt !== false && $curiosityAt > $openingAt && $contextualInlineAt !== false
    && count(array_filter($spacingAudit, static fn (array $item): bool => ($item['code'] ?? '') === 'contextual_illustration_spaced_from_hero')) === 1,
    'contextual illustration from the first section is moved behind the second section when a direct hero already opens the article');
$adminPreviewSource = file_get_contents(app_path('php/admin-post-preview.php')) ?: '';
$adminProposalsSource = file_get_contents(app_path('php/admin-proposals.php')) ?: '';
$postRendererSource = file_get_contents(app_path('php/admin-database.php')) ?: '';
$adminCss = file_get_contents(app_path('assets/css/admin.css')) ?: '';
p8_assert(str_contains($adminPreviewSource, 'render_article_blocks_with_layout(')
    && !str_contains($adminPreviewSource, 'render_article_blocks(article_draft_content_blocks($draft)'),
    'standalone draft preview uses canonical LayoutPlan text presentation');
p8_assert(str_contains($adminPreviewSource, '$post[\'rendered_content_override\'] = render_article_blocks_with_layout(')
    && str_contains($postRendererSource, '$post[\'rendered_content_override\'] ?? null')
    && str_contains($postRendererSource, '$preview ?'),
    'standalone link renders the exact selected proposal preview instead of persisted post content_blocks');
p8_assert(str_contains($adminPreviewSource, '$post[\'rendered_content_includes_hero\'] = true')
    && str_contains($postRendererSource, '$contentAlreadyIncludesHero = $preview')
    && str_contains($postRendererSource, 'if (!$contentAlreadyIncludesHero)'),
    'standalone selected-version preview suppresses the duplicate page-header hero');
p8_assert(str_contains($adminProposalsSource, '$proposalPreviewHtml =')
    && str_contains($adminProposalsSource, 'render_article_blocks_with_layout(')
    && !str_contains($adminProposalsSource, "foreach (['lead', 'why_important', 'facts', 'context', 'summary']"),
    'embedded ready-proposal preview uses canonical LayoutPlan text presentation');
p8_assert(str_contains($adminCss, '.proposal-draft-content .article-layout__section .article-section p + p')
    && str_contains($adminCss, 'margin-top: 1.2rem'),
    'embedded proposal preview exposes visible paragraph rhythm');
unlink($asset);
if ($failed > 0) { echo "P8_LAYOUT_PLAN_SMOKE_FAIL {$failed}\n"; exit(1); }
echo "P8_LAYOUT_PLAN_SMOKE_OK {$passed}\n";
