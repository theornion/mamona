<?php

declare(strict_types=1);

$options = getopt('', ['live', 'trials::', 'deadline::']);
if (!isset($options['live']) || getenv('CMS_ALLOW_FULL_AUTO_GEMINI_CANARY') !== '1') {
    fwrite(STDERR, "SKIP: wymagane są --live oraz CMS_ALLOW_FULL_AUTO_GEMINI_CANARY=1.\n");
    exit(2);
}
$trials = (int) ($options['trials'] ?? 3);
$deadlineSeconds = (int) ($options['deadline'] ?? 180);
if ($trials < 1 || $trials > 5 || $deadlineSeconds < 30 || $deadlineSeconds > 300) {
    fwrite(STDERR, "Dozwolone: --trials=1..5, --deadline=30..300.\n"); exit(2);
}
require_once dirname(__DIR__) . '/php/app-config.php';
require_once dirname(__DIR__) . '/php/generation-service.php';
$apiKey = app_environment_value('GEMINI_API_KEY');
if ($apiKey === null) { fwrite(STDERR, "Brakuje GEMINI_API_KEY.\n"); exit(2); }

$sources = [
    ['id' => 'S1', 'text' => 'W lokalnym teście trzy kontrolowane próby zakończyły się stabilnym wynikiem.'],
    ['id' => 'S2', 'text' => 'Opis nie rozstrzyga, czy wynik uogólnia się poza warunki testowe.'],
];
$schemas = [
    'research' => ['type'=>'object','properties'=>['summary'=>['type'=>'string'],'source_ids'=>['type'=>'array','items'=>['type'=>'string']],'evidence'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['summary','source_ids','evidence'],'additionalProperties'=>false],
    'draft' => ['type'=>'object','properties'=>['title'=>['type'=>'string'],'text'=>['type'=>'string'],'source_ids'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['title','text','source_ids'],'additionalProperties'=>false],
    'qc' => ['type'=>'object','properties'=>['pass'=>['type'=>'boolean'],'issues'=>['type'=>'array','items'=>['type'=>'string']],'hard_blocks'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['pass','issues','hard_blocks'],'additionalProperties'=>false],
];
$allowed = ['schema','source_id','unknown_id','exact-evidence','quality','transport'];
$report = ['started_at'=>gmdate(DATE_ATOM),'trials'=>$trials,'published'=>false,'stages'=>[],'errors'=>array_fill_keys($allowed,0)];
$deadline = microtime(true) + $deadlineSeconds;
$callCount = 0; $maxCalls = $trials * 3; // Bez automatycznych retry: maksymalnie 15 wywołań.

function canary_category(Throwable $e): string {
    $m = strtolower($e->getMessage());
    if (str_contains($m, 'source')) return 'source_id';
    if (str_contains($m, 'evidence')) return 'exact-evidence';
    if (str_contains($m, 'json') || str_contains($m, 'schema')) return 'schema';
    if (str_contains($m, '429') || str_contains($m, 'timeout') || str_contains($m, 'http')) return 'transport';
    return 'quality';
}
function canary_redacted(mixed $value): array {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return ['sha256'=>hash('sha256', (string)$json),'bytes'=>strlen((string)$json),'keys'=>is_array($value)?array_keys($value):[]];
}

foreach (array_keys($schemas) as $stage) { $report['stages'][$stage] = ['first_pass_success'=>0,'success_after_repair'=>0,'artifacts'=>[]]; }
try {
    for ($trial=1; $trial <= $trials; $trial++) {
        $context = ['sources'=>$sources];
        foreach ($schemas as $stage=>$schema) {
            if (microtime(true) >= $deadline || $callCount >= $maxCalls) { throw new RuntimeException('timeout: twardy limit canary'); }
            $prompt = "Etap {$stage}. Użyj wyłącznie lokalnych fragmentów i zwróć JSON zgodny ze schematem. Nie publikuj. Dane: " . json_encode($context, JSON_UNESCAPED_UNICODE);
            $payload = ['contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json','responseJsonSchema'=>$schema,'temperature'=>0]];
            $callCount++;
            try {
                $response = gemini_curl_transport($payload, $apiKey, "full-auto-canary-{$trial}-{$stage}", (string)app_config('gemini_model'));
                if ((int)$response['status'] < 200 || (int)$response['status'] >= 300) { throw new RuntimeException('HTTP ' . (int)$response['status']); }
                $decoded = json_decode((string)$response['body'], true, 128, JSON_THROW_ON_ERROR);
                $value = decode_generation_response((string)gemini_extract_output($decoded)['text']);
                validate_generation_value($value, $schema);
                foreach ((array)($value['source_ids'] ?? []) as $id) { if (!in_array($id, ['S1','S2'], true)) throw new RuntimeException('source_id'); }
                if ($stage === 'qc' && (($value['pass'] ?? false) !== true || ($value['hard_blocks'] ?? []) !== [])) throw new RuntimeException('quality');
                $report['stages'][$stage]['first_pass_success']++;
                $report['stages'][$stage]['success_after_repair']++;
                $report['stages'][$stage]['artifacts'][] = canary_redacted($value);
                $context[$stage] = $value;
            } catch (Throwable $e) {
                $category = canary_category($e); $report['errors'][$category]++;
                $report['stages'][$stage]['artifacts'][] = ['error_category'=>$category];
                break; // Jawny brak retry chroni quota; "after repair" pozostaje 0 dla tej próby.
            }
        }
    }
} finally {
    $report['finished_at'] = gmdate(DATE_ATOM); $report['calls'] = $callCount; $report['max_calls'] = $maxCalls;
    $dir = dirname(__DIR__) . '/logs/full-auto';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('Nie można utworzyć katalogu raportu.'); }
    $path = $dir . '/canary-' . gmdate('Ymd-His') . '.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    echo "CANARY_REDACTED_REPORT={$path}\n";
}
echo "FULL_AUTO_GEMINI_CANARY_COMPLETE\n";
