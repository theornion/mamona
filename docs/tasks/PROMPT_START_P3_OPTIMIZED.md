# PROMPT_START_P3 — MAMONA-24 / V3.1 AUTO-COMPACTION

Pracuj jako `mamona-orchestrator` na `ollama/qwen3.6:27b`, variant `balanced`.

To jest nowa sesja po zakończeniu P2.

Najpierw przeczytaj tylko:
1. `AGENTS.md`;
2. `docs/AGENT_EXECUTION_PROTOCOL.md`;
3. `docs/tasks/NEXT_TASK_ARTICLE_PIPELINE.md`;
4. najnowszy `CHECKPOINT_P2` albo handoff P2;
5. `docs/CURRENT_WORK.md`;
6. zaakceptowaną specyfikację MAMONA-24.

Następnie:
- `git status --short`;
- `git diff --stat`.

Nie czytaj ponownie P0/P1/P2, jeżeli checkpoint/handoff zawiera potwierdzony stan.
Nie implementuj kodu jako Orchestrator.

## P3 — zasada wykonania

Podziel testy P3 na małe sekwencyjne atomy:
`mamona-tester 27B/balanced → wynik → ewentualny targeted mamona-coder → targeted retest`.

Nie uruchamiaj realnego Gemini, providerów obrazów, publikacji ani produkcyjnych mutacji.

### P3-A — długości + GeminiBudget + convergence
Sprawdź:
- min/max typów tekstów;
- kanoniczne liczenie znaków;
- limit 20;
- retry;
- convergence od 16;
- brak obniżania QC.

### P3-B — freeze + narracja + VisualSlot
Sprawdź:
- zamrożenie zaakceptowanego A;
- brak pełnego rewrite przy problemie graficznym;
- hero=A;
- inline image ↔ sekcja;
- progi slotów 1200/2400/3600/4800;
- B/C;
- brak obowiązkowej monotonnej matrycy.

### P3-C — final QC + renderer + publikacja + UTF-8
Sprawdź:
- hard/soft QC;
- brak publikacji bez wymaganych grafik;
- brak finalnych fallbacków/placeholderów;
- fixture polityk-zombie/Trump + brain;
- polskie znaki / JSON / renderer / docs.

### P3-D — audyt/reset deterministyczny
Sprawdź:
- `--dry-run`;
- wykrywanie wymaganych klas wadliwych artykułów;
- brak false positives dla poprawnego artykułu;
- brak Gemini/providerów;
- zachowanie seed/historii;
- bezpieczny pending state;
- manifest/backup/checksum;
- idempotencję;
- NIE uruchamiaj `--apply` na rzeczywistych danych.

## DIRECT_TARGET_MODE

Gdy checkpoint/spec wskazuje exact pliki/symbole, przekaż je testerowi i zabroń ponownego czytania całego taska/specyfikacji.

## Recovery

Tester może zwrócić `PARTIAL_COMPLETE`.
Wtedy jeden continuation task tylko dla `Remaining`.

Jeżeli tester znajdzie błąd:
- Orchestrator NIE edytuje;
- targeted `mamona-coder`;
- potem targeted retest.


## Durable result files

Dla KAŻDEGO subtasku nadaj unikalne ID, np.:
- `P3-A-TEST-01`
- `P3-A-FIX-01`
- `P3-A-RETEST-01`

i przekaż:
`Result file: .kilo/results/<ID>.json`

Po zakończeniu subagenta:
- najpierw przeczytaj jego Result file;
- jeśli JSON istnieje, nie miel ponownie całego wyniku/diffu;
- pusty tekst childa przy poprawnym JSON nie wymaga recovery;
- recovery dopiero gdy brakuje zarówno raportu, jak i Result file.

Tester może natywnie tworzyć/edytować tylko `tests/**`.
Coder może natywnie tworzyć/edytować pliki wymagane przez targeted fix.
Nie używaj shella do zapisu plików.

## Auto-compaction

Kilo automatycznie kondensuje kontekst przy 65%.

Gdy compaction się uruchomi:
1. NIE twórz nowej sesji;
2. kontynuuj jako ten sam Orchestrator;
3. nie czytaj ponownie P0/P1/P2;
4. sprawdź tylko, czy summary zachowało aktywny atom, wykonany zakres, blockery i exact next step;
5. jeżeli summary jest wystarczające, kontynuuj natychmiast.

Nie przerywaj aktualnego child tasku z powodu progu kontekstu.

Handoff do `docs/research/MAMONA-24-P3-handoff.md` wykonuj tylko:
- na formalnym checkpointcie/fazie;
- gdy auto-compaction nie zadziała;
- gdy summary utraci krytyczny stan;
- gdy sesja nadal jest blisko limitu mimo compaction.

Awaryjny handoff: `quick-maintainer` na `qwen3.5:9b/no-think`.

Po zakończeniu całego P3 wykonaj CHECKPOINT_P3.
Nie przechodź do P4 bez zgody użytkownika.
