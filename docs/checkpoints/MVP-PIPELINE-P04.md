# MVP Pipeline P04 — Image Coverage State + required hero

Status: COMPLETE

## Contract before / after

Before P04, batch readiness compared image row totals and publication counted usable files. Neither path matched individual required VisualPlan slots, required hero, or the plan's post/topic association.

After P04, `article_image_coverage_state()` derives `required_slots`, `filled_slots`, `missing_slots`, `hero_status`, and `coverage_complete` from the persisted VisualPlan. A filled slot must be `direct_ok`, or an allowed `related_supported` asset with source-backed multimodal evidence. A hero must be `direct_ok`; fallback assets never satisfy coverage. Publication reads the plan through post + topic association and requires the frozen core lock and complete coverage.

## Changed files

- `php/narrative-plan-service.php`
- `php/quality-check-service.php`
- `php/generation-batch-service.php`
- `tests/p4-image-coverage-smoke.php`
- `tests/p3c-publication-behavior-test.php`
- `docs/CURRENT_WORK.md`

## Verification

- `php -l php/quality-check-service.php`
- `php -l php/narrative-plan-service.php`
- `php -l php/generation-batch-service.php`
- `php -l tests/p3c-publication-behavior-test.php`
- `php -l tests/p4-image-coverage-smoke.php`
- `php tests/p4-image-coverage-smoke.php` — PASS (7)
- `php tests/p3c-publication-behavior-test.php` — PASS (10)
- `php tests/p3-core-text-lock-smoke.php` — PASS
- `$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php` — PASS

Gemini calls executed by tests: 0. All tests use `CMS_TEST_DATABASE_FILE=:memory:` or the controlled batch smoke gate.

## Risks / next task

Related recovery is intentionally not produced by P04. P05 will implement direct acquisition; P06/P07 must set `related_supported` only after the required source-backed additive context and validation. Next task: P05.
