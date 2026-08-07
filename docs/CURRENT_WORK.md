# Current Work — MAMONA-24: Article Generation & Visual Narrative Pipeline V2

## COMPLETED AND APPROVED

```text
MAMONA-24-P1 — ROOT CAUSE AND SPEC (APPROVED, 2026-08-07)
P1-C korekta zatwierdzona. Findingi CRITICAL i HIGH rozwiązane. Findingi MEDIUM przeniesione do open issues P2.

Oczekiwanie na CHECKPOINT_P1 i akceptację użytkownika przed uruchomieniem P2.
```

---

---

## Historia — TASK-23 (archiwum)

Zakończony zakres: image selection and rendering regression (R1 fallback caption, R2 token dominance).
Potwierdzone dokumenty: `docs/ARCHITECTURE.md`, `docs/IMAGE_PIPELINE_MAP.md`, `docs/DECISIONS.md`, `docs/CONTEXT_INDEX.md`.
P0 TASK-23 zakończony 2026-08-05. P1 nie uruchomiono.
---

## Główny cel

Naprawić automatyczny dobór oraz renderowanie obrazów w artykułach.

Po ostatniej aktualizacji występują dwie klasy regresji:

1. Neutralny fallback wyświetla caption lub alt opisujący konkretny obraz, którego finalnie nie ma.
2. Legalny, ale semantycznie albo redakcyjnie niepasujący obraz może wygrać przez przypadkowe dopasowanie pojedynczego słowa.

Do zakończenia zadania nie publikować automatycznie kolejnych materiałów z niesprawdzonymi obrazami.

## Przykłady referencyjne

### R1 — fałszywy caption fallbacku

Artykuł o pingwinie Adélie pokazuje neutralną grafikę geometryczną, ale caption twierdzi, że przedstawia pingwina w naturalnym środowisku.

Oczekiwane: fallback ma własny neutralny caption, alt i brak zewnętrznego creditu.

### R2 — legalny, ale niedopuszczalny obraz

Artykuł o neuroplastyczności otrzymuje satyryczny obraz polityka-zombie jedzącego mózg, ponieważ metadane zawierają token `brain`.

Oczekiwane: legalność przechodzi osobną walidację, ale kandydat odpada na bramce semantycznej/redakcyjnej.

## Zasady wykonania

- Używaj semantycznego indeksu przed ręcznym otwieraniem plików.
- Maksymalnie 12 plików na jeden subtask eksploracyjny.
- Nie czytaj ponownie pliku bez nazwania konkretnego brakującego pytania.
- Brakującego pliku nie otwieraj drugi raz.
- Nie implementuj przed zapisaniem root cause i zaakceptowaniem specyfikacji.
- Nie uruchamiaj realnych providerów, płatnych API ani publikacji.
- Nie osłabiaj walidacji praw i licencji.
- Nie opieraj filtra wyłącznie na liście nazwisk albo pojedynczych słowach.
- Po każdej fazie zatrzymaj się na checkpoint.

# Kolejka faz

## MAMONA-23-P0 — INITIAL INDEXED MAP — ACTIVE

**Agent nadrzędny:** `mamona-orchestrator`  
**Subagenci:** 2 × `repo-scout`  
**Modele:** scout `qwen3.5:9b/fast`, synteza `qwen3.6:27b/deep`  
**Edycja kodu:** zabroniona

### Cel

Zbudować potwierdzoną mapę istniejącego przepływu obrazów przy minimalnym odczycie plików.

### Zadania równoległe

#### P0-A — selection pipeline

Znajdź:

1. dane artykułu/researchu/kategorii używane do zapytań;
2. generowanie query;
3. providerów i pobieranie kandydatów;
4. prawa/licencje;
5. ranking semantyczny i redakcyjny;
6. wybór zwycięzcy.

#### P0-B — metadata and rendering pipeline

Znajdź:

