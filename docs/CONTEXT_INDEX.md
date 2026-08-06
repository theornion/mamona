# CONTEXT_INDEX — Punkt wejścia dla agenta

## Streszczenie

Mamona to produkcyjny system CMS i automatyzacji redakcyjnej w PHP 8.1+ z SQLite. Aktywne zadanie MAMONA-24 dotyczy przebudowy pipeline'u generowania artykułów: spójna narracja, budżet Gemini (max 20 odpowiedzi), brak fallbacków w finalnym artykule, deterministyczny reset wadliwych artykułów.

Poprzednie zadanie TASK-23 (MAMONA-23) dotyczyło regresji w doborze i renderowaniu obrazów: fałszywe captiony fallbacku (R1) i ranking premiujący pojedyncze tokeny (R2). Historia zachowana w `docs/CURRENT_WORK.md`.

## Kolejność czytania dokumentów

1. `docs/CURRENT_WORK.md` — aktywna faza, cele, przykłady referencyjne, zasady
2. `AGENTS.md` — reguły edycji, walidacji, orkiestracji, nienaruszalne inwarianty
3. `docs/ARCHITECTURE.md` — potwierdzona mapa modułów, entry points, data contracts, call graph Gemini
4. `docs/IMAGE_PIPELINE_MAP.md` — end-to-end flow obrazów, verified stages, metadata lineage, problemy MAMONA-24
5. `docs/DECISIONS.md` — trwałe decyzje procesowe (D1-D12)
6. `docs/research/MAMONA-24-P0-repository-map.md` — pełna mapa P0 z raportów A2-D2

## Aktualny task i stan

- **Task:** MAMONA-24 — Article Generation & Visual Narrative Pipeline V2
- **Zakończona faza:** P0 — REPOSITORY RECONNAISSANCE (COMPLETE, 2026-08-06)
- **Następna faza:** P1 — ROOT CAUSE AND SPEC (BLOCKED BY akceptacja użytkownika)

## Ostatni potwierdzony etap

P0 — Repository Reconnaissance:
- Wykonano 4 subtaski repo-scout na qwen3.6:27b/balanced:
  - P0-A2 — typy tekstów, limity znaków, prompty (COMPLETE, 6 plików)
  - P0-B2 — call graph Gemini, retry, quota, salvage, warunki końca (COMPLETE, 10 plików)
  - P0-C2 — narracja, QC, obrazy, fallbacki, renderer (COMPLETE, 9 plików)
  - P0-D2 — schemat danych, statusy, bezpieczny reset (COMPLETE, 5 plików)
- Zmapowano 16 kluczowych plików z symbolami i liniami kodu
- Potwierdzono pełny przepływ generacji: topic → batch → Gemini → QC → repair → salvage
- Potwierdzono pipeline obrazów: query → search → rights → select → download → persist → render
- Zidentyfikowano 8 ograniczeń obecnej architektury wymagających MAMONA-24

## Najbliższy bezpieczny krok

Po akceptacji P0 uruchomić `mamona-architect` z modelem `qwen3.6:27b/deep` do fazy P1:
- Root cause monotonnej struktury i niekontrolowanych iteracji
- Kontrakty NarrativePlan, GenerationState, GeminiBudget, QcReport, VisualSlot, SupplementalTopic
- Maszyna stanów z warunkami przejścia oraz zakończenia
- Specyfikacja kryteriów audytu istniejących artykułów i kontrakt resetu
- Zapis specyfikacji w `docs/specs/MAMONA-24-article-generation-visual-narrative-v2.md`

## Potwierdzone pliki i symbole — kluczowe

