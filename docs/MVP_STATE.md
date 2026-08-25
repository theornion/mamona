# Mamona — MVP State / Single-File Project Documentation

**Snapshot audytu:** 2026-08-10  
**Źródło:** przekazane repozytorium `mamona.zip`  
**Cel dokumentu:** jedna kanoniczna mapa wykonanej pracy, istniejących funkcji, ograniczeń i aktualnego kierunku MVP.

## Aktualizacja cleanupu — 2026-08-10

Wykonano clean baseline lokalnej bazy `data/cms.sqlite`:

- wszystkie rekordy artykułowe, RSS discoveries, tematy, research, szkice, QC,
  batch/operation state i article images zostały usunięte;
- zachowano konfigurację, 22 `technical_sources`, 46 migracji i 8 profili;
- po `PRAGMA integrity_check = ok` oraz `VACUUM` baza ma 704 512 B;
- zweryfikowany backup sprzed resetu jest poza repo:
  `C:\Projekty\mamona-backups\mamona-pre-mvp-cleanup-20260810-211415.sqlite`;
- usunięto legacy `cats` oraz jego endpointy, bez zmiany ogólnego renderera galerii.

Pozostałe zadania: fizyczne usunięcie historycznych artefaktów repo, które
zostało zablokowane przez środowisko wykonawcze, oraz mock E2E wymagający PHP
GD z WebP lub Pythona z Pillow. Szczegóły bieżącej pracy: `docs/CURRENT_WORK.md`.

---

## 1. Produkt

Mamona jest lekkim CMS-em/redakcyjnym pipeline'em w PHP + SQLite. System łączy:
- pobieranie RSS/Atom;
- tworzenie niepublicznych pomysłów;
- grupowanie wpisów w tematy;
- scoring tematów;
- research;
- generowanie struktury narracyjnej i szkicu;
- QC;
- planowanie i dobór grafik;
- preview;
- ręczną publikację;
- generowane publiczne HTML/RSS/sitemap;
- panel administratora.

Aktualnym celem nie jest dalsze zwiększanie autonomii. Celem jest **minimalny wiarygodny produkt**, w którym jeden materiał przechodzi cały przepływ od RSS do świadomej publikacji.

---

## 2. Technologia i model danych

- PHP 8.1+.
- SQLite/PDO.
- Główna lokalna baza: `data/cms.sqlite`.
- Konfiguracja: `php/app-config.php`.
- `.env.example` jest dokumentacją zmiennych.
- Aplikacja **nie ładuje automatycznie `.env`**.
- Sekrety, w tym `GEMINI_API_KEY`, muszą być dostępne jako environment procesu/servera.
- Status `posts.status` jest kanonicznym statusem redakcyjnym.
- `is_published` jest polem legacy/synchronizowanym.
- Publiczny materiał powinien istnieć dopiero po świadomym przejściu do `published`.

---

## 3. Główny przepływ produktu

### 3.1 RSS / Studio

Główne moduły:
- `php/admin-content-studio.php`;
- `php/feed-ingestion-service.php`;
- `php/technical-source-repository.php`;
- `php/admin-technical-sources.php`.

Przepływ:
1. aktywne `technical_sources`;
2. fetch RSS/Atom;
3. normalizacja;
4. zapis `discovered_feed_items`;
5. utworzenie niepublicznego `posts` w stanie pomysłu;
6. membership do tematu;
7. grouping;
8. scoring.

Feed ingestion ma zabezpieczenia transportowe i SSRF oraz bounded retry SQLite lock/busy.

### 3.2 Tematy

Główne moduły:
- `php/admin-editorial-topics.php`;
- topic grouping/scoring services;
- tabele `editorial_topics`, `feed_topic_memberships`, history/scoring.

Temat może zostać:
- automatycznie połączony;
- zasugerowany do połączenia;
- rozdzielony;
- odrzucony;
- wybrany do generowania.

### 3.3 Research

Research jest osobnym artefaktem od szkicu.

Istotne tabele:
- `verified_research_sources`;
- `research_packages`;
- `research_policy_audit`.

