# Mamona — project context

Last synchronized from the public repository documentation: 2026-08-05.

## Product summary

Mamona is a lightweight PHP/SQLite CMS with a complete editorial workflow. It combines a public site and administrator panel with source ingestion, topic grouping and scoring, research packages, article drafts, quality review, thumbnail handling, scheduled publication, generated article pages, RSS and sitemap synchronization.

## Current documented baseline

The repository README documents TASK-01 through TASK-22 as completed. The current baseline includes:

- centralized environment-based configuration;
- versioned database migrations;
- editorial statuses, authors, sources and history;
- private draft preview and publication privacy safeguards;
- article rendering with metadata, canonical URLs, Open Graph and JSON-LD;
- server-rendered feed, pagination and categories;
- editorial queue and editor;
- scheduled publication worker;
- technical source registry and RSS/Atom ingestion;
- deterministic topic grouping and scoring;
- manual and API generation workflows;
- versioned research packages, drafts, quality checks and thumbnails;
- trust/transparency pages and author profiles;
- mobile/Core Web Vitals work;
- full editorial pipeline end-to-end test and operational documentation.

This file is a handoff aid, not a replacement for `README.md`, `OPERATIONS.md` or `NIEUSUWAĆ-TASKI.txt`.

## Architecture map

### Web and admin layer

The application is served from the repository root. The administrator panel and most PHP endpoints live under `php/`.

### Persistence

- Primary database: `data/cms.sqlite`
- Migrations: versioned through `schema_migrations`, with some older compatibility `ensure_*` paths
- Sessions, locks and operational logs also live under `data/`

### Editorial truth

- `editorial_status` controls visibility and lifecycle.
- `published` plus “not in trash” is required for public exposure.
- `is_published` remains only as a backward-compatible mirror.
- Draft preview is a private authenticated path and does not generate public HTML.

### Generated public output

Generated artifacts include:

- article pages under `pages/`;
- `index.html` and server-rendered public feed views;
- `feed.xml`;
- `sitemap.xml`;
- `robots.txt`;
- public thumbnails under `images/posts/thumbnails/`.

Generated public writes must remain atomic and synchronized with editorial state.

### Editorial automation flow

A simplified flow is:

1. Register and fetch approved RSS/Atom sources.
2. Store source items as non-public ideas.
3. Group related items into topics.
4. Score topics deterministically and record reasons/risk.
5. Prepare a versioned research package.
6. Explicitly approve research for drafting.
7. Generate a versioned draft.
8. Perform a versioned quality check.
9. Prepare and approve a versioned thumbnail.
10. Edit, schedule or publish through guarded transitions.
11. Synchronize public HTML, sitemap and RSS.

Human review and publication gates are intentional product requirements.

## Security and safety boundaries

- Never commit secrets or administrator credentials.
- The project does not automatically load `.env`.
- API keys come from the process environment.
- Feed ingestion must keep SSRF and redirect protections.
- Public visibility must never be inferred from legacy flags alone.
- Paid API operations must not be run accidentally during testing.
- `OPENAI_API_MOCK=true` and other documented mocks are preferred for regression tests.
- Image sources require per-asset rights evidence and credit data.
- Unsplash and Pixabay are documented as manual-only sources.

## Primary development environment

The documentation assumes PHP 8.1+ and commonly uses XAMPP on Windows:

```text
C:\xampp\php\php.exe
```

Required PHP capabilities include PDO SQLite, mbstring, DOM/XML, cURL, fileinfo and zlib. Thumbnail processing requires PHP GD with WebP or Python 3 with Pillow.

## Important operational commands

```text
php php/fetch-feeds.php
php php/group-topics.php
php php/score-topics.php
php php/publish-scheduled.php --dry-run
php scripts/audit-feed-redirects.php
```

Use `OPERATIONS.md` for flags, environment requirements and destructive-operation warnings.

## Testing baseline

The repository contains targeted smoke tests and `tests/editorial-pipeline-e2e.php`. Mutating tests require explicit `CMS_ALLOW_*` variables documented in each test. Many tests use `CMS_SKIP_PUBLIC_SYNC=1` and clean up their own data.

The full E2E success marker is `EDITORIAL_PIPELINE_E2E_OK` for manual and API-mock flows.

## Durable decisions for agents

- Preserve the existing PHP/SQLite architecture unless a task explicitly requests migration.
- Preserve auditability and version history.
- Prefer deterministic validation around model output.
- Keep UI actions distinct: save, review, schedule, publish and reject are separate operations.
- Continue processing independent items after one item fails when the existing worker is designed that way.
- Keep production publication disabled unless the deployment has complete publisher/contact/author information and explicit configuration.

## Unknowns to verify locally

The public repository documentation does not prove:

- the exact local XAMPP document-root path;
- the currently active branch and uncommitted changes on the user’s computer;
- the next product task after the documented baseline;
- local environment variables and installed PHP extensions;
- production hosting details.

Agents must inspect the local checkout instead of guessing these values.
