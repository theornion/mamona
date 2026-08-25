# RECOVERY / CONTINUE MAMONA-24 — P4 AFTER AGENT PACK 5.1.3

Pracuj jako **`mamona-coordinator`** wybrany bezpośrednio jako primary agent sesji.

Agent Pack 5.1.3 = V4.5 flat workflow + Evidence-First + Verified-Write + Autonomous Queue.

## AUTONOMY MODE

```text
AUTONOMY_TARGET_MINUTES: 120
FINALIZATION_RESERVE_MINUTES: 10
SESSION_MODE: AUTONOMOUS
```

Pracuj możliwie ciągle przez około 2 godziny bez pytania użytkownika o recoverable decyzje. Nie kończ sesji po jednym BLOCKED atomie — zaparkuj go i kontynuuj następny READY atom, jeżeli zależności na to pozwalają.

Nie wykonuj pracy pozornej. Jeżeli cała wymagana kolejka skończy się wcześniej, zakończ wcześniej.

Nie commituj, nie pushuj, nie resetuj, nie wykonuj produkcyjnych mutacji ani destrukcyjnych operacji.

## START — aktualne tree jest źródłem prawdy

Najpierw przeczytaj tylko:

1. `docs/CURRENT_WORK.md`
2. aktywny dokument tasku MAMONA-24 / P4
3. właściwą zaakceptowaną specyfikację / override dla P4
4. `docs/AGENT_EXECUTION_PROTOCOL.md`

Następnie wykonaj bezpośrednio jako coordinator:

```text
Get-Date
git status --short
git diff --stat
```

Nie czytaj ponownie całego P0/P1/P2/P3. Targeted historical read tylko jeśli aktualny atom ma konkretny brak dowodu.

## SNAPSHOT Z OSTATNIEGO POTWIERDZONEGO PRZEBIEGU

Traktuj jako sanity check. Aktualny working tree / CURRENT_WORK ma pierwszeństwo.

### P4-B

P4-B został doprowadzony do potwierdzonego PASS:

- exact symbol definition potwierdzona: `php/article-draft-service.php`, funkcja `prepare_article_draft_operation()`;
- produkcyjne call sites istniały;
- test reflektował symbol w `tests/p3b-narrative-freeze-visualslot-test.php` około linii 511;
- root cause: `TEST_BOOTSTRAP_MISSING_SERVICE`;
- minimalny fix: test bootstrap ładuje `php/article-draft-service.php` przez:

```php
require_once __DIR__ . '/../php/article-draft-service.php';
```

- final targeted retest zakończył się `exit code 0`, PASS, 80+ assertions;
- P4-B nie powinien być ponownie diagnozowany bez nowego failure.

Najpierw zweryfikuj aktualny diff/stan tego fixa. NIE uruchamiaj ponownie pełnej diagnozy P4-B, jeśli kod i test state nadal odpowiadają temu snapshotowi.

### Pozostałe historyczne statusy

- P4-A: wcześniejsze findingi INVALID/DUPLICATE; nie powtarzaj ich.
- P4-C: wcześniejszy brak zweryfikowanego findingu.
- P4-D: wcześniejszy finding INVALID.
- P4-E: poprzedni 14B audit zwrócił sprzeczne evidence i był INVALID. To jest znany rejected claim, nie dowód.
- P4-F: wcześniej nieuruchomione.

## V5.1.3 — NAJWAŻNIEJSZA ZASADA

**Najpierw coordinator zbiera deterministyczne evidence. Dopiero potem LLM interpretuje.**

Dla symbol/loader/caller/entrypoint/include/diff używaj bezpośrednio:

```text
git grep
rg
Select-String
exact read
git diff
```

Nie deleguj evidence collection do `mamona-executor`.

Executor służy tylko do dokładnie wskazanego testu/lintu.

## ATTEMPT LEDGER

Dla każdego P4-x osobno:

```text
BASELINE_TEST_RUNS: 0..2
EVIDENCE_BATCHES: 0..2
DIAGNOSIS_RUNS: 0..2
FIX_ATTEMPTS: 0..2
RETEST_ATTEMPTS_AFTER_VERIFIED_WRITE: 0..2
REPORT_REPAIRS_PER_CHILD: 0..1
REOPEN_COUNT: 0..1
```

