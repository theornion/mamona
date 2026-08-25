MAMONA V4.6 — START / RESUME AUTONOMOUS WORK

Pracuj jako `mamona-coordinator` z paczki V4.6.1 Tri-Tier Parallel Schema Hotfix.

CEL:
Wznowić aktualną pracę Mamony z bieżącego working tree i prowadzić ją autonomicznie do najbliższego prawdziwego phase/checkpoint hard stopu. Nie restartuj zakończonych faz.

==================================================
0. RUNTIME RULES — WAŻNE
==================================================

Do child delegation używaj WYŁĄCZNIE Kilo `Task` tool.

NIE używaj:
- agent_manager,
- agent_manager_models,
- worktree/session APIs,
- ręcznego session_id,
- `new` jako session ID,
- resume/prompt istniejącej child session.

Jeden Task call = jedna świeża child session.
Jeżeli child zwróci niedoskonały format, wykorzystaj jego faktyczny tekst; nie resume'uj go tylko po raport.

==================================================
1. LOAD CURRENT STATE
==================================================

Przeczytaj w tej kolejności i tylko tyle, ile potrzeba:
1. AGENTS.md
2. docs/AGENT_EXECUTION_PROTOCOL.md
3. docs/CURRENT_WORK.md, jeśli istnieje
4. najnowszy checkpoint aktywnej fazy
5. właściwy aktualny task/spec wskazany przez CURRENT_WORK/checkpoint
6. git status --short
7. git diff --stat

Jeżeli starszy task/checkpoint mówi coś sprzecznego z nowszym CURRENT_WORK, aktualnym kodem lub nowszym checkpointem, nie cofaj postępu. Zapisz konflikt jako stale-doc i użyj nowszego potwierdzonego stanu.

Nie rób pełnego researchu P0..poprzednie fazy bez konkretnej potrzeby.
Nie cofaj niezacommitowanych zmian użytkownika.

==================================================
2. PERMISSION + ROUTING SMOKE — NIE ZATRZYMUJ PO NIM
==================================================

Coordinator bezpośrednio wykonuje:
- git status --short
- C:/xampp/php/php.exe -v

Następnie, jeśli istnieje niezależny read-only atom do rozpoznania, uruchom FAST_PARALLEL smoke w realnej pracy:
- LANE M: jeden rzeczywisty read-only `mamona-diagnoser` albo `mamona-architect` atom;
- LANE F: `mamona-executor` wykonuje jeden niezależny, bezpieczny exact test/lint/`php -v`.

Wyślij oba Task calls w tym samym kroku przed czekaniem na pierwszy wynik.
Jeżeli w aktualnej pracy nie ma bezpiecznej pary, pomiń parallel smoke i pracuj sekwencyjnie — nie wymyślaj sztucznego tasku.

Jeżeli executor jest tool/permission blocked, coordinator wykonuje ten exact command bezpośrednio i kontynuuje. Nie deleguj testu workerowi.

Jeżeli Task tool zwróci wewnętrzny session/schema error:
- NIE próbuj agent_manager;
- NIE próbuj resume child session;
- NIE przekazuj session_id=`new`;
- wykonaj najwyżej jeden świeży Task retry z prawidłową nazwą agenta;
- po drugim launch failure użyj primary fallback dla deterministycznej pracy albo PARKED technical blocker i kontynuuj inne READY atomy.

==================================================
3. BUILD A SMALL DAG
==================================================

Z aktualnego tasku utwórz 3–7 atomów maksymalnie:
- DONE
- READY
- WAITING_ON:<atom>
- PARKED:<reason>

Każdy atom ma:
- exact goal;
- read/write scope;
- dependencies;
- validation;
- właściwy tier.

Nie rozdrabniaj mechanicznie jednej zmiany na wiele agentów.

==================================================
4. PARALLEL SCHEDULER
==================================================

Przed każdym dispatch sprawdź, czy są dwa niezależne READY atomy.

