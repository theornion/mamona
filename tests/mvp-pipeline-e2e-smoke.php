<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_MVP_PIPELINE_E2E') !== '1') {
    fwrite(STDERR, "Set CMS_ALLOW_MVP_PIPELINE_E2E=1 to run this disposable test.\n");
    exit(2);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-mvp-e2e-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create fixture directory.');
$databaseFile = $directory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_ENV=development');
putenv('GEMINI_API_MOCK=false');
require_once dirname(__DIR__) . '/php/admin-database.php';

function mvp_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function mvp_response(array $value, string $id): array
{
    return ['status'=>200, 'headers'=>[], 'network_error'=>'', 'body'=>generation_json([
        'responseId'=>$id,
        'candidates'=>[['content'=>['parts'=>[['text'=>generation_json($value)]]], 'finishReason'=>'STOP']],
        'usageMetadata'=>['promptTokenCount'=>10,'candidatesTokenCount'=>10,'totalTokenCount'=>20],
    ])];
}

function mvp_transport(array $value, array &$audit, string $stage): callable
{
    return static function (array $payload, string $key, string $operationKey, string $model) use ($value, &$audit, $stage): array {
        $audit[] = $stage;
        return mvp_response($value, 'mvp-' . $stage);
    };
}

function mvp_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function mvp_png(int $width = 1600, int $height = 900): string
{
    $row = "\0" . str_repeat("\x35\x70\x95", $width);
    return "\x89PNG\r\n\x1a\n"
        . mvp_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . mvp_png_chunk('IDAT', gzcompress(str_repeat($row, $height), 9))
        . mvp_png_chunk('IEND', '');
}

function mvp_image(array $slot, string $path, string $relationship = 'exact_subject', string $status = 'downloaded'): array
{
    return [
        'role'=>(string)$slot['role'], 'section_id'=>(string)$slot['section_anchor'],
        'visual_intent'=>(string)$slot['visual_need'], 'expected_content'=>(string)$slot['visual_need'],
        'search_queries'=>(array)$slot['search_queries_direct'],
        'source_page_url'=>'https://fixture.example/source', 'source_file_url'=>'https://fixture.example/image.png',
        'local_path'=>$path, 'author'=>'MVP fixture author', 'license'=>'cc0',
        'license_url'=>'https://creativecommons.org/publicdomain/zero/1.0/',
        'attribution'=>'MVP fixture author / CC0', 'alt'=>'Scientific fixture image',
        'caption'=>'Source-backed scientific fixture.', 'layout'=>'full', 'status'=>$status,
        'width'=>1600, 'height'=>900, 'relationship'=>$relationship, 'is_fallback'=>0,
        'editorial_rejected'=>0, 'multimodal_accepted'=>$status === 'downloaded' ? 1 : 0,
        'multimodal_assessment'=>['decision'=>$status === 'downloaded' ? 'accept' : 'reject'],
    ];
}