Research zachowuje źródła i evidence, a generowanie szkicu powinno bazować na zatwierdzonym researchu.

### 3.4 Generowanie i NarrativePlan

Główne moduły:
- `php/generation-service.php`;
- `php/generation-batch-service.php`;
- `php/generation-batch-worker.php`;
- `php/narrative-plan-service.php`;
- `php/article-draft-service.php`;
- `php/gemini-quota-service.php`.

System ma:
- generation operations;
- batch;
- retry;
- quota;
- mock mode;
- wersjonowane szkice;
- NarrativePlan;
- deterministic fallback/salvage.

W bieżącym working tree `generate_narrative_plan()` akceptuje nullable transport, aby batch mógł użyć domyślnego transportu/mocka bez `TypeError`.

### 3.5 QC

Główne moduły:
- `php/quality-check-service.php`;
- `php/repair-router-service.php`.

QC łączy:
- wynik modelowy;
- deterministyczne walidacje;
- hard blocks;
- wersjonowane `quality_check_runs`;
- router napraw.

Publikacja nie powinna omijać aktywnych hard blocków.

### 3.6 Grafiki

Główny moduł:
- `php/article-image-service.php`.

Pipeline obejmuje:
- VisualSlot;
- wyszukiwanie źródeł;
- prawa/licencje/attribution;
- download;
- warianty;
- multimodal/Vision assessment;
- blokadę publikacji przy niespełnionym wymaganym assetcie.

`assets/js/cat-gallery.js` ma historyczną nazwę, ale aktualnie służy również do zwykłych galerii. Nie jest bezpiecznym kandydatem do prostego usunięcia.

### 3.7 Preview i publikacja

Główne moduły:
- `php/admin-post-preview.php`;
- `php/publication-service.php`;
- `php/admin-database.php`;
- scheduler publikacji.

Zasada:
- generowanie nie powinno samodzielnie publikować;
- preview pozostaje niepubliczny;
- świadoma zmiana statusu do `published` uruchamia publiczne artefakty.

Publiczne pliki są zapisywane atomowo.

### 3.8 Public output

System generuje:
- root `index.html`;
- strony artykułów w `pages/post-*.html`;
- strony kategorii i paginacji;
- `feed.xml`;
- `sitemap.xml`;
- trust pages;
- galerie.

`data/generated-news-pages.json` jest manifestem wygenerowanych stron feedu/kategorii.

---

## 4. Obecne istotne zabezpieczenia

- fail-closed publication gates;
- brak publikacji przy statusie innym niż `published`;
- atomowy zapis public files;
- source/image rights validation;
- SSRF restrictions w feed/image transport;
- batch/worker locks;
- bounded retry;
- test flags `CMS_ALLOW_*`;
- test DB przez `CMS_TEST_DATABASE_FILE`;
- możliwość blokowania public sync w testach;
- sekrety poza repo.

---

## 5. Aktualny working tree — ważne zmiany do zachowania

Audit snapshot pokazuje rozległy uncommitted diff. Nie wolno go wyzerować podczas cleanupu.

W szczególności obecne są:

### SQLite RSS retry
`php/feed-ingestion-service.php`
- 5 prób;
- opóźnienia 200/300/450/675 ms;
- retry tylko dla komunikatów lock/busy;
- ostatnia próba propaguje wyjątek.

`tests/content-studio-smoke.php`
- istniejący transient-lock recovery;
- dodany test trwałego `database is busy` i wyczerpania 5 prób.

### Narrative transport
`php/narrative-plan-service.php`
- nullable/default transport;
- deterministic mock NarrativePlan zgodny z schema.

`tests/generation-batch-smoke.php`
- jawna regresja dla batcha bez explicit transport.

### MAMONA-24
W working tree znajdują się również większe zmiany w:
- `article-image-service.php`;
- `quality-check-service.php`;
- `gemini-quota-service.php`;
- `generation-batch-service.php`;
- `repair-router-service.php`;
- `cli-reset-invalid-articles.php`;
- powiązanych testach.

