# MAMONA-24 — P2 Handoff (po P2-D)

> Utworzony: 2026-08-07 przez orchestratora jako handoff po zakończeniu P2-A–P2-D.
> Nie powtarzaj P2-A–P2-D. Kontynuuj od P2-E.

---

## Status faz P2

| Faza | Zakres | Coder | Tester | Status |
|------|--------|-------|--------|--------|
| P2-A | Centralny GeminiBudget, limit 20, convergence mode | COMPLETE | COMPLETE (113 PASS, 1 FAIL audit log) | ✅ ZAKOŃCZONY |
| P2-B | NarrativePlan, zróżnicowana struktura, dispatch integration | COMPLETE (recovery + dispatch fix) | NIE URUCHOMIONY osobno | ✅ ZAKOŃCZONY |
| P2-C | Ustrukturyzowany QC, naprawy zakresowe, zamrażanie artefaktów | COMPLETE | NIE URUCHOMIONY osobno | ✅ ZAKOŃCZONY |
| P2-D | VisualSlot, publication gate, semantic gate, salvage bez SVG | COMPLETE | NIE URUCHOMIONY osobno | ✅ ZAKOŃCZONY |

---

## Raporty coderów

### P2-A Coder (recovery)
- Status: COMPLETE
- Zmiany odziedziczone: GeminiArticleBudgetException, threshold 15→20, budget ensure/increment/state, migracja ARTICLE_GENERATION_BUDGET_MIGRATION, catch exception → manual_review
- Zmiany recovery: 3× budget increment w dispatch loop, convergence propagation, repair_router_assess z $convergenceActive
- Testy: php -l OK

### P2-A Tester
- Status: COMPLETE
- Wyniki: 113 PASS, 1 FAIL (calls_log_json 21 vs 20 — audit log poprawny)
- Krytyczny bug wykryty: off-by-one `$used >= $max` → naprawiony przez orchestratora na `$used > $max`

### P2-B Coder (recovery + dispatch fix)
- Status: COMPLETE
- narrative-plan-service.php: 515 linii, pełny kontrakt NarrativePlan
- Dispatch integration: etap narrative_plan między research a draft
- Migracja NARRATIVE_PLANS_MIGRATION

### P2-C Coder
- Status: COMPLETE
- QC_HARD_GATES (9 bramek), QC_SOFT_GATES (6 bramek)
- quality_check_auto_repair_decision z $convergenceActive
- qc_freeze_accepted_artifacts(), qc_is_artifact_frozen()
- Propagacja convergence w batch service

### P2-D Coder
- Status: COMPLETE
- Publication gate: fallback check + min. liczba grafik
- Renderer: is_fallback guard (early return)
- Semantic gate: threshold 60, negatywne sygnały
- Salvage: status='manual_review', is_fallback=1, bez SVG jako asset

---

## Zmienione pliki

| Plik | Zmiany | Faza |
|------|--------|------|
| php/gemini-quota-service.php | +91 linii: GeminiArticleBudgetException, budget 15→20, ensure/increment/state, off-by-one fix | P2-A |
| php/editorial-schema.php | +56 linii: ARTICLE_GENERATION_BUDGET_MIGRATION + NARRATIVE_PLANS_MIGRATION | P2-A/B |
| php/generation-batch-service.php | +74 linii: catch exception → manual_review, 3× budget increment, convergence propagation, narrative_plan dispatch, QC convergence freeze | P2-A/B/C |
| php/repair-router-service.php | +10 linii: repair_router_assess z $convergenceActive, rewrite→targeted_repair | P2-A |
| php/narrative-plan-service.php | NOWY, 515 linii: pełny kontrakt NarrativePlan | P2-B |
| php/admin-database.php | +1 linia: require narrative-plan-service.php | P2-B |
| php/quality-check-service.php | +424 linii: QC_HARD_GATES/QC_SOFT_GATES, convergence decision, freeze artifacts, publication gate fallback+min images | P2-C/D |
| php/article-image-service.php | +96 linii: render is_fallback guard, semantic gate threshold 60, persist is_fallback | P2-D |
| php/salvage-service.php | +9 linii: status=manual_review, is_fallback=1, bez SVG asset | P2-D |

---

## Wykonane testy

| Test | Wynik |
|------|-------|
| php -l × wszystkie zmienione pliki | OK — zero błędów składni |
| p2a-gemini-budget-test.php | 113 PASS, 1 FAIL (audit log) |
| Off-by-one fix verification | Call 20 dozwolony, call 21 odrzucony |

---

## Poprawki po testach

1. **Off-by-one bug P2-A:** `$used >= $max` → `$used > $max` w gemini-quota-service.php L285. Naprawiony przez orchestratora po raporcie testerów.

---

## Open issues

| # | Issue | Faza | Status |
|---|-------|------|--------|
| O1 | Mock transport i budżet w testach — flaga gemini_mock_budget_bypass | P2-A | ✅ Rozwiązany |
| O3 | NarrativePlan i generation_batch_items.stage — nowy stage vs. pod-etap | P2-B | ✅ Rozwiązany |
| O4 | Kalibracja thresholdu bramki semantycznej — domyślny 60 | P2-D | ⚠️ Domyślny 60, kalibracja w P3 |
| O5 | Polityka expiracji gemini_call_cache | P2-A | ⚠️ Nie dotknięte |

---

## Blockery

- Brak osobnych testerów dla P2-B, P2-C, P2-D (tylko P2-A miał dedykowany test). Testy integracyjne planowane w P3.
- `frozen_artifact_ids` nie istnieje jako kolumna w `generation_batch_items` — zamrożenie działa przez status draft version.

---

## Następny krok: P2-E

**Zakres P2-E:** Aktualizacja konfiguracji wszystkich typów tekstu — maksimum +2000 znaków.
- informational: 5000 → 7000
- problem_discovery_return: 5000 → 7000
- Plik: php/article-draft-service.php L14-16 (stałe max)

**Zakres pozostały:**
- P2-E: limity znaków +2000
- P2-F: diagnostyka i bezpieczny manual_review po wyczerpaniu budżetu
- P2-G: narzędzie audytu/resetu wadliwych artykułów (--dry-run/--apply)

**ZAKAZ:** Nie powtarzaj P2-A, P2-B, P2-C, P2-D. Te fazy są zakończone.
