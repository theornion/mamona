# P06/P07 Pretransport Validation

Status: COMPLETE

## Zakres i kontrakt

- Centralny, typowany preflight P06/P07 działa przed rezerwacją punktu budżetu i przed transportem do Gemini.
- Kontrakt `NarrativePlan.expansion_modules[]` zawiera audytowalne `source_claim_ids`, a preflight sprawdza powiązanie modułu z zatwierdzoną mapą źródeł RSS.
- P06 zachowuje floor `3` wywołań na domknięcie P07/P08/P09; P07 zachowuje floor `2` wywołań na P08/P09.
- Ownership operacji jest serializowany przez stabilny klucz oraz `BEGIN IMMEDIATE` z CAS na świeżym wierszu.
- Guardy API/live są wykonywane przed przejęciem ownership, więc niedopuszczona próba nie pozostawia fałszywego aktywnego właściciela.
- Typowana odmowa preflightu jest zapisywana audytowalnie i obsługiwana przez batch jako wynik niekrytyczny, bez automatycznego transportu.

## Dowód współbieżności

Prawdziwy test dwuprocesowy potwierdza dla jednego artykułu:

- dokładnie jeden transport;
- dokładnie jeden punkt budżetu;
- drugi worker otrzymuje typowaną odmowę;
- brak operacji pozostawionej w stanie `running`;
- brak niejawnego requestu nr 21.

## Exact changed files

- `php/article-image-service.php`
- `php/generation-batch-service.php`
- `php/narrative-plan-service.php`
- `tests/p6-pretransport-smoke.php`
- `tests/p6-pretransport-race-child.php`
- `tests/p6-image-recovery-smoke.php`
- `tests/p6-approved-source-map-smoke.php`
- `tests/generation-batch-smoke.php`
- `tests/narrative-plan-completion-smoke.php`
- `docs/CURRENT_WORK.md`
- `docs/checkpoints/P06-PRETRANSPORT-VALIDATION.md`

## Walidacja

PASS:

- lint zmienionych plików PHP;
- `tests/p6-pretransport-smoke.php`;
- `tests/p6-image-recovery-smoke.php`;
- `tests/p6-approved-source-map-smoke.php`;
- `tests/gemini-quota-smoke.php`;
- `tests/generation-batch-smoke.php`;
- `tests/p3-core-text-lock-smoke.php`;
- `tests/narrative-plan-completion-smoke.php`;
- `tests/draft-visual-plan-schema-smoke.php`.

Gemini calls wykonane przez testy: `0`.

Nie wykonano live provider calls, publikacji ani operacji na produkcyjnej bazie danych.

## Ryzyka i pozostały zakres

- P10 pozostaje `[~]`; ten checkpoint nie dowodzi jeszcze kompletnego mock E2E ani świeżego real proofu do preview.
- Przed real proofem trzeba przeprowadzić pełny completion audit wymaganej macierzy P10 i naprawić każdy wykryty failure.

## Next exact action

Przeprowadzić pełny audyt i uruchomić wymaganą macierz mock/disposable-DB P10; naprawić wykryte regresje przed świeżym kontrolowanym real proofem. Nie wykonywać live provider calls ani publikacji podczas audytu mock.
