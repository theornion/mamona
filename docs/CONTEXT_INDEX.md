# CONTEXT_INDEX — Punkt wejścia dla agenta

## Streszczenie

Mamona to produkcyjny system CMS i automatyzacji redakcyjnej w PHP 8.1+ z SQLite. Zadanie TASK-23 dotyczy regresji w automatycznym doborze i renderowaniu obrazów artykułów: fałszywe captiony fallbacku (R1) i ranking premiujący pojedyncze tokeny (R2).

## Kolejność czytania dokumentów

1. `docs/CURRENT_WORK.md` — aktywna faza, cele, przykłady referencyjne, zasady
2. `AGENTS.md` — reguły edycji, walidacji, orkiestracji, nienaruszalne inwarianty
3. `docs/ARCHITECTURE.md` — potwierdzona mapa modułów, entry points, data contracts
4. `docs/IMAGE_PIPELINE_MAP.md` — end-to-end flow, verified stages, metadata lineage
5. `docs/DECISIONS.md` — trwałe decyzje procesowe

## Aktualny task i stan

- **Task:** MAMONA-23 — image selection and rendering regression
- **Zakończona faza:** P0 — INITIAL INDEXED MAP (COMPLETED)
- **Następna faza:** P1 — ROOT CAUSE AND SPEC (NEXT, BLOCKED BY P0 → teraz otwarta)

## Ostatni potwierdzony etap

P0 — Initial Indexed Map:
- Wykonano 2 skany semantyczne
- Zmapowano 13 entry points z funkcjami i liniami kodu
- Potwierdzono 7 modułów, 4 data contracts, 6 testów
- Zidentyfikowano 2 hipotezy regresji (R1, R2) — wymagają P1 root cause

## Najbliższy bezpieczny krok

Uruchomić `mamona-architect` z modelem `qwen3.6:27b/deep` do fazy P1:
- Analiza `article_image_candidate_score` i `select_source_image_from_results()` — ranking i token dominance
- Analiza przepływu metadanych fallbacku w `render_article_image_record()` — caption inheritance
- Zapis specyfikacji w `docs/specs/TASK-23-image-selection-rendering-regression.md`

## Lista dokumentów z przeznaczeniem

| Dokument | Przeznaczenie |
|---|---|
| `AGENTS.md` | Reguły pracy, stack, inwarianty, walidacja, orkiestracja |
| `docs/CURRENT_WORK.md` | Aktywna faza, kolejka P0-P5, stan wykonania, validation log |
| `docs/ARCHITECTURE.md` | Trwała mapa architektury: entry points, moduły, data contracts, testy |
| `docs/IMAGE_PIPELINE_MAP.md` | End-to-end flow, verified stages, metadata lineage, hipotezy regresji |
| `docs/DECISIONS.md` | Decyzje procesowe z uzasadnieniem i źródłami |
| `docs/CONTEXT_INDEX.md` (ten plik) | Punkt wejścia dla nowej instancji agenta |

## Branch i commit

- **Branch:** main
- **Ostatni commit:** e3d937a
- **Data aktualizacji:** 2026-08-05

## Nierozstrzygnięte pytania

1. Czy fallback w `render_article_image_record()` dziedziczy caption/alt kandydata? (R1 — wymaga P1)
2. Czy ranking w `select_source_image_from_results()` premiuje pojedynczy token nad semantyczną trafnością? (R2 — wymaga P1)
3. Jakie negatywne sygnały są dostępne w metadanych kandydatów do odrzucenia satyry, zombie, gore?
4. Czy naprawa wymaga migracji istniejących rekordów `article_images`?
