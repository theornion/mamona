# Agent Execution Protocol — Mamona V3.1

Ten dokument jest trwałym kontraktem wykonawczym dla agentów Kilo.

## 1. Definicja subtasku

```text
SUBTASK
- ID:
- Cel:
- Agent:
- Model:
- Wariant:
- Tryb: read-only / edit / test / review / mechanical / checkpoint
- Tryb lokalizacji: DIRECT_TARGET / TARGETED_SEARCH / EXPLORATORY
- Maks. plików produkcyjnych:
- Maks. nowych/zmienionych linii (orientacyjnie):
- Dozwolone narzędzia:
- Zakazane operacje:
- Punkt startowy:
- Exact targets:
- Wymagane dowody:
- Warunek COMPLETE:
- Warunek PARTIAL_COMPLETE:
- Warunek BLOCKED:
- Format raportu:
```

## 2. Rozmiar atomu

Domyślny atom:
- 1–2 pliki produkcyjne;
- jedna logiczna odpowiedzialność;
- jeden integration point;
- około 100–150 nowych/zmienionych linii.

Jeżeli zadanie wymaga >200 linii nowego komponentu, podziel:
`schema/contract → component → integration → test`.

Nie łącz:
`migration + duży service + dispatch integration + duży test + docs`.

## 3. DIRECT_TARGET_MODE

Aktywuj, gdy prompt zawiera dokładny plik i symbol.

Subagent:
1. nie czyta całego taska/specyfikacji;
2. nie wykonuje `git log`;
3. nie używa broad grep/glob;
4. czyta wskazany symbol i najwyżej bezpośrednie zależności;
5. wykonuje maksymalnie 8–12 operacji discovery przed pierwszym `edit`;
6. edytuje;
7. uruchamia najmniejszy test;
8. kończy raportem.

## 4. TARGETED_SEARCH

Gdy exact target nie jest znany:
1. maks. 2 wyszukiwania symboli;
2. maks. 6 nowych plików;
3. po znalezieniu symbolu przejdź do DIRECT_TARGET_MODE;
4. nie wracaj do broad research.

## 5. EXPLORATORY

Tylko dla repo-scout/architect:
- maks. 12 plików;
- brak edycji produkcyjnej;
- każdy odczyt odpowiada konkretnemu pytaniu;
- semantic search jest tropem, nie dowodem.

## 6. Statusy

### COMPLETE
Cały atom wykonany, test minimalny zakończony, raport kompletny.

### PARTIAL_COMPLETE
Spójna część została zapisana, ale atom nie został domknięty.

```text
SUBTASK_RESULT
- Status: PARTIAL_COMPLETE
- Completed:
- Remaining:
- Safe continuation point:
- Changed files:
- Tests:
- Risks:
```

Rodzic uruchamia jeden continuation task wyłącznie dla `Remaining`.

### BLOCKED
Nie ma bezpiecznego następnego kroku bez nowej decyzji/danych.

## 7. Coder

Coder:
- nie wykonuje nowego szerokiego researchu;
- preferuje małe `edit`;
- nie tworzy wielkiego `write/apply_patch`, gdy zmianę można podzielić;
- nowy plik >200 linii traktuje jako osobny atom;
- nie łączy dużego komponentu z integracją, jeśli grozi to utratą raportu;
- przy rosnącym zakresie zwraca `PARTIAL_COMPLETE`, nie pusty wynik.

Docelowo:
- 3–8 min typowo;
- <=25 tool calli;
- <=12 discovery przed pierwszym edit;
- 1–2 pliki produkcyjne.

To cele operacyjne, nie twarde kryteria błędu.

## 8. Tester

Kolejność:
1. uruchom istniejące testy związane ze zmianą;
2. zinterpretuj failure;
3. dodaj nową fixture tylko dla brakującego coverage;
4. preferuj test tabelaryczny i mały;
5. nie twórz mini-frameworka, jeśli prosty test wystarczy;
6. dla realnego błędu zwróć exact fix target;
7. nie implementuj produkcyjnej poprawki.

## 9. Orchestrator

Orchestrator jest dispatcherem, nie drugim coderem.

### Po COMPLETE
Sprawdza status, pliki, test i ryzyka. Nie czyta całego diffu ponownie. Deleguje kolejny wymagany krok.

### Po PARTIAL_COMPLETE
Nie reverse-engineeruje repo. Uruchamia jeden continuation task z `Completed`, `Remaining`, `Safe continuation point`, `Changed files`.

