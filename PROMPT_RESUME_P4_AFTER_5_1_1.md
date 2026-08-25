RECOVERY / RESUME MAMONA-24 — P4 AFTER 5.1.1 PERMISSION HOTFIX

Pracuj jako Mamona Orchestrator na aktualnym stanie repo.
Agent Pack V5.1.1 został zainstalowany po technicznej blokadzie V5.1.

NIE zaczynaj P4 od początku.
NIE licz poprzedniej blokady permission jako wykorzystanej próby P4-E, ponieważ child session nie została uruchomiona.

Najpierw przeczytaj wyłącznie:

1. AGENTS.md
2. docs/AGENT_EXECUTION_PROTOCOL.md
3. docs/CURRENT_WORK.md
4. aktywny dokument tasku MAMONA-24 / P4
5. właściwą zaakceptowaną specyfikację dla tego tasku

Następnie wykonaj tylko read-only inspection:

- git status --short
- git diff --stat
- git diff tylko dla plików bezpośrednio powiązanych z P4-E, jeśli potrzebny

Nie czytaj ponownie P0/P1/P2/P3.
Nie wykonuj pełnego researchu repo.
Nie implementuj samodzielnie jako koordynator.

==================================================
SNAPSHOT PO NIEUDANEJ PRÓBIE 5.1
==================================================

- V5.1 było załadowane.
- P4-A: BLOCKED — wcześniejsze evidence INVALID/DUPLICATE; brak nowej próby.
- P4-B: fix jest w working tree; targeted retest nadal niezweryfikowany.
- P4-C: BLOCKED — brak zweryfikowanego findingu.
- P4-D: BLOCKED — wcześniejszy finding INVALID.
- P4-E: BLOCKED WYŁĄCZNIE TECHNICZNIE: Task invocation do `mamona-heavy-auditor` została odrzucona przez `task:*`.
- P4-E Attempt 1 NIE został faktycznie uruchomiony i NIE został zużyty.
- P4-F: nieuruchomione.
- Changed_files w tamtym recovery: none.
- Tests_run w tamtym recovery: none.

Jeżeli aktualny `docs/CURRENT_WORK.md` albo diff pokazuje nowszy stan, aktualny stan repo jest źródłem prawdy.

==================================================
V5.1.1 PERMISSION CHECK
==================================================

Przed P4-E sprawdź, czy w bieżącej sesji dostępny jest Task target `mamona-heavy-auditor`.
Nie uruchamiaj built-in `general` ani `explore`.

Jeżeli Task target jest dostępny, przejdź dalej.
Jeżeli nie jest dostępny, zakończ `BLOCKED_AGENT_REGISTRY_NOT_RELOADED` i NIE zużywaj attemptu.

==================================================
P4-E — ATTEMPT 1 AFTER HOTFIX
==================================================

Uruchom dokładnie jeden bounded audit:

agent: mamona-heavy-auditor
model: ollama/qwen3:14b
Attempt_number: 1
method: ENTRYPOINT_ENUMERATION + SYMBOL_TRACE
write_scope: NONE

Cel:
- zacząć od zweryfikowanego symbolu `assert_post_quality_allows_publication()`;
- zidentyfikować rzeczywiste produkcyjne publication entry points;
- dla każdego sprawdzić, czy call path przechodzi przez quality gate;
- nie szukać innych problemów;
- nie wracać do odrzuconych findingów P4-A/C/D;
- jeśli wszystkie entry points są chronione: `NO_FINDING` / `VERIFIED_OK`;
- nie wymyślać findingu tylko po to, aby zakończyć audit.

Jeżeli child session wystartuje, ale zwróci techniczny BLOCKED/INCONCLUSIVE, wolno później rozważyć Attempt 2 tylko z nową metodą/evidence.
Jeżeli child session nie wystartuje z powodu permissions/registry, Attempt_count pozostaje 0 dla faktycznej delegacji po hotfixie.

==================================================
P4-B — TARGETED RETEST
==================================================

Po zakończeniu P4-E, jeśli CURRENT_WORK nadal pokazuje retest jako niewykonany:

agent: mamona-executor
model: ollama/qwen3.5:9b

Uruchom dokładnie test wskazany w P4-B.
Jedna korekta ścieżki/składni jest dozwolona.
Po drugim failure -> BLOCKED.

==================================================
P4-A / P4-C / P4-D
==================================================

Wróć do nich tylko jeśli aktywny task nadal wymaga ich rozstrzygnięcia.

Dla każdego:
- nie powtarzaj wcześniejszego INVALID/DUPLICATE;
- najwyżej jeden nowy celowany `mamona-heavy-auditor` attempt po hotfixie;
- musi mieć nową metodę/evidence;
- jeśli brak findingu -> NO_FINDING i zamknij atom;
- żadnego 30B auditora.

==================================================
P4-F
==================================================

Uruchom dopiero gdy P4-A..E mają końcowe statusy albo jawne BLOCKED zgodne z attempt budget.

==================================================
ANTI-LOOP
==================================================

- maksymalnie 2 faktycznie uruchomione próby na ACTIVE_ATOM;
- permission/registry prelaunch failure nie zużywa próby;
- NO_FINDING jest poprawnym wynikiem końcowym;
- NIE ISTNIEJE Attempt 3;
- nie uruchamiaj kolejnego agenta tylko dlatego, że poprzedni nic nie znalazł;
- nie eskaluj audytu do 30B;
- każdy subagent kończy SUBTASK_RESULT;
- koordynator nie edytuje kodu bezpośrednio mimo szerokich runtime permissions.

==================================================
STOP CONDITION
==================================================

Zatrzymaj się, gdy:
- P4-A..P4-F mają końcowe statusy; albo
- atom wyczerpie 2 faktyczne próby i zostanie BLOCKED; albo
- potrzebna jest decyzja użytkownika; albo
- agent registry nie został przeładowany.

Na końcu zwróć:

P4_RECOVERY_RESULT
- V5.1.1_loaded: YES/NO
- Agent_registry_ok: YES/NO
- P4-A:
- P4-B:
- P4-C:
- P4-D:
- P4-E:
- P4-F:
- Changed_files:
- Tests_run:
- Invalid_or_duplicate_findings_not_repeated:
- Blocked_atoms_and_attempt_counts:
- Next_action: NONE | <dokładnie jedna akcja>

Nie twórz kolejnego subagenta po spełnieniu STOP CONDITION.
