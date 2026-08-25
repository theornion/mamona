RECOVERY / RESUME MAMONA-24 — P4 AFTER AGENT PACK 5.1

Pracuj jako Mamona Orchestrator na aktualnym stanie repo. Paczka agentów V5.1 Anti-Loop została właśnie zainstalowana.

CEL: wznowić P4 od miejsca, w którym praca została zatrzymana na potrzeby update'u 5.1. NIE zaczynaj P4 od początku.

==================================================
START — TYLKO STAN AKTUALNY
==================================================

Najpierw przeczytaj wyłącznie:

1. AGENTS.md
2. docs/AGENT_EXECUTION_PROTOCOL.md
3. docs/CURRENT_WORK.md
4. aktywny dokument tasku MAMONA-24 / P4
5. właściwą zaakceptowaną specyfikację dla tego tasku

Następnie wykonaj:

- git status --short
- git diff --stat
- git diff tylko dla plików powiązanych z aktywnym atomem

Nie czytaj ponownie P0/P1/P2/P3, chyba że CURRENT_WORK wskazuje konkretny brak dowodu wymagający jednego celowanego odczytu.
Nie wykonuj pełnego researchu repo.
Nie cofaj żadnych istniejących zmian.
Nie implementuj samodzielnie jako koordynator.

==================================================
SANITY CHECK STANU P4
==================================================

Oczekiwany snapshot sprzed update'u 5.1 był następujący:

- P4-A: BLOCKED — NEEDS_READ_ONLY_HEAVY_AUDITOR; wcześniejsze wyniki INVALID/DUPLICATE.
- P4-B: fix zapisany; targeted retest był technicznie BLOCKED przez permissions executora.
- P4-C: BLOCKED — NEEDS_READ_ONLY_HEAVY_AUDITOR; brak zweryfikowanego findingu.
- P4-D: BLOCKED — NEEDS_READ_ONLY_HEAVY_AUDITOR; zmyślony finding odrzucony jako INVALID.
- P4-E: PARTIAL; potwierdzone, że quality-check-service.php zawiera blokadę manual_review, ale nie zweryfikowano wszystkich produkcyjnych publication entry points pod kątem assert_post_quality_allows_publication().
- P4-F: nieuruchomione.
- ACTIVE_ATOM był P4-E: zweryfikować actual publication entry points -> quality gate call.

To jest tylko sanity check. Jeśli aktualny `docs/CURRENT_WORK.md` lub diff pokazuje nowszy stan, aktualny stan repo jest źródłem prawdy.

==================================================
V5.1 ANTI-LOOP — BEZWZGLĘDNIE
==================================================

Dla każdego P4-x utrzymuj attempt ledger.

- Maksymalnie 2 próby łącznie na ACTIVE_ATOM.
- Wcześniejsze INVALID/DUPLICATE liczą się jako zakończone próby/hipotezy i NIE wolno ich odtwarzać.
- NO_FINDING jest poprawnym wynikiem końcowym.
- Druga próba tylko po INCONCLUSIVE albo technicznym BLOCKED i tylko z nową metodą/evidence.
- Nie istnieje Attempt 3.
- Nie uruchamiaj kolejnego agenta tylko dlatego, że poprzedni nic nie znalazł.
- Nie eskaluj audytu do 30B.
- 30B (`mamona-heavy-coder`) wolno użyć wyłącznie do ciężkiej implementacji i tylko jeśli pojawi się potwierdzona potrzeba zapisu.
- Do read-only heavy audits używaj `mamona-heavy-auditor` na 14B.
- Każdy subagent kończy SUBTASK_RESULT.

==================================================
KOLEJNOŚĆ WZNOWIENIA
==================================================

1. P4-E — jako pierwszy.

Uruchom dokładnie jeden bounded audit:

agent: mamona-heavy-auditor
model: ollama/qwen3:14b
method: ENTRYPOINT_ENUMERATION + SYMBOL_TRACE
write_scope: NONE

Cel:
- zacząć od zweryfikowanego symbolu `assert_post_quality_allows_publication()`;
- zidentyfikować rzeczywiste produkcyjne publication entry points;
- dla każdego sprawdzić, czy call path przechodzi przez quality gate;
- nie szukać „innych problemów”;
- nie wracać do odrzuconych findingów P4-A/C/D;
- jeśli wszystkie entry points są chronione, zwrócić NO_FINDING / VERIFIED_OK zgodnie z protokołem, bez wymyślania findingu.

2. P4-B — targeted retest.

Jeżeli CURRENT_WORK nadal pokazuje retest jako niewykonany, użyj:

agent: mamona-executor
model: ollama/qwen3.5:9b

Uruchom dokładnie test wskazany w P4-B. V5.1 daje executorowi permission do bezpiecznych komend `php ...`.
Jedna korekta ścieżki/składni jest dozwolona; po drugim failure -> BLOCKED.

3. P4-A / P4-C / P4-D.

Wróć do nich tylko jeśli aktywny task nadal wymaga ich rozstrzygnięcia.
Dla każdego:
- nie powtarzaj wcześniejszego INVALID/DUPLICATE;
- użyj najwyżej jednego nowego, celowanego `mamona-heavy-auditor` attempt po update 5.1;
- nowa próba musi mieć nową metodę/evidence;
- jeśli brak findingu -> NO_FINDING i zamknij atom;
- żadnego 30B auditora.

4. P4-F.

Uruchom dopiero gdy P4-A..E mają końcowe statusy albo jawne BLOCKED zgodne z attempt budget.

==================================================
RÓWNOLEGŁOŚĆ
==================================================

- Nie uruchamiaj dwóch prób tego samego P4-x równolegle.
- 30B zawsze solo.
- 14B + 9B mogą działać równolegle tylko jeśli atomy są niezależne i nie ma write overlap.
- Dla obecnego recovery preferuj sekwencyjność do momentu stabilizacji P4-E.

==================================================
STOP CONDITION
==================================================

Zatrzymaj się, gdy:

- P4-A..P4-F mają końcowe statusy zgodne z protokołem; albo
- którykolwiek atom wyczerpie 2 próby i zostanie BLOCKED; albo
- potrzebna jest decyzja użytkownika.

Na końcu zwróć:

P4_RECOVERY_RESULT
- V5.1_loaded: YES/NO
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
