# P3-CHECKPOINT-V4.3 — MAMONA-24

**Data:** 2026-08-08  
**Status:** COMPLETE  

## Podsumowanie checkpointu

Mechaniczny zapis bez nowego researchu/decyzji/testów. Wszystkie fazy P3-A/B/C/D oznaczone jako COMPLETE, final regression PASS, final review PASS. CHECKPOINT_P3 saved. P4 NOT STARTED.

---

## Stan faz P3

### P3-A: text limits + Unicode handling — 148 PASS
- mb_strlen Unicode poprawiony; central GeminiBudget 20; convergence od 16; call20 allowed/call21 blocked bez mutacji.
- fix `php/gemini-quota-service.php`.

### P3-B: NarrativePlan + VisualSlot — 92 PASS
- differentiated structure, freeze, convergence, VisualSlot hero/inline/max5/supplemental.
- fix `php/repair-router-service.php` warning.

### P3-C: image/QC/publication/UTF-8 — COMPLETE
- production actual-image Gemini Vision adapter w `php/article-image-service.php`;
- actual bytes przez inlineData, article/section/VisualSlot/visual_intent/expected_content/provider metadata context;
- structured semantic_relevance/editorial_fit/depicts_required_subject/misleading/inappropriate/decision/reason;
- token/metadata score tylko cheap prefilter; BAD_OBVIOUS może odpaść wcześnie; BAD_METADATA_PLAUSIBLE przechodzi prefilter i mock Vision REJECT;
- 3 domeny: neuroscience, astronomy, biology; bez blacklist i bez overfitu;
- Vision używa tego samego central GeminiBudget, call21 blocked przed transport;
- multimodal assessment + accepted persistowane przez schema migration;
- publication wymaga technical/legal + physical asset + multimodal ACCEPT; brak ACCEPT, fallback, placeholder/missing, editorial_rejected, missing asset, manual_review blokują;
- renderer/gallery behavioral gates;
- aktywne helpers `source_image_candidate_is_suitable_for_role` i `source_image_candidate_matches_query` oczyszczone z iconv/ASCII; Unicode mb_/regex/tokenizer; polski/mixed fixtures.
- wyniki: p3c vision 73 PASS/0 FAIL; publication behavior 10/0; QC/renderer 86/0; article image smoke, post renderer smoke, quality smoke PASS; UTF tokenizer + mojibake scan PASS.

### P3-D: audit/reset invalid articles — COMPLETE
- `php/cli-reset-invalid-articles.php` + `tests/p3d-audit-reset-test.php`;
- fixture/in-memory DB, dry-run no mutation, manifest/context, detection all invalid image/publication states, good article absent, backup path+SHA, preserved input/seed/context/history, derived cleanup incl multimodal/render, unpublish before cleanup, safe paused/no-auto state, idempotence;
- production `--apply` fail-closed guard; test did not touch production; targeted P3-D test PASS (review potwierdził 38 assertions).

---

## Final regression: all suites PASS

- P3-A and P3-B targeted PASS;
- all P3-C suites PASS;
- P3-D + UTF PASS;
- targeted lints PASS; jeden broad local lint task zwrócił fałszywy environment failure, rozstrzygnięty targeted lintem i diagnoserem; nie jest code failure.

---

## Final review: OVERALL PASS

- initial PASS, follow-up wykrył active iconv BLOCKER; worker naprawił; targeted retest PASS 3/3 + image/UTF regression PASS; ponowny mamona-reviewer OVERALL PASS.

---

## Open issues / risks

- working tree zawiera wiele niezwiązanych zmian V4/config/docs oraz untracked helper `tests/run-p3d.bat`; nie commitowano/pushowano; P4 NOT STARTED.
- pozostałe iconv poza image helper call chain są poza P3 scope, bez uznawania ich za blocker.

---

## Git diff --stat (tracked)

23 files changed, 890 insertions(+), 1130 deletions(-). Untracked P3 tests/checkpoint files nie są ujęte przez zwykły diff stat.

---

## Production fixes

- app-config
- article-image-service
- editorial-schema
- gemini-quota-service
- quality-check-service
- repair-router-service
- cli-reset-invalid-articles

---

## Test fixes / new tests

- article-image-pipeline-smoke
- p2a budget
- quality-check-smoke
- p3a
- p3b
- p3c vision/publication/QC-renderer
- p3d audit-reset
- utf8 tokenizer

---

## P4 scope (NOT STARTED)

read-only/implementation review i dalsza faza zgodnie z canonical, ale NIE uruchamiaj P4; wymaga osobnej decyzji/startu po tym checkpointcie.
