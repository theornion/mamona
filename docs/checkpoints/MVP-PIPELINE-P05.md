# MVP Pipeline P05 — Direct Image Acquisition

Status: COMPLETE

## Contract before / after

The acquisition loop previously used the full semantic cascade, which included related-context queries during the initial image attempt. P05 adds a direct-only query set and makes it the default for `fulfill_article_source_images()`; related querying is now reserved for an explicit later recovery mode.

Each candidate still passes deterministic rights, technical and metadata gates before Vision. The production Vision adapter downloads and embeds actual image bytes, uses one central Gemini-budget admission/accounting event per provider response, and rejects request 21 before transport. A rejected direct candidate is followed by the next bounded candidate; exhausted candidates persist `missing`, never an automatic fallback.

## Changed files

- `php/article-image-service.php`
- `tests/article-image-pipeline-smoke.php`
- `docs/CURRENT_WORK.md`

## Verification

- `php -l php/article-image-service.php` — PASS
- `php -l tests/article-image-pipeline-smoke.php` — PASS
- `$env:CMS_ALLOW_ARTICLE_IMAGE_SMOKE='1'; php tests/article-image-pipeline-smoke.php` — PASS
- `php tests/p3c-vision-gate-test.php` — PASS (73)
- `$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php` — PASS

Gemini calls executed by tests: 0; controlled transports prove production payload/budget behavior without provider access.

## Risks / next task

P06 must opt into related queries only after direct coverage is incomplete, and must preserve the frozen core text while requiring source-backed recovery justification. Next task: P06.
