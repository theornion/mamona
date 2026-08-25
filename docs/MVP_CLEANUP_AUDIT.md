# Mamona — Cleanup Audit

**Data audytu:** 2026-08-10  
**Wejście:** `mamona.zip`

## Podsumowanie

ZIP:
- ok. 465 MB;
- 8042 wpisy;
- ok. 3.34 GB po rozpakowaniu.

Największe źródła rozrostu:

| Obszar | Pliki | Rozmiar nieskompresowany | Ocena |
|---|---:|---:|---|
| `data/` | 34 | ~2152.9 MB | runtime + stare backupy |
| `_handoff/` | 1128 | ~736.9 MB | bezpieczny kandydat do usunięcia |
| `.git/` | 2161 | ~117.5 MB | zachować; bez history rewrite teraz |
| `images/` | 406 | ~84.5 MB | `images/posts` czyścić z artykułami |
| `.kilo/` | 3726 | ~52.8 MB | głównie regenerowalne node_modules |
| `backups/` | 74 | ~18.7 MB | historyczne backupy, część tracked |
| `subagent-exports/` | 12 | ~6.8 MB | historyczne agent exports |
| `qa/` | 112 | ~6.3 MB | głównie historyczne screenshots |

## 1. `data/`

### `data/cms.sqlite`
- 761,446,400 B.
- 162113 / 185900 stron SQLite to freelist.
- około 633 MiB pliku to wolne strony.
- live content ~92.9 MiB.

### `data/backups/`
~1.43 GiB:
- `before-reset-nonready-20260810_113103.sqlite` ~680.7 MiB;
- `before-reset-nonready-20260810_113156.sqlite` ~680.7 MiB;
- dwa `reset_invalid_articles_20260810_*.json` po ~27.4 MiB;
- pozostałe starsze backupy.

**Rekomendacja:** przed cleanupem utworzyć jeden świeży, zweryfikowany backup poza repo. Następnie usunąć stare backupy z repo.

## 2. `_handoff/`

~736.95 MiB:
- `mamona-repo-20260807-202349` ~710.08 MiB — kompletna stara kopia repo;
- trzy zestawy session exports.

**Rekomendacja:** usunąć cały `_handoff/` z working tree i dodać do root `.gitignore`.

## 3. `.kilo/`

~52.8 MiB, z czego ~52.76 MiB to `.kilo/node_modules/`.

**Rekomendacja:**
- usunąć `.kilo/node_modules/`;
- zachować na razie aktualny `.kilo/kilo.jsonc` i canonical agent definitions, jeśli planowany jest powrót do lokalnych agentów;
- stare root-level install/prompt/version docs usunąć.

## 4. `backups/`

~18.72 MiB, 65 tracked files.

Zawiera:
- `backups/admin-ui-pre-redesign-20260731/`;
- `backups/cms-before-title-repair-20260731-210614.sqlite` ~17.27 MiB.

**Rekomendacja:** po zachowaniu wymaganych faktów w `docs/MVP_STATE.md` usunąć katalog i dodać `/backups/` do `.gitignore`.

## 5. `subagent-exports/`

~6.84 MiB, 11 tracked files.

Wyłącznie historyczne exporty sesji P2.

**Rekomendacja:** usunąć cały katalog i dodać `/subagent-exports/` do `.gitignore`.

## 6. Root-level stare buildy agentów

71 plików / ~5.6 MiB.

Kategorie:
- P2/P3 full session JSON;
- instalatory 4.5/5.1/V4/V4.6;
- verify scripts;
- resume/start prompts;
- README poszczególnych wersji;
- permission/precheck/changelog/smoke files;
- package manifests.

Największe:
- `P2-parent-full.json` ~1.51 MiB;
- `P2-B-initial-coder.json` ~1.30 MiB;
- `P3-A-tester-full.json` ~1.24 MiB;
- `P2-D-mamona-coder.json` ~0.98 MiB;
- `P3-parent-full.json` ~0.41 MiB.

