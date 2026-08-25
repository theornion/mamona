<?php

declare(strict_types=1);

putenv('CMS_TEST_DATABASE_FILE=:memory:');
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function p6_source_map_assert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}

function p6_source_map_expect(callable $callback, string $needle): void
{
    try { $callback(); } catch (Throwable $exception) {
        p6_source_map_assert(str_contains($exception->getMessage(), $needle), 'Fail-closed reason is explicit: ' . $needle);
        return;
    }
    throw new RuntimeException('Expected fail-closed exception was not raised: ' . $needle);
}

$db = bueno_database();
$db->exec('PRAGMA foreign_keys = OFF');
$db->exec('INSERT INTO post_categories (title, description, slug, sort_order) VALUES ("P6 sources", "", "p6-sources", 0)');
$categoryId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO posts (category_id,title,excerpt,content,slug,status,is_published) VALUES (:category,"P6 source map","","","p6-source-map","draft",0)')->execute([':category'=>$categoryId]);
$postId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO editorial_topics (primary_post_id,title,normalized_title,event_at) VALUES (:post,"P6 topic","p6 topic",CURRENT_TIMESTAMP)')->execute([':post'=>$postId]);
$topicId = (int) $db->lastInsertId();

$input = ['numbered_sources'=>[['source_id'=>'s-esa','title'=>'ESA source','url'=>'https://example.test/esa','excerpt'=>'Verified source excerpt']]];
$package = ['claims'=>[['claim_id'=>'claim-1','source_ids'=>['s-esa']]]];
$db->prepare('INSERT INTO generation_operations (operation_key,post_id,topic_id,operation_type,execution_mode,status,prompt_text,input_json,output_schema_json,output_json,input_hash) VALUES ("p6-source-map",:post,:topic,"research_package","mock","completed","fixture",:input,"{}",:output,"fixture")')->execute([':post'=>$postId, ':topic'=>$topicId, ':input'=>generation_json($input), ':output'=>generation_json($package)]);
$operationId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO research_packages (topic_id,post_id,generation_operation_id,status,execution_mode,package_json,approved_at) VALUES (:topic,:post,:operation,"approved","mock",:package,CURRENT_TIMESTAMP)')->execute([':topic'=>$topicId, ':post'=>$postId, ':operation'=>$operationId, ':package'=>generation_json($package)]);

$map = article_image_approved_research_source_map($postId, $topicId);
p6_source_map_assert(count($map) === 1 && $map[0]['source_id'] === 's-esa' && $map[0]['claim_trace']['claim_ids'] === ['claim-1'], 'P06 uses approved package numbered_sources with claim trace.');
$additiveSources = article_image_additive_sources_from_approved_map($map);
p6_source_map_assert($additiveSources[0]['id'] === 's-esa' && $additiveSources[0]['claim_trace']['research_package_id'] > 0, 'P07 inherits the approved source map rather than enrichment rows.');

$transportCalls = 0;
p6_source_map_expect(static function () use (&$transportCalls): void {
    article_image_shortage_recovery_preflight(['research_source_map'=>[], 'expansion_modules'=>[], 'visual_plan'=>[], 'missing_slots'=>[]]);
    $transportCalls++;
}, 'recovery_missing_source_map');
p6_source_map_assert($transportCalls === 0, 'Empty source map is rejected before any provider transport.');
p6_source_map_expect(static function (): void {
    article_image_shortage_recovery_preflight(['research_source_map'=>[['source_id'=>'s-esa','claim_ids'=>['claim-1']]], 'expansion_modules'=>[], 'visual_plan'=>[], 'missing_slots'=>[]]);
}, 'recovery_no_supported_modules');

$db->prepare('UPDATE generation_operations SET input_json=:input WHERE id=:id')->execute([':id'=>$operationId, ':input'=>generation_json(['numbered_sources'=>[['source_id'=>'s-esa','title'=>'ESA']]]), ':id'=>$operationId]);
$db->prepare('UPDATE research_packages SET package_json=:package WHERE generation_operation_id=:operation')->execute([':package'=>generation_json(['claims'=>[['claim_id'=>'bad','source_ids'=>['unknown-source']]]]), ':operation'=>$operationId]);
p6_source_map_expect(static fn () => article_image_approved_research_source_map($postId, $topicId), 'numbered_sources');

$db->exec('DELETE FROM research_packages');
p6_source_map_expect(static fn () => article_image_approved_research_source_map($postId, $topicId), 'zatwierdzonego research package');
echo "P6_APPROVED_SOURCE_MAP_SMOKE_OK\n";
