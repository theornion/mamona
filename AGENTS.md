# AGENTS.md — Mamona

## 1. Purpose

Mamona is a lightweight CMS and editorial automation system built with PHP and SQLite. The public site, admin panel, editorial queue, source ingestion, research, draft generation, quality checks, thumbnails, scheduled publication, RSS, sitemap and generated public pages are part of one workflow.

Treat this repository as production-oriented software. Prefer small, reviewable changes and preserve existing behavior unless the task explicitly requires otherwise.

## 2. Technology stack

- PHP 8.1+
- SQLite through PDO
- Apache/XAMPP as the primary Windows development environment
- Plain HTML, CSS and JavaScript
- PHP CLI scripts for workers, maintenance and tests
- Optional Python 3 + Pillow for image processing when PHP GD/WebP is unavailable

The application does not automatically load `.env`. Configuration comes from process, Apache, hosting, cron or Windows Task Scheduler environment variables.

## 3. Read this before changing code

At the beginning of a new task:

1. Read `docs/CURRENT_WORK.md`.
2. Read the relevant sections of `docs/PROJECT_CONTEXT.md`.
3. Check `git status` and inspect the latest commits.
4. Read only the relevant sections of `README.md`, `OPERATIONS.md` and `NIEUSUWAĆ-TASKI.txt`.
5. Locate the smallest set of files needed for the task.
6. State assumptions when the repository does not prove them.

Do not scan or summarize the whole repository unless the task actually requires repository-wide analysis.

## 4. Non-negotiable invariants

Preserve these rules unless the user explicitly changes the product specification:

1. `editorial_status` is the source of truth for article visibility.
2. Only a non-trashed article with status `published` may have a public HTML file or appear in public API, RSS, sitemap or public feeds.
3. `is_published` is only a compatibility mirror for older code; do not make it the primary visibility rule.
4. Draft previews must remain authenticated, non-public, non-cacheable and `noindex`.
5. Writes of generated public files must remain atomic.
6. Normal save actions must never publish implicitly.
7. Research, draft, quality-check and thumbnail versions must remain auditable and must not silently overwrite prior versions.
8. Publication and scheduling safeguards must not be bypassed.
9. Do not add secrets, API keys, administrator credentials, private contact data or production database contents to Git.
10. Do not log full secrets. Sanitize diagnostic output.
11. Image automation must preserve per-asset rights validation, credit data and source traceability. Do not weaken license checks to make a source pass.
12. Network fetchers must preserve SSRF, redirect, scheme, port, size and timeout protections.
13. Tests that mutate SQLite or public files must use their documented `CMS_ALLOW_*` flags, isolate their data and clean up after themselves.
14. Do not run destructive scripts, real paid API calls or automatic publication without explicit approval.

## 5. Important locations

- `php/` — application, admin panel, repositories, services, workers and CLI entry points
- `tests/` — PHP smoke, regression and end-to-end tests
- `scripts/` — audits, maintenance and image-processing helpers
- `data/` — SQLite database, logs, sessions, locks and generated working data
- `pages/` — generated public article pages
- `images/posts/` — public post images and thumbnails
- `assets/`, `fonts/`, `images/` — public frontend assets
- `README.md` — architecture summary and task status
- `OPERATIONS.md` — operational runbook and test instructions
- `NIEUSUWAĆ-TASKI.txt` — detailed functional specification and acceptance criteria
- `.env.example` — documentation of supported environment variables; it is not auto-loaded

## 6. Editing rules

- Make the smallest change that fully solves the requested problem.
- Follow existing naming, service, repository and validation patterns.
- Do not refactor unrelated code.
- Do not silently change database semantics, status transitions, publication gates or public URLs.
- Prefer extending an existing service or repository over duplicating logic in a controller or view.
- Keep PHP, SQL, HTML, CSS and JavaScript readable and explicit.
- Source code, identifiers and technical comments should be in English unless an existing user-facing Polish string requires Polish.
- User-facing responses may be in Polish.
- Avoid new dependencies unless they provide clear value and the user approves them.
- Preserve Windows/XAMPP compatibility for project scripts.

## 7. Validation strategy

Choose validation proportionally to the change.

### PHP syntax check

PowerShell with XAMPP:

```powershell
Get-ChildItem -Recurse -Filter *.php |
    ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

Linux/macOS:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Targeted smoke test

Run the test closest to the changed feature. Read the beginning of the test file first and set only its documented `CMS_ALLOW_*` variables.

Example pattern on Windows:

```powershell
$env:CMS_SKIP_PUBLIC_SYNC='1'
$env:CMS_ALLOW_<TEST_FLAG>='1'
C:\xampp\php\php.exe tests\<relevant-test>.php
```

Do not invent a flag name. Read it from the test.

### Full editorial pipeline E2E

Run only when the change affects the editorial pipeline or before a meaningful release. Do not run it concurrently with the admin panel, scheduler or another mutating test.

PowerShell:

```powershell
$env:CMS_ALLOW_PIPELINE_E2E='1'
$env:CMS_IMAGE_PROCESSOR_PYTHON='C:\full\path\to\python.exe'
C:\xampp\php\php.exe tests\editorial-pipeline-e2e.php
```

Expected success marker: `EDITORIAL_PIPELINE_E2E_OK` for both manual and API-mock paths.

### Operational CLI examples

```powershell
C:\xampp\php\php.exe php\fetch-feeds.php
C:\xampp\php\php.exe php\group-topics.php
C:\xampp\php\php.exe php\score-topics.php
C:\xampp\php\php.exe php\publish-scheduled.php --dry-run
```

Never run real publication without the requested environment and explicit approval.

## 8. Working with local AI agents

Two Roo Code modes are provided in `.roomodes`:

- `Mamona Coder` — use with `qwen3-coder:30b` for normal implementation, debugging and multi-file work.
- `Mamona Fast` — use with `qwen3.5:9b` for narrow, low-risk and mechanical work.

Start with Fast only when the task is clear, local and easy to verify. Move to Coder for ambiguity, cross-module changes, database or publication logic, security boundaries, difficult debugging or repeated failure.

Do not run both modes against overlapping files at the same time.

## 9. Completion requirements

Before finishing a task:

1. Review `git diff`.
2. Run the smallest relevant validation set.
3. Confirm that no secret, database file, session, log, generated test artifact or credential was added.
4. Update `docs/CURRENT_WORK.md` with completed work, remaining work, relevant files and test results.
5. Update `docs/PROJECT_CONTEXT.md` only for durable architectural or operational knowledge.
6. Report changed files, validation results, assumptions and unresolved risks concisely.
7. Do not commit or push unless the user asked for it.
