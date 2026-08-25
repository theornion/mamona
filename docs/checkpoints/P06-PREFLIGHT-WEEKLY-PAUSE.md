# P06/P07 Pretransport Validation — Weekly Usage Pause

Status: IN PROGRESS

## Weekly usage safety gate

- `WEEKLY_USAGE_VISIBILITY=UNAVAILABLE`
- Runtime nie udostępnia wiarygodnego licznika pozostałego tygodniowego limitu Codexa.
- Zgodnie z hard gate nie rozpoczęto kolejnego dużego tasku, implementacji, subagenta ani realnego E2E.
- W ramach zaktualizowanego celu nie wykonano zmian w kodzie, testów ani live provider calls.

## Active bounded task

- Task: `P06_PRETRANSPORT_VALIDATION`
- Status: `[~] IN_PROGRESS`
- P10 pozostaje `IN_PROGRESS`; ten checkpoint nie zmienia jego statusu ukończenia.

## Verified inventory

- P06 shortage input sprawdza locked core, niepełne coverage, utrwalony NarrativePlan i zatwierdzoną mapę źródeł RSS.
- P06 ma częściowy preflight przed transportem; obecnie odrzuca pustą mapę źródeł, brak modułów i brak brakujących slotów.
- RSS-only source map jest budowana z zatwierdzonego research package i dziedziczona przez P07.
- Budżet ma atomowy claim oparty na `BEGIN IMMEDIATE` oraz reconciliation po braku odpowiedzi.

## Remaining gaps

- Brakuje typowanego centralnego preflightu P06/P07 dla module IDs, powiązań module–claim–source, `acceptable_related`, post/topic i duplicate/running operation.
- Brakuje rezerwy P08/P09 closure floor przed claimem P06.
- Brakuje wymaganych jawnych kodów wyników `recovery_*`.
- Brakuje dedykowanego testu dwóch równoległych workerów P06 dla tego samego artykułu.
- Brakuje testu, że odmowa preflightu lub budżetu nie pozostawia operacji `running`.
- Centralna walidacja P07 bezpośrednio przed generic transportem pozostaje niepotwierdzona.

## Changed files in this pause

- `docs/checkpoints/P06-PREFLIGHT-WEEKLY-PAUSE.md`
- `docs/CURRENT_WORK.md`

## Tests

- Nie uruchamiano — checkpoint-only bounded task, bez zmian source/test.
- Gemini calls: `0`.

## Last verified state

Implementacja ma częściowe guardy, source-map support i atomowy budget claim, ale nie spełnia jeszcze pełnego centralnego kontraktu pretransportowego P06/P07. Nie wykonano żadnej nowej próby realnego E2E.

## Next exact action

Zaimplementować typowany centralny preflight P06/P07 z source-backed module validation i rezerwą closure przed jakimkolwiek claimem lub transportem; następnie dodać targeted zero-call, concurrency i orphan-cleanup tests.
