# Anchored Summary — P3 Progress

## Goal
P3 — Deterministic integration tests (Text Limits, Gemini Budget, Narrative Plan, Freeze, VisualSlot, QC, Publication gates, UTF-8). Current: P3-C classification phase before targeted fixes.

## Constraints & Preferences
- Orchestrator never writes files directly; delegates to child sessions via Task tool
- Production is read-only except for tests/** and .kilo/results/** directories
- Direct Target Mode for all coder tasks (minimum fix, no refactoring)
- Test failures classified as TEST_BUG / PRODUCTION_BUG / CONTRACT_AMBIGUITY before fixing
- UTF-8 mojibake must be absent in prompts, JSON, SQLite fixtures, renderer output

## Progress
### Done
- P3-A COMPLETE: 148 PASS after fix to gemini-quota-service.php (call 21 blocked without mutation)
- P3-B COMPLETE: 92 PASS; Array-to-string warning fixed in repair-router-service.php line ~25
- P3-C initial run identified FAILs but no result file created
### In Progress
- Classifying P3-C failures F5, I2, J2 before fixing (TEST_BUG vs PRODUCTION_BUG)
- Reading article_image_semantic_gate_score() contract in php/article-image-service.php:787
- Verifying list_posts() bootstrap require chain for deterministic_quality_checks path

### Blocked
- None yet; awaiting classification results to route fixes correctly

## Key Decisions
- P3-A fix moved budget check before state mutation (if ($used >= $max) throw first)
- Array-to-string warning in repair-router-service.php fixed with ternary: `is_array($issue) ? json_encode($issue) : (string) $issue`
- Classification-first approach for P3-C failures to avoid adapting tests to buggy production behavior

## Next Steps
1. Read article_image_semantic_gate_tokenize() output for Polish transliteration behavior
2. Verify list_posts() is defined in admin-database.php and check test bootstrap requires
3. Classify F5 (semantic score), I2 (manual_review gate), J2 (empty gallery contract)
4. Route each case: TEST_BUG→tester, PRODUCTION_BUG→coder, CONTRACT_AMBIGUITY→BLOCKED

## Critical Context
- `article_image_semantic_gate_tokenize()` uses iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE') for Polish characters → ASCII approximation (neuroplastyczność → neuroplasticznosc)
- Candidate title "Neuroplasticity brain synapses..." is English; planned tokens are Polish transliterations — token matching fails due to language mismatch, not production bug
- `list_posts()` defined in php/admin-database.php:1396 but test may lack require for this file

## Relevant Files
- C:\Projekty\mamona\php\article-image-service.php (line 787): semantic gate scoring function
- C:\Projekty\mamona\php\admin-database.php (line 1396): list_posts() definition
- C:\Projekty\mamona\tests\p3c-qc-renderer-publication-test.php: P3-C test harness
