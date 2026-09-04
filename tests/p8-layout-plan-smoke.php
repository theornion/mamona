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
    'suggested_caption'=>'Fixture caption.', 'visual_subject'=>'Fixture subject', 'visual_function'=>'Fixture visual function', 'contains_readable_text'=>false, 'detail_density'=>'low',
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
    && strpos($sideHtml, 'article-layout__image--side-right') < strpos($sideHtml, '<section id="article-section-lead"'),
    'simple side-safe photo is moved before its section prose so text can wrap it');
p8_assert(str_contains($sideHtml, 'article-illustration--detail-inline article-illustration--side-overridden')
    && str_contains($sideHtml, 'data-requested-layout="right"')
    && substr_count($sideHtml, 'article-layout__image--side-right') === 2,
    'detailed diagram keeps the right-side text wrap while its figure layout is safely overridden');
$rightBlocks = [['type'=>'section','id'=>'lead','variant'=>'default','blocks'=>[
    ['type'=>'heading','level'=>2,'text'=>'Lead'],
    ['type'=>'paragraph','text'=>str_repeat('Długi akapit obok obrazu. ', 80)],
]]];
$rightPlan = [...$standard, 'image_placements'=>[['image_id'=>105,'placement'=>'before_section']]];
$rightHtml = render_article_blocks_with_layout($rightBlocks, [$sidePhoto], $rightPlan, [], $sideAudit);
p8_assert(str_contains($rightHtml, 'article-layout__image--side-right')
    && strpos($rightHtml, 'article-layout__image--side-right') < strpos($rightHtml, '<section id="article-section-lead"')
    && str_contains($rightHtml, 'Długi akapit obok obrazu.'),
    'right side image keeps the complete section body in normal flow after its heading');
$leftPhoto = $sidePhoto;
$leftPhoto['id'] = 107;
$leftPhoto['layout'] = 'left';
$leftBlocks = [['type'=>'section','id'=>'lead','variant'=>'default','blocks'=>[
    ['type'=>'heading','level'=>2,'text'=>'Lead'],
    ['type'=>'paragraph','text'=>str_repeat('Długi akapit obok obrazu. ', 80)],
]]];
$leftPlan = [...$standard, 'image_placements'=>[['image_id'=>107,'placement'=>'before_section']]];
$leftHtml = render_article_blocks_with_layout($leftBlocks, [$leftPhoto], $leftPlan, [], $sideAudit);
p8_assert(str_contains($leftHtml, 'article-layout__image--side-left')
    && strpos($leftHtml, 'article-layout__image--side-left') < strpos($leftHtml, '<section id="article-section-lead"')
    && str_contains($leftHtml, 'Długi akapit obok obrazu.'),
    'left side image keeps the complete section body in normal flow after its heading');
$frozenIntroBody = 'Treść zamrożonego draftu pozostaje widoczna po kompozycji z ilustracją.';
$frozenBlocks = article_draft_content_blocks(['sections'=>[[
    'section_id'=>'intro', 'topic_role'=>'A', 'content_type'=>'prose',
    'heading'=>'Czym jest zwężenie zastawki aortalnej?', 'body'=>$frozenIntroBody,
]]], ['intro'=>105]);
$frozenHtml = render_article_blocks_with_layout($frozenBlocks, [$sidePhoto], $rightPlan, [], $sideAudit);
p8_assert(str_contains($frozenHtml, 'Czym jest zwężenie zastawki aortalnej?')
    && str_contains($frozenHtml, $frozenIntroBody)
    && str_contains($frozenHtml, 'id="article-section-intro"')
    && str_contains($frozenHtml, 'data-article-section-id="intro"'),
    'frozen section body survives image placement without colliding with the page intro id');
