# MAMONA-24 — Article Generation & Visual Narrative Pipeline V3

## Status

P1-C APPROVED — 2026-08-06. Model: qwen3.6:27b/deep.
Rozwiązano findingi CRITICAL (F2, F4) i HIGH (F3, F6, F10). Findingi MEDIUM przeniesione do open issues P2.

---

## Root cause

**RC1 — Monotonna struktura artykułów:** 7 stałych sekcji narrative jest zakodowana w `php/article-image-service.php` L28-34 i dynamicznie wstrzykiwana jako properties/required w schema draftu (`article-draft-service.php` L510-604). Brak osobnego artefaktu NarrativePlan.

**RC2 — Niekontrolowane iteracje Gemini:** Istnieje budżet tematyczny max 15 wywołań, ale nie ma hard limitu 20 z convergence mode od 16. odpowiedzi. Retry transport, contract repair retry, batch-level auto_retry_scheduled, research retry i QC auto-repair loop są niezależne i sumują się bez centralnego licznika.

**RC3 — Fallback renderowany jako asset finalny:** `salvage_local_editorial_images()` generuje SVG CC0 zapisywane z `status='downloaded'`. Renderer nie odróżnia tego assetu od rzeczywistej grafiki.

**RC4 — Brak bramki semantycznej/redakcyjnej dla obrazów:** `select_source_image_from_results()` rankuje po relevance score bez sprawdzania negatywnych sygnałów (polityka, satyra, zombie, gore, memy).

**RC5 — Fallback dziedziczy metadane kandydata (R1):** renderer `render_article_image_record()` L1457 używa `$image['caption']` w placeholderze.

**RC6 — Brak narzędzia resetu wadliwych artykułów:** Nie istnieje dedykowana funkcja ani skrypt do cofnięcia statusu i wyczyszczenia artefaktów pochodnych.

---

## Decyzje architektoniczne

| # | Decyzja |
|---|---|
| A1 | Centralny GeminiBudget jako osobna struktura z limitem 20, niezależna od RPM/TPM/RPD |
| A2 | Convergence mode od wywołania 16: zamrożenie zaakceptowanych artefaktów, brak pełnych rewrite'ów, progi QC nie obniżane |
| A3 | NarrativePlan jako osobny artefakt przed generacją draftu z uzasadnieniem wyboru struktury |
| A4 | Twarde vs. miękkie bramki QC: twarde blokują każdy dalszy krok; miękkie generują feedback |
| A5 | Fallback jako wewnętrzny sygnał błędu, nigdy jako renderowany asset finalnego artykułu |
| A6 | Bramka semantyczna/redakcyjna jako osobny etap po rights validation |
| A7 | Moduły B/C jako uzupełnienie narracji, nie filler. Maksymalnie dwa moduły |
| A8 | Reset wadliwych artykułów jako deterministyczne narzędzie CLI z --dry-run/--apply |
| A9 | Maksimum znaków +2000 dla każdego typu tekstu (informational 5000→7000, problem_discovery_return 5000→7000) |
| A10 | Zastąpienie budżetu tematycznego 15 limitem przebiegu 20. Stary limit jest zastępowany, nie pozostawiany równolegle |
| A11 | Convergence mode jako kolumna w generation_batch_items propagowana do repair router, QC i draft generatora |
| A12 | Publication gate rozszerzone o walidację grafik: brak fallbacków + minimalna liczba assetów |

---

## Kontrakty danych

### GeminiBudget

- `article_id`, `max_calls`: 20, `used_calls`, `convergence_threshold`: 16
- `replaces_topic_budget`: true (zastępuje stary limit 15)
- `calls[]`: `call_number`, `operation_type`, `stage`, `attempt`, `tokens_estimated`, `tokens_actual`, `status`, `timestamp`
  - `status`: "success" | "contract_repair" | "failed" — USUNIĘTO "retry_transport", transport retry nie zużywa budżetu
- `is_exhausted`, `convergence_active`, `remaining_calls`

