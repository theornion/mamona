# TASK-23 — wynik macierzy `generate_all`

Data lokalnego wykonania: 2026-08-01. Test: `tests/generate-all-regression.php`.

## Wynik aktualny

Wszystkie przypadki 1–7 przechodzą. Routing wybiera kolejno research, draft, quality_check i images; kompletne obrazy dają `already_complete`; ponowne użycie tego samego request/idempotency key zwraca ten sam batch bez nowych rekordów etapów, `generation_operations` ani wywołań transportu. Snapshoty porównują listy ID research package, draft version i quality check oraz globalny licznik operacji.

Przypadek 6 potwierdza, że nowszy, nieaktywny placeholder `status=prepared` nie zasłania wcześniejszego aktywnego draftu `status=completed`; wynik pozostaje `already_complete` ze stabilnymi ID ukończonych etapów.

Nie wykonano sieci ani publikacji.

## Historia regresji

Wcześniejsze wykonanie wykrywało zasłonięcie aktywnego draftu przez placeholder. Bieżący kod preferuje właściwą ukończoną aktywną wersję i test chroni tę poprawkę przed regresją.