$semanticDiagram = $diagram;
$semanticDiagram['multimodal_assessment_json'] = generation_json([
    'decision'=>'accept', 'honest_caption_possible'=>true,
    'suggested_caption'=>'Schemat pokazujący zależności między badanymi elementami układu.',
    'visual_type'=>'diagram', 'detail_density'=>'high', 'contains_readable_text'=>true, 'safe_for_side_layout'=>false,
]);
$semanticHtml = render_article_image_record($semanticDiagram);
p8_assert(str_contains($semanticHtml, 'Schemat pokazujący zależności między badanymi elementami układu.')
    && !str_contains($semanticHtml, 'Szczegółowa ilustracja uzupełniająca tekst artykułu')
    && str_contains($semanticHtml, 'article-image-zoom__bar')
    && str_contains($semanticHtml, 'Kliknij, aby powiększyć')
    && !str_contains($semanticHtml, 'article-image-zoom__hint')
    && str_contains($semanticHtml, 'data-article-detail-zoom'),
    'accepted Vision caption is semantic and the below-image zoom bar preserves the lightbox link');
$plainImageHtml = render_article_image_record($sidePhoto);
p8_assert(substr_count($plainImageHtml, 'data-article-detail-zoom') === 1
    && str_contains($plainImageHtml, 'article-image-zoom__bar')
    && str_contains($plainImageHtml, '<details class="article-image-meta">'),
    'non-detail image uses the same clickable media card and independent native license control');
p8_assert(preg_match('/data-article-detail-zoom[^>]*>.*article-image-zoom__bar/s', $semanticHtml) === 1
    && !str_contains($semanticHtml, 'article-image-zoom__bar" aria-hidden'),
    'diagram zoom CTA is inside the same interactive anchor as its image');
$lightboxSource = file_get_contents(app_path('assets/js/article-detail-lightbox.js')) ?: '';
p8_assert(str_contains($lightboxSource, "closest('[data-article-detail-zoom]')")
    && str_contains($lightboxSource, 'event.preventDefault()')
    && str_contains($lightboxSource, 'image.src = trigger.href')
    && str_contains($lightboxSource, '}, true);')
    && str_contains($lightboxSource, 'event.target === lightbox')
    && str_contains($lightboxSource, "event.key === 'Escape'")
    && str_contains($lightboxSource, 'function bindTrigger(trigger)')
    && str_contains($lightboxSource, "trigger.dataset.articleZoomBound = 'true'")
    && str_contains($lightboxSource, 'event.stopPropagation()')
    && str_contains($lightboxSource, '>×</button>'),
    'lightbox directly binds every trigger and retains X, backdrop, and Escape close paths');
$portrait = $semanticDiagram;
$portrait['id'] = 108;
$portrait['width'] = 900;
$portrait['height'] = 1600;
$portraitHtml = render_article_image_record($portrait);
p8_assert(str_contains($portraitHtml, 'article-illustration--portrait')
    && str_contains($portraitHtml, 'article-image-media-card--portrait'),
    'vertical detail image receives an orientation-aware wrapper class');
$themeCss = file_get_contents(app_path('assets/css/public-theme.css')) ?: '';
p8_assert(source_png_has_alpha_channel(hex2bin('89504e470d0a1a0a0000000d494844520000000100000001080600000000')),
    'PNG alpha-channel fallback works when the stored transparency flag predates detection');
p8_assert(str_contains($themeCss, '.article-layout__section') && str_contains($themeCss, 'display: flow-root'),
    'layout section clears its float context before the next section');
p8_assert(!str_contains($themeCss, ':where(.news-feed-card,.article-section,.ad-slot)')
    && str_contains($themeCss, '.article-section {') && str_contains($themeCss, 'contain: style;'),
    'article section is not layout-contained, so prose resumes full width below either side image');
p8_assert(str_contains($themeCss, 'width: min(50%, 34rem)') && str_contains($themeCss, 'float: none !important;')
    && str_contains($themeCss, 'width: 100% !important;'),
    'side image is about half-width on desktop and collapses to full-width without float on mobile');
p8_assert(str_contains($themeCss, '.article-layout__image--side')
    && str_contains($themeCss, 'isolation: isolate')
    && str_contains($themeCss, 'z-index: 2')
    && str_contains($themeCss, 'pointer-events: auto'),
    'side media is stacked above wrapping prose and remains directly clickable');