1. finalny plik i identyfikator assetu;
2. source page i direct file;
3. creator i credit;
4. caption i alt;
5. rights manifest;
6. flagę/typ fallbacku;
7. fallback creation;
8. publiczny renderer HTML;
9. zachowanie przy niedostępnym pliku.

### Wyniki

- uzupełnione `docs/ARCHITECTURE.md`;
- uzupełnione `docs/IMAGE_PIPELINE_MAP.md`;
- lista maksymalnie 16 najważniejszych plików łącznie;
- lista luk, których indeks/kod nie rozstrzyga.

### Kryterium zakończenia

Mapa zawiera potwierdzoną kolejność funkcji i pola przenoszone między etapami. Brak implementacji i testów.

---

## MAMONA-23-P1 — ROOT CAUSE AND SPEC — BLOCKED BY P0

**Agent:** `mamona-architect`  
**Model:** `qwen3.6:27b/deep`  
**Edycja:** tylko `docs/`

### Cel

Ustalić konkretną przyczynę obu regresji i zapisać specyfikację:

```text
docs/specs/TASK-23-image-selection-rendering-regression.md
```

### Obowiązkowe ustalenia

- gdzie finalny asset może rozminąć się z captionem/alt/credit/source;
- czy fallback dziedziczy metadane kandydata;
- co dzieje się, gdy plik nie istnieje albo processing kończy się błędem;
- jak powstaje relevance score;
- czy pojedynczy token może zdominować ranking;
- gdzie legalność jest mylona z użytecznością redakcyjną;
- jakie negatywne sygnały są dostępne w metadanych;
- czy naprawa wymaga migracji istniejących rekordów.

### Checkpoint

Po specyfikacji zatrzymaj się i poproś o akceptację. Nie implementuj.

---

## MAMONA-23-P2 — FINAL ASSET AND FALLBACK CONSISTENCY — BLOCKED BY APPROVAL

**Agent:** `mamona-coder`  
**Model:** `qwen3.6:27b/balanced`

### Cel

Zapewnić, że finalnie wyświetlany plik, caption, alt, credit, source i rights manifest należą do jednego finalnego assetu.

### Wymagania

- fallback ma własne neutralne metadane;
- fallback nie dziedziczy danych odrzuconego lub niedostępnego kandydata;
- niedostępny finalny plik nie renderuje podpisu/creditu źródła;
- renderer używa wyłącznie zweryfikowanego finalnego rekordu;
- istniejące poprawne assety zachowują dotychczasowe dane.

### Minimalna walidacja

- test pingwina/fallbacku;
- test niedostępnego pliku;
- test poprawnego legalnego obrazu.

---

## MAMONA-23-P3 — SEMANTIC AND EDITORIAL RELEVANCE GATE — BLOCKED BY P2

**Agent:** `mamona-coder`  
**Model:** `qwen3.6:27b/deep` dla projektu reguł, potem `balanced` dla implementacji

### Cel

Oddzielić:

```text
rights validation
```

od:

```text
semantic and editorial suitability
```

### Sygnały pozytywne

- główny temat;
- tytuł;
- kategoria;
- kluczowe encje;
- gatunki, obiekty, procesy i instytucje z researchu;
- title, description i tags assetu.

### Sygnały negatywne

- inny główny kontekst niż artykuł;
- osoby publiczne niebędące tematem;
- polityczna satyra;
- zombie, gore, makabra, przemoc;
- memy i karykatury;
- szokujący albo sensacyjny przekaz nieobecny w artykule.

Rozwiązanie musi być ogólne i oparte na dostępnych metadanych. Nie może bazować wyłącznie na jednej liście nazwisk lub słów.

Jeżeli żaden kandydat nie spełnia minimum, wybierz neutralny fallback.

---

## MAMONA-23-P4 — DIAGNOSTICS — BLOCKED BY P3

**Agent:** `mamona-coder`  
**Model:** `qwen3.6:27b/balanced`

Zapisuj bez sekretów:

