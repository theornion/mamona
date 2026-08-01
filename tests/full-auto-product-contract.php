<?php

declare(strict_types=1);

$service=implode("\n", array_map(static fn (string $path): string => (string) file_get_contents(dirname(__DIR__).$path), [
    '/php/generation-batch-service.php', '/php/repair-router-service.php', '/php/salvage-service.php', '/php/admin-proposals.php',
]));
$failures=[];
$requirements=[
    'ready_with_notes'=>'Brak terminalnego kompletnego preview package ready_with_notes.',
    'auto_retry_scheduled'=>'Brak jawnego statusu retry dla 429/timeout.',
    'safe_composer'=>'Brak deterministic safe composer po dwóch modelowych repair.',
    'local_editorial_illustration'=>'Brak neutralnego lokalnego fallbacku obrazu.',
    'final_qc_contract_salvage'=>'Final QC invalid contract does not trigger deterministic salvage.',
    "'model_qc_passed' => false"=>'Deterministic safe-ready falsely reports a model QC pass.',
    'deterministic_preflight_and_post_qc_gate'=>'Missing deterministic decision basis after final QC.',
    'Automatyczne decyzje i wątpliwości'=>'Brak raportu decyzji pod preview.',
];
foreach($requirements as $needle=>$message) if(!str_contains($service,$needle))$failures[]=$message;
if(str_contains($service,"function generation_batch_auto_reject")
    || !str_contains($service,'generation_batch_is_autonomous($item)')){
    $failures[]='generate_all nadal ma terminalną ścieżkę jakości auto_rejected albo brak jawnego rozdzielenia od trybu manualnego.';
}
if($failures!==[]){fwrite(STDERR,"FULL_AUTO_PRODUCT_CONTRACT_RED\n- ".implode("\n- ",$failures)."\n");exit(1);}
echo "FULL_AUTO_PRODUCT_CONTRACT_OK\n";
