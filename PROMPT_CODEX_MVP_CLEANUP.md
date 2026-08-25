# CODEX TASK — MAMONA MVP CLEANUP + CLEAN DATABASE BASELINE

Pracujesz bezpośrednio nad repo Mamona.

Lokalne agenty Kilo/Ollama są na tym etapie WSTRZYMANE.
Nie uruchamiaj local-agent orchestration.
Jeżeli środowisko Codex ma własną natywną delegację, możesz jej używać, ale źródłem prawdy pozostaje bieżący working tree.

Najpierw przeczytaj:

1. `AGENTS.md`
2. `docs/CURRENT_WORK.md`
3. `docs/MVP_STATE.md`
4. `docs/MVP_CLEANUP_AUDIT.md`
5. `git status --short`
6. `git diff --stat`

==================================================
NADRZĘDNY CEL
==================================================

Uprościć Mamona do czystego baseline MVP.

Po tej pracy repo ma być:
- znacznie mniejsze;
- bez starych buildów/handoffów/session dumps;
- bez wszystkich dotychczas pobranych artykułów i ich workflow state;
- bez starych article media/pages;
- z zachowaną konfiguracją i listą RSS;
- gotowe do jednego czystego E2E:
  RSS -> topic -> research -> draft -> QC -> images -> preview -> manual publish.

Nie rozwijaj nowych funkcji niezwiązanych z tym celem.

==================================================
WAŻNE — NIE UTRACAJ CURRENT WORK
==================================================

Working tree zawiera ważne, niekoniecznie zacommitowane zmiany.

NIE używaj:
- git reset;
- git restore;
- git checkout --;
- clean worktree by force;
- history rewrite.

Najpierw rozpoznaj current diff.

W szczególności zachowaj obecne poprawki:
- nullable transport w narrative plan;
- mock narrative plan fixture;
- bounded SQLite RSS lock retry;
- retry exhaustion regression;
- bieżące MAMONA-24 image/QC/batch/quota fixes.

Jeżeli coś wygląda na partial/broken:
napraw bounded diff, nie cofaj całego pliku do HEAD.

==================================================
TASK C0 — PRE-FLIGHT
==================================================

Zrób inventory bez zmian.

Potwierdź:
- exact repo root;
- current branch;
- current modified/untracked files;
- rozmiary:
  `_handoff`,
  `data`,
  `data/backups`,
  `data/cms.sqlite`,
  `images/posts`,
  `.kilo/node_modules`,
  `backups`,
  `subagent-exports`,
  `qa`;
- czy baza docelowa to dokładnie lokalne:
  `C:\Projekty\mamona\data\cms.sqlite`
  lub równoważny exact current repo path;
- że nie jest to production DB.

Jeżeli target DB jest niejednoznaczny albo `CMS_ENV=production`:
STOP przed mutacją.

Nie pytaj użytkownika o rutynowe decyzje wynikające jednoznacznie z repo.

==================================================
TASK C1 — HIGH-CONFIDENCE REPO CLEANUP
==================================================

Usuń artefakty, które nie są częścią runtime aplikacji.

HIGH CONFIDENCE:

1. `_handoff/`
2. `.kilo/node_modules/`
3. `subagent-exports/`
4. historyczny root `backups/`
5. `analysis/` jeśli zawiera wyłącznie dawną telemetrię agentów
6. root-level session exports:
   - `P2-parent-full.json`
   - `P2-B-initial-coder.json`
   - `P2-D-mamona-coder.json`
   - `P3-A-tester-full.json`
   - `P3-parent-full.json`

7. stare root-level lokal-agent build artifacts, po grep potwierdzającym brak runtime dependency:
   - `README_4.5*`
   - `README_5.1*`
   - `README-V4*`
   - `PROMPT_START_MAMONA_*`
   - `PROMPT_RESUME_*`
   - `INSTALL-V4*`
   - `install-mamona-*`
   - `VERIFY-V4*`
   - `verify-mamona-*`
   - stare `PRECHECK-*`
   - stare `CHANGELOG*`
   - `ROUTING-SMOKE-*`
   - `SCHEMA-HOTFIX-*`
   - `COORDINATOR-SEPARATION-*`
   - `DELEGATION-CEILING-*`
   - stare package/manifest notes dotyczące tych buildów.

Nie usuwaj:
- `AGENTS.md`;
- obecnego kodu aplikacji;
- aktualnego `.kilo/kilo.jsonc` i canonical `.kilo/agents`, jeśli ich zachowanie nic nie kosztuje poza kilkoma KB;
- `OPERATIONS.md`;
- aktualnej dokumentacji MVP.

`qa/`:
- najpierw sprawdź references;
- zachowaj aktywne narzędzia/skrypty;
- usuń stare PNG/log/metrics outputy, które są tylko historycznym evidence.

==================================================
TASK C2 — LEGACY CATS CLEANUP
==================================================

Zweryfikowany kandydat legacy:

- `php/cats.php`
- `php/admin-cats.php`
- tabela `cats`
- cat-only CRUD/seed/trash code w `php/admin-database.php`

