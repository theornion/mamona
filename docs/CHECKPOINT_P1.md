# CHECKPOINT_P1 — MAMONA-24 Article Generation & Visual Narrative Pipeline V2

## Status: APPROVED, awaiting user acceptance before P2 launch

---

## 1. Zakończona faza

P1-C korekta architektury zatwierdzona 2026-08-07 po rozwiązaniu findingi CRITICAL (F2, F4) i HIGH (F3, F6, F10). Findingi MEDIUM przeniesione do open issues P2.

---

## 2. Potwierdzone ustalenia — Final P1 Architecture Result

### Root cause korekta

- RC1: Monotonna struktura artykułów → NarrativePlan jako osobny artefakt z uzasadnieniem wyboru sekcji zamiast hardkodowanych 7 stałych sekcji.
- RC2: Niekontrolowane iteracje Gemini → Centralny GeminiBudget (max 20) + convergence mode od wywołania 16; transport retry nie zużywa budżetu, ale contract/research/QC auto-repair tak.
- RC3: Fallback jako finalny asset → fallback internal error signal only, never rendered as final image; is_fallback flag w bazie + renderer blokuje renderowanie SVG CC0 z salvage.
- RC4: Brak bramki semantycznej → osobna walidacja po rights validation odrzuca satyrę, polityków niezwiązanych z tematem, zombie/gore/memy na threshold 60 (kalibracja P2).
- RC5: Fallback dziedziczy metadane R1 → placeholder używa własnych neutralnych caption/alt; fallback nie przekazuje danych odrzuconego kandydata.
- RC6: Brak resetu wadliwych artykułów → CLI narzędzie z --dry-run/--apply, backup SHA-256, manifest, rollback SQL.

### Decyzje architektoniczne A1-A12 potwierdzone

A1–A4: Centralny budżet 20, convergence mode od 16, NarrativePlan jako artefakt, twarde/miękkie QC gates.
A5–A9: Fallback never rendered, semantic gate separate from rights validation, moduły B/C nie filler, reset CLI tool, +2000 znaków limity.
A10–A12: Limit 15→20 atomowy, convergence flag propagation, publication gate z walidacją grafik.

### Kontrakty danych — specyfikacja zatwierdzona

GeminiBudget: article_id, max_calls=20, used_calls, convergence_threshold=16; calls_log_json bez "retry_transport"; is_exhausted, convergence_active.
NarrativePlan: promise_to_reader, main_thesis, narrative_arc z arc_justification, sections[] dynamiczna zamiast hardcodu, transitions[], rhythm_notes, visual_slots_planned, hero_topic_ref="A", ending_type, supplemental_topics_json, target_length, status, batch_stage_ref.
GenerationState: research/narrative_plan/draft/qc_reports/images/artifacts; gemini_budget (GeminiBudget), convergence_active boolean, frozen_artifact_ids[].
QcReport: hard_gates[] z no_fallback_images nowa brama; soft_gates[] narrative_coherence/transitions_smooth/no_redundancy/rhythm_varied/engagement_level/not_monotonic_matrix; convergence_check flag.
VisualSlot: slot_id/article_id/topic_ref/A-B-C, visual_intent, candidate_status (planned→selected→downloaded|rejected|semantic_failed|missing), is_fallback boolean blokuje render/publikację, semantic_score int|null threshold 60, editorial_rejected boolean.
SupplementalTopic: topic_id B/C, relation_to_A, brief, content, visual_slots_required/filled, status planned/completed/exhausted.

### Maszyna stanów — propagacja convergence mode potwierdzona

Stany: idle→research→narrative_plan→draft→qc→images→final_qc→complete (ready_for_preview/ready_with_notes/manual_review).
Repair loop powraca do qc; salvage → manual review nie publikowany.
Convergence active od call≥16: micro-repair only, QC thresholdy frozen, accepted artefakty zamrożone w generation_batch_items.frozen_artifact_ids.

### Migracja limitu 15→20 — plan P2-A zatwierdzony

