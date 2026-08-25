# MVP Pipeline — P10 E2E Regression Matrix

Status: IN_PROGRESS — REAL_PROOF_TRACE_STOP

## Verified controlled-test evidence

- P08/P09 targeted D1–D7 evidence passed on disposable databases and controlled/injected transports.
- Tests were non-live; Gemini calls executed by them: `0`.

## Single approved real-proof execution and controlled resume

- The only approved fresh proof is batch `#27` for topic `#261` / post `#287`; no second article or batch was started.
- Its initial execution stopped at the first product failure with budget `2/20`:
  - research operation `#349`: completed, `live_request_count=1`;
  - narrative-plan operation `#350`: completed, `live_request_count=1`.
- Research package `#40` was approved.
- The canonical consumer mismatch was then repaired and batch `#27` alone received one controlled resume, prechecked at `2/20`.
- The resume made exactly three additional live calls, in order: `article_draft`, `field_text_repair`, `article_draft`.
- Total Gemini budget is `5/20`.
- No publication was requested or performed. Post `#287` remains `idea` and unpublished.

## Failure trace

- Initial stage: draft at `45%`, `non_retryable_provider_error`: `VisualPlan nie zawiera slotu do zablokowania w schemacie szkicu.`
- The repair addressed that producer–consumer mismatch: the canonical consumer now accepts the persisted NarrativePlan.
- On the controlled resume, draft version `#88` was persisted, then draft operation `#356` stopped with `validation_contract`: `Szkic zmienił wymagany hero VisualPlan: slot_id.`
- The model-generated illustration plan omitted canonical required `slot_id` for the hero, using a legacy hero representation. The assertion rejected it correctly.
- Audit rows `#432`–`#437` are the trace evidence.
- No text QC, images, P08 LayoutPlan or P09 final QC was reached.

## Contract/result

The real proof remains intentionally limited to one fresh item. It now demonstrates a model-output schema mismatch after the persisted-plan canonical consumer was repaired. The evidence is not a complete P10 proof; P10 remains `[~]`.

No second real article is authorized. Do not resume batch `#27` or mutate the post to manufacture a pass.

## Test commands recorded before the real proof

- `php -l php/article-image-service.php`
- `php -l php/generation-batch-service.php`
- `php -l tests/p8-layout-plan-smoke.php`
- `php -l tests/generation-batch-smoke.php`
- `php tests/p8-layout-plan-smoke.php` — PASS, 11 assertions.
- `php tests/p4-image-coverage-smoke.php` — PASS, 14 assertions.
- `$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php` — PASS.

## Known risks / blocker

- The prompt/output schema must preserve canonical required VisualPlan fields in the model-generated illustration plan, or a deterministic adapter must canonicalize it before the assertion.
- Add a controlled regression for the missing-`slot_id` hero output before any future real proof.
- The user specification requires trace and STOP after the first real failure; no new real provider call may be made without fresh direction.
- Publication remains manual and prohibited in this proof.

## Next exact action

After user direction, fix only the model-generated illustration-plan schema/adapter failure, add a controlled regression, revalidate the mock path, then request a new explicit real-run authorization.
