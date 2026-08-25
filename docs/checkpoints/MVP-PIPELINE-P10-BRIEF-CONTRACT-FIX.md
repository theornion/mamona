# P10 — Minimal brief contract fix

Status: COMPLETE (local only)

- Contract changed from exactly one completed sentence to one or two completed sentences; each must end with `.`, `!`, or `?`.
- The canonical validator and draft prompt now use the same rule. No automatic shortening or Gemini repair was added.
- `tests/brief-contract-smoke.php` covers one sentence, two sentences, the 186-character draft #88 fixture, three sentences, an unterminated brief, and zero Gemini calls.
- Validation: PHP lint and `CMS_ALLOW_BRIEF_CONTRACT_SMOKE=1 php tests/brief-contract-smoke.php` PASS.
- `CMS_ALLOW_ARTICLE_DRAFT_SMOKE=1 php tests/article-draft-smoke.php` remains FAIL before draft validation at its pre-existing prompt-policy assertion: `Prompt prostego szkicu nie zawiera języka oraz kompletnej polityki długości i jakości.` It is outside this minimal brief-only scope.
- No live Gemini/Vision call, no mutation of post #287, and no resume occurred.
- Next: cheap resume #287, only with a separate explicit approval.
