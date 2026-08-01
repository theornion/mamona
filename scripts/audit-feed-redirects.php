<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

$report = [];
foreach (array_filter(list_technical_sources(true), static fn(array $source): bool => $source['source_type'] === 'rss') as $source) {
    // A health audit must exercise the full response and redirect chain without
    // mutating the stored conditional validators.
    $source['feed_etag'] = '';
    $source['feed_last_modified'] = '';
    $row = ['name'=>$source['name'], 'feed_url'=>$source['feed_url'], 'status'=>'failed', 'items'=>0, 'error'=>'', 'category'=>'', 'redirect_chain'=>[]];
    try {
        $response = fetch_remote_feed((string) $source['feed_url'], $source);
        $row['http_status'] = $response['status'];
        $row['bytes'] = $response['bytes'];
        $row['content_type'] = $response['headers']['content-type'] ?? '';
        $row['redirect_chain'] = $response['redirect_chain'] ?? [];
        if ((int) $response['status'] === 304) {
            $row['status'] = 'not_modified';
        } else {
            $row['items'] = count(parse_feed_document((string) $response['body'], $source));
            $row['status'] = 'succeeded';
        }
    } catch (Throwable $exception) {
        $row['error'] = $exception->getMessage();
        if ($exception instanceof FeedTransportException) {
            $row['category'] = $exception->category;
            $row['http_status'] = $exception->httpStatus;
            $row['bytes'] = $exception->bytesReceived;
            $row['redirect_chain'] = $exception->diagnostics['redirect_chain'] ?? [];
            $row['decision_code'] = $exception->diagnostics['decision_code'] ?? '';
        } else {
            $row['category'] = 'parser';
        }
    }
    $report[] = $row;
}
$cleanup = bueno_database()->query(
    "SELECT migration_key, removed_count, marker, created_at FROM test_artifact_cleanup_audit ORDER BY created_at"
)->fetchAll();
echo json_encode(['cleanup'=>$cleanup, 'sources'=>$report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
