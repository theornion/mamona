# MVP Pipeline P06 — Image Shortage Recovery + Related Images

Status: COMPLETE

## Contract before / after

Direct acquisition leaves an unfilled slot as `missing`; it does not use a technical fallback. P06 adds a separate recovery input built only after incomplete coverage and only for a frozen core draft. The input carries the locked-core fingerprint, missing slots, verified research source map, NarrativePlan expansion modules, bounded provider-search shortlists and remaining Gemini budget.

Recovery decisions are persisted through `generation_operations` as `image_recovery`. The standard generation executor owns Gemini accounting. A selected candidate must match a persisted stable provider ID and source-file URL, use an allowed related relationship, cite a source page and select an existing expansion module. A weak hero recovery remains manual review.

## Changed files

- `php/article-image-service.php`
- `php/generation-batch-service.php`
- `tests/p6-image-recovery-smoke.php`
- `docs/CURRENT_WORK.md`
- `docs/checkpoints/MVP-PIPELINE-P06.md`

## Verification

- `php -l php/article-image-service.php` — PASS
- `php -l php/generation-batch-service.php` — PASS
- `php -l tests/p6-image-recovery-smoke.php` — PASS
- `php tests/p6-image-recovery-smoke.php` — PASS
- `$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php` — PASS
- `$env:CMS_ALLOW_ARTICLE_IMAGE_SMOKE='1'; php tests/article-image-pipeline-smoke.php` — PASS

Gemini calls executed by tests: 0. Provider search and all generation paths used fixtures/mocks.

## Risks / next task

P06 records and validates the recovery decision, but does not itself make a related image count toward coverage. P07 must materialize only approved candidates with a source-backed additive block, revalidate them, and set `related_supported` only after that pass. Next task: P07.
