<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

$command = strtolower((string) ($argv[1] ?? 'status'));
if (!in_array($command, ['status', 'pause', 'resume'], true)) {
    fwrite(STDERR, "Usage: php scripts/automatic-dispatch-control.php [status|pause|resume]\n");
    exit(2);
}

$result = $command === 'status'
    ? ['mode' => 'status', 'report' => generation_dispatch_pause_report()]
    : ['mode' => $command, 'result' => generation_set_automatic_dispatch_paused($command === 'pause', 'operator-cli', $command === 'pause')];

$result['gemini_calls'] = 0;
$result['topics_started'] = 0;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