Nie mieszaj tych liczników.

Po workerze zawsze wykonaj `WRITE_VERIFICATION` przed retestem.

## READY QUEUE

Po odczycie aktualnego tasku zbuduj:

```text
READY_QUEUE
PARKED_BLOCKERS
CLOSED_ATOMS
```

P4-B potraktuj jako CLOSED/PASS, jeśli aktualny tree potwierdza fix i nie ma nowego failure.

Następnie pracuj zgodnie z zależnościami aktualnego tasku. Preferowana kolejność, jeśli task nie mówi inaczej:

1. P4-E evidence-first verification;
2. atomy A/C/D tylko jeśli nadal są wymagane przez phase gate i można użyć nowej metody/evidence bez powtarzania INVALID/DUPLICATE;
3. P4-F po spełnieniu jego dependencies;
4. phase gate / reviewer;
5. update `docs/CURRENT_WORK.md` i checkpoint/handoff.

## P4-E — EVIDENCE-FIRST

Nie rozpoczynaj od diagnosera.

Coordinator najpierw bezpośrednio zbiera EVIDENCE_PACKET dla:

```text
assert_post_quality_allows_publication()
```

Cel:
- literal definition;
- real production publication entrypoints/callers;
- exact call paths istotne dla gate;
- uwzględnienie tracked/untracked coverage;
- brak broad issue huntingu.

Dopiero gdy raw evidence jest spójne, uruchom maksymalnie jednego `mamona-diagnoser` z:

```text
Search_authorization: NONE
Question_for_diagnoser: czy każdy rzeczywisty production publication path przechodzi przez quality gate i czy istnieje konkretny gap?
```

Jeśli answer jest mechanicznie oczywisty z raw evidence, coordinator może zamknąć atom bez subagenta.

Nie używaj 30B do P4-E.

## REPORT REPAIR

Jeśli child wykona pracę, ale nie zwróci wymaganego raportu:

- wznowić tę samą child session maksymalnie raz;
- wysłać wyłącznie:

```text
RETURN REPORT ONLY. DO NOT USE TOOLS.
```

Nie twórz nowego agenta tylko dla brakującego raportu.

## WRITE VERIFICATION

Po każdej implementacji:

```text
git diff -- <expected file(s)>
exact read / Select-String anchor
```

Jeżeli worker twierdzi COMPLETE, ale diff nie zawiera zmiany -> NIE RETESTUJ. Najpierw `WRITE_NOT_VERIFIED` / ewentualny one-time WRITE_REPAIR.

## EXECUTOR

Executor:
- tylko dokładny command;
- po wykonaniu natychmiast PASS/FAIL/BLOCKED;
- nie czyta repo;
- nie diagnozuje;
- nie edytuje;
- nie decyduje o globalnym attempt budget.

## AUTONOMOUS CONTINUATION

Jeżeli atom zostanie PARKED/BLOCKED, a istnieje niezależny READY atom:

**kontynuuj automatycznie.**

Nie wracaj do użytkownika tylko po zgodę na następny atom.

Na phase boundary zaktualizuj `docs/CURRENT_WORK.md` wyłącznie potwierdzonym stanem.

Przed wejściem w ostatnie ~10 minut nie rozpoczynaj nowego ciężkiego atomu. Zakończ bieżący bounded krok, uporządkuj ledger i zapisz handoff.

## FINAL OUTPUT

```text
P4_AUTONOMOUS_RESULT
- V5.1.3_loaded: YES|NO
- Primary_agent: mamona-coordinator | OTHER
- Session_start:
- Approx_elapsed:
- P4-A:
- P4-B:
- P4-C:
- P4-D:
- P4-E:
- P4-F:
- Closed_atoms:
- Parked_blockers:
- Changed_files:
- Tests_run:
- Ledger_summary:
- Invalid_or_duplicate_not_repeated:
- CURRENT_WORK_updated: YES|NO
- User_decision_required: YES|NO
- Next_action: NONE | <dokładnie jedna akcja>
```
