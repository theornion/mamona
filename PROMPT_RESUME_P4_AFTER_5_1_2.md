# RECOVERY / RESUME MAMONA-24 — P4 AFTER AGENT PACK 5.1.2

Pracuj jako **`mamona-coordinator`** wybrany bezpośrednio jako primary agent sesji.

Agent Pack 5.1.2 przywraca architekturę V4.5 i dodaje anti-loop. NIE używaj `mamona-orchestrator` ani `mamona-heavy-auditor`.

CEL: wznowić P4 z aktualnego working tree. NIE zaczynaj P4 od początku i NIE cofaj istniejących zmian.

## START — tylko stan aktualny

Najpierw przeczytaj wyłącznie:

1. `docs/CURRENT_WORK.md`
2. aktywny dokument tasku MAMONA-24 / P4
3. właściwą zaakceptowaną specyfikację
4. `docs/AGENT_EXECUTION_PROTOCOL.md`

Następnie:

- `git status --short`
- `git diff --stat`
- tylko jeśli potrzebne: targeted diff dla plików aktywnego atomu

Nie czytaj ponownie P0/P1/P2/P3 bez jednego konkretnego brakującego dowodu. Nie wykonuj broad repo researchu.

## Snapshot recovery po nieudanych 5.1 / 5.1.1

Traktuj poniższe jako sanity check, a aktualny `CURRENT_WORK` i working tree jako źródło prawdy:

- P4-A: wcześniejsze evidence było INVALID/DUPLICATE; nie odtwarzaj go.
- P4-B: fix był w working tree; targeted retest pozostawał niezweryfikowany.
- P4-C: brak zweryfikowanego findingu.
- P4-D: wcześniejszy finding był INVALID.
- P4-E: child nie wystartował z powodu technicznej blokady Task/permissions. Te prelaunch failures NIE zużyły faktycznego Attempt 1.
- P4-F: nieuruchomione w tamtym recovery.

## Anti-loop 5.1.2

Dla każdego P4-x utrzymuj attempt ledger.

- maksymalnie 2 faktycznie uruchomione próby;
- permission/registry prelaunch failure nie zużywa próby;
- Attempt 2 tylko po `INCONCLUSIVE`/technicznym `BLOCKED` i z nową metodą/evidence;
- `NO_FINDING` jest wynikiem terminalnym;
- NIE ISTNIEJE Attempt 3;
- nie uruchamiaj kolejnego agenta dlatego, że poprzedni nic nie znalazł;
- nie eskaluj audytu do 30B;
- nie używaj built-in `general` ani `explore` jako obejścia.

## P4-E — pierwsza realna próba po 5.1.2

Uruchom dokładnie jeden bounded read-only audit przez:

```text
agent: mamona-diagnoser
model: ollama/mamona-qwen14-64k
Attempt_number: 1
method: ENTRYPOINT_ENUMERATION + SYMBOL_TRACE
write_scope: NONE
```

Cel:

- zacznij od zweryfikowanego symbolu `assert_post_quality_allows_publication()`;
- zidentyfikuj rzeczywiste produkcyjne publication entry points;
- dla każdego sprawdź, czy realny call path przechodzi przez quality gate;
- nie szukaj innych problemów;
- nie wracaj do odrzuconych findingów P4-A/C/D;
- jeżeli wszystkie entry points są chronione: `NO_FINDING` / `VERIFIED_OK`; nie wymyślaj findingu.

Jeżeli `mamona-diagnoser` NIE WYSTARTUJE z powodu permissions/registry:

```text
P4_RECOVERY_RESULT
- V5.1.2_loaded: YES
- P4-E: AGENT_LAUNCH_BLOCKED
- P4-E_attempt_count: 0
- Next_action: sprawdzić effective agent registry/permission bez uruchamiania innego agenta
```

i STOP. Nie próbuj innej roli.

## P4-B — targeted retest

Po terminalnym zakończeniu P4-E, jeśli aktualny stan nadal wymaga retestu P4-B:

```text
agent: mamona-executor
model: ollama/mamona-qwen9-64k
```

Uruchom dokładnie wskazany test. Jedna korekta ścieżki/składni jest dozwolona. Po drugim technicznym failure -> `BLOCKED`. Nie modyfikuj testu podczas retestu.

## P4-A / P4-C / P4-D

Wróć tylko do atomów, których aktualny task nadal wymaga. Do bounded read-only verification używaj `mamona-diagnoser`, nie 30B.

- nie powtarzaj wcześniejszego INVALID/DUPLICATE;
- nowa próba musi mieć nową metodę/evidence;
- brak findingu -> `NO_FINDING` i zamknięcie atomu;
- po 2 faktycznych próbach -> STOP/BLOCKED.

## Implementacja findingu

Jeżeli bounded audit potwierdzi konkretny mały finding i fix jest jednoznaczny, coordinator może wykonać mały fix sam zgodnie z V4.5 DIRECT_TARGET_MODE, a następnie uruchomić `mamona-executor`.

Jeżeli write scope jest większy -> `mamona-worker` 14B. `mamona-heavy-coder` 30B tylko dla rzeczywiście ciężkiej implementacji i zawsze solo.

## P4-F

Uruchom dopiero, gdy P4-A..P4-E mają końcowe statusy zgodne z attempt ledger.

## STOP CONDITION

Zatrzymaj się, gdy:

- P4-A..P4-F mają końcowe statusy; albo
- aktywny atom wyczerpał 2 faktyczne próby; albo
- wystąpi `AGENT_LAUNCH_BLOCKED`; albo
- potrzebna jest decyzja użytkownika.

Na końcu zwróć:

```text
P4_RECOVERY_RESULT
- V5.1.2_loaded: YES/NO
- Primary_agent: mamona-coordinator | OTHER
- P4-A:
- P4-B:
- P4-C:
- P4-D:
- P4-E:
- P4-F:
- Changed_files:
- Tests_run:
- Attempt_ledger:
- Invalid_or_duplicate_findings_not_repeated:
- Next_action: NONE | <dokładnie jedna akcja>
```
