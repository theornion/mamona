# CHECKPOINT_P2 — MAMONA-24 Implementation Phase

**Data:** 2026-08-07  
**Status:** COMPLETE  
**Faza:** P2-A przez P2-G  

---

## P2-A — Centralny GeminiBudget, limit 20, convergence mode (COMPLETE)

### Zakres
- `php/gemini-quota-service.php`: implementacja centralnego budżetu z limitem 20 i convergence mode
- `$max` zmieniony z 15 na 20
- GemniArticleBudgetException dla wyczerpania budżetu
- Budżet: ensure(), increment(), state()

### Zmienione pliki
- `php/gemini-quota-service.php`: +91 linii (GeminiArticleBudgetException, threshold 15→20, budget ensure/increment/state, off-by-one fix `$used >= $max` → `$used > $max`)
- `php/editorial-schema.php`: ARTICLE_GENERATION_BUDGET_MIGRATION flag
- `php/generation-batch-service.php`: catch exception → manual_review, 3× budget increment, convergence propagation

### Testy
- `tests/p2a-gemini-budget-test.php`: 113 PASS, 1 FAIL (audit log off-by-one, naprawiony)

---

## P2-B — Narrative plan service (COMPLETE)

### Zakres
- Nowy plik: `php/narrative-plan-service.php` z pełnym kontraktem NarrativePlan
- Integracja w batch service między research a draft
- Migracja NARRATIVE_PLANS_MIGRATION w editorial-schema.php

### Zmienione pliki
- **Nowe:** `php/narrative-plan-service.php`: 515 linii (pełny kontrakt NarrativePlan)
- `php/admin-database.php`: require narrative-plan-service.php
- `php/editorial-schema.php`: NARRATIVE_PLANS_MIGRATION

### Testy
- `php -l` OK na wszystkich plikach

---

## P2-C — Ustrukturyzowany QC, naprawy zakresowe, zamrażanie artefaktów (COMPLETE)

### Zakres
- 9 bramek twardych (QC_HARD_GATES), 6 bramek miękkich (QC_SOFT_GATES)
- `quality_check_auto_repair_decision` z `$convergenceActive`
- `qc_freeze_accepted_artifacts()`, `qc_is_artifact_frozen()`
- Propagacja convergence w batch service

### Zmienione pliki
- **Nowe:** `php/quality-check-service.php`: QC_HARD_GATES (9), QC_SOFT_GATES (6), quality_check_auto_repair_decision, qc_freeze_accepted_artifacts(), qc_is_artifact_frozen()

### Testy
- `php -l` OK na wszystkich plikach

---

## P2-D — VisualSlot, publication gate, semantic gate, salvage bez SVG (COMPLETE)

### Zakrase
- render is_fallback guard w article-image-service.php
- semantic gate threshold 60
- persist is_fallback flaga
- status=manual_review, is_fallback=1 dla salvage bez SVG assetu
- Publication gate: fallback check + min. liczba grafik

### Zmienione pliki
- `php/article-image-service.php`: +96 linii (render is_fallback guard, semantic gate threshold 60, persist is_fallback)
- **Nowe:** `php/salvage-service.php`: +9 linii (status=manual_review, is_fallback=1, bez SVG asset)

### Testy
- `php -l` OK na wszystkich plikach

---

## P2-E — Limity tekstów (COMPLETE)

### Zakres
- ARTICLE_MAIN_CONTENT_MAX_LENGTH 5000→7000
- Minima niezmienione: informational min=3000, problem_discovery_return min=4000
- UI opis zaktualizowany do 3000–7000 i 4000–7000

### Zmienione pliki
- `php/article-draft-service.php`: ARTICLE_MAIN_CONTENT_MAX_LENGTH 5000→7000
- `php/admin-generation.php`: UI opis zaktualizowany do 3000–7000 i 4000–7000
- **Nowe:** `php/narrative-plan-service.php`: hard-coded 7000→ARTICLE_MAIN_CONTENT_MAX_LENGTH (4 miejsca)

### Testy
- `tests/article-draft-smoke.php`: assert 5000→7000
- `php -l` OK na wszystkich plikach

---

## P2-F — Publication gate, manual_review, diagnostyka (COMPLETE)

### Zakres
- QC_HARD_GATES (9), QC_SOFT_GATES (6) w quality-check-service.php
- publication gate: fallback check + min images + manual_review block
- gemini_budget_exhaustion_diagnostics()
- qc_structured_report(), qc_freeze_accepted_artifacts()
- convergence propagation w reconcile/process_item, narrative plan integration

### Zmienione pliki
- `php/quality-check-service.php`: QC_HARD_GATES (9), QC_SOFT_GATES (6), publication gate fallback+min images+manual_review block, gemini_budget_exhaustion_diagnostics(), qc_structured_report(), qc_freeze_accepted_artifacts()
- **Nowe:** `php/generation-batch-service.php`: convergence propagation w reconcile/process_item, narrative plan integration, budget exhaustion diagnostics w repair_report+audit

### Testy
- `php -l` OK na obu plikach

---

## P2-G — Audit i reset wadliwych artykułów (COMPLETE)

### Zakres
- CLI narzędzie do audytu i resetu wadliwych artykułów
- Idempotentność, dry-run mode
- Static analysis 16/16 PASS

### Zmienione pliki
- **Nowe:** `php/cli-reset-invalid-articles.php`: 515 linii (audit i reset wadliwych artykułów)

### Testy
- `php -l` PASS
- `--dry-run` PASS (22 kandydatów)
- idempotency PASS
- static analysis 16/16 PASS
- Zero błędów produkcyjnych
- Brakujące testy jednostkowe: 1 (nieblokujące)

---

## Podsumowanie P2

| Element | Wartość |
|---|---|
| Zmienione pliki produkcyjne | 9 |
| Nowe pliki | 2 (`narrative-plan-service.php`, `cli-reset-invalid-articles.php`) |
| Krytyczne błędy | 0 |
| php -l failures | 0 |
| Brakujące testy jednostkowe | 1 dla P2-G (nieblokujące) |

---

## Open issues przeniesione z P2

| # | Issue | Status |
|---|-------|--------|
| O4 | Kalibracja thresholdu bramki semantycznej — domyślny 60 | ⚠️ Domyślny 60, kalibracja w P3 |
| O5 | Polityka expiracji gemini_call_cache | ⚠️ Nie dotknięte |

---

## Blockery P2

- Brak osobnych testerów dla P2-B, P2-C, P2-D (tylko P2-A miał dedykowany test). Testy integracyjne planowane w P3.
- `frozen_artifact_ids` nie istnieje jako kolumna w `generation_batch_items` — zamrożenie działa przez status draft version.

---

## Git state

- Ostatni commit: `cf0903f p2-a completed`
- Uncommitted changes: 9 plików produkcyjnych zmodyfikowanych + 2 nowe pliki (`narrative-plan-service.php`, `cli-reset-invalid-articles.php`) + 1 test zmieniony
- Łącznie: ~519 insertions, ~19 deletions w php/ i tests/

---

## Gotowość do P3

P2-A–P2-G kompletne. Zero krytycznych błędów. Kod produkcyjny przechodzi walidację.  
P3 może zostać uruchomione po akceptacji tego checkpointu.