Krok 1–4 atomowy zamiast okna czasowego z dwoma limitami:
- gemini-quota-service.php L131–137 threshold 15→20, GeminiArticleBudgetException.
- generation-batch-service.php L1632–1640 catch exception → manual_review po 20 call; salvage → manual_review.
- article_generation_budget tabela jako jedno źródło prawdy; gemini_quota_events historyczny log.

### Tryb zbieżności od wywołania 16 — specyfikacja zatwierdzona

Call 1–15: normalny tryb — pełne rewrite, regeneracje, dowolny zakres napraw.
Call 16–20: convergence mode — zamrożone artefakty; naprawy tylko w zakresie blocker QC; progi nie obniżane.
Propagacja: flaga generation_batch_items.convergence_active + parametr do repair_router_assess()/quality_check_auto_repair_decision()/draft generatora.

### NarrativePlan jako osobny etap — specyfikacja zatwierdzona

Etap przed draftem z uzasadnieniem struktury; status accepted zamraża artefakty; frozen nie modyfikowane przez repair router; convergence mode automatycznie freeze accepted→frozen.

### QcReport hard/soft gates rozszerzone — specyfikacja zatwierdzona

Twarde: char_count_range, gemini_budget_limit, max_5_images, required_slots_filled, assets_exist, rights_license_ok, metadata_consistent, publication_safe, no_fallback_images (nowa).
Miękkie: narrative_coherence, transitions_smooth, no_redundancy, rhythm_varied, engagement_level, not_monotonic_matrix.
convergence_check flag blokuje obniżanie thresholdów w convergence mode.

### Freeze zaakceptowanych artefaktów — specyfikacja zatwierdzona

Statusy: planned→generated→accepted→frozen; accepted w convergence mode automatycznie frozen; lista ID w generation_batch_items.frozen_artifact_ids.

### VisualSlot semantic_threshold 60 — specyfikacja zatwierdzona (kalibracja P2-D)

Liczba slotów ceil(target_length/1200), max 5, min 1; hero zawsze A; inline wymaga section_id; score<60→editorial_rejected=true.

### SupplementalTopic B/C jako uzupełnienie narracji — specyfikacja zatwierdzona

Moduł B po pierwszym niepowodzeniu grafik, C po niepowodzeniu B; max dwa moduły; wyczerpanie → manual_review bez publikacji.

### Limity tekstów +2000 znaków — specyfikacja zatwierdzona

informational: min 3000–max 7000 (+2000); problem_discovery_return: min 4000–max 7000 (+2000).
Kanoniczne liczenie mb_strlen(implode(...)) w article_draft_main_content_length() — ta sama funkcja generatorze, QC logach.

### Kanoniczne liczenie grafik i brak fallbacków — specyfikacja zatwierdzona

Prawidłowy asset: status='downloaded', is_fallback=0, editorial_rejected=0; plik istnieje, rights manifest OK.
Publication gate dwa warunki DODANE PRZED QC score check: (1) SELECT na article_images WHERE post_id=? AND is_fallback=1 → throw; (2) min liczba downloaded/is_fallback=0/editorial_rejected=0 vs wymagane sloty → throw.

### Backfill starych fallbacków — specyfikacja zatwierdzona

Kryteria: local_path LIKE '%editorial-fallback/%' OR search_audit_json LIKE '%local_fallback%' OR rights_manifest contains 'local-editorial'.
Backfill SQL w tej samej transakcji co ADD COLUMN is_fallback INTEGER NOT NULL DEFAULT 0; dry-run manifest, backup SHA-256, rollback UPDATE SET is_fallback=0.

### Reset wadliwych artykułów — specyfikacja zatwierdzona CLI tool P2-G

Pola zachowane: posts (id, category_id, seed title, created_at, updated_at), post_status_history, gemini_quota_events.
Pola czyszczone: posts (title/excerpt/content/image_path/slug/status→draft), article_draft_versions DELETE, quality_check_runs DELETE, generation_operations DELETE, article_images DELETE, narrative_plans DELETE, supplemental_topics DELETE.
Manifest dry-run z candidates[], backup JSON+SHA-256 w logu; idempotentny reset bez publikacji naruszonej treści.

