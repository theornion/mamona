# MVP Pipeline P07 — Additive Expansion Engine

Status: COMPLETE

Related context is now a persisted, source-backed additive artifact. A block requires a frozen core draft, a previously accepted related image, an allowed block type, a stable target slot/module/placement and verified source claim IDs. The standard `additive_module` operation has an immutable target contract; its completed output is persisted only through the same deterministic gate. The original core draft is never replaced.

Verification: PHP lint for changed services; `php tests/p4-image-coverage-smoke.php`; `$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php` — PASS. Gemini calls in tests: 0.

Next task: P08 — allowlisted LayoutPlan and deterministic renderer.
