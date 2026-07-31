<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin-database.php';

$result = cleanup_trashed_editorial_topics(null, 'maintenance-cli');
fwrite(STDOUT, sprintf(
    "TOPIC_TRASH_CLEANUP cutoff=%s deleted=%d skipped=%d errors=%d\n",
    $result['cutoff_at'],
    $result['deleted'],
    $result['skipped'],
    count($result['errors'])
));
foreach ($result['errors'] as $error) {
    fwrite(STDERR, sprintf("topic_id=%d error=%s\n", $error['topic_id'], $error['message']));
}
exit($result['errors'] === [] ? 0 : 1);
