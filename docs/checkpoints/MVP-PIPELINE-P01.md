# MVP Pipeline — P01

Status: PASS

Changed files:

- `php/gemini-quota-service.php`
- `php/generation-service.php`
- `php/article-image-service.php`
- `tests/gemini-quota-smoke.php`
- `tests/p2a-gemini-budget-test.php`
- `tests/p3a-text-limits-and-budget-test.php`

Contract before/after:

- Before: text requests with an injected transport and Vision were accounted separately; the old topic counter imposed incompatible finalizer rules.
- After: every non-cache Gemini response for a post, including controlled retries/transports and Vision, increments `article_generation_budget` exactly once. The shared budget is checked before transport; calls 19–20 require an allowlisted closure-safe operation and call 21 cannot reach transport.

Tests:

- `php -l php/gemini-quota-service.php`
- `php -l php/generation-service.php`
- `php -l php/article-image-service.php`
- `php tests/p2a-gemini-budget-test.php` — PASS (114 assertions)
- `php tests/p3a-text-limits-and-budget-test.php` — PASS (34 assertions)
- `php tests/gemini-quota-smoke.php` — PASS
- `php tests/p3c-vision-gate-test.php` — PASS (73 assertions)

Gemini calls executed by tests: 0 live; all provider responses used controlled transports or built-in mocks.

Known risks: closure-safe operation names introduced by later Program P tasks must be added deliberately to `gemini_article_budget_is_closure_safe()` rather than bypassing the gate.

First remaining blocker: none.

Next exact task: P02 — establish the shared NarrativePlan + VisualPlan contract before core-text generation.
