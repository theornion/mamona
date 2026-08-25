# MVP Pipeline — P08 LayoutPlan

Status: COMPLETE

Changed files:

- `php/article-image-service.php`
- `tests/p8-layout-plan-smoke.php`
- `tests/editorial-schema-smoke.php`
- `docs/CURRENT_WORK.md`

Contract before/after:

- Before: rendering used one fixed block renderer; the initial LayoutPlan helper did not constrain nested plan fields or drive composition.
- After: `layout_plan` is a strict, allowlisted structured generation operation. PHP maps the plan deterministically, places one approved hero first, emits every approved inline image once, and keeps approved related context blocks adjacent to their anchored section. Arbitrary HTML/CSS is neither a schema field nor rendered.
- Invalid or absent persisted plans resolve to the stable `standard` plan with an `invalid_layout_plan` audit note.

Tests (all PASS, no live providers):

- `php -l php/article-image-service.php`
- `php tests/p8-layout-plan-smoke.php`
- `php tests/editorial-schema-smoke.php`
- `php tests/p4-image-coverage-smoke.php`
- `$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php`

Gemini calls executed by tests: 0. All generation paths used a disposable SQLite database or deterministic fixture data.

Known risks:

- P09 must treat layout output as an input to final QC, but deterministic hero, coverage, source, rights, and core-lock gates remain authoritative.

First remaining blocker: none.

Next exact task: P09 — final multimodal QC and publication gate.