**Co zużywa budżet, co nie:**
- Transport retry (HTTP 429/5xx): NIE — ten sam logical call
- Contract repair retry: TAK — nowa odpowiedź modelu
- Research retry: TAK
- QC auto-repair (targeted_repair): TAK
- QC auto-repair (fresh_conservative_rewrite): TAK
- Narrative plan generation: TAK
- Image analysis (jeśli używa Gemini): TAK
- Mock transport: TAK w produkcji; NIE w testach z gemini_mock_budget_bypass
- Cache hit: NIE — brak nowego wywołania API

### NarrativePlan

- `article_id`, `promise_to_reader`, `main_thesis`, `narrative_arc`, `arc_justification`
- `sections[]`: `section_id`, `type`, `topic_ref` (A/B/C), `content_brief`, `visual_slot_required`, `estimated_length`
- `transitions[]`, `rhythm_notes`, `visual_slots_planned`, `hero_topic_ref`: "A", `ending_type`
- `supplemental_topics[]`: `topic_id`, `relation_to_A`, `brief`, `visual_slots`
- `target_length`, `status`, `batch_stage_ref` (nowa: powiązanie z generation_batch_items.stage)

### GenerationState

- `article_id`, `topic_seed`, `composition_mode`, `current_stage`
- `artifacts`: research, narrative_plan, draft (z `frozen_sections[]`), qc_reports[], images[]
- `gemini_budget`: GeminiBudget, `convergence_active`: boolean, `frozen_artifacts`: [string]
- `repair_history[]`, `final_status`

### QcReport

- `qc_id`, `article_id`, `iteration`
- `hard_gates[]`: `gate_name`, `passed`, `detail`, `severity`: "blocker"
  - `gate_name`: char_count_range | gemini_budget_limit | max_5_images | required_slots_filled | assets_exist | rights_license_ok | metadata_consistent | publication_safe | no_fallback_images
- `soft_gates[]`: `gate_name`, `score` (0-100), `detail`, `suggested_fix_scope`
  - `gate_name`: narrative_coherence | transitions_smooth | no_redundancy | rhythm_varied | engagement_level | not_monotonic_matrix
- `hard_blocks_json`, `model_score`, `final_score`, `passed` (true iff all hard_gates.passed && final_score >= 75)
- `human_review_status`, `validation_json`, `convergence_check`: boolean

### VisualSlot

- `slot_id`, `article_id`, `role`: "hero"|"inline", `section_id`, `topic_ref`: A/B/C
- `visual_intent`, `expected_content`, `search_queries_json`
- `candidate_status`: planned|searched|selected|downloaded|rejected|semantic_failed|missing
- `local_path`, `rights_manifest_json`, `license`, `attribution`, `alt`, `caption`
- `is_fallback`: boolean (true blokuje renderowanie i publikację)
- `semantic_score`: int|null, `semantic_threshold`: 60 (domyślny, kalibrowalny w P2)
- `editorial_rejected`: boolean, `width`, `height`, `status`

### SupplementalTopic

- `topic_id`: "B"|"C", `article_id`, `relation_to_A`, `brief`, `content`
- `visual_slots_required`, `visual_slots_filled`, `status`

---

## Maszyna stanów

Stany: `idle` → `research` → `narrative_plan` → `draft` → `qc` → `images` → `final_qc` → `complete` (ready_for_preview / ready_with_notes)
  ↳ `repair_loop` → `qc` (powtórne)
  ↳ `salvage` → `ready_with_notes`
  ↳ `convergence_mode` (call ≥ 16) → micro-repair only
  ↳ `budget_exhausted` → `manual_review`

**Propagacja convergence mode:** flaga w `generation_batch_items.convergence_active` BOOLEAN DEFAULT 0. Ustawiana automatycznie gdy `GeminiBudget.used_calls >= 16`. Odczytywana przez `repair_router_assess($check, $convergenceActive)`, `quality_check_auto_repair_decision($check, $convergenceActive)` i draft repair generatora. W convergence mode: repair router ignoruje fresh_conservative_rewrite; QC nie obniża thresholdów; draft repair otrzymuje scope z `suggested_fix_scope`.

**Warunki zakończenia:**
- Sukces: `ready_for_preview` — wszystkie hard gates OK, sloty wypełnione, budget ≤ 20.
- Sukces z uwagami: `ready_with_notes` — safe composer/repair, quality score ≥ 75.
- Manual review: budget wyczerpany lub brak grafik po B/C. NIE publikowany.
- Błąd terminalny: `failed`.

Żaden stan nie przechodzi automatycznie do `published`. `manual_review` nie wraca bez interwencji człowieka.

---

## Freeze zaakceptowanych artefaktów

Statusy: `planned` → `generated` → `accepted` → `frozen` | `rejected` | `manual_review`.
Po udanej iteracji QC artefakty przechodzą do `frozen`. W convergence mode wszystkie `accepted` automatycznie stają się `frozen`. Frozen artefakt nie jest modyfikowany przez żaden etap repair. Lista frozen ID w `$item['frozen_artifact_ids']`.

---

## NarrativePlan

Osobny etap generowania przed draftem. Konsumuje research, produkuje plan narracyjny z polem `status: "accepted"`. Wymaga: obietnica dla czytelnika, główna teza, łuk narracyjny z uzasadnieniem, kolejność sekcji (dynamiczna), przejścia, rytm, sloty wizualne, zakończenie, opcjonalne gałęzie B/C. Dodano pole `batch_stage_ref` powiązujące plan z etapem batch item.

---

## VisualSlot — limity i reguły

Liczba slotów: 1 na każde rozpoczęte 1200 znaków, max 5, min 1. Hero zawsze temat A. Inline wymaga `section_id`.
Dodano `semantic_threshold`: 60 (domyślny). Kandydat z score < 60 → `editorial_rejected` = true. Kalibracja w P2.

### SupplementalTopic B/C

Moduł B po pierwszym niepowodzeniu grafik, C po niepowodzeniu B. Maksymalnie dwa. Po wyczerpaniu B/C bez rozwiązania → `manual_review`.

---

## Limity tekstów

| Typ | Min | Max (stary) | Max (nowy) |
|---|---|---|---|
| informational | 3000 | 5000 | 7000 (+2000) |
| problem_discovery_return | 4000 | 5000 | 7000 (+2000) |

Kanoniczne liczenie znaków: `mb_strlen(implode(...))` z `article_draft_main_content_length()`. Ta sama funkcja w generatorze, QC, logach i testach.

Kanoniczne liczenie grafik: `ceil(target_length / 1200)`, max 5, min 1. Hero wliczony. Zamrożone po zaakceptowaniu NarrativePlan.
Prawidłowe: `status='downloaded'`, `is_fallback=0`, `editorial_rejected=0`, plik istnieje, rights manifest OK.

---

## Bramki praw i trafności

Dwie osobne bramki: (1) rights validation — niezmieniona; (2) semantic/editorial gate — nowa, po rights validation.
Sygnały negatywne: inny kontekst, osoby publiczne niebędące tematem, satyra, zombie/gore, memy, szok bez uzasadnienia.
Domyślny threshold: 60. Kalibracja w P2-D (open issue O4).

---

## Publication gate

`assert_post_quality_allows_publication()` rozszerzona o dwa warunki DODANE PRZED sprawdzaniem QC score:
1. Brak fallbacków: `SELECT na article_images WHERE post_id=? AND is_fallback=1` → throw.
2. Minimalna liczba grafik: liczy `status='downloaded'`, `is_fallback=0`, `editorial_rejected=0` vs. wymagana liczba slotów → throw.

---

## Reset wadliwych artykułów

**Pola zachowywane:** posts (id, category_id, seed title, created_at, updated_at), post_status_history, gemini_quota_events.
**Pola czyszczone:** posts (wygenerowany title, excerpt, content, image_path, slug, status→draft), article_draft_versions (DELETE), quality_check_runs (DELETE), generation_operations (DELETE), article_images (DELETE), narrative_plans (DELETE), supplemental_topics (DELETE).