Evidence z audytu:
- `cats` table = 0 rows;
- admin nav nie prowadzi do `admin-cats.php`;
- `list_cats()` ma legacy callers;
- `trash_all_cats()` nie ma aktywnego callera.

ALE:

`assets/js/cat-gallery.js` jest używany przez aktualne generic galleries.

Dlatego:

1. fresh grep/read wszystkich references;
2. usuń legacy cat PHP entrypoints;
3. usuń cat-only DB functions/schema/trash nodes;
4. usuń fallback `cats.php` / cat-specific dataset compatibility z JS, jeśli nie ma aktywnego callera;
5. ZACHOWAJ generic gallery behavior;
6. nie rób dużego rename/refactor JS tylko dla estetyki;
7. dodaj/uruchom targeted gallery/admin tests.

Po C2 nie może być regresji zwykłych galerii.

==================================================
TASK C3 — CREATE SAFE MVP CONTENT RESET
==================================================

Użytkownik jawnie chce usunąć WSZYSTKIE obecne pobrane/przetwarzane artykuły lokalnej bazy developerskiej.

To obejmuje:
- idea;
- draft;
- review;
- rejected;
- completed workflow;
- stale batches/operations;
- article images;
- research;
- QC;
- topic/feed state.

To NIE obejmuje konfiguracji globalnej i źródeł RSS.

Nie wykonuj ręcznej serii przypadkowych DELETE bez audytowalnego mechanizmu.

Preferowane:
utwórz dedykowany CLI, np.

`php/cli-reset-mvp-content.php`

Kontrakt:

- default = `--dry-run`;
- explicit `--apply`;
- działa tylko na local/dev DB;
- exact DB path w output;
- refuse `CMS_ENV=production`;
- przed apply tworzy jeden spójny backup POZA repo;
- backup SHA256;
- counts before;
- preservation assertions;
- transaction dla purge;
- filesystem cleanup dopiero po poprawnym DB commit;
- integrity check;
- VACUUM;
- counts after.

User authorization in this prompt allows apply na exact lokalnej dev DB
po successful dry-run + verified backup.
Nie pytaj ponownie, chyba że target jest niejednoznaczny/production.

==================================================
PRESERVE DATA
==================================================

Zachowaj co najmniej:

- schema_migrations
- cms_meta
- authors
- contact_settings
- site_style_settings
- social_media
- post_categories
- technical_sources
- editorial_profile_categories
- generation_settings

Zachowaj inne globalne/static tables, jeśli nie są związane z konkretnymi article runs.

Nie usuwaj listy RSS.

==================================================
PURGE DYNAMIC ARTICLE/RUN STATE
==================================================

Wyczyść zgodnie z actual FK/schema, co najmniej:

- article_feedback_operations
- article_proposal_audit
- thumbnail_versions
- quality_check_runs
- article_draft_versions
- research_policy_audit
- research_packages
- generation_repair_reports
- generation_batch_audit
- generation_batch_items
- generation_batches
- generation_operations
- post_generation_runs
- article_images
- post_sources
- post_status_history
- verified_research_sources
- topic_grouping_candidates
- topic_grouping_history
- feed_topic_memberships
- topic_score_history
- discovered_feed_items
- editorial_topics
- posts
- article_generation_budget
- editorial_ingestion_jobs
- full_auto_reservations
- full_auto_runs
- generation_worker_guard
- narrative_plans

Dla clean MVP możesz również zresetować runtime cache/quota state, po sprawdzeniu że nie jest globalną konfiguracją:

- gemini_call_cache
- gemini_quota_events
- gemini_quota_state
- gemini_model_leases
- image_provider_cache
- image_provider_rate_windows

Nie usuwaj `schema_migrations`.

Nie zakładaj FK order z prompta:
sprawdź actual schema i wykonaj delete order bez łamania RESTRICT/CASCADE.

Jeżeli resetujesz `sqlite_sequence`, tylko dla wyczyszczonych dynamic tables.

==================================================
BACKUP
==================================================

Nie trzymaj nowego jedynego backupu wewnątrz repo.

Preferowany katalog sibling/outside repo, np.:

`C:\Projekty\mamona-backups\`

Nazwa:
`mamona-pre-mvp-cleanup-YYYYMMDD-HHMMSS.sqlite`

Backup musi być spójny dla WAL DB.
Użyj mechanizmu gwarantującego spójny SQLite snapshot.

Zapisz:
- path;
- size;
- SHA256;
- integrity check backupu.

Dopiero potem apply.

Po wykonaniu nowego backupu i zweryfikowanym reset:
stare `data/backups/` można usunąć.

==================================================
TASK C4 — FILESYSTEM ARTICLE RESET
==================================================

Po DB commit usuń article-derived filesystem state.

1. `images/posts/**`
2. public `pages/post-*.html`
3. stale generated news/category/pagination pages zgodnie z bezpieczną allowlistą/manifestem
4. runtime article thumbnail dirs
5. article-only temporary/runtime files

NIE usuwaj:
- `pages/index.html` template;
- trust pages templates/outputs wymaganych przez aplikację;
- generic gallery assets;
- global static images;
- CSS/JS/font assets.

Potem wykonaj public sync w sposób bezpieczny dla pustego article setu:

- root index = empty feed state;
- `feed.xml` = empty;
- `sitemap.xml` bez starych article URLs;
- generated-news-pages manifest zgodny ze stanem;
- brak `post-*.html`.

==================================================
TASK C5 — DB COMPACTION + ASSERTIONS
==================================================

Po purge:

1. `PRAGMA integrity_check` => `ok`;
2. `VACUUM`;
3. odczytaj final DB size;
4. potwierdź counts.

Expected:
- posts = 0
- discovered_feed_items = 0
- editorial_topics = 0
- generation_operations = 0
- generation_batches = 0
- generation_batch_items = 0
- article_draft_versions = 0
- quality_check_runs = 0
- article_images = 0
- research_packages = 0

I zachowane:
- technical_sources > 0
- schema_migrations > 0
- editorial_profile_categories > 0
- global settings present.

==================================================
TASK C6 — GIT / PACKAGING HYGIENE
==================================================

Zaktualizuj `.gitignore`, aby repo ponownie nie puchło.

Uwzględnij m.in.:

- `/_handoff/`
- `/backups/`
- `/subagent-exports/`
- `/.kilo/node_modules/`
- runtime DB/backups/logs
- article-generated media
- generated QA screenshots/logs/metrics, ale nie source scripts potrzebne do testów
- root session dumps

Obecnie część `images/posts/`, `backups/`, `subagent-exports/` jest tracked.
Usuń je normalnie z bieżącego tree.

Zaktualizuj `pack_mamona_repo.ps1`:
nowa paczka/handoff nie może zawierać:
- `.git`;
- `.env`;
- runtime `data/`;
- `_handoff/`;
- `backups/`;
- `subagent-exports/`;
- node_modules;
- generated `images/posts/`;
- generated QA output.

Nie wykonuj:
- `git filter-repo`;
- BFG;
- rebase;
- commit;
- push;
bez osobnej zgody.

==================================================
TASK C7 — DOC CONSOLIDATION
==================================================

Kanoniczny dokument:

`docs/MVP_STATE.md`

Zaktualizuj go po faktycznym cleanupie:
- final sizes;
- final counts;
- files removed;
- legacy removed;
- tests run;
- current MVP state.

Zaktualizuj:
`docs/CURRENT_WORK.md`.

Uprość `README.md`:
- usuń stale references do nieistniejących starych tasków;
- wskaż `docs/MVP_STATE.md`;
- wskaż `docs/CURRENT_WORK.md`;
- nie utrzymuj kilkunastu historycznych "next task" źródeł.

Po upewnieniu się, że unique facts zostały przeniesione, usuń nieaktualne:
- `docs/research/MAMONA-24-*`;
- stare `docs/tasks/*`;
- stare local-agent build docs;
- stare checkpoint/resume docs niebędące już źródłem prawdy.

Nie kasuj dokumentu tylko dlatego, że jest stary, jeśli nadal zawiera unikalny aktywny contract.
Najpierw consolidate, potem delete.

==================================================
TASK C8 — FINAL VALIDATION
==================================================

Po cleanupie:

- lint zmienionych PHP;
- targeted tests dla legacy cats/gallery cleanup;
- feed ingestion smoke;
- SQLite lock retry + exhaustion regression;
- generation batch smoke w mock mode;
- relevant publication/public sync smoke;
- test DB musi być disposable;
- no live provider call;
- no live publication.

Na końcu zrób MOCK MVP E2E:
RSS fixture
→ topic
→ research
→ draft
→ QC
→ images mock/source mock
→ ready preview

Nie uruchamiaj live Gemini w ramach cleanupu.

==================================================
STOP CONDITION
==================================================

Cleanup jest COMPLETE dopiero gdy:

- repo bloat artifacts usunięte;
- DB article content wyzerowany;
- DB skompaktowany;
- RSS sources/config zachowane;
- generated article files usunięte;
- legacy cats usunięte bez regresji galleries;
- docs skonsolidowane;
- focused tests PASS;
- mock E2E PASS;
- `docs/MVP_STATE.md` i `docs/CURRENT_WORK.md` odzwierciedlają rzeczywisty stan.

==================================================
FINAL REPORT
==================================================

MAMONA_MVP_CLEANUP_RESULT

- Repo_size_before:
- Repo_size_after:
- Deleted_high_confidence_artifacts:
- Preserved_local_agent_files:
- Legacy_cats_status:
- DB_path:
- DB_size_before:
- DB_backup_path:
- DB_backup_sha256:
- DB_size_after:
- Posts_before:
- Posts_after:
- Feed_items_before:
- Feed_items_after:
- Topics_before:
- Topics_after:
- Generation_operations_before:
- Generation_operations_after:
- Technical_sources_preserved:
- Article_media_removed:
- Generated_article_pages_removed:
- Integrity_check:
- Vacuum:
- Gitignore_updated:
- Pack_script_updated:
- Docs_consolidated:
- Tests:
- Mock_MVP_E2E:
- Live_provider_calls: NO
- Live_publication: NO
- Remaining_blockers:
- Next_action:
