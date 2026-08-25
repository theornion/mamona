# MAMONA-24 — Canonical State V4.0

**Data:** 2026-08-07  
**Status:** P3-C IN PROGRESS  
**Następna faza:** NIE P4 — najpierw domknąć P3-C, P3-D i final regression.

## 1. Nadrzędny produkt

Mamona jest ogólnym pipeline'em publikacji popularnonaukowych:

`RSS -> temat/research -> Gemini komponuje artykuł -> grafiki -> QC -> renderer -> gotowy do publikacji artykuł`

Artykuł o neuroplastyczności/neuronach był tylko przykładem regresji wcześniejszego doboru złej grafiki. Nie jest centralnym use-case'em i nie wolno hardcodować pod niego algorytmu ani testów.

## 2. P3-A — COMPLETE

- 148 PASS po finalnym reteście.
- Limity: informational min 3000, problem_discovery_return min 4000, max 7000.
- Kanoniczne liczenie długości używa `mb_strlen`.
- Centralny article GeminiBudget: 20; convergence od 16; 20 dozwolone; próba 21 nie może mutować persisted state/log.
- Fix: `php/gemini-quota-service.php` blokuje increment przed mutacją, gdy `used >= max`.

## 3. P3-B — COMPLETE

- 92 PASS.
- NarrativePlan, zróżnicowana struktura, freeze, convergence, VisualSlot, hero, inline slots, max 5, supplemental B/C.
- Fix: `php/repair-router-service.php` — usunięto Array-to-string warning.

## 4. P3-C — IN PROGRESS

### Zrobione

`P3-C-IMAGE-VISION-GATE-FIX-01`:

- `article_image_semantic_gate_score()` jest preselection heuristic, nie finalnym semantic gate;
- dodano `article_image_multimodal_assess()` z mockowalnym callbackiem;
- `select_source_image_from_results()` wymaga multimodal ACCEPT po prefilterze;
- dodano `ARTICLE_IMAGE_GEMINI_OPERATION_TYPE = image_vision_assessment`;
- smoke test dostał mock Vision.

`P3-C-UTF8-PREFILTER-FIX-01`:

- w `article_image_semantic_gate_tokenize()` usunięto `iconv(...ASCII//TRANSLIT...)`;
- tokenizacja używa Unicode-aware regex `[^\p{L}\p{N}]+/u` w kodzie produkcyjnym;
- długość tokenu przez `mb_strlen`.

### Krytyczny blocker potwierdzony w aktualnym kodzie

W produkcyjnym `fulfill_article_source_images()` wywołanie `select_source_image_from_results()` nadal nie przekazuje callbacka/provider adaptera multimodalnego ani article context.

`article_image_multimodal_assess()` bez callbacka obecnie zawsze rzuca:

`Multimodal image assessment requires Gemini Vision callback or production provider.`

Wniosek: refactor zbudował kontrakt i testowalny seam, ale **realna integracja Gemini Vision w produkcyjnym image pipeline nie jest jeszcze zrobiona**. Samo istnienie stałej operation type nie podłącza provider call ani centralnego budgetu.

P3-C nie może być oznaczone COMPLETE, dopóki ten integration point nie zostanie wdrożony i przetestowany bez live API.

### Dodatkowe punkty do audytu P3-C

- `source_image_candidate_is_suitable_for_role()` nadal używa `iconv` do heurystyki hero.
- `source_image_candidate_matches_query()` nadal używa `iconv` i ASCII regex.
- To nie jest automatycznie bug, ale wymaga krótkiego cross-platform/UTF-8 audytu przed zamknięciem P3-C.
- `tests/p3c-vision-gate-test.php` obejmuje 3 kategorie, ale ostatni clean execution-only retest nie został ukończony.
- pozostały: manual_review publication behavior, renderer/gallery contract, hard/soft gates, final mojibake scan.

## 5. Image gate — obowiązujący kontrakt

`search -> technical/legal filters -> cheap prefilter -> actual-image multimodal semantic/editorial assessment -> ACCEPT/REJECT -> publication gate`

Nie używać blacklist nazwisk/słów jako finalnego rozwiązania.

Dwie klasy negatywne w testach:

- `BAD_OBVIOUS`: może legalnie odpaść już na prefilterze;
- `BAD_METADATA_PLAUSIBLE`: metadata wyglądają sensownie, więc przechodzi prefilter, ale rzeczywisty obraz jest zły i mock Vision musi go odrzucić.

Testy muszą obejmować kilka różnych dziedzin popularnonaukowych, nie jeden artykuł.

## 6. P3-D — NOT STARTED

Deterministyczny audit/reset: dry-run/fixture DB, backup/checksum/idempotence, brak providerów i brak realnego produkcyjnego `--apply`.

## 7. P4 — NOT STARTED

Nie uruchamiać przed CHECKPOINT_P3.

## 8. Źródła prawdy po V4

1. aktualne polecenie użytkownika;
2. ten plik;
3. aktualny kod/testy;
4. najnowszy checkpoint fazy;
5. stara spec/dokumentacja tylko gdy nie koliduje z powyższym.

Stary emergency checkpoint P3-C jest pomocniczy i nie jest wystarczającym dowodem, że wszystkie P3-C failures były TEST_BUG.