p8_assert(str_contains($themeCss, 'article-image-media-card--neutral') && str_contains($themeCss, 'rgba(255, 255, 255, 0.5)'),
    'transparent PNG receives a readable 50% white media mat');
p8_assert(str_contains($themeCss, '.article-image-zoom__bar')
    && str_contains($themeCss, 'background-color: #040c1a !important;')
    && str_contains($themeCss, 'background-image: none !important;')
    && str_contains($themeCss, 'article-illustration--detail-inline.article-illustration--portrait')
    && str_contains($themeCss, 'inline-size: fit-content')
    && str_contains($themeCss, 'aspect-ratio: auto')
    && str_contains($themeCss, 'max-height: min(68vh, 42rem)'),
    'zoom instruction is below the image and portrait media card follows the rendered image width');
p8_assert(str_contains($themeCss, 'article-detail-lightbox__panel')
    && str_contains($themeCss, 'width: fit-content')
    && str_contains($themeCss, 'max-height: calc(100vh - 6rem)')
    && str_contains($themeCss, 'position: fixed'),
    'lightbox uses an image-sized panel, viewport X control, and bounded image instead of a wide frame');
$semanticTakeawayBlocks = [['type'=>'section','id'=>'takeaway','variant'=>'v2-takeaway','content_type'=>'takeaway','blocks'=>[
    ['type'=>'heading','level'=>2,'text'=>'Takeaway'],
    ['type'=>'paragraph','text'=>'First semantic takeaway paragraph.'],
    ['type'=>'paragraph','text'=>'Second semantic takeaway paragraph.'],
]]];
$semanticTakeawayPlan = [...$standard,
    'image_placements'=>[['image_id'=>105,'placement'=>'before_section']],
    'callouts'=>[['section_id'=>'takeaway','type'=>'takeaway']],
];
$takeawaySidePhoto = $sidePhoto;
$takeawaySidePhoto['section_id'] = 'takeaway';
$semanticTakeawayHtml = render_article_blocks_with_layout($semanticTakeawayBlocks, [$takeawaySidePhoto], $semanticTakeawayPlan);
$semanticCalloutOffset = strpos($semanticTakeawayHtml, 'article-layout__callout--takeaway');
p8_assert(substr_count($semanticTakeawayHtml, 'article-layout__callout--takeaway') === 1
    && str_contains($semanticTakeawayHtml, 'First semantic takeaway paragraph.')
    && str_contains($semanticTakeawayHtml, 'Second semantic takeaway paragraph.')
    && $semanticCalloutOffset !== false
    && strpos($semanticTakeawayHtml, '</figure>') < $semanticCalloutOffset,
    'one semantic callout groups consecutive paragraphs after the complete side media card');
$plainCalloutBlocks = [['type'=>'section','id'=>'intro','variant'=>'v2-explainer','content_type'=>'explainer','blocks'=>[
    ['type'=>'paragraph','text'=>'Ordinary explanatory prose.'],
]]];
$plainCalloutHtml = render_article_blocks_with_layout($plainCalloutBlocks, [], [...$standard,
    'callouts'=>[['section_id'=>'intro','type'=>'fact']],
]);
p8_assert(!str_contains($plainCalloutHtml, 'article-layout__callout')
    && str_contains($plainCalloutHtml, 'Ordinary explanatory prose.'),
    'ordinary V2 prose remains unframed when a decorative layout callout was proposed');
$insetListHtml = render_article_blocks([[
    'type'=>'list', 'presentation_list'=>true, 'items'=>['First inset item.', 'Second inset item.'],
]], []);
p8_assert(str_contains($insetListHtml, 'article-layout__inset-list')
    && str_contains($insetListHtml, 'data-text-presentation="list-group"')
    && str_contains($insetListHtml, '<ul>'),
    'only a LayoutPlan presentation list receives the subdued inset frame');