Cleanup ma je zachować i nie cofać automatycznie.

---

## 6. Stan testów

Z wcześniejszego potwierdzonego wykonania:
- P3-A: 34 PASS / 0 FAIL;
- P3-B: PASS;
- P3-C Vision Gate: 73 PASS / 0 FAIL;
- P3-C Publication: 10 PASS / 0 FAIL;
- P3-C QC/Renderer: 86 PASS / 0 FAIL;
- P3-D reset: 51 PASS / 0 FAIL;
- lint `php/cli-reset-invalid-articles.php`: PASS.

`quality-check-smoke.php` był wcześniej pominięty bez `CMS_ALLOW_QUALITY_SMOKE=1` i nie jest liczony jako pokrycie.

W sandboxie audytu nie wykonano świeżych smoke tests, ponieważ dostępny PHP nie miał sterownika `pdo_sqlite`. Nie zmienia to stanu evidence z repo, ale oznacza, że po cleanupie Codex musi uruchomić testy w docelowym lokalnym środowisku Windows/XAMPP.

---

## 7. Stan bazy przed cleanupem

`data/cms.sqlite`:
- 761,446,400 B;
- 379 postów;
- 0 opublikowanych;
- 377 discovered feed items;
- 375 editorial topics;
- 3028 generation operations;
- 128 generation batches;
- 5656 generation batch audit;
- 11514 topic score history;
- 88 article images;
- 35 draft versions;
- 32 QC runs.

Rozkład postów:
- `idea`: 358;
- `draft`: 17;
- `rejected`: 3;
- `review`: 1.

Baza ma około 633 MiB wolnych stron. Live DB data zajmuje około 92.9 MiB. Duży rozmiar jest więc w dużej mierze skutkiem braku `VACUUM` po wcześniejszych operacjach.

Największe live struktury:
- `generation_operations`: ~45.7 MiB;
- `topic_score_history`: ~30.7 MiB;
- `image_provider_cache`: ~8.7 MiB.

---

## 8. Docelowy clean baseline MVP

Po cleanupie zachować konfigurację/globalne dane:

- `schema_migrations`;
- `cms_meta`;
- `authors`;
- `contact_settings`;
- `site_style_settings`;
- `social_media`;
- `post_categories`;
- `technical_sources`;
- `editorial_profile_categories`;
- `generation_settings`.

Dynamiczny content pipeline ma zostać wyzerowany:
- posts;
- feed items;
- topics/memberships/grouping/scoring history;
- research;
- draft versions;
- QC;
- article images;
- generation operations;
- generation batches/items/audit;
- repair reports;
- post sources/status history/generation runs;
- full-auto run state;
- ingestion job history;
- article budgets;
- runtime generation/image caches i leases, jeśli są powiązane ze starym run state.

Po resecie:
- dynamic article counts = 0;
- generated article media = empty;
- generated post pages = none;
- feed/sitemap/index zsynchronizowane do pustego feedu;
- DB integrity check = `ok`;
- `VACUUM` wykonany.

---

## 9. Legacy / cleanup candidates

### Wysoka pewność

1. `_handoff/`
   - ok. 736.95 MiB;
   - zawiera pełną kopię repo ~710 MiB + eksporty sesji;
   - artifact handoff, nie runtime.

2. `data/backups/`
   - ok. 1.43 GiB;
   - dwa backupy SQLite po ~680.7 MiB + duże JSON manifesty;
   - po utworzeniu jednego świeżego backupu poza repo stare backupy są zbędne.

3. `.kilo/node_modules/`
   - ok. 52.76 MiB;
   - regenerowalne;
   - już ignorowane przez `.kilo/.gitignore`.

4. `backups/`
   - ok. 18.72 MiB;
   - 65 plików tracked;
   - historyczny pre-redesign snapshot i stary DB backup.

5. `subagent-exports/`
   - ok. 6.84 MiB;
   - 11 historycznych eksportów agentów.

6. Root-level session dumps:
   - `P2-parent-full.json`;
   - `P2-B-initial-coder.json`;
   - `P2-D-mamona-coder.json`;
   - `P3-A-tester-full.json`;
   - `P3-parent-full.json`.