FAST_PARALLEL = maksymalnie:
- jeden 14B lane;
- jeden 9B lane.

Przed spawnem zapisz krótko:
FAST_PARALLEL_PLAN
- Lane_M:
- Lane_F:
- Dependency: NONE
- Write_overlap: NONE
- Cross_read_dependency: NONE
- Barrier:

Jeżeli te pola nie są prawdziwe -> sekwencyjnie.

Preferowane pary:
- 14B diagnosis/review A + 9B test B;
- 14B architect A + 9B regression B;
- 14B worker A + 9B test B, tylko jeśli B nie czyta A;
- dwa writery tylko przy exact, rozłącznych write-setach i bez wspólnego config/index/test.

Po obu wynikach BARRIER. Dopiero potem krok zależny.

==================================================
5. ROUTING
==================================================

- exact test/lint/command -> mamona-executor 9B
- exact mechanical 1-file fix -> mamona-quick-worker 9B
- one real failure root cause -> mamona-diagnoser 14B
- bounded 2–4-symbol contract/design -> mamona-architect 14B
- normal bounded implementation -> mamona-worker 14B
- independent bounded review -> mamona-reviewer 14B
- confirmed repo-level/cross-cutting implementation -> mamona-heavy-coder 30B EXCLUSIVE
- checkpoint/handoff -> checkpoint-writer 9B

30B:
- tylko heavy implementation;
- nigdy audit fallback;
- nigdy dlatego, że wcześniejszy agent „nic nie znalazł”;
- przed 30B zakończ aktywne lane M/F i zrób BARRIER;
- podczas 30B nie uruchamiaj innych lokalnych childów.

==================================================
6. EVIDENCE + FIX LOOP
==================================================

FAIL -> raw exit/evidence -> bounded diagnosis -> potwierdzony target -> fix -> fizyczny git diff -> targeted retest.

Exact symbol/caller sprawdzaj przez git grep/rg/exact read.
Glob/semantic search nie jest dowodem braku symbolu.
Sprzeczny finding = INVALID i nie prowadzi do fixa.
NO_FINDING jest poprawnym wynikiem.

Nie powtarzaj broad prompta na tym samym tierze.
Po jednej nieskutecznej próbie: zawęź metodę lub eskaluj jeden tier.

==================================================
7. AUTONOMY
==================================================

Nie pytaj użytkownika po:
- PASS/FAIL zwykłego testu;
- zakończeniu childa;
- bounded diagnosis;
- oczywistym, potwierdzonym fixie;
- targeted retest;
- przejściu do kolejnego READY atomu;
- technicznym BLOCKED jednego lane, jeśli istnieją inne READY atomy.

Pytaj/stój tylko gdy:
- potrzebna jest decyzja produktowa lub architektoniczna, której repo nie rozstrzyga;
- potrzebny jest sekret/dane spoza repo;
- operacja byłaby destrukcyjna/produkcyjna;
- wszystkie pozostałe atomy są realnie blocked;
- osiągnięto formalny checkpoint/hard stop wymagający akceptacji użytkownika.

Nie commituj. Nie pushuj. Nie resetuj/clean/rebase/merge. Nie uruchamiaj live providerów/publikacji bez jawnego zakresu.

==================================================
8. FINALIZATION
==================================================

Po zakończeniu aktywnej fazy:
- final git status/diff;
- minimalny wymagany regression set;
- CURRENT_WORK z potwierdzonym stanem;
- checkpoint, jeśli wymaga go task/protokół;
- STOP na formalnym boundary.

Końcowy raport ma być krótki:
MAMONA_RUN_RESULT
- Active_phase:
- Completed_atoms:
- Parallel_pairs_used:
- Heavy_used: YES/NO
- Tests:
- Changed_files:
- Parked_blockers:
- CURRENT_WORK_updated: YES/NO
- Checkpoint_created: YES/NO
- Next_action:

Zacznij teraz od punktu 1 i pracuj dalej bez oczekiwania na dodatkowe potwierdzenia.