- query dla każdego providera;
- liczbę kandydatów;
- przyczynę odrzucenia;
- relevance score;
- najważniejsze sygnały pozytywne i negatywne;
- przyczynę fallbacku;
- identyfikator finalnego assetu.

Diagnostyka ma być wystarczająca do odtworzenia decyzji, ale nie może logować kluczy API ani pełnych sekretów.

---

## MAMONA-23-P5 — REGRESSION TESTS AND VALIDATION — BLOCKED BY P2–P4

**Agent:** `mamona-tester`  
**Model:** `qwen3.6:27b/balanced`  
**Review:** `mamona-reviewer/deep`

### Test matrix

1. Pingwin:
   - trafny obraz pingwina wygrywa z ogólną naturą;
   - fallback nie twierdzi, że przedstawia pingwina.

2. Neuroplastyczność:
   - naukowy obraz mózgu może przejść;
   - polityk-zombie odpada;
   - sam token `brain` nie wystarcza.

3. Brak odpowiedniego obrazu:
   - fallback;
   - brak starego captionu;
   - brak fałszywego creditu i źródła.

4. Niedostępny plik:
   - brak podpisu do nieistniejącego zdjęcia;
   - bezpieczny fallback albo pominięcie figury zgodnie z architekturą.

5. Legalny, ale niepasujący:
   - prawa przechodzą;
   - redakcja odrzuca;
   - kandydat nie trafia do artykułu.

6. Pasujący i legalny:
   - nadal wygrywa;
   - caption, alt, credit i source pozostają spójne.

### Istniejące testy do sprawdzenia

- `tests/article-image-pipeline-smoke.php`
- `tests/image-rights-providers-smoke.php`
- `tests/full-auto-selector-smoke.php`
- `tests/post-renderer-smoke.php`
- `tests/generate-all-regression.php`
- `tests/editorial-pipeline-e2e.php`

Nie usuwaj istniejących testów i nie osłabiaj walidacji licencji.

### Minimalne komendy walidacyjne

```powershell
C:\xampp\php\php.exe tests\article-image-pipeline-smoke.php
C:\xampp\php\php.exe tests\image-rights-providers-smoke.php
C:\xampp\php\php.exe tests\full-auto-selector-smoke.php
C:\xampp\php\php.exe tests\post-renderer-smoke.php
C:\xampp\php\php.exe tests\generate-all-regression.php
```

Pełny `editorial-pipeline-e2e.php` uruchom dopiero po sprawdzeniu wymaganych flag i tylko wtedy, gdy zakres faktycznie dotyka pełnego pipeline'u.

## Stan wykonania — MAMONA-24

### Aktywny stan

```text
P0 zakończone — oczekiwanie na checkpoint i akceptację użytkownika przed P1
```

**Zakaz rozpoczęcia P1 bez wyraźnej akceptacji użytkownika.**

### Zakończone

- [x] P0 — repository reconnaissance (COMPLETE, 2026-08-06)
- [x] P1 — root cause and spec (APPROVED, 2026-08-07)
- [x] P2-A — centralny GeminiBudget, limit 20, convergence mode (COMPLETE, 2026-08-07)
- [x] P2-G — audit i reset wadliwych artykułów (COMPLETE, 2026-08-07)

### W toku

- brak

### Zablokowane

- P1 — root cause and spec (BLOCKED BY akceptacja użytkownika P0)
- P2 — implementacja (BLOCKED BY P1 akceptacja)
- P3 — testy (BLOCKED BY P2)
- P4 — review (BLOCKED BY P3)
- P5 — audyt i reset wadliwych artykułów (BLOCKED BY P2-P4)
- P6 — dokumentacja i handoff (BLOCKED BY P5)

### Subagenty P0 — wyniki