7. Stare build/config artifacts agentów:
   - wersje `README_4.5*`, `README_5.1*`, `README-V4*`;
   - `PROMPT_START_MAMONA_*`;
   - `PROMPT_RESUME_*`;
   - `INSTALL-V4*`, `install-mamona-*`;
   - `VERIFY-V4*`, `verify-mamona-*`;
   - stare `PRECHECK`, `CHANGELOG`, routing/permission smoke i package notes.

8. `pages/post-cipki.html`
   - stale public artifact;
   - brak odpowiadającego rekordu w aktualnej DB.

### Legacy `cats`

Silne evidence:
- `cats` table ma 0 rekordów;
- `php/admin-cats.php` nie jest w obecnym admin nav;
- `php/cats.php` jest wykorzystywany tylko jako legacy/default fallback przez `cat-gallery.js`;
- cat CRUD jest skupiony w `php/admin-database.php`.

Nie usuwać całego `cat-gallery.js`, bo obecne gallery pages go używają.

### Do weryfikacji przed usunięciem

- `qa/`: większość to screenshots/metrics/logs, ale są też narzędzia i dokumenty testowe;
- `analysis/`: historyczna telemetria agentów, prawdopodobnie zbędna;
- stare `docs/research/` i `docs/tasks/`: nie runtime, ale trzeba najpierw skonsolidować unikalne fakty;
- część local-agent `.kilo/agents`: lokalne agenty są wstrzymane, ale można zachować aktualny canonical config na przyszłość.

---

## 10. Problemy repo hygiene

- `_handoff` nie jest globalnie ignorowany w root `.gitignore`.
- root `backups/` jest tracked.
- `subagent-exports/` jest tracked.
- 84 pliki z `images/posts/` są nadal tracked mimo obecnego ignore.
- `qa/` zawiera 96 tracked historycznych plików.
- `.git` ma ok. 117.5 MiB i zawiera historyczne duże assety/backup DB.
- repo ma wiele root-level dokumentów instalacyjnych lokalnych agentów, które nie są częścią aplikacji.

Nie wykonywać history rewrite przy tym cleanupie. Najpierw uprościć bieżący tree. History rewrite może być osobnym, świadomym etapem.

---

## 11. Docelowa minimalna struktura dokumentacji

Po cleanupie preferowane źródła:

```text
AGENTS.md
README.md
OPERATIONS.md
docs/MVP_STATE.md
docs/CURRENT_WORK.md
```

Pozostałe docs utrzymywać tylko jeśli zawierają nadal potrzebny kontrakt, którego nie ma w powyższych plikach.

---

## 12. Docelowy pierwszy flow MVP

Po czystym baseline:

1. wybrać jeden aktywny RSS;
2. pobrać feed;
3. wybrać jeden wpis;
4. utworzyć temat;
5. research;
6. NarrativePlan;
7. draft;
8. QC;
9. grafiki;
10. gotowa propozycja;
11. preview;
12. ręczna publikacja;
13. sprawdzić:
   - `pages/post-*.html`;
   - home/category;
   - `feed.xml`;
   - `sitemap.xml`;
   - brak duplikatu;
   - poprawny status.

Dopiero po tym wracać do masowej automatyzacji.

---

## 13. Stan pipeline'u po zmianach 2026-08-11

### Gemini i kolejka

- Prompt jest budowany przy tworzeniu `generation_operations`; wspólne opakowanie
  i wywołanie Gemini znajdują się w `php/generation-service.php`.
- Etapy mają własne buildery: research (`research-package-service.php`), NarrativePlan
  (`narrative-plan-service.php`), szkic i naprawy (`article-draft-service.php`), QC
  (`quality-check-service.php`), feedback (`proposal-review-service.php`) oraz Vision
  ilustracji (`article-image-service.php`).
- Zwykły lokalny odstęp między wywołaniami Gemini został usunięty. Nadal honorowane są
  rzeczywiste odpowiedzi `Retry-After` providera oraz limity RPM/TPM/RPD.
