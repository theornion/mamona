# MVP Pipeline — P02

Status: PASS

Changed files:

- `php/editorial-schema.php`
- `php/narrative-plan-service.php`
- `php/article-image-service.php`
- `php/generation-batch-service.php`
- `tests/p3b-narrative-freeze-visualslot-test.php`
- `docs/CURRENT_WORK.md`

Contract before/after:

- Before: NarrativePlan stored only a scalar image count and legacy plan fields.
- After: NarrativePlan requires and persists `visual_plan` with exactly one required direct hero, typed inline slots, direct/related query separation and `expansion_modules`; the draft schema consumes persisted inline anchors while retaining the established illustration-plan interface.

Tests:

- `php -l php/narrative-plan-service.php` — PASS
- `php -l php/editorial-schema.php` — PASS
- `php tests/p3b-narrative-freeze-visualslot-test.php` — PASS
- `CMS_ALLOW_BATCH_SMOKE=1 php tests/generation-batch-smoke.php` — PASS

Gemini calls executed by tests: 0 live; mocks/controlled transports only.

Known risks: P06 must make use of `acceptable_related` and related queries; P02 deliberately does not implement related-image recovery.

First remaining blocker: none.

Next exact task: P03 — Core Text Lock.