| Subtask | Agent | Model | Status | Pliki otwarte |
|---|---|---|---|---|
| P0-A2 text types and limits | repo-scout | qwen3.6:27b/balanced | COMPLETE | 6 |
| P0-B2 Gemini call graph | repo-scout | qwen3.6:27b/balanced (wznowiony) | COMPLETE | 10 |
| P0-C2 narrative and image flow | repo-scout | qwen3.6:27b/balanced (wznowiony) | COMPLETE | 9 |
| P0-D2 invalid article inventory | repo-scout | qwen3.6:27b/balanced | COMPLETE | 5 |

### Dokumenty utworzone lub zaktualizowane w P0

| Dokument | Status | Opis |
|---|---|---|
| `docs/research/MAMONA-24-P0-repository-map.md` | Utworzony | Pełna mapa P0: typy tekstów, call graph Gemini, retry/quota/salvage, narracja/QC, obrazy/fallbacki, schemat danych, luki |
| `docs/ARCHITECTURE.md` | Zaktualizowany | Dodano przepływ generacji, call graph Gemini, moduły, data contracts, ograniczenia, planowane zmiany MAMONA-24 |
| `docs/IMAGE_PIPELINE_MAP.md` | Zaktualizowany | Dodano salvage fallback, advertising wrapper, problemy MAMONA-24 (placeholdery, fallbacki techniczne, brak wymaganых grafik) |
| `docs/CONTEXT_INDEX.md` | Zaktualizowany | Nowy punkt wejścia dla MAMONA-24 z mapą plików i symboli |
| `docs/CURRENT_WORK.md` | Zaktualizowany | Ten plik — stan P0, subagenty, dokumenty |

## P2-F — Publication gate, manual_review, diagnostyka (COMPLETE, 2026-08-07)

```
Zakres: publication gate z blokadą fallback/min grafik/manual_review, 
        structured diagnostics po wyczerpaniu budżetu, freeze artifacts.
Koder: mamona-coder/qwen3.6:27b/balanced
Tester: mamona-tester/qwen3.6:27b/balanced

Zmienione pliki:
- php/quality-check-service.php: QC_HARD_GATES (9), QC_SOFT_GATES (6), 
  publication gate fallback+min images+manual_review block,
  gemini_budget_exhaustion_diagnostics(), qc_structured_report(),
  qc_freeze_accepted_artifacts(), qc_is_artifact_frozen()
- php/generation-batch-service.php: convergence propagation w reconcile/process_item,
  narrative plan integration, budget exhaustion diagnostics w repair_report+audit

Testy: php -l OK na obu plikach.
Wszystkie 9 punktów zakresu potwierdzone przez tester.
```

### Najważniejsze luki

1. Czy istnieją inne typy tekstów poza `informational` i `problem_discovery_return`?
2. Jakie są limity dla pól `lead.text`, `why_important.text`, `key_facts[*].text`?
3. Dokładna implementacja `quality_check_auto_repair_decision()` — ciało funkcji nieotwarte
4. Implementacja `promote_article_draft_to_post()` — szczegóły promocji nieprzeczytane
5. Czy istnieje mechanizm logowania wywołań Gemini poza `gemini_quota_events`?
6. Szczegóły `generation_batch_worker.php` — kolejność przetwarzania itemów
7. Czy cache `gemini_call_cache` ma TTL / politykę expiracji?
8. Gdzie zdefiniowana jest stała `QUALITY_PASS_SCORE`?
9. Jak wygląda pełna funkcja `quality_check_schema()`?
10. Czy istnieje dedykowana funkcja "reset wadliwego artykułu"?
11. Jakie są dokładne warunki w `assert_post_quality_allows_publication()`?
12. Jak `post_legacy_publication_flag()` mapuje status na is_published?

### Ryzyka

1. Brak centralnego budżetu 20 odpowiedzi Gemini — istnieje budżet tematyczny 15, ale bez convergence mode ani ścieżki do manual_review po wyczerpaniu.
2. Fallback obrazów renderowany jako asset finalny — `salvage_local_editorial_images()` generuje SVG CC0 mogący trafić do renderu.
3. Brak bramki semantycznej/redakcyjnej dla obrazów — ranking nie odróżnia legalności od trafności redakcyjnej.
4. Fallback może dziedziczyć metadane kandydata (R1) — caption/alt placeholderu mogą pochodzić z odrzuconego obrazu.
5. Ranking premiuje pojedynczy token (R2) — satyryczny obraz może wygrać przez przypadkowe dopasowanie tokenu.

