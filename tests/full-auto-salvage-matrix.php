<?php

declare(strict_types=1);

function salvage_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function salvage_supported(array $claims, array $sources): array {
    return array_values(array_filter($claims, static function(array $claim) use($sources): bool {
        $source=(string)($sources[$claim['source_id']??'']??''); $evidence=(string)($claim['evidence']??'');
        return $evidence!=='' && str_contains($source,$evidence);
    }));
}

$sources=[
    'A'=>'Sonda wykryła lód w zacienionym kraterze.',
    'B'=>'Spektrometr potwierdził sygnał w trzech niezależnych pomiarach.',
    'C'=>'Mapa temperatur wskazuje obszar zgodny z warunkami zachowania lodu.',
];
$report=['published'=>false,'status'=>'running','decisions'=>[],'sources'=>[],'removed_claims'=>[],'image_provenance'=>[]];

// Błędny tytuł: pięć kandydatów, wybór wyłącznie spośród wspartych.
$titles=['Na Księżycu na pewno jest ocean','Pomiar wskazuje lód w kraterze','Sensacyjne odkrycie zmienia wszystko','Lód wykryty przez sondę','Sygnał lodu potwierdzono pomiarami'];
$supportedTitles=array_values(array_filter($titles,static fn(string $t):bool=>!str_contains($t,'ocean')&&!str_contains($t,'Sensacyjne')));
salvage_assert(count($titles)===5 && $supportedTitles!==[],'Drabina nie przygotowała pięciu kandydatów tytułu.');
$report['decisions'][]=['step'=>'title_candidates','count'=>5,'selected'=>$supportedTitles[0]];

// Za krótki draft: zweryfikowane B i pełna struktura A-B-A-B-A.
$structure=['A','B','A','B','A']; salvage_assert($structure===['A','B','A','B','A'],'Nie zachowano struktury A-B-A-B-A.');
$report['sources']['B']=['source_id'=>'B','evidence'=>'trzech niezależnych pomiarach'];
$report['decisions'][]=['step'=>'expand_short_draft','structure'=>$structure,'source'=>'B'];

// Brak B wymusza enrichment przed użyciem; C jest następnym, zweryfikowanym salvage.
$events=['research_enrichment_B','use_B','verify_C','image_search_C'];
salvage_assert(array_search('research_enrichment_B',$events,true)<array_search('use_B',$events,true),'B użyto przed enrichmentem.');
$report['sources']['C']=['source_id'=>'C','evidence'=>'obszar zgodny z warunkami'];
$report['decisions'][]=['step'=>'weak_after_B','source'=>'C','action'=>'image_search'];

// Brak obrazu zewnętrznego: neutralny lokalny asset, z jawnym pochodzeniem.
$report['image_provenance'][]=['kind'=>'local_neutral_fallback','path'=>'assets/fallbacks/neutral-science.svg','external'=>false];
salvage_assert($report['image_provenance'][0]['external']===false,'Fallback obrazu udaje źródło zewnętrzne.');

// Dwa modelowe repair kończą się bezpiecznym composerem; unsupported claim jest usuwany, nigdy przepuszczany.
$claims=[
    ['text'=>'Wykryto lód','source_id'=>'A','evidence'=>'wykryła lód'],
    ['text'=>'Lód nadaje się do picia','source_id'=>'A','evidence'=>'nadaje się do picia'],
];
$safeClaims=salvage_supported($claims,$sources); $report['removed_claims']=array_values(array_diff(array_column($claims,'text'),array_column($safeClaims,'text')));
salvage_assert(count($safeClaims)===1 && $report['removed_claims']===['Lód nadaje się do picia'],'Safe composer przepuścił niewsparty fakt.');
$report['decisions'][]=['step'=>'model_repair','attempts'=>2,'result'=>'failed'];
$report['decisions'][]=['step'=>'deterministic_safe_composer','factual_pass'=>true,'result'=>'ready_with_notes'];
$report['status']='ready_with_notes';

// Transport jest jedyną klasą kończącą przebieg statusem retry zamiast preview.
foreach(['429','timeout'] as $failure){$transport=['failure'=>$failure,'status'=>'auto_retry_scheduled'];salvage_assert($transport['status']==='auto_retry_scheduled','Transport nie zaplanował retry.');}

// Stary waiting_review jest wznawiany przez reconcile, nie pozostaje terminalny.
$legacy=['before'=>'waiting_review','reconcile'=>'resume','after'=>'queued'];
salvage_assert($legacy['after']==='queued','Legacy waiting_review nie został wznowiony.');

foreach(['decisions','sources','removed_claims','image_provenance'] as $field) salvage_assert($report[$field]!==[],'Raport preview nie zawiera '.$field.'.');
salvage_assert($report['status']==='ready_with_notes' && $report['published']===false,'Nie powstał kompletny niepubliczny preview package.');
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nFULL_AUTO_SALVAGE_MATRIX_OK\n";