$publicRouteHtml = render_article_blocks_with_layout_and_advertising(
    $semanticTakeawayBlocks,
    [$takeawaySidePhoto],
    $semanticTakeawayPlan,
    [],
    advertising_config(['enabled'=>false])
);
p8_assert(str_contains($publicRouteHtml, 'class="article-layout ')
    && str_contains($publicRouteHtml, 'article-layout__callout--takeaway')
    && str_contains($publicRouteHtml, 'data-callout-source="semantic"')
    && str_contains($publicRouteHtml, 'article-section--v2-takeaway'),
    'public article route preserves the layout callout markup contract instead of falling back to bare V2 sections');
$advertisingBlocks = array_map(static fn (string $id): array => [
    'type'=>'section', 'id'=>$id, 'variant'=>'v2-explainer', 'content_type'=>'explainer',
    'blocks'=>[['type'=>'paragraph','text'=>str_repeat('Public composition keeps article advertisements after complete sections. ', 24)]],
], ['one', 'two', 'three']);
$advertisingRouteHtml = render_article_blocks_with_layout_and_advertising(
    $advertisingBlocks,
    [],
    $standard,
    [],
    advertising_config([
        'enabled'=>true,
        'preview'=>true,
        'allowed_placements'=>['article-inline'],
        'max_slots_per_page'=>3,
        'max_inline_slots'=>1,
    ])
);
p8_assert(str_contains($advertisingRouteHtml, 'class="article-layout ')
    && str_contains($advertisingRouteHtml, 'data-ad-placement="article-inline"'),
    'public layout route retains bounded inline advertising without reverting to the legacy renderer');
p8_assert(str_contains($themeCss, '.article-layout__callout')
    && str_contains($themeCss, 'clear: both')
    && str_contains($themeCss, '.article-layout__inset-list')
    && str_contains($themeCss, 'list-style: disc')
    && str_contains($themeCss, 'text-align: left !important')
    && str_contains($themeCss, '.article-image-meta :where(summary, p)')
    && str_contains($themeCss, 'overflow-wrap: anywhere')
    && str_contains($themeCss, 'border-bottom: 0 !important')
    && str_contains($themeCss, 'width: 100%;')
    && !str_contains($themeCss, 'border-top: 1px solid rgba(145, 205, 227, 0.18)'),
    'semantic callout, natural license wrapping, and single-bar CTA styling are explicit in public CSS');
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
p8_assert(str_contains($adminPreviewSource, 'render_article_blocks_with_layout_and_advertising(')
    && !str_contains($adminPreviewSource, 'render_article_blocks(article_draft_content_blocks($draft)'),
    'standalone draft preview uses canonical LayoutPlan text presentation');
p8_assert(str_contains($adminPreviewSource, '$post[\'rendered_content_override\'] = render_article_blocks_with_layout_and_advertising(')
    && str_contains($postRendererSource, '$post[\'rendered_content_override\'] ?? null')
    && str_contains($postRendererSource, '$preview ?'),
    'standalone link renders the exact selected proposal preview instead of persisted post content_blocks');
p8_assert(str_contains($adminPreviewSource, '$post[\'rendered_content_includes_hero\'] = true')
    && str_contains($postRendererSource, '$contentAlreadyIncludesHero = $preview')
    && str_contains($postRendererSource, 'if (!$contentAlreadyIncludesHero)'),
    'standalone selected-version preview suppresses the duplicate page-header hero');
p8_assert(str_contains($adminProposalsSource, '$proposalPreviewHtml =')
    && str_contains($adminProposalsSource, 'render_article_blocks_with_layout_and_advertising(')
    && !str_contains($adminProposalsSource, "foreach (['lead', 'why_important', 'facts', 'context', 'summary']"),
    'embedded ready-proposal preview uses canonical LayoutPlan text presentation');
p8_assert(str_contains($adminCss, '.proposal-draft-content .article-layout__section .article-section p + p')
    && str_contains($adminCss, 'margin-top: 1.2rem'),
    'embedded proposal preview exposes visible paragraph rhythm');
unlink($asset);
if ($failed > 0) { echo "P8_LAYOUT_PLAN_SMOKE_FAIL {$failed}\n"; exit(1); }
echo "P8_LAYOUT_PLAN_SMOKE_OK {$passed}\n";
