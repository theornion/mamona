<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mamona-fresh-reset-' . bin2hex(random_bytes(5));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Nie można utworzyć katalogu testu.');
$databaseFile = $directory . DIRECTORY_SEPARATOR . 'cms.sqlite';
putenv('CMS_TEST_DATABASE_FILE=' . $databaseFile);
putenv('CMS_SKIP_PUBLIC_SYNC=1');
putenv('CMS_SOURCE_IMAGE_MOCK=true');
putenv('GEMINI_API_MOCK=true');
register_shutdown_function(static function () use ($databaseFile, $directory): void {
    foreach ([$databaseFile, $databaseFile . '-wal', $databaseFile . '-shm'] as $file) if (is_file($file)) @unlink($file);
    if (is_dir($directory)) @rmdir($directory);
});
require_once dirname(__DIR__) . '/php/admin-database.php';

$database = bueno_database();
$token = bin2hex(random_bytes(4));
$sourceId = save_technical_source(['name'=>'Reset '.$token,'website_url'=>'https://example.test/'.$token,'feed_url'=>'https://example.test/'.$token.'.xml','source_type'=>'rss','topic_category'=>'science','language'=>'pl','credibility_level'=>5,'is_primary'=>1,'is_active'=>1]);
$source = find_technical_source($sourceId);
$postId = persist_discovered_feed_item($source, ['external_id'=>$token,'source_url'=>'https://example.test/article/'.$token,'title'=>'Tytuł RSS','source_name'=>'Reset smoke','published_at'=>gmdate('Y-m-d H:i:s'),'summary'=>'Opis RSS pozostaje po resecie.','category'=>'science','content_hash'=>hash('sha256',$token)]);
$topicStatement = $database->prepare('SELECT topic_id FROM feed_topic_memberships m INNER JOIN discovered_feed_items i ON i.id=m.feed_item_id WHERE i.post_id=:post');
$topicStatement->execute([':post'=>$postId]);
$topicId = (int)$topicStatement->fetchColumn();
$database->prepare('UPDATE posts SET content=:content,content_blocks=:blocks,status="draft" WHERE id=:post')->execute([':post'=>$postId,':content'=>'generated',':blocks'=>json_encode([['type'=>'paragraph','text'=>'generated']])]);
$database->prepare('INSERT INTO article_generation_budget(article_id,max_calls,used_calls,convergence_threshold,calls_log_json,is_exhausted,convergence_active) VALUES(:post,30,7,24,"[]",0,0)')->execute([':post'=>$postId]);
$result = reset_topic_for_fresh_pipeline($topicId, 'smoke');
$post = find_post($postId, true);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$assert(is_file((string)$result['backup_path']), 'Brak backupu.');
$assert(hash_file('sha256', (string)$result['backup_path']) === (string)$result['backup_sha256'], 'Nieprawidłowa checksum backupu.');
$assert((string)$post['status'] === 'idea' && (string)$post['content'] === '' && (string)$post['title'] === 'Tytuł RSS', 'Post nie wrócił do danych RSS.');
$assert((int)$database->query('SELECT COUNT(*) FROM article_generation_budget WHERE article_id='.(int)$postId)->fetchColumn() === 0, 'Budżet artykułu nie został wyzerowany.');
$assert((int)$database->query('SELECT COUNT(*) FROM discovered_feed_items WHERE post_id='.(int)$postId)->fetchColumn() === 1, 'Usunięto dane RSS.');
@unlink((string)$result['backup_path']);
echo "FRESH_PIPELINE_RESET_SMOKE_OK\n";
