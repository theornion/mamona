# MVP Image Recovery Fix

Status: COMPLETE (bounded recovery fix; P10 remains in progress)

## Contract after the fix

- P06 classifies every required visual slot as `RECOVERABLE`, `UNRECOVERABLE`, or `ALREADY_COMPLETE`. Only `RECOVERABLE` slots enter planner input, so one missing hero cannot prevent recovery of a supported inline slot.
- Hero remains direct-first. After documented direct exhaustion, P06 may derive a controlled related-hero query from a source-backed expansion module. It still requires legal media, source-backed editorial context, Vision validation, and final multimodal QC; it never treats a technical fallback as hero coverage.
- `role_or_quality` is restricted to hard technical disqualifiers. Semantic relevance ranks candidates rather than rejecting them before Vision. Direct and related Vision shortlists are bounded to three candidates per missing slot.

## Validation

- `php tests/p6-image-recovery-smoke.php` — PASS, 18 assertions, including P07 `related_supported` transition.
- `php tests/p6-pretransport-smoke.php` — PASS.
- `php tests/article-image-pipeline-smoke.php` — PASS.
- `php tests/p10-related-hero-recovery-smoke.php` — PASS.

No live Gemini/Vision transport, production database mutation, publication, or real proof was performed.

## Changed scope

The bounded implementation and test changes are in the image recovery pipeline and its fixtures. This checkpoint records the verified behavior only; it does not mark P10 complete.

## Remaining work

- Current active task: `P10 / FINAL_QC_BATCH_GATE [~]`.
- Next exact action: inspect and resume the intentionally interrupted final-QC batch gate, then run the authentic related-hero E2E on disposable fixtures.
- A real proof remains forbidden and untouched.