### Dry-run manifest i rollback — specyfikacja zatwierdzona

Manifest: candidates[] article_id/title_seed/qualification_reason/current_status/is_public/assets/fields_to_clean/preserve[].
Backup: JSON eksport + SHA-256; rollback SQL UPDATE is_fallback=0 z backupu; post_status_history do odtworzenia publikacji.

### Migracja SQLite — specyfikacja zatwierdzona

Krok 1: Backup bazy + SHA-256 checksum.
Krok 2: Transakcja BEGIN IMMEDIATE → COMMIT < 1s:
  - ALTER article_images ADD is_fallback INTEGER NOT NULL DEFAULT 0, semantic_score INTEGER, editorial_rejected INTEGER NOT NULL DEFAULT 0;
  - ALTER generation_batch_items ADD convergence_active INTEGER NOT NULL DEFAULT 0;
  - CREATE article_generation_budget (article_id PK, max_calls=20, used_calls=0, convergence_threshold=16, calls_log_json, is_exhausted=0);
  - CREATE narrative_plans (id FK→articles.article_id, promise_to_reader, main_thesis, narrative_arc, arc_justification, sections_json, transitions_json, rhythm_notes, visual_slots_planned, hero_topic_ref='A', ending_type, supplemental_topics_json, target_length, status='planned', batch_stage_ref);
  - CREATE supplemental_topics (id FK→articles.article_id, topic_id CHECK B/C, relation_to_A, brief, content, visual_slots_required/filled, status='planned');
  - UPDATE article_images SET is_fallback=1 WHERE local_path LIKE '%editorial-fallback/%' OR search_audit_json LIKE '%local_fallback%';
Krok 3: Walidacja kolumny/tabeli/checksum.
Rollback DROP TABLE nowych + backfill rollback; SQLite < 3.35 nie wspiera DROP COLUMN — dokumentowane ograniczenie.

### Zgodność wsteczna — specyfikacja zatwierdzona

(1) is_fallback default=0 + backfill dla starych fallbacków.
(2) Budget table brak wpisu = brak budżetu (tylko nowe przebiegi).
(3) NarrativePlan brak rekordu = stara ścieżka; convergence_active flaga w generation_batch_items.
(4) QC nowa brama no_fallback_images dodatkowa do quality_check_runs.hard_gates[].
(5) Limit 15→20 atomowy, bez okna z dwoma limitami.

### Pliki i symbole wymagające zmiany P2 — specyfikacja zatwierdzona (P2-A–G)

| # | Plik | Symbol/zakres | Zmiana | Faza P2 | Priority |
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

### Test matrix — specyfikacja zatwierdzona T1-T25

T1: Limity znaków +2000 — informational max 7000, problem_discovery_return max 7000.
T2: Kanoniczne liczenie znaków — ta sama funkcja w generatorze i QC.
T3: Hard limit 20 odpowiedzi — 20. call twardy limit; retry go zużywa.
T4: Convergence mode od 16. — poprawki zakresowe; pełne rewrite odrzucane; QC nie obniżone.
T5: Zamrożenie tekstu A — frozen tekst się nie zmienia przy braku grafik.
T6: Hero → A; inline → sekcje — walidacja topic_ref i section_id.
T7: Progi długości → sloty — 1199/1200/2399/2400/3599/3600/4799/4800, max 5.
T8: Brak obrazu → B → C → manual_review — bez placeholderu, bez przypadkowego obrazu.
T9: Po B/C → manual_review + blokada publikacji.
T10: Brak jednej matrycy sekcji — NarrativePlan generuje różne układy.
T11: Finalny QC bez pełnych rewrite'ów — zaakceptowane części niezmienione.
T12: Polskie znaki → UTF-8 — bez mojibake.
T13: Artykuł bez hero/za mało grafik → nie ukończony, nie opublikowany.
T14: Placeholder/fallback → odrzucenie przez QC i renderer.
T15: Fixture zombie/Trump + brain → odrzucenie przez bramkę semantyczną.
T16: Audyt --dry-run wykrywa wadliwe artykuły — 5 wadliwych + 2 poprawnych.
T17: Reset: bez Gemini, zachowuje seed i historię.
T18: Reset idempotentny + manifest + backup + ochrona publikacji.
T19: Budżet 15→20 — call 16-20 nie rzucają GeminiTopicBudgetException; call 21 rzuca GeminiArticleBudgetException.
T20: Cache hit nie zużywa budżetu — gemini_cached_call() → budget nie inkrementowany.
T21: Convergence mode propagacja — flaga dociera do repair router, QC i draft generatora.
T22: Backfill is_fallback — fallbacky z editorial-fallback/ mają is_fallback=1 po migracji; prawidłowe obrazy mają 0.
T23: Publication gate + grafiki — artykuł z is_fallback=1 nie przechodzi assert_post_quality_allows_publication().
T24: Reset: dokładny zakres pól — czyści tylko zadeklarowane artefakty, zachowuje seed/historię.
T25: Semantic gate threshold — score < 60 odrzucony; score ≥ 60 zaakceptowany.

