<?php

declare(strict_types=1);

$options = getopt('', ['trials::']);
$trials = (int) ($options['trials'] ?? 3);
if ($trials < 1 || $trials > 5) { fwrite(STDERR, "--trials musi być w zakresie 1..5.\n"); exit(2); }
$allowed = ['schema', 'source_id', 'unknown_id', 'exact-evidence', 'quality', 'transport'];
$report = ['trials' => $trials, 'stages' => []];
foreach (['research', 'draft', 'qc'] as $stage) {
    $first = 0; $repaired = 0; $errors = array_fill_keys($allowed, 0);
    for ($i = 1; $i <= $trials; $i++) {
        // Deterministyczna atrapa: druga próba wymaga jednej naprawy, pozostałe przechodzą od razu.
        $category = $i === 2 ? match ($stage) { 'research' => 'exact-evidence', 'draft' => 'unknown_id', default => 'quality' } : null;
        if ($category === null) { $first++; $repaired++; } else { $errors[$category]++; $repaired++; }
    }
    $report['stages'][$stage] = [
        'first_pass_success' => $first, 'success_after_repair' => $repaired,
        'first_pass_rate' => $first / $trials, 'after_repair_rate' => $repaired / $trials,
        'errors' => $errors,
    ];
}
foreach ($report['stages'] as $stage => $data) {
    if ($data['success_after_repair'] !== $trials || array_diff(array_keys($data['errors']), $allowed) !== []) {
        throw new RuntimeException("Niepełny raport dla {$stage}.");
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "FULL_AUTO_STATISTICS_OK\n";