- Szkic po zaliczonym QC może mieć status `frozen`; jest prawidłową, nieedytowalną
  wersją do podglądu i review, tak samo jak `completed`.

### Grafiki i gotowość

- Materiał jest gotowy wyłącznie, gdy wszystkie wymagane grafiki mają status
  `downloaded`; brakujące lub ręcznie weryfikowane sloty trafiają do kolejki
  „Wymagające akcji”, nie do „Gotowych propozycji”.
- `completed_with_warnings` nie oznacza ukończonych grafik: interfejs wyświetla
  „Wymaga uwagi — grafiki”, a publikacja pozostaje zablokowana.
- Rekord `downloaded` bez istniejącego lokalnego pliku jest automatycznie przywracany
  do wyszukiwania zamiast błędnie traktowany jako ukończony.
- Domyślna kaskada wyszukiwania źródłowego to 4 zapytania na slot
  (`CMS_SOURCE_IMAGE_QUERY_BUDGET_PER_SLOT`); ogranicza to ryzyko HTTP 429 od
  Wikimedia. Weryfikacja licencji, roli, formatu i Gemini Vision pozostaje fail-closed.
- Dopasowanie kandydata uwzględnia również udokumentowane zapytanie źródłowe, co
  pozwala bezpiecznie oceniać angielskie metadane wobec polskiego planu ilustracji;
  Gemini Vision nadal jest końcową bramką semantyczną.

### Zweryfikowane testy

- `tests/generation-batch-smoke.php`
- `tests/topic-filter-backend-smoke.php`
- `tests/proposal-review-smoke.php`
- `tests/article-image-pipeline-smoke.php`

## 15. Pipeline MVP P01–P10 (2026-08-11)

Aktualny, niepubliczny przepływ jakościowy jest audytowalny przez `generation_operations`, wersje szkiców, QC i rekordy obrazów:

```text
RSS → research → NarrativePlan/VisualPlan → draft → text QC → locked core
→ direct images → bounded related recovery → source-backed additive blocks
→ allowlisted LayoutPlan → final multimodal QC → ready_for_manual_publish → manual publication
```

- Każda faktyczna odpowiedź Gemini jest rozliczana w jednym budżecie artykułu; request #21 jest blokowany przed transportem.
- Hero i wszystkie wymagane sloty są hard gate. Fallback techniczny, brak assetu, prawa, źródła lub brak approved related context blokują gotowość.
- Po udanym text QC core jest `frozen`/`core_text_locked`; kolejne etapy mogą dodać tylko ograniczone elementy addytywne albo korekty punktowe.
- LayoutPlan zawiera wyłącznie allowlistowane struktury. PHP renderuje go deterministycznie i przy nieprawidłowym planie wybiera bezpieczny wariant `standard` z notą audytową.
- Final multimodal QC nie publikuje artykułu i nie może obejść gate’ów deterministycznych. Tylko `PASS` lub `PASS_WITH_MINOR_NOTES` daje wynik `ready_for_manual_publish`; status `published` nadal wymaga ręcznej akcji administratora.

Do weryfikacji na disposable SQLite używane są m.in.:

- `tests/gemini-quota-smoke.php` — budżet oraz blokada #21 przed transportem;
- `tests/p3-core-text-lock-smoke.php` i `tests/quality-check-smoke.php` — lock i QC;
- `tests/article-image-pipeline-smoke.php`, `tests/p4-image-coverage-smoke.php`, `tests/p6-image-recovery-smoke.php` — hero, coverage i recovery;
- `tests/p8-layout-plan-smoke.php` — allowlisted renderer;
- `tests/generation-batch-smoke.php` — batch/RSS workflow bez providerów live.

## 14. Aktualna zasada pracy

Na tym etapie:
- Codex jest głównym wykonawcą;
- lokalne agenty Kilo/Ollama są wstrzymane;
- priorytetem jest redukcja złożoności, a nie kolejne warstwy orchestration;
- każdy cleanup ma zostawić repo prostsze do zrozumienia niż przed zmianą.
