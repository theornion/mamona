# RESUME MAMONA-24 — P4 / AGENT PACK 4.5.1

Pracuj jako **`mamona-coordinator`** wybrany bezpośrednio jako primary agent sesji.

To jest paczka **V4.5.1 = V4.5 + permission hotfix**.
Nie stosuj architektury, nazw agentów, attempt-ledgerów ani atomów P4-A..P4-F z paczek 5.1.x.

## WAŻNE: kanoniczny P4

Dla V4.5 obowiązuje P4 z aktualnego tasku MAMONA-24: **niezależny review i ograniczone poprawki**.
Historyczne etykiety `P4-A` ... `P4-F` z późniejszych recovery są wyłącznie notatkami pomocniczymi i NIE definiują scope V4.5.
Nie szukaj osobnej specyfikacji `P4-F`.

Kanoniczny P4 ma sprawdzić co najmniej:
- centralny GeminiBudget i ukryte wywołania;
- brak degradacji QC;
- freeze/convergence;
- publication gate / manual_review;
- brak finalnych fallbacków;
- spójność assetów;
- VisualSlot / B/C;
- narzędzie resetu;
- UTF-8;
- zgodność dokumentacji.

Maksymalnie dwie rundy:

```text
review -> poprawka -> retest
```

Po P4 obowiązuje CHECKPOINT_P4 i hard stop. Nie uruchamiaj `--apply`.

## START

Przeczytaj wyłącznie:

1. `docs/CURRENT_WORK.md`
2. aktualny task MAMONA-24 (użyj faktycznej istniejącej ścieżki, np. `docs/tasks/NEXT_TASK_ARTICLE_PIPELINE.md`)
3. właściwą aktualną specyfikację / override MAMONA-24
4. najnowszy `CHECKPOINT_P3`, jeśli istnieje
5. `docs/AGENT_EXECUTION_PROTOCOL.md`, jeśli aktualny task go wskazuje

Następnie bezpośrednio jako coordinator:

```text
git status --short
git diff --stat
C:/xampp/php/php.exe -v
```

Jeżeli `git` i PHP działają, oznacz `PERMISSION_SMOKE: PASS` i kontynuuj bez pytania użytkownika.

Jeżeli PHP nadal jest technicznie zablokowane, zwróć dokładny `source/pattern/action` permission i STOP. Nie próbuj omijać blokady workerem/reviewerem.

## POTWIERDZONY HANDOFF Z OSTATNIEJ SESJI

Traktuj jako sanity check; aktualny working tree ma pierwszeństwo.

P4-B bootstrap regression została wcześniej naprawiona:

- symbol `prepare_article_draft_operation()` jest zdefiniowany w `php/article-draft-service.php`;
- `tests/p3b-narrative-freeze-visualslot-test.php` reflektował symbol bez załadowania serwisu;
- minimalny fix dodał:

```php
require_once __DIR__ . '/../php/article-draft-service.php';
```

- finalny targeted retest wcześniej zakończył się `exit code 0`, PASS, 80+ assertions.

Nie diagnozuj tego ponownie, jeżeli aktualny tree nadal zawiera fix i świeży test przechodzi.

## PHASE 1 — FRESH EXECUTION PROOF

Najpierw spróbuj command-only subtasku:

```text
agent: mamona-executor
command: C:/xampp/php/php.exe tests/p3b-narrative-freeze-visualslot-test.php
```

Executor ma tylko wykonać komendę i zwrócić exit code/PASS/FAIL.

Jeżeli executor zostanie technicznie zablokowany mimo hotfixu:
- NIE deleguj tej samej komendy do workera;
- wykonaj dokładną komendę bezpośrednio jako coordinator w DIRECT_TARGET_MODE;
- brak shella u childa nie może sam zablokować P4, jeżeli primary ma działający PHP execution.

## PHASE 2 — KANONICZNY REVIEW P4