### Validation log — MAMONA-24

| Data | Faza | Komenda/test | Wynik | Uwagi |
|---|---|---|---|---|
| 2026-08-06 | P0 | 4x repo-scout task (A2-D2) | COMPLETE | Wszystkie subtaski zwróciły SUBTASK_RESULT |
| 2026-08-06 | P0 | git diff docs/ | OK | Zmieniono tylko wymagane pliki, UTF-8 poprawny |
| 2026-08-07 | P2-A | php -l ×4 + p2a-gemini-budget-test.php | 113 PASS, 1 FAIL (log audit) | Bug off-by-one naprawiony przez orchestratora |
| 2026-08-07 | P2-G | php -l + --dry-run ×2 + static analysis 16 punktów | ALL PASS | cli-reset-invalid-articles.php, 22 kandydaty, zero błędów |

### Format raportu po fazie

1. Zakończona faza.
2. Potwierdzone ustalenia.
3. Zmienione pliki.
4. Walidacja.
5. Ryzyka.
6. Jedna następna faza.
7. Czy wymagana jest akceptacja użytkownika.

---

## P2-G — Audit i reset wadliwych artykułów (COMPLETE, 2026-08-07)

```
Zakres: deterministyczny CLI tool do audytu i resetu wadliwych artykułów.
Koder: mamona-coder/qwen3.6:27b/balanced
Tester: mamona-tester/qwen3.6:27b/balanced

Plik produkcyjny:
- php/cli-reset-invalid-articles.php (515 linii, nowy plik)

Testy:
- php -l: PASS
- --dry-run execution: PASS (manifest JSON, 22 kandydatów w danych produkcyjnych)
- dry-run idempotency: PASS (22/22 identyczne)
- Static analysis — wszystkie 16 punktów: PASS
  A. transakcje (beginTransaction/commit/rollBack)
  B. post_status_history zapisywany przed resetem
  C. gemini_quota_events nie usuwany
  D. article_id, category_id, created_at zachowane
  E. brak wywołań Gemini ani zewnętrznych API
  F. brak providerów grafik
  G. status→draft + is_published→0
  H. clear generated fields (title, excerpt, content, image_path, slug)
  I. derived artifacts cleanup (6 tabel, kolejność FK)
  J. backup + SHA-256 checksum
  K. manifest structure (timestamp, total, candidates[])
  L. audit criteria (fallback, semantic_rejected, missing_asset, too_few_images)

Błędy produkcyjne: Brak.
Brakujące testy: brak dedykowanego testu jednostkowego (nieblokujące).
```

---

## P2-E — LIMITY TEKSTÓW (COMPLETE, 2026-08-07)

```
Zakres: maksimum +2000 znaków dla wszystkich typów tekstów.
Koder: mamona-coder/qwen3.6:27b/balanced
Tester: mamona-tester/qwen3.6:27b/balanced

Zmienione pliki:
- php/article-draft-service.php L16: ARTICLE_MAIN_CONTENT_MAX_LENGTH 5000→7000
- php/admin-generation.php L209-210: UI opis zaktualizowany do 3000–7000 i 4000–7000
- php/narrative-plan-service.php: hard-coded 7000→ARTICLE_MAIN_CONTENT_MAX_LENGTH (4 miejsca)
- tests/article-draft-smoke.php L177,314,323,325: assert 5000→7000

Minima niezmienione: informational min=3000, problem_discovery_return min=4000.
Kanoniczna funkcja: article_draft_main_content_length() używa mb_strlen.
Generator i QC używają article_draft_length_policy() — jedno źródło prawdy.
php -l: wszystkie pliki OK.
```