### Rollback plan — specyfikacja zatwierdzona

Poziom 1: Git revert/rollback zmian kodu.
Poziom 2: DROP TABLE nowych tabel + backfill is_fallback=0 z backupu (SQLite < 3.35 wymaga restore).
Poziom 3: Przywrócenie thresholdu w gemini-quota-service.php i ścieżki salvage w generation-batch-service.php.
Poziom 4: post_status_history pozwala odtworzyć poprzedni stan publikacji.

### Ryzyka — mitigacja zatwierdzona R1-R9

R1: Budget 20 za niski → convergence mode + manual_review jako bezpieczna ścieżka.
R2: Bramka semantyczna false positive → kalibracja thresholdu na fixture'ach, test T25.
R3: Moduły B/C jako filler → walidacja NarrativePlan wymaga relation_to_A i uzasadnienia.
R4: Zamrożenie blokuje konieczne poprawki → odmrażanie przez człowieka; convergence nie blokuje napraw zakresowych.
R5: Migracja is_fallback okno czasowe → backfill w tej samej transakcji co ADD COLUMN.
R6: Naruszenie poprawnych artykułów → testy T13/T14/T16/T23, backup przed migracją.
R7: Cache bez TTL → P2-A weryfikacja polityki expiracji.
R8: SQLite lock podczas migracji → BEGIN IMMEDIATE, krótkie DDL, < 1s.
R9: Brak DROP COLUMN w SQLite < 3.35.0 → dokumentowane; pełny rollback z backupu.

### Open issues P2 — findings MEDIUM przeniesione do open issues

O1: Mock transport i budżet w testach (F7) — flaga gemini_mock_budget_bypass omijająca inkrementację budżetu. Faza P2-A.
O2: Dokładna definicja "artefakty pochodne" w resetu (F9) — konkretny SQL DELETE/UPDATE dla każdej tabeli. Faza P2-G.
O3: NarrativePlan i generation_batch_items.stage (F11) — nowy stage vs pod-etap research/draft. Decyzja wpływa na strukturę dispatch loop. Faza P2-B.
O4: Kalibracja thresholdu bramki semantycznej (F12) — domyślny 60, walidacja na fixture'ach i rzeczywistych kandydatach. Faza P2-D.
O5: Polityka expiracji gemini_call_cache — cache bez TTL może zwracać przestarzałe odpowiedzi w nowych przebiegach generacji. Faza P2-A.

### Braki do rozwiązania w ramach MAMONA-24 (15 pytań)

1–3: Typy tekstów i limity znaków (P0-A2 luki).
4: Implementacja quality_check_auto_repair_decision().
5: Implementacja promote_article_draft_to_post().
6: Logowanie wywołań Gemini poza gemini_quota_events.
7: Szczegóły generation_batch_worker.php — kolejność przetwarzania itemów.
8: Cache gemini_call_cache — TTL/expiracja (O5).
9: Definicja stałej QUALITY_PASS_SCORE.
10: Pełna funkcja quality_check_schema().
11: render_article_image_record($image, true) dla hero.
12: validate_article_blocks().
13: Dedykowana funkcja resetu wadliwego artykułu (P2-G).
14–15: Warunki assert_post_quality_allows_publication() i post_legacy_publication_flag() → is_published.

