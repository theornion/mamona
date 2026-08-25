# MVP Pipeline — P03

Status: COMPLETE

Frozen draft versions are the audited lock source. `core_text_lock_state()` exposes lock/version/time/hash; `core_text_operation_allowed()` permits only additive or bounded changes. Full rewrite routing rejects frozen sources.

Changed files: `php/quality-check-service.php`, `php/article-draft-service.php`, `tests/p3-core-text-lock-smoke.php`, `docs/CURRENT_WORK.md`.

Tests: P3 core-lock smoke PASS; quality smoke with `CMS_ALLOW_QUALITY_SMOKE=1` PASS; batch smoke PASS; PHP lint PASS.

Regression: locked core hash remains unchanged through a simulated image-side operation.

Known risk: P06/P07 must consult the additive-operation allowlist.

Next task: P04.