**Rekomendacja:** usunąć po zachowaniu `AGENTS.md` i ewentualnie jednego aktualnego local-agent setupu poza runtime app.

## 7. `qa/`

~6.3 MiB / 96 tracked files.

Większość:
- PNG screenshots;
- performance metrics;
- stare visual regression evidence;
- `.log`.

Są też:
- markdown test plans;
- `qa/performance/runner.php`;
- `qa/performance/scroll-probe.js`.

**Rekomendacja:** nie usuwać całego katalogu w ciemno. Najpierw zachować aktywne narzędzia, usunąć historyczne outputy i dodać odpowiednie generated-output ignore patterns.

## 8. `images/posts/`

~72.65 MiB / 382 pliki w ZIP.

Git nadal śledzi część tych plików mimo obecnego ignore.

Po pełnym article reset:
- usunąć wszystkie article-derived source/downloaded/rendered files;
- pozostawić katalog lub `.gitkeep`, jeśli kod wymaga ścieżki;
- upewnić się, że nowe wygenerowane media nie wracają do Git.

## 9. Generated public pages

Obecnie m.in.:
- `pages/post-cipki.html`;
- `pages/kategoria-nowa-kategoria.html`.

`pages/post-cipki.html` nie ma odpowiadającego rekordu w aktualnym `posts`.

`data/generated-news-pages.json` jest obecnie pusty, więc `kategoria-nowa-kategoria.html` jest przykładem stale generated output spoza aktualnego manifestu.

**Rekomendacja:** po DB reset uruchomić kontrolowany public sync lub jawny cleanup allowlisty generated pages. Zachować `pages/index.html` jako template oraz trust pages.

## 10. Legacy `cats`

Evidence:
- tabela `cats`: 0 rekordów;
- `php/admin-cats.php` nie jest obecny w aktualnym admin navigation;
- `php/cats.php` obsługuje legacy cats JSON;
- `list_cats()` jest używane tylko przez legacy endpoint/admin;
- `trash_all_cats()` nie ma zewnętrznych callerów;
- `admin-database.php` nadal tworzy/obsługuje `cats`;
- `cat-gallery.js` ma fallback do `cats.php`.

Jednocześnie:
- `cat-gallery.js` jest aktywnie używany przez zwykłe strony galerii.

**Rekomendacja:** usunąć cat-specific PHP/schema/CRUD/fallback, ale zachować generic gallery renderer. Rename JS zostawić na później, jeśli nie jest wymagany do MVP.

## 11. Git

`.git` ~117.5 MiB.

Duże historyczne obiekty obejmują:
- stary DB backup ~18.1 MiB;
- liczne `images/posts/sources/*` po kilka–10 MiB;
- `images/digital_rain.png` ~9.3 MiB;
- session export JSON.

Repo ma też dużo loose objects.

**Nie wykonywać teraz history rewrite.**
Po uporządkowaniu bieżącego tree można osobno zdecydować o:
- zwykłym `git gc`;
- ewentualnym `git filter-repo` tylko po pełnym backupie i jawnej zgodzie.

## 12. Stale docs/tasks

Do konsolidacji/usunięcia po `docs/MVP_STATE.md`:
- `docs/research/MAMONA-24-*`;
- `docs/tasks/*` starego pipeline;
- stare local-agent docs V3/V4/V5;
- stare checkpoint/resume prompts.

`README.md` ma również stare literalne referencje do nieistniejących:
- `NIEUSUWAĆ-TASKI.txt`;
- `AUDYT-TASK-01.md`;
- `AUDYT-TASK-21.md`.

README wymaga uproszczenia.

## 13. Oczekiwany efekt

Największą redukcję da fizyczne usunięcie:
1. `data/backups`;
2. `_handoff`;
3. starego `data/cms.sqlite` po purge + VACUUM;
4. `images/posts`;
5. `.kilo/node_modules`;
6. `backups`;
7. historycznych agent/session/QA artefaktów.

Samo `VACUUM` bazy po prawidłowym purge powinno usunąć setki MiB pustych stron.