Dry-run manifest, backup z SHA-256.

---

## Backfill starych fallbacków

Kryteria detekcji (co najmniej jeden):
1. `local_path LIKE '%editorial-fallback/%'`
2. `search_audit_json LIKE '%local_fallback%'`
3. provider metadata: 'local-editorial' w rights_manifest

Backfill SQL: `UPDATE article_images SET is_fallback = 1 WHERE local_path LIKE '%editorial-fallback/%' OR search_audit_json LIKE '%local_fallback%'`.
Wykonywany w tej samej transakcji co ADD COLUMN — brak okna czasowego.
Rollback SQL: `UPDATE article_images SET is_fallback = 0 WHERE id IN (lista_z_backupu)`.

---

## Migracja SQLite

Krok 1: Backup bazy + SHA-256 checksum.
Krok 2: Transakcja BEGIN IMMEDIATE:
- ALTER TABLE article_images ADD COLUMN is_fallback INTEGER NOT NULL DEFAULT 0
- ALTER TABLE article_images ADD COLUMN semantic_score INTEGER
- ALTER TABLE article_images ADD COLUMN editorial_rejected INTEGER NOT NULL DEFAULT 0
- ALTER TABLE generation_batch_items ADD COLUMN convergence_active INTEGER NOT NULL DEFAULT 0
- CREATE TABLE article_generation_budget (article_id PK, max_calls DEFAULT 20, used_calls DEFAULT 0, convergence_threshold DEFAULT 16, calls_log_json, is_exhausted DEFAULT 0, convergence_active DEFAULT 0, created_at, updated_at)
- CREATE TABLE narrative_plans (id PK, article_id FK, promise_to_reader, main_thesis, narrative_arc, arc_justification, sections_json, transitions_json, rhythm_notes, visual_slots_planned, hero_topic_ref DEFAULT 'A', ending_type, supplemental_topics_json, target_length, status DEFAULT 'planned', batch_stage_ref, created_at, updated_at)
- CREATE TABLE supplemental_topics (id PK, topic_id CHECK B/C, article_id FK, relation_to_A, brief, content, visual_slots_required, visual_slots_filled, status DEFAULT 'planned', created_at)
- UPDATE article_images SET is_fallback = 1 WHERE local_path LIKE '%editorial-fallback/%' OR search_audit_json LIKE '%local_fallback%'
- COMMIT

Strategia locka: krótkie DDL, UPDATE na końcu, transakcja < 1s.
Rollback: DROP TABLE nowych tabel + backfill is_fallback=0 z backupu. Ograniczenie: SQLite < 3.35.0 nie wspiera DROP COLUMN — pełny rollback wymaga restore z backupu.

---

## Zgodność wsteczna

1. `is_fallback` default 0 + backfill dla starych fallbacków.
2. Budget table — brak wpisu = brak budżetu (tylko nowe przebiegi).
3. NarrativePlan — brak rekordu = stara ścieżka.
4. quality_check_runs — nowa bramka no_fallback_images dodatkowa.
5. Limit 15→20 atomowy w kodzie, bez okna z dwoma limitami.

---

## Migracja limitu 15 do 20

Krok 1: Zmiana thresholdu w `php/gemini-quota-service.php` L131-134: 15→20, 13→17, 14→18.
Krok 2: Zmiana w `php/generation-batch-service.php` L1632-1640: GeminiTopicBudgetException → GeminiArticleBudgetException; salvage → manual_review.
Krok 3: Nowa klasa GeminiArticleBudgetException z polami article_id, usedCalls, maxCalls (20).
Krok 4: Nowa tabela article_generation_budget jako jedno źródło prawdy. Stary mechanizm gemini_quota_events pozostaje jako historyczny log, ale nie steruje admission.

---

## Tryb zbieżności od wywołania 16

Call 1-15: normalny tryb — pełne rewrite, pełne regeneracje, dowolny zakres napraw.
Call 16-20: convergence mode — zamrożone artefakty niezmienialne; naprawy tylko w zakresie wskazanym przez QC jako blocker; progi QC nie obniżane.