$db = bueno_database();
$audit = [];
$createdAssets = [];
try {
    $sourceId = save_technical_source([
        'name'=>'MVP disposable RSS', 'website_url'=>'https://fixture.example/',
        'feed_url'=>'https://fixture.example/feed.xml', 'source_type'=>'rss',
        'topic_category'=>'science', 'language'=>'en', 'credibility_level'=>5,
        'is_primary'=>1, 'is_active'=>1,
    ]);
    foreach (list_technical_sources() as $registeredSource) {
        if ((int)$registeredSource['id'] !== $sourceId && !empty($registeredSource['is_active'])) {
            set_technical_source_active((int)$registeredSource['id'], false);
        }
    }
    $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>MVP</title><item>'
        . '<title>Disposable telescope research result</title><link>https://fixture.example/result</link>'
        . '<guid>mvp-e2e-one</guid><description>Scientists report a measured telescope result and its method.</description>'
        . '<category>science</category></item></channel></rss>';
    $ingestion = run_feed_ingestion(static fn (): array => [
        'status'=>200, 'body'=>$rss, 'bytes'=>strlen($rss), 'duration_ms'=>1,
        'headers'=>['content-type'=>'application/rss+xml'], 'attempts'=>1,
    ]);
    mvp_assert((int)$ingestion['created'] === 1, 'RSS fixture is persisted through production ingestion');
    $row = $db->query('SELECT items.post_id,memberships.topic_id FROM discovered_feed_items items JOIN feed_topic_memberships memberships ON memberships.feed_item_id=items.id ORDER BY items.id DESC LIMIT 1')->fetch();
    mvp_assert(is_array($row), 'RSS ingestion creates post/topic membership');
    $postId = (int)$row['post_id'];
    $topicId = (int)$row['topic_id'];
    set_technical_source_active($sourceId, false);

    update_generation_mode('api');
    $researchOperation = prepare_research_package_operation($topicId);
    $researchOperationRow = find_generation_operation($researchOperation);
    $researchValue = research_mock_generation_value($researchOperationRow);
    execute_generation_operation($researchOperation, mvp_transport($researchValue, $audit, 'research'));
    $research = find_research_package_by_operation($researchOperation);
    mvp_assert(is_array($research) && (string)$research['status'] === 'completed', 'research operation completes via controlled transport');
    approve_research_package((int)$research['id']);

    $researchPayload = json_decode((string)$research['package_json'], true, 128, JSON_THROW_ON_ERROR);
    $sourceIds = array_values(array_unique(array_filter(array_map('strval', (array)($researchPayload['claims'][0]['source_ids'] ?? [])))));
    $claimIds = array_values(array_unique(array_filter(array_map(static fn (array $claim): string => (string)($claim['claim_id'] ?? ''), (array)($researchPayload['claims'] ?? [])))));
    $planValue = narrative_plan_mock_generation_value(['input_json'=>generation_json([
        'numbered_sources'=>array_map(static fn (string $id): array => ['source_id'=>$id], $sourceIds),
        'research_package'=>['claims'=>array_map(static fn (string $id): array => ['claim_id'=>$id], $claimIds)],
    ])]);
    $claimText = (string)($researchPayload['claims'][0]['claim'] ?? 'Disposable telescope research result');
    $planValue['visual_plan']['hero_slot']['visual_need'] = $claimText . ' shown as the direct scientific subject.';
    $planValue['visual_plan']['hero_slot']['search_queries_direct'] = [$claimText . ' direct photograph'];
    $plan = generate_narrative_plan($topicId, $researchPayload, mvp_transport(
        $planValue,
        $audit, 'narrative_plan'
    ));
    mvp_assert((int)($plan['plan_id'] ?? 0) > 0, 'NarrativePlan and VisualPlan persist through production completion');
    $storedPlan = find_narrative_plan_for_post($postId, $topicId);
    $visual = json_decode((string)$storedPlan['visual_plan_json'], true, 128, JSON_THROW_ON_ERROR);
    $modules = json_decode((string)$storedPlan['expansion_modules_json'], true, 128, JSON_THROW_ON_ERROR);
    mvp_assert(!empty($visual['hero_slot']['search_queries_direct']) && !empty($modules[0]['source_claim_ids']), 'visual slots and recovery modules retain source-aware contract');

    $draftOperation = prepare_article_draft_operation((int)$research['id'], 'informational', $storedPlan);
    $draftValue = article_draft_mock_generation_value(find_generation_operation($draftOperation));
    $safeTitle = mb_substr((string)($researchPayload['claims'][0]['claim'] ?? 'Disposable telescope research result'), 0, 100);
    $draftValue['title'] = $safeTitle;
    $knownClaims = [];
    foreach ((array)$researchPayload['claims'] as $claim) $knownClaims[(string)$claim['claim_id']] = $claim;
    $draftValue = [...$draftValue, ...article_title_deterministic_fallback($draftValue, $knownClaims)];
    unset($draftValue['seo_title']);
    $draftOperationKey = (string)find_generation_operation($draftOperation)['operation_key'];
    $draftCalls = 0;
    $draftTransport = static function (array $payload, string $key, string $operationKey, string $model) use (&$draftCalls, &$audit, $draftValue, $safeTitle, $draftOperationKey): array {
        $draftCalls++; $audit[] = ($operationKey === $draftOperationKey ? 'draft' : 'draft_title_repair') . ':' . $draftCalls;
        $repair = array_intersect_key($draftValue, array_flip(['title','title_variants','title_selection_reason','seo_title','seo_description']));
        return mvp_response($operationKey === $draftOperationKey ? $draftValue : $repair, 'mvp-draft-' . $draftCalls);
    };
    execute_generation_operation($draftOperation, $draftTransport);
    $draft = find_article_draft_by_operation($draftOperation);
    mvp_assert(is_array($draft) && (string)$draft['status'] === 'completed', 'core draft completes through production operation');
    $qcOperation = prepare_quality_check_operation((int)$draft['id']);
    execute_generation_operation($qcOperation, mvp_transport(quality_check_mock_generation_value(), $audit, 'text_qc'));
    $qc = find_quality_check_by_operation($qcOperation);
    mvp_assert(is_array($qc) && (int)$qc['passed'] === 1, 'deterministic and model text QC pass');
    qc_freeze_accepted_artifacts((int)$draft['id']);
    $coreBefore = core_text_lock_state((int)$draft['id']);
    mvp_assert(!empty($coreBefore['core_text_locked']), 'accepted core is frozen and auditable');

    $post = find_post($postId);
    $draftJson = json_decode((string)$draft['draft_json'], true, 128, JSON_THROW_ON_ERROR);
    update_post($postId, (string)$draftJson['title'], (string)$draftJson['lead']['text'], '<section id="lead"><p>Locked core preview.</p></section><section id="why-important"><p>Why it matters.</p></section>', '', false, '', null, [], 'cover', [], 'draft');

    $heroPath = 'images/posts/mvp-e2e-' . bin2hex(random_bytes(5)) . '-hero.png';
    $inlinePath = 'images/posts/mvp-e2e-' . bin2hex(random_bytes(5)) . '-inline.png';
    foreach ([$heroPath,$inlinePath] as $relative) {
        $absolute = app_path($relative); if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, mvp_png()); $createdAssets[] = $absolute;
    }
    persist_article_image($postId, mvp_image($visual['hero_slot'], $heroPath));
    $inlineSlots = array_values((array)$visual['inline_slots']);
    persist_article_image($postId, mvp_image($inlineSlots[0], $inlinePath));
    foreach (array_slice($inlineSlots, 1) as $slot) persist_article_image($postId, mvp_image($slot, '', 'related_context', 'missing'));
    $coverageBefore = article_image_coverage_state($postId, $topicId, false);
    mvp_assert(!$coverageBefore['coverage_complete'], 'incomplete direct image set remains blocked before P06');

    $candidate = [
        'provider'=>'openverse','provider_id'=>'related-1','source_page_url'=>'https://fixture.example/related',
        'source_file_url'=>'https://fixture.example/related.png','author'=>'Fixture author','license'=>'cc0',
        'license_url'=>'https://creativecommons.org/publicdomain/zero/1.0/','attribution'=>'Fixture author / CC0',
        'width'=>1600,'height'=>900,'mime_type'=>'image/png','relationship'=>'apparatus','depicts_required_subject'=>false,
        'third_party_warning'=>false,'identifiable_people'=>false,'trademarks_logos'=>false,
        'title'=>'Scientific research apparatus evidence','chosen_query'=>'scientific research apparatus evidence',
    ];
    $searcher = static fn (string $query): array => [[...$candidate,
        'title'=>'Obraz wyjaśniający znaczenie wyniku scientific result explanation Obraz lub schemat pierwszego faktu scientific evidence diagram ' . $query,
        'chosen_query'=>$query]];
    $recoveryOperation = prepare_article_image_recovery_operation($postId, $topicId, $searcher);
    $recoveryInput = json_decode((string)find_generation_operation($recoveryOperation)['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $recoveries = [];
    foreach ((array)$recoveryInput['missing_slots'] as $index => $slot) {
        if (empty($slot['related_candidates'])) continue;
        $module = $modules[min($index, count($modules)-1)];
        $recoveries[] = ['slot_id'=>$slot['slot_id'],'module_id'=>$module['module_id'],
            'placement'=>$module['preferred_placement'],'editorial_reason'=>'Source-backed apparatus explains the method.',
            'candidate'=>array_intersect_key($candidate, array_flip(['provider_id','source_file_url','relationship','source_page_url','depicts_required_subject']))];
    }
    execute_generation_operation($recoveryOperation, mvp_transport(['recoveries'=>$recoveries], $audit, 'image_recovery'));
    $downloader = static function (array $image) use (&$createdAssets): array {
        $relative = 'images/posts/mvp-e2e-' . bin2hex(random_bytes(5)) . '-recovered.png';
        $absolute = app_path($relative); file_put_contents($absolute, mvp_png()); $createdAssets[] = $absolute;
        return [...$image, 'local_path'=>$relative, 'status'=>'downloaded', 'width'=>1600, 'height'=>900,
            'mime_type'=>'image/png', 'author'=>'Fixture author', 'license'=>'cc0',
            'license_url'=>'https://creativecommons.org/publicdomain/zero/1.0/', 'attribution'=>'Fixture author / CC0',
            'multimodal_accepted'=>1, 'multimodal_assessment'=>['decision'=>'accept']];
    };
    $vision = static fn (): array => ['semantic_relevance'=>10,'editorial_fit'=>10,'hero_fit'=>10,
        'depicts_required_subject'=>false,'misleading'=>false,'inappropriate'=>false,'decision'=>'accept','reason'=>'fixture'];
    $additive = static function (array $payload, string $key, string $operationKey, string $model) use (&$audit, $modules, $db): array {
        $audit[] = 'additive_module';
        $statement = $db->prepare('SELECT * FROM generation_operations WHERE operation_key=:key');
        $statement->execute([':key'=>$operationKey]);
        $prepared = $statement->fetch();
        $input = is_array($prepared) ? json_decode((string)$prepared['input_json'], true) : [];
        $module = null; foreach ($modules as $item) if (($item['module_id'] ?? '') === ($input['module_id'] ?? '')) $module = $item;
        return mvp_response(['module_id'=>(string)($input['module_id'] ?? ''),'target_slot_id'=>(string)($input['target_slot_id'] ?? ''),
            'placement_after_section'=>(string)($input['placement_after_section'] ?? ''),'type'=>'explainer','heading'=>'Source-backed context',
            'body'=>'The approved RSS evidence explains why this related apparatus is relevant.','caption'=>'Related apparatus.',
            'reader_attention_note'=>'This image supplies context, not the direct subject.','source_claim_ids'=>(array)($module['source_claim_ids'] ?? [])], 'mvp-additive');
    };
    $recoveryResult = article_image_apply_shortage_recovery($recoveryOperation, $downloader, $vision, $additive);
    mvp_assert(count((array)$recoveryResult['applied']) === 2, 'two missing inline slots recover through two P06/P07 additive modules');
    mvp_assert((int)$db->query('SELECT COUNT(*) FROM article_related_context_blocks WHERE post_id=' . $postId . ' AND status="approved"')->fetchColumn() === 2,
        'both related images retain approved source-backed additive context');
    $coverage = article_image_coverage_state($postId, $topicId, true);
    mvp_assert($coverage['coverage_complete'] && !empty($coverage['hero_is_allowed']), 'all required slots have local nonfallback legally evidenced assets');
    $imageEvidence = $db->query('SELECT local_path,rights_manifest_json,license,source_page_url FROM article_images WHERE post_id=' . $postId . ' AND status="downloaded"')->fetchAll();
    mvp_assert(count($imageEvidence) === count((array)$coverage['required_slots']), 'every required slot maps to one downloaded evidence record');
    foreach ($imageEvidence as $evidence) {
        $manifest = json_decode((string)$evidence['rights_manifest_json'], true);
        mvp_assert(is_file(app_path((string)$evidence['local_path'])) && filesize(app_path((string)$evidence['local_path'])) > 100
            && is_array($manifest) && trim((string)$evidence['license']) !== '' && str_starts_with((string)$evidence['source_page_url'], 'https://'),
            'each final image has real local bytes plus source/license manifest evidence');
    }
    mvp_assert($coreBefore['core_hash'] === core_text_lock_state((int)$draft['id'])['core_hash'], 'image recovery preserves locked core hash');

    $layoutOperation = prepare_article_layout_plan_operation($postId, $topicId);
    $layout = article_safe_layout_plan();
    execute_generation_operation($layoutOperation, mvp_transport($layout, $audit, 'layout_plan'));
    $layoutAudit = []; $resolvedLayout = article_layout_plan_for_post($postId, $layoutAudit);
    mvp_assert(in_array($resolvedLayout['template_family'], ARTICLE_LAYOUT_FAMILIES, true), 'layout remains allowlisted and PHP-rendered');
    $contentBlocks = [
        ['type'=>'section','id'=>'lead','variant'=>'default','blocks'=>[['type'=>'paragraph','text'=>'Locked core preview.']]],
        ['type'=>'section','id'=>'why-important','variant'=>'importance','blocks'=>[['type'=>'paragraph','text'=>'Why it matters.']]],
        ['type'=>'section','id'=>'fact-1','variant'=>'default','blocks'=>[['type'=>'paragraph','text'=>'Source-backed fact.']]],
    ];
    $db->prepare('UPDATE posts SET content_blocks=:blocks WHERE id=:id')->execute([':blocks'=>generation_json($contentBlocks), ':id'=>$postId]);
    refresh_article_image_rendering($postId);

    $finalOperation = prepare_final_multimodal_qc_operation($postId, $topicId, (int)$draft['id']);
    execute_generation_operation($finalOperation, mvp_transport(final_multimodal_qc_mock_generation_value(), $audit, 'final_multimodal_qc'));
    $final = complete_final_multimodal_qc_operation($finalOperation);
    mvp_assert($final['decision'] === 'PASS' && final_multimodal_qc_readiness($postId) === 'ready_for_manual_publish', 'final multimodal QC yields private manual-publish readiness');
    $finalPost = find_post($postId);
    mvp_assert((int)$finalPost['is_published'] === 0 && (string)$finalPost['status'] !== 'published', 'E2E stops at private preview and never publishes');
    mvp_assert(str_contains((string)$finalPost['content'], 'article-layout') && str_contains((string)$finalPost['content'], '<img'), 'private preview contains composed local image markup');

    $budget = gemini_article_budget_state($postId);
    $log = json_decode((string)$budget['calls_log_json'], true, 128, JSON_THROW_ON_ERROR);
    mvp_assert((int)$budget['used_calls'] <= 20 && count($log) <= 20, 'article budget is at most 20 and has no request #21');
    $types = array_map(static fn (array $entry): string => (string)($entry['operation_type'] ?? ''), $log);
    foreach (['research_package','narrative_plan','article_draft','quality_check','image_recovery','additive_module','layout_plan','final_multimodal_qc'] as $required) {
        mvp_assert(in_array($required, $types, true), 'budget/audit sequence contains ' . $required);
    }
    mvp_assert($audit[0] === 'research' && end($audit) === 'final_multimodal_qc', 'controlled transport audit spans the entire ordered chain');

    echo 'MVP_PIPELINE_E2E_OK ' . count($audit) . "\n";
} finally {
    foreach ($createdAssets as $asset) if (is_file($asset)) @unlink($asset);
    foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm'] as $file) if (is_file($file)) @unlink($file);
    if (is_dir($directory)) @rmdir($directory);
}