---

## 3. Zmienione pliki — MECHANICAL_FINALIZATION P1 completed

- docs/IMAGE_PIPELINE_MAP.md: dodano sekcję "P1-C korekty" z convergence mode, freeze artefaktów, semantic threshold, publication gate + grafiki, backfill starych fallbacków oraz reset CLI tool.
- docs/CONTEXT_INDEX.md: zmieniono najbliższy bezpieczny krok na P2 implementację; dodano open issues P2 (O1-O5). Nie zmieniono ARCHITECTURE.md i DECISIONS.md zgodnie z instrukcją.

---

## 4. Walidacja — MAMONA-24-P1-C specyfikacja zatwierdzona przez użytkownika

Specyfikacja P1-C zatwierdzona. Wszystkie findingi CRITICAL (F2, F4) i HIGH (F3, F6, F10) rozwiązane w dokumencie. Findingi MEDIUM poprawnie przeniesione do open issues P2 z przypisanymi fazami O1-O5 oraz 12 nierozstrzygniętych pytań z P0 pozostających otwartych i będących zakresu P2.

---

## 5. Ryzyka — wszystkie mitigowane, żadne blokujące

R1-R9: Wszystkie ryzyka dokumentowane z konkretną mitigacją. Żadne nie jest blokujące dla fazy P2; open issues O1-O5 są planem działania na P2-A/G/D/B odpowiednio.

---

## 6. Następną fazę — MECHANICAL_FINALIZATION P1 completed, awaiting user acceptance for P2 launch

P2 — IMPLEMENTATION AND TESTING
**Agent:** mamona-coder  
**Model:** qwen3.6:27b/balanced (implementacja), deep dla trudnych decyzji w ramach testów  
Edycja kodu dozwolona; publikacje i płatne API zabronione bez zgody użytkownika.

### Cel P2
Zaimplementować wszystkie zmiany specyfikacji P1-C z priorytetem CRITICAL, HIGH, MEDIUM zgodnie z tabelą plików/symboli w CHECKPOINT_P1 oraz test matrix T1-T25.

### Priorytetyzacja zmian P2-A–G

P2-A (CRITICAL/HIGH): gemini-quota-service.php threshold 15→20, generation-batch-service.php salvage→manual_review, convergence_active propagacja w dispatch loop i downstream funkcjach, semantic gate w select_source_image_from_results(), backfill is_fallback migracja SQLite.
P2-B (HIGH): NarrativePlan czytanie ze schema zamiast hardcodu 7 sekcji; batch stage vs pod-etap research/draft decyzja O3.
P2-C (HIGH): quality_check_auto_repair_decision() parametr convergenceActive, nie obniża thresholdów w mode zbieżności.
P2-D (CRITICAL/HIGH): render_article_image_record() sprawdzenie is_fallback przed renderowaniem; assert_post_quality_allows_publication() dwa warunki grafik + no_fallback_images brama.
P2-E (HIGH): Stały limit znaków informational/problem_discovery_return max 7000 (+2000).
P2-G (HIGH): CLI reset wadliwych artykułów --dry-run/--apply; definicja artefaktów pochodnych DELETE/UPDATE SQL dla każdej tabeli O2.

### Testy P2 — uruchomienie przed publikacją i po implementacji

- `tests/article-image-pipeline-smoke.php`: T13 (brak hero/grafik), T14 (placeholder/fallback odrzucenie).
- `tests/post-renderer-smoke.php`: walidacja render_article_image_record() is_fallback check.
- `tests/generate-all-regression.php`: pełna regresja po P2-A–E implementacji.
- Nowe testy T3, T4, T7, T8, T9, T10, T15, T16, T17, T18, T19, T20, T21, T22, T23, T24, T25 po implementacji.
- `tests/editorial-pipeline-e2e.php`: tylko po sprawdzeniu wymaganych flag i bezpiecznej ścieżki; publikacja zabroniona bez akceptacji użytkownika.

