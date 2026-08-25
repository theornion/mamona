# P4-CHECKPOINT-V4.6.1 — MAMONA-24

**Data:** 2026-08-09  
**Status:** COMPLETE — P5 mutation gate pending user approval

## Completed scope

- Started P4 from the user's explicit V4.6.1 resume command.
- Ran the complete offline P3 regression suite after inspecting the existing P3 checkpoint.
- Repaired the P3-D fixture/schema contract:
  - migration `20260809_044_editorial_topics_brief_type_language` adds `editorial_topics.brief`, `type`, and `language` idempotently;
  - migration `20260809_045_generation_batch_item_context` adds `generation_batch_items.input` and `settings` idempotently;
  - corrected fixture use of `event_at` and `search_queries_json` to match the actual schema;
  - reset uses a deterministic `reset-<article_id>` slug, avoiding the real `posts.slug UNIQUE` collision when multiple posts are reset.

## Evidence and tests

- Initial P3-D failure: `editorial_topics` lacked `brief`; later fixture/schema mismatches exposed `event_at`, `input`, `settings`, and `search_queries_json` assumptions.
- Confirmed production failure: `apply_reset()` attempted `slug = ''` for multiple posts and hit `UNIQUE constraint failed: posts.slug`.
- `C:/xampp/php/php.exe -l php/editorial-schema.php` — PASS.
- `C:/xampp/php/php.exe -l php/cli-reset-invalid-articles.php` — PASS.
- `C:/xampp/php/php.exe -l tests/p3d-audit-reset-test.php` — PASS.
- Full offline P3-A/P3-B/P3-C/P3-D/UTF suite — PASS; P3-D reports 51 PASS / 0 FAIL.

## Files changed in this cycle

- `php/editorial-schema.php`
- `php/cli-reset-invalid-articles.php`
- `tests/p3d-audit-reset-test.php`
- `docs/CURRENT_WORK.md`
- `docs/research/MAMONA-24-CHECKPOINT-P4.md`

## Review

- No confirmed new P4 finding remains after the evidence-backed P3-D repair and final regression.

## Hard stop / next step

P5 may run a deterministic `--dry-run` audit and review its manifest. Any real-data `--apply` reset is blocked pending explicit user approval of candidate identifiers/counts, reasons, public statuses, cleanup scope, and backup location. No provider calls, publication, commit, push, or production mutation were performed.