Uruchom `mamona-reviewer` dla **jednego kanonicznego P4 review**, przekazując:
- aktualny task/spec;
- aktualny `git diff` / changed files;
- świeże wyniki testów;
- wyłącznie rzeczywiste ścieżki i symbole z repo.

Reviewer ma sprawdzić kanoniczne kryteria P4. Nie dziel review na sztuczne P4-A..F.

Jeżeli reviewer podaje nieistniejącą ścieżkę/symbol albo finding sprzeczny z repo:
- odrzuć ten konkretny finding;
- zweryfikuj exact evidence przez `git grep`, `rg`, exact read lub diff;
- nie implementuj na podstawie sprzecznego evidence.

## PHASE 3 — OGRANICZONE POPRAWKI

Jeżeli review zwróci potwierdzony finding:

- mały, jednoznaczny fix: coordinator może wykonać go sam w DIRECT_TARGET_MODE;
- większy, ale standardowy fix: `mamona-worker`;
- `mamona-heavy-coder` tylko dla rzeczywiście ciężkiej implementacji, nie do review/diagnozy.

Po każdej zmianie:

```text
git diff -- <zmienione pliki>
C:/xampp/php/php.exe -l <zmieniony plik PHP>   # jeśli dotyczy
<targeted test>
```

Jeżeli test FAIL i root cause nie jest oczywisty:

```text
mamona-diagnoser -> jeden failing cluster -> potwierdzony fix -> retest
```

Nie łącz diagnozy i fixa w jednym subtasku.

Kanoniczny P4 pozwala maksymalnie na **dwie rundy review -> poprawka -> retest**. Po drugiej rundzie z realnym blockerem zapisz stan i zatrzymaj się.

## PHASE 4 — FINAL PHASE GATE

Po rozwiązaniu wymaganych findingów wykonaj najmniejszy wystarczający finalny zestaw deterministycznych testów wynikający z tasku/spec i aktualnego diffu.

Nie uruchamiaj:
- live Gemini;
- providerów obrazów;
- publikacji;
- produkcyjnych mutacji;
- resetu `--apply`;
- commit/push/reset/clean.

Jeżeli wszystkie wymagane testy i review przejdą:

1. `git status --short`
2. `git diff --stat`
3. final targeted diff
4. zaktualizuj `docs/CURRENT_WORK.md` wyłącznie potwierdzonym stanem P4
5. utwórz/zaktualizuj `CHECKPOINT_P4` zgodnie z aktualnym standardem repo
6. STOP przed P5

Jeżeli `checkpoint-writer` zwróci gotową treść, ale nie może zapisać pliku, coordinator ma ją zapisać sam — coordinator ma w 4.5.1 `edit: allow`.

## AUTONOMICZNA KONTYNUACJA

Nie zatrzymuj się po zwykłym PASS, po pojedynczym `SUBTASK_RESULT` ani przed oczywistym targeted retestem.
Pracuj dalej bez ingerencji użytkownika aż do:
- CHECKPOINT_P4;
- realnego technicznego blockera;
- drugiej nieudanej kanonicznej rundy review/fix/retest;
- decyzji produktowej wymagającej użytkownika.

## FINAL OUTPUT

```text
P4_FINAL_RESULT
- Agent_pack: 4.5.1
- Primary_agent: mamona-coordinator
- Permission_smoke:
- Executor_PHP: WORKING | CHILD_BLOCKED_PRIMARY_FALLBACK_USED | BLOCKED
- Canonical_review:
- Review_rounds_used:
- Tests_run:
- Tests_result:
- Findings_fixed:
- Changed_files:
- CURRENT_WORK_updated: YES | NO
- Checkpoint_P4_created: YES | NO
- Remaining_blockers:
- Next_action: AKCEPTUJĘ P4 — URUCHOM P5 DRY-RUN | <exact blocker>
```

Jeżeli P4 jest COMPLETE, zakończ dokładnie na hard stopie P4. Nie uruchamiaj P5 automatycznie.