### Minimalne komendy walidacyjne P2

```powershell
C:\xampp\php\php.exe tests\article-image-pipeline-smoke.php
C:\xampp\php\php.exe tests\post-renderer-smoke.php
C:\xampp\php\php.exe tests\generate-all-regression.php
# Po implementacji T3-T10, T15-T25:
C:\xampp\php\php.exe bin/reset-invalid-article.php --dry-run  # walidacja CLI resetu
```

### Ryzyka P2 — dodatkowe mitigacje

R7 (cache bez TTL): weryfikacja polityki expiracji gemini_call_cache; dodanie TTL jeśli brak.
O1: test mock transport z flagą bypass, budżet nie inkrementowany przy cache hit i bypassie.
O5: audyt logów pod kątem przestarzałych odpowiedzi cache w nowych przebiegach generacji.

---

## 7. Jedna następna faza — P2 await user acceptance

Po akceptacji CHECKPOINT_P1 przez użytkownika uruchomić `mamona-coder` do implementacji zmian specyfikacji P1-C:
- Konwersja limitu tematycznego 15→centralny budżet 20 z convergence mode od wywołania 16.
- Implementacja NarrativePlan generowania przed draftem i zamrożenia artefaktów w convergence mode.
- Dodanie bramki semantycznej/redakcyjnej po walidacji praw do odrzucania satyry, polityków niezwiązanych z tematem, zombie/gore/memy na threshold 60 (kalibracja P2-D).
- Rozszerzenie publication gate o brak fallbacków i minimalną liczbę grafik.
- Migracja is_fallback=1 dla starych fallbacków w tej samej transakcji co ADD COLUMN; dry-run manifest, backup SHA-256.
- Implementacja CLI resetu wadliwych artykułów z --dry-run/--apply, zachowaniem seed/historii i czyszczeniem artefaktów pochodnych.

---

## 8. Czy wymagana jest akceptacja użytkownika — TAK

Wymagana akceptacja CHECKPOINT_P1 przed uruchomieniem P2:
- Użytkownik potwierdza że specyfikacja P1-C jest kompletna i poprawna (wszystkie CRITICAL/HIGH rozwiązane, MEDIUM przeniesione do O1-O5).
- Użytkownik zgadza się na przejście z fazy RESEARCH/SPEC do IMPLEMENTATION/TESTING.
- Potwierdzenie akceptacji uruchamia P2; brak akceptacji blokuje kodowanie i publikacje.

---

## 9. Format raportu po fazie — CHECKPOINT_P1 zatwierdzony przez użytkownika

Po akceptacji:
1. P1 zakończone, APPROVED — oczekiwanie na CHECKPOINT_P1 i akceptację przed P2 (obecny stan).
2. Potwierdzone ustalenia: wszystkie root cause RC1-RC6 rozwiązane; decyzje A1-A12 zatwierdzone; kontrakty GeminiBudget/NarrativePlan/GenerationState/QcReport/VisualSlot/SupplementalTopic specyfikowane i gotowe do implementacji.
3. Zmienione pliki: IMAGE_PIPELINE_MAP.md, CONTEXT_INDEX.md (ARCHITECTURE.md i DECISIONS.md nie zmieniane).
4. Walidacja: P1-C zatwierdzona przez użytkownika; wszystkie findingi CRITICAL/HIGH rozwiązane w specyfikacji.
5. Ryzyka R1-R9 dokumentowane z mitigacją, żadne blokujące dla P2.
6. Następną fazę: P2 — IMPLEMENTATION AND TESTING (mamona-coder/qwen3.6:27b/balanced).
7. Akceptacja użytkownika uruchamia P2; brak akceptacji blokuje kodowanie i publikacje.

---

**AKCEPTUJĘ P1 — URUCHOM P2**