Propagacja: flaga w `generation_batch_items.convergence_active` + parametr przekazywany do `repair_router_assess()`, `quality_check_auto_repair_decision()` i draft repair generatora. Po inkrementacji budżetu batch service sprawdza próg 16 i ustawia flagę w `$item`.

---

## Test matrix

| # | Test |
|---|---|
| T1 | Limity znaków +2000 — informational max 7000, problem_discovery_return max 7000 |
| T2 | Kanoniczne liczenie znaków — ta sama funkcja w generatorze i QC |
| T3 | Hard limit 20 odpowiedzi — 20. call twardy limit; retry go zużywa |
| T4 | Convergence mode od 16. — poprawki zakresowe; pełne rewrite odrzucane; QC nie obniżone |
| T5 | Zamrożenie tekstu A — frozen tekst się nie zmienia przy braku grafik |
| T6 | Hero → A; inline → sekcje — walidacja przypisań topic_ref i section_id |
| T7 | Progi długości → sloty — 1199/1200/2399/2400/3599/3600/4799/4800, max 5 |
| T8 | Brak obrazu → B → C → manual_review — bez placeholderu, bez przypadkowego obrazu |
| T9 | Po B/C → manual_review + blokada publikacji |
| T10 | Brak jednej matrycy sekcji — NarrativePlan generuje różne układy |
| T11 | Finalny QC bez pełnych rewrite'ów — zaakceptowane części niezmienione |
| T12 | Polskie znaki → UTF-8 — bez mojibake |
| T13 | Artykuł bez hero/za mało grafik → nie ukończony, nie opublikowany |
| T14 | Placeholder/fallback → odrzucenie przez QC i renderer |
| T15 | Fixture zombie/Trump + brain → odrzucenie przez bramkę semantyczną |
| T16 | Audyt --dry-run wykrywa wadliwe artykuły — 5 wadliwych + 2 poprawnych |
| T17 | Reset: bez Gemini, zachowuje seed i historię |
| T18 | Reset idempotentny + manifest + backup + ochrona publikacji |
| T19 | Budżet 15→20 — call 16-20 nie rzucają GeminiTopicBudgetException; call 21 rzuca GeminiArticleBudgetException |
| T20 | Cache hit nie zużywa budżetu — gemini_cached_call() → budget nie inkrementowany |
| T21 | Convergence mode propagacja — flaga dociera do repair router, QC i draft generatora |
| T22 | Backfill is_fallback — fallbacky z editorial-fallback/ mają is_fallback=1 po migracji; prawidłowe obrazy mają 0 |
| T23 | Publication gate + grafiki — artykuł z is_fallback=1 nie przechodzi assert_post_quality_allows_publication() |
| T24 | Reset: dokładny zakres pól — czyści tylko zadeklarowane artefakty, zachowuje seed/historię |
| T25 | Semantic gate threshold — score < 60 odrzucony; score ≥ 60 zaakceptowany |

---

## Rollback

Poziom 1: Git revert/rollback zmian kodu.
Poziom 2: DROP TABLE nowych tabel + backfill is_fallback=0 z backupu. Ograniczenie: SQLite < 3.35.0 nie wspiera DROP COLUMN — pełny rollback wymaga restore z backupu.
Poziom 3: Przywrócenie thresholdu w `gemini-quota-service.php` i ścieżki salvage w `generation-batch-service.php`.
Poziom 4: post_status_history pozwala odtworzyć poprzedni stan publikacji.

---

## Ryzyka

| # | Ryzyko | Mitigacja |
|---|---|---|
| R1 | Budget 20 za niski | convergence mode + manual_review jako bezpieczna ścieżka |
| R2 | Bramka semantyczna false positive | kalibracja thresholdu na fixture'ach, test T25 |
| R3 | Moduły B/C jako filler | walidacja NarrativePlan wymaga relation_to_A i uzasadnienia |
| R4 | Zamrożenie blokuje konieczne poprawki | odmrażanie przez człowieka; convergence nie blokuje napraw zakresowych |
| R5 | Migracja is_fallback okno czasowe | backfill w tej samej transakcji co ADD COLUMN |
| R6 | Naruszenie poprawnych artykułów | testy T13/T14/T16/T23, backup przed migracją |
| R7 | Cache bez TTL | P2-A weryfikacja polityki expiracji |
| R8 | SQLite lock podczas migracji | BEGIN IMMEDIATE, krótkie DDL, < 1s |
| R9 | Brak DROP COLUMN w SQLite < 3.35.0 | dokumentowane; pełny rollback z backupu |

---

## Open issues do P2

| # | Issue | Faza |
|---|---|---|
| O1 | Mock transport i budżet w testach — flaga gemini_mock_budget_bypass omijająca inkrementację budżetu | P2-A |
| O2 | Dokładna definicja "artefakty pochodne" w resecie — konkretny SQL DELETE/UPDATE dla każdej tabeli | P2-G |
| O3 | NarrativePlan i generation_batch_items.stage — nowy stage vs. pod-etap research/draft | P2-B |
| O4 | Kalibracja thresholdu bramki semantycznej — domyślny 60, walidacja na fixture'ach | P2-D |
| O5 | Polityka expiracji gemini_call_cache — cache bez TTL może zwracać przestarzałe odpowiedzi | P2-A |

---

## Pliki i symbole wymagające zmiany

| # | Plik | Symbol/zakres | Zmiana | Faza | Priority |
|---|---|---|---|---|---|
| 1 | php/gemini-quota-service.php | L131-137 threshold 15→20, GeminiArticleBudgetException | Zastąpienie limitu 15 limitem 20 | P2-A | CRITICAL |
| 2 | php/generation-batch-service.php | L1632-1640 catch exception → manual_review | salvage → manual_review po 20 call | P2-A | CRITICAL |
| 3 | php/generation-batch-service.php | dispatch loop | Propagacja convergence_active | P2-A | HIGH |
| 4 | php/repair-router-service.php | repair_router_assess() | Parametr $convergenceActive; ignoruje fresh_conservative_rewrite | P2-A | HIGH |
| 5 | php/quality-check-service.php | assert_post_quality_allows_publication() L738-767 | Brak fallbacków + min. liczba grafik | P2-D | HIGH |
| 6 | php/quality-check-service.php | quality_check_auto_repair_decision() | Parametr $convergenceActive; nie obniża thresholdów | P2-C | HIGH |
| 7 | php/article-image-service.php | render_article_image_record() L1453 | Sprawdzenie is_fallback przed renderowaniem | P2-D | CRITICAL |
| 8 | php/article-draft-service.php | Stałe L14-16 max 5000→7000 | Maksimum +2000 | P2-E | HIGH |
| 9 | php/generation-service.php | Mock transport L151-1174 | gemini_mock_budget_bypass dla testów | P2-A | MEDIUM |
| 10 | php/article-draft-service.php | Schema draftu L510-604 | Czytanie z NarrativePlan zamiast hardcodowanych 7 sekcji | P2-B | HIGH |
| 11 | php/editorial-schema.php | Nowe migracje | ALTER TABLE + CREATE TABLE + backfill | P2-A–D | CRITICAL |
| 12 | php/article-image-service.php | select_source_image_from_results() L780 | Bramka semantyczna z thresholdem | P2-D | HIGH |
| 13 | php/salvage-service.php | salvage_local_editorial_images() L85 | Brak zapisu SVG jako renderowanego assetu | P2-D | CRITICAL |
| 14 | Nowy plik | Skrypt resetu wadliwych artykułów | CLI --dry-run/--apply | P2-G | HIGH |

---

## Wynik review P1

APPROVED. Wszystkie findingi CRITICAL (F2, F4) i HIGH (F3, F6, F10) rozwiązane. Findingi MEDIUM poprawnie przeniesione do open issues P2 z przypisanymi fazami.