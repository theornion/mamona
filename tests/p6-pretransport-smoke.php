<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function p6pt_assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}

function p6pt_reject(callable $callback, string $code): void
{
    try { $callback(); } catch (ArticleRecoveryPreflightException $exception) {
        p6pt_assert($exception->reasonCode === $code, 'typed refusal: ' . $code);
        return;
    }
    throw new RuntimeException('Expected typed refusal: ' . $code);
}

$source = [['source_id'=>'s1','title'=>'RSS','url'=>'https://example.test/rss','excerpt'=>'Evidence','claim_ids'=>['c1'],'claim_trace'=>['research_package_id'=>1,'claim_ids'=>['c1']]]];
$module = ['module_id'=>'m1','topic'=>'Scientific method','purpose'=>'Explain source-backed scientific context.','suitable_visual_types'=>['diagram'],'preferred_placement'=>'after-lead','source_claim_ids'=>['c1']];
$slot = ['slot_id'=>'inline-lead','role'=>'inline','section_anchor'=>'lead','visual_need'=>'Related research apparatus.','must_be_direct'=>false,'acceptable_related'=>true,'search_queries_direct'=>['apparatus'],'search_queries_related'=>['research apparatus'],'required'=>true];
$hero = ['slot_id'=>'hero-main','role'=>'hero','section_anchor'=>'article','visual_need'=>'Direct subject photograph.','must_be_direct'=>true,'acceptable_related'=>false,'search_queries_direct'=>['subject'],'search_queries_related'=>[],'required'=>true];
$candidate = ['provider_id'=>'img1','source_file_url'=>'https://example.test/i.jpg','source_page_url'=>'https://example.test/page','relationship'=>'apparatus','depicts_required_subject'=>false];
$input = ['research_source_map'=>$source,'expansion_modules'=>[$module],'visual_plan'=>['hero_slot'=>$hero,'inline_slots'=>[$slot]],'missing_slots'=>[[...$slot,'related_candidates'=>[$candidate]]]];

article_image_shortage_recovery_preflight($input, false, true);
p6pt_assert(true, 'valid source/module/slot binding passes central preflight');
$perSlot = article_image_apply_shortage_recovery_slot_classification([...$input, 'missing_slots'=>[
    ['slot_id'=>'hero-main','role'=>'hero','required'=>true,'acceptable_related'=>false,
        'hero_recovery_policy'=>'source_backed_related_hero_v1','direct_exhaustion'=>['confirmed'=>true], 'related_candidates'=>[]],
    [...$slot, 'related_candidates'=>[$candidate]],
]]);
article_image_shortage_recovery_preflight($perSlot, false, true);
p6pt_assert(array_column($perSlot['missing_slots'], 'slot_id') === ['inline-lead'], 'One unrecoverable hero does not block preflight for another recoverable slot.');

$bad = $input; $bad['research_source_map'] = [];
p6pt_reject(static fn () => article_image_shortage_recovery_preflight($bad), 'recovery_missing_source_map');
$bad = $input; $bad['expansion_modules'][0]['source_claim_ids'] = ['unknown'];
p6pt_reject(static fn () => article_image_shortage_recovery_preflight($bad), 'recovery_no_supported_modules');
$bad = $input; $bad['visual_plan']['inline_slots'][0]['acceptable_related'] = false;
p6pt_reject(static fn () => article_image_shortage_recovery_preflight($bad), 'recovery_invalid_narrative_plan');
$bad = $input; $bad['missing_slots'][0]['related_candidates'] = [];
p6pt_reject(static fn () => article_image_shortage_recovery_preflight($bad, false, true), 'recovery_no_supported_modules');
p6pt_assert(article_recovery_protected_closure_calls('image_recovery') === 4, 'P06 preserves P07, final hero Vision, and P08/P09 closure calls');
p6pt_assert(article_recovery_protected_closure_calls('additive_module') === 3, 'P07 preserves final hero Vision and P08/P09 closure calls');

$schema = narrative_plan_schema(['s1'], ['c1']);
$moduleSchema = $schema['properties']['expansion_modules']['items'];
p6pt_assert(in_array('source_claim_ids', $moduleSchema['required'], true), 'NarrativePlan modules carry auditable source claims');

$db = bueno_database();
$db->exec('INSERT INTO post_categories (title,description,slug,sort_order) VALUES ("P6 budget","","p6-budget",0)');
$categoryId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO posts (category_id,title,excerpt,content,slug,status,is_published) VALUES (:category,"P6 budget","","","p6-budget-post","draft",0)')->execute([':category'=>$categoryId]);
$budgetPostId = (int) $db->lastInsertId();
for ($index = 1; $index <= 17; $index++) {
    $claim = gemini_article_budget_claim($db, $budgetPostId, 'research_package', 'test', $index, 'p6-used-' . $index);
    gemini_article_budget_reconcile_claim($db, $budgetPostId, (string) $claim['claim_token'], 'completed');
}
$budgetBefore = gemini_article_budget_state($budgetPostId);
$reservedBefore = count(array_filter(json_decode((string) ($budgetBefore['calls_log_json'] ?? '[]'), true) ?: [], static fn (array $entry): bool => (string) ($entry['status'] ?? '') === 'reserved'));
try {
    gemini_article_budget_claim($db, $budgetPostId, 'image_recovery', 'images', 1, 'p6-closure-refusal', article_recovery_protected_closure_calls('image_recovery'));
    p6pt_assert(false, 'P06 must not consume the closure floor');
} catch (GeminiArticleBudgetException) {
    p6pt_assert((int) (gemini_article_budget_state($budgetPostId)['used_calls'] ?? -1) === 17, 'closure refusal consumes zero budget');
}
$budgetAfter = gemini_article_budget_state($budgetPostId);
$reservedAfter = count(array_filter(json_decode((string) ($budgetAfter['calls_log_json'] ?? '[]'), true) ?: [], static fn (array $entry): bool => (string) ($entry['status'] ?? '') === 'reserved'));
p6pt_assert($reservedBefore === $reservedAfter, 'closure refusal leaves no reserved claim');