| Plik | Kluczowe symbole | Rola |
|---|---|---|
| `php/generation-service.php` | `execute_generation_operation()`, `gemini_curl_transport()`, `build_generation_prompt()` | Dispatcher Gemini, transport, prompt |
| `php/gemini-quota-service.php` | `gemini_quota_acquire()`, `GeminiTopicBudgetException`, `gemini_cached_call()` | Quota RPM/TPM/RPD, budżet tematyczny 15 |
| `php/generation-batch-service.php` | `generation_batch_dispatch_stage()`, auto-repair loop | Orkiestracja pipeline'u, retry batch-level |
| `php/article-draft-service.php` | `ARTICLE_COMPOSITION_MODES`, `article_draft_length_policy()`, `validate_article_draft_output()` | Limity tekstów, walidacja, promocja draftu |
| `php/quality-check-service.php` | `prepare_quality_check_operation()`, `quality_check_auto_repair_decision()`, `QUALITY_PASS_SCORE=75` | QC, auto-repair routing, threshold |
| `php/article-image-service.php` | Pełny pipeline obrazów: query → search → select → download → persist → render | Selekcja, pobieranie, persistence, rendering |
| `php/salvage-service.php` | `salvage_execute_safe_composer()`, `salvage_local_editorial_images()` | Deterministyczny fallback draftu i obrazów |
| `php/repair-router-service.php` | `repair_router_assess()`, budżet stage:3/global:9 | Ruting napraw QC, drabina tytułów |
| `php/editorial-schema.php` | Migracje schematu bazy (posts, article_images, quality_check_runs) | Schema SQLite |
| `php/admin-database.php` | `change_post_editorial_status()`, `render_post_page_html()` | Statusy, render publiczny |

## Lista dokumentów z przeznaczeniem

| Dokument | Przeznaczenie |
|---|---|
| `AGENTS.md` | Reguły pracy, stack, inwarianty, walidacja, orkiestracja |
| `docs/CURRENT_WORK.md` | Aktywna faza MAMONA-24, kolejka P0-P6, stan wykonania, validation log |
| `docs/ARCHITECTURE.md` | Trwała mapa architektury: entry points, moduły, data contracts, call graph Gemini, ograniczenia, planowane zmiany |
| `docs/IMAGE_PIPELINE_MAP.md` | End-to-end flow obrazów, verified stages, metadata lineage, problemy MAMONA-24 |
| `docs/DECISIONS.md` | Decyzje procesowe z uzasadnieniem i źródłami (D1-D12) |
| `docs/research/MAMONA-24-P0-repository-map.md` | Pełna mapa P0: typy tekstów, call graph Gemini, retry/quota/salvage, narracja/QC, obrazy/fallbacki, schemat danych, luki |
| `docs/CONTEXT_INDEX.md` (ten plik) | Punkt wejścia dla nowej instancji agenta |

## Branch i commit

- **Branch:** main
- **Ostatni commit:** e3d937a
- **Data aktualizacji:** 2026-08-06

## Nierozstrzygnięte pytania

### Z P0-A2 (typy tekstów)
1. Czy istnieją inne typy tekstów poza `informational` i `problem_discovery_return`?
2. Jakie są limity dla pól `lead.text`, `why_important.text`, poszczególnych `key_facts[*].text`?
3. Czy `build_generation_prompt()` wstrzykuje jawnie reguły długości do promptu, czy polegają wyłącznie na walidacji po stronie schematu?

### Z P0-B2 (Gemini call graph)
4. Dokładna implementacja `quality_check_auto_repair_decision()` — ciało funkcji nieotwarte
5. Implementacja `promote_article_draft_to_post()` — szczegóły promocji nieprzeczytane
6. Czy istnieje mechanizm logowania wywołań Gemini poza `gemini_quota_events`?
7. Szczegóły `generation_batch_worker.php` — jak worker decyduje o kolejności przetwarzania?
8. Czy cache `gemini_call_cache` ma TTL / politykę expiracji?

### Z P0-C2 (narracja i QC)
9. Gdzie zdefiniowana jest stała `QUALITY_PASS_SCORE`? (użyta w L638, L724 — nie znaleziono definicji)
10. Jak wygląda pełna funkcja `quality_check_schema()`?
11. Czy `render_article_image_record($image, true)` dla hero generuje inną strukturę HTML?
12. Jak `validate_article_blocks()` weryfikuje bloki przed renderowaniem?

### Z P0-D2 (schemat danych)
13. Czy istnieje dedykowana funkcja "reset wadliwego artykułu"?
14. Jakie są dokładne warunki w `assert_post_quality_allows_publication()` blokujące publikację?
15. Jak `post_legacy_publication_flag()` mapuje status na is_published?