### Po pustym wyniku / abort
1. `git diff --stat`;
2. diff tylko plików zmienionych przez subtask;
3. jeden targeted recovery;
4. po drugim niepowodzeniu `BLOCKED`.

Orchestrator nie wykonuje `edit` produkcyjnego kodu.


## 9A. Result file — obowiązkowy durable handback

Każdy subtask reasoningowy otrzymuje:
`Result file: .kilo/results/<SUBTASK-ID>.json`

Subagent zapisuje ten JSON natywnym `write` przed finalną odpowiedzią.

Minimalny kontrakt:
```json
{
  "status": "COMPLETE|PARTIAL_COMPLETE|BLOCKED",
  "changed_files": [],
  "tests": [],
  "remaining": [],
  "safe_continuation_point": "",
  "risks": []
}
```

Rodzic po zakończeniu childa:
1. czyta Result file;
2. jeżeli istnieje i jest spójny, nie analizuje ponownie całego diffu;
3. pusty wynik tekstowy przy istniejącym Result file nie jest awarią;
4. brak tekstu i brak Result file uruchamia dopiero recovery.

Pliki `.kilo/results/*.json` są ignorowane przez Git.

## 9B. Zakaz shell-write

Żaden agent nie może traktować braku `edit/write/apply_patch` jako zgody na zapis przez:
- PowerShell;
- bash redirection;
- `echo`;
- `Set-Content`;
- `Out-File`;
- `php -r`;
- here-string;
- base64;
- skrypt generujący plik.

Do plików używaj natywnych file tools zgodnie z permission scope.

## 10. Auto-compaction Orchestratora

### Standard

Nie rotuj sesji automatycznie przy 65%.

Kilo wykonuje Context Condensing przy `threshold_percent = 65`:
- starsza historia zostaje zastąpiona zakotwiczonym summary;
- ostatnie 2 turny są zachowywane verbatim, gdy mieszczą się w budżecie;
- do 8000 tokenów recent tail jest preferowane do zachowania;
- stare duże tool outputs są prunowane;
- summary wykonuje `ollama/qwen3.5-no-think`.

Po compaction:
1. kontynuuj w tej samej sesji;
2. nie czytaj ponownie P0/P1/P2 tylko dlatego, że odbył się compaction;
3. sprawdź jedynie, czy summary zachowało aktywny cel, completed scope, blockery i exact next step;
4. jeżeli stan jest wystarczający, kontynuuj;
5. jeżeli summary utraciło krytyczną informację, odczytaj wyłącznie najnowszy trwały checkpoint/handoff.

### Formalny handoff nadal istnieje

Handoff do pliku wykonuj:
- na granicy dużych faz, np. P2→P3, P3→P4;
- przed krytyczną mutacją wymagającą osobnej akceptacji;
- gdy auto-compaction technicznie nie zadziała;
- gdy po compaction kontekst nadal jest blisko limitu lub summary jest niewystarczające.

Nie twórz nowej sesji tylko po to, aby zejść z 65%.

## 11. Mechanical finalization

- `quick-maintainer` → `ollama/qwen3.5:9b/no-think`;
- `checkpoint-writer` → `ollama/qwen3.5:9b/no-think`.

Po successful write/edit:
- nie powtarzaj write;
- nie czytaj ponownie pliku;
- zwróć marker.

Rodzic po markerze nie uruchamia 27B tylko po to, aby ponownie sprawdził dokument.

## 12. Runtime stability

- subagent `doom_loop: deny`;
- subagent nie uruchamia dalszych subagentów;
- `OLLAMA_NUM_PARALLEL=1`;
- provider `timeout=false`;
- `chunkTimeout=1800000`;
- `OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX=65536`;
- duże argumenty tool call dziel na atomy.

Techniczny abort nie oznacza rollbacku zapisanych zmian.

## 13. Recovery limit

Maksymalnie:
1. continuation istniejącej sesji, jeśli dostępne;
2. jeden nowy targeted recovery.

Po kolejnym failure: `BLOCKED`.

## 14. Bezpieczeństwo

- bez płatnych API bez zgody;
- bez publikacji bez zgody;
- bez produkcyjnych mutacji bez zgody;
- bez commit/push bez prośby;
- nie nadpisuj niezacommitowanych zmian użytkownika.

## 15. UTF-8

- UTF-8;
- kod bez BOM, jeśli format nie wymaga inaczej;
- zachowuj polskie znaki;
- sprawdź `�`, `Ã`, `Â`, `Ä`, `Å`, `â€`.