$operationInput = [...$input, 'post_id'=>$budgetPostId, 'topic_id'=>1, 'draft_version_id'=>1, 'narrative_plan_id'=>1];
$firstOperation = prepare_generation_operation('image_recovery', $operationInput, article_image_recovery_planner_schema(), $budgetPostId, null);
$secondOperation = prepare_generation_operation('image_recovery', $operationInput, article_image_recovery_planner_schema(), $budgetPostId, null);
p6pt_assert($firstOperation === $secondOperation, 'identical recovery has one stable auditable operation key');

// Two independent PHP processes/PDOs race for one article. SQLite BEGIN
// IMMEDIATE plus the fresh-row CAS must allow exactly one provider transport.
$raceBase = tempnam(sys_get_temp_dir(), 'mamona-p6-race-');
if ($raceBase === false) throw new RuntimeException('Cannot allocate race fixture.');
@unlink($raceBase);
$raceDb = $raceBase . '.sqlite';
$raceSetup = $raceBase . '-setup.json';
$raceMarker = $raceBase . '-transport.log';
$raceResultOne = $raceBase . '-one.json';
$raceResultTwo = $raceBase . '-two.json';
$child = __DIR__ . '/p6-pretransport-race-child.php';
$php = PHP_BINARY;
$setupCommand = escapeshellarg($php) . ' ' . escapeshellarg($child) . ' setup ' . escapeshellarg($raceDb) . ' ' . escapeshellarg($raceSetup);
exec($setupCommand, $setupOutput, $setupExit);
p6pt_assert($setupExit === 0 && is_file($raceSetup), 'race fixture prepared on disposable SQLite');
$race = json_decode((string) file_get_contents($raceSetup), true, 16, JSON_THROW_ON_ERROR);
$commandOne = escapeshellarg($php) . ' ' . escapeshellarg($child) . ' run ' . escapeshellarg($raceDb) . ' ' . (int) $race['first'] . ' ' . escapeshellarg($raceMarker) . ' ' . escapeshellarg($raceResultOne);
$commandTwo = escapeshellarg($php) . ' ' . escapeshellarg($child) . ' run ' . escapeshellarg($raceDb) . ' ' . (int) $race['second'] . ' ' . escapeshellarg($raceMarker) . ' ' . escapeshellarg($raceResultTwo);
$processOne = proc_open($commandOne, [['pipe','r'],['pipe','w'],['pipe','w']], $pipesOne);
$processTwo = proc_open($commandTwo, [['pipe','r'],['pipe','w'],['pipe','w']], $pipesTwo);
if (!is_resource($processOne) || !is_resource($processTwo)) throw new RuntimeException('Cannot start race workers.');
foreach ([$pipesOne,$pipesTwo] as $pipes) foreach ($pipes as $pipe) fclose($pipe);
$exitOne = proc_close($processOne);
$exitTwo = proc_close($processTwo);
p6pt_assert($exitOne === 0 && $exitTwo === 0, 'both race workers terminate deterministically');
$transportLines = is_file($raceMarker) ? array_values(array_filter(file($raceMarker, FILE_IGNORE_NEW_LINES) ?: [])) : [];
$resultOne = json_decode((string) file_get_contents($raceResultOne), true, 16, JSON_THROW_ON_ERROR);
$resultTwo = json_decode((string) file_get_contents($raceResultTwo), true, 16, JSON_THROW_ON_ERROR);
if (count($transportLines) !== 1) {
    fwrite(STDERR, 'RACE_RESULTS=' . generation_json([$resultOne,$resultTwo]) . "\n");
}
p6pt_assert(count($transportLines) === 1, 'same-article recovery race reaches provider transport exactly once');
p6pt_assert(count(array_filter([$resultOne,$resultTwo], static fn (array $item): bool => ($item['status'] ?? '') === 'refused')) >= 1, 'race loser is explicitly refused before transport');
$racePdo = new PDO('sqlite:' . $raceDb);
$racePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$running = (int) $racePdo->query('SELECT COUNT(*) FROM generation_operations WHERE operation_type IN ("image_recovery","additive_module") AND status="running"')->fetchColumn();
$used = (int) $racePdo->query('SELECT COALESCE(used_calls,0) FROM article_generation_budget WHERE article_id=' . (int) $race['post_id'])->fetchColumn();
if ($running !== 0) fwrite(STDERR, 'RACE_ORPHAN_RESULTS=' . generation_json([$resultOne,$resultTwo]) . "\n");
p6pt_assert($running === 0, 'race loser leaves no running orphan');
p6pt_assert($used === 1, 'race owns exactly one Gemini budget point and cannot create request #21');
foreach ([$raceDb,$raceSetup,$raceMarker,$raceResultOne,$raceResultTwo] as $path) @unlink($path);

echo "P6_PRETRANSPORT_SMOKE_OK\n";
