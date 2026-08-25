RESUME MAMONA-24 — P4 / AGENT PACK 4.5.2 / PROJECT PERMISSION CEILING HOTFIX

Pracuj jako:
mamona-coordinator

Aktualna konfiguracja agentów to PACZKA 4.5.2 oparta na V4.5.
Nie stosuj kontraktów ani nazw agentów z 5.1.x.
Nie rozpoczynaj P4 od początku.

ŹRÓDŁEM PRAWDY SĄ:
1. aktualny working tree;
2. docs/CURRENT_WORK.md;
3. aktualny task/spec MAMONA-24;
4. rzeczywiste wyniki testów i diff.

==================================================
0. PERMISSION SMOKE — MUSI BYĆ REALNIE WYKONANY
==================================================

Najpierw, BEZ delegacji, wykonaj jako mamona-coordinator:

C:/xampp/php/php.exe -v

git status --short

Jeżeli PHP nadal zostanie zablokowane i permission error zawiera:

source: project
permission: bash
pattern: *
action: deny

NIE próbuj kolejnych subagentów i NIE wracaj do P4.
Zwróć dokładnie:

PROJECT_PERMISSION_CONFLICT
- Exact_error:
- Coordinator_agent: mamona-coordinator
- Loaded_pack: 4.5.2

Jeżeli oba polecenia działają, kontynuuj automatycznie.

==================================================
1. EXECUTOR PHP SMOKE
==================================================

Uruchom dokładnie jeden subtask:

agent: mamona-executor
cel: wykonać dokładnie
C:/xampp/php/php.exe -v

Executor ma zwrócić raw exit code.

Jeżeli executor PASS:
- używaj go normalnie do exact PHP tests/lint.

Jeżeli executor technicznie BLOCKED przez child permission/tool:
- NIE próbuj workera jako executora;
- NIE powtarzaj tej samej delegacji;
- coordinator przejmuje deterministyczne PHP tests/lint bezpośrednio;
- child permission BLOCKED nie jest findingiem produktu.

==================================================
2. START P4 Z AKTUALNEGO STANU
==================================================

Przeczytaj wyłącznie:

- AGENTS.md
- docs/CURRENT_WORK.md
- aktualny task MAMONA-24
- aktualną obowiązującą specyfikację/override P4
- CHECKPOINT_P3 tylko jeśli task odwołuje się do niego jako wejścia

Następnie:

git status --short
git diff --stat

Nie wykonuj ponownie szerokiego researchu P0-P3.
Nie wymyślaj atomów P4-A..P4-F, jeżeli nie istnieją w aktualnym tasku/spec.

==================================================
3. POTWIERDZONY HANDOFF
==================================================

P4-B bootstrap regression była wcześniej naprawiona:

- prepare_article_draft_operation() jest zdefiniowane w php/article-draft-service.php;
- tests/p3b-narrative-freeze-visualslot-test.php nie ładował wcześniej tego serwisu;
- dodano require_once do article-draft-service.php;
- targeted retest po fixie uzyskał PASS, exit code 0, 80+ assertions.

Nie diagnozuj tego ponownie, jeśli aktualny working tree nadal zawiera fix.
Wykonaj świeży regression test tylko jeśli jest wymagany przez aktualny P4 phase gate.

P4-E miało wcześniej zweryfikowany quality-gate evidence. Nie wracaj do odrzuconych sprzecznych findingów bez nowego konkretnego evidence.

==================================================
4. WORKFLOW V4.5
==================================================

Preferowany przepływ:

mamona-coordinator
-> mamona-executor (exact command/test)
-> przy REALNYM FAIL: mamona-diagnoser
-> przy potwierdzonym fix scope: mamona-worker
-> exact retest
-> mamona-reviewer na phase boundary

Zasady:

- coordinator może sam wykonywać deterministyczne testy/lint/git oraz małe jednoznaczne edycje;
- executor = command-only; bez diagnozy i edycji;
- diagnoser = jeden realny failing cluster; bez implementacji;
- worker = jeden zaakceptowany bounded fix;
- reviewer = review aktualnego diffu/test evidence; nie wykonuje testów;
- heavy-coder tylko dla rzeczywiście ciężkiej implementacji, nigdy jako audit fallback.

Nie używaj:
- mamona-orchestrator;
- mamona-heavy-auditor;
- mamona-quick-worker;
- kontraktów attempt ledger z 5.1.x.

==================================================
5. CANONICAL P4 REVIEW / PHASE GATE
==================================================

Ustal dokładny P4 phase gate z AKTUALNEGO tasku/spec.
Nie zgaduj zakresu.

Uruchom najmniejszy wystarczający zestaw deterministycznych testów wynikający z tasku/spec i aktualnego diffu.

Jeżeli test PASS:
- zapisz raw exit code/evidence;
- przejdź do następnego wymaganego kroku.

Jeżeli test FAIL:
- zapisz pierwszy rzeczywisty failure;
- uruchom bounded mamona-diagnoser dla tego failure;
- tylko po potwierdzonym findingu wykonaj minimalny fix;
- targeted retest.

Reviewer ma ocenić:
- aktualny kod;
- rzeczywisty diff;
- realne test evidence;
- task/spec.

Odrzuć review jako INVALID, jeśli opiera się na nieistniejących ścieżkach, zmyślonych symbolach albo sprzecznym evidence.

Jeżeli kanoniczny P4 dopuszcza rundy review -> fix -> retest, nie przekraczaj limitu określonego w aktualnym tasku/spec.

==================================================
6. FINALIZACJA
==================================================

Jeżeli wymagane testy/review przejdą:

1. git status --short
2. git diff --stat
3. finalny targeted diff
4. zaktualizuj docs/CURRENT_WORK.md wyłącznie potwierdzonym stanem
5. utwórz/zaktualizuj CHECKPOINT_P4 zgodnie z aktualnym standardem repo
6. STOP przed następną główną fazą wymagającą akceptacji użytkownika

Jeżeli checkpoint-writer zwróci poprawną gotową treść, ale nie ma prawa zapisu, coordinator zapisuje ją sam.

Nie commituj.
Nie pushuj.
Nie resetuj/clean working tree.
Nie uruchamiaj live Gemini, providerów grafik, publikacji ani produkcyjnych mutacji.

==================================================
7. AUTONOMICZNA KONTYNUACJA
==================================================

Nie zatrzymuj się po zwykłym PASS ani pojedynczym SUBTASK_RESULT.
Kontynuuj przez jednoznaczne kroki tej samej fazy bez pytania użytkownika.

Pytaj użytkownika tylko przy:
- realnej decyzji produktowej/architektonicznej;
- danych wymaganych spoza repo;
- destrukcyjnej operacji;
- formalnym approval boundary tasku.

==================================================
FINAL OUTPUT
==================================================

P4_FINAL_RESULT
- Agent_pack: 4.5.2
- Primary_agent: mamona-coordinator
- Coordinator_PHP: WORKING | BLOCKED
- Executor_PHP: WORKING | CHILD_BLOCKED_PRIMARY_FALLBACK_USED | BLOCKED
- Canonical_review:
- Tests_run:
- Tests_result:
- Findings_fixed:
- Changed_files:
- CURRENT_WORK_updated: YES | NO
- Checkpoint_P4_created: YES | NO
- Remaining_blockers:
- Next_action:

Jeżeli P4 jest COMPLETE, zakończ na hard stopie P4 i nie uruchamiaj następnej głównej fazy automatycznie.
