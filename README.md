# Mamona

Mamona to redakcyjny CMS i pipeline automatyzacji treści w PHP 8.1+ z bazą
SQLite. Jego celem jest audytowalne przeprowadzenie materiału od źródła RSS do
świadomej publikacji:

```text
RSS → temat → research → NarrativePlan/draft → QC → obrazy → preview
→ ręczna publikacja → publiczny HTML/RSS/sitemap
```

System łączy panel administratora, wersjonowane artefakty redakcyjne, kolejkę
generowania, kontrolę jakości, pozyskiwanie grafik z kontrolą praw oraz
generowanie statycznych plików publicznych.

## Architektura

### Stack i runtime

- PHP 8.1+; aplikacja webowa i procesy CLI używają wspólnego kodu.
- SQLite przez PDO; główna lokalna baza to `data/cms.sqlite`.
- HTML publiczny jest generowany do plików. Aplikacja zapisuje pliki
  publikacji atomowo.
- Zewnętrzne źródła to RSS/Atom, providerzy grafik oraz opcjonalny provider AI
  (domyślnie Gemini; obsługiwany jest także OpenAI).

### Przepływ redakcyjny

1. **RSS** — `admin-content-studio.php`, `content-studio-service.php`,
   `feed-ingestion-service.php` i `technical-source-repository.php` pobierają,
   normalizują i zapisują wpisy z aktywnych `technical_sources` jako
   `discovered_feed_items`.
2. **Temat** — `topic-grouping-service.php` i `topic-scoring-service.php`
   grupują odkrycia oraz nadają tematom priorytet. Obsługa redakcyjna jest w
   `admin-editorial-topics.php`.
3. **Research** — `research-package-service.php` utrwala zweryfikowane źródła,
   evidence i politykę researchu w `verified_research_sources`,
   `research_packages` oraz audycie polityki. Generowanie wymaga zatwierdzonej
   paczki researchowej.
4. **NarrativePlan i draft** — `narrative-plan-service.php` tworzy i utrwala
   NarrativePlan, a `article-draft-service.php` waliduje oraz wersjonuje szkic.
   `generation-service.php`, `generation-batch-service.php` i
   `generation-batch-worker.php` obsługują operacje, retry, quota, mocki i
   kolejkę.
5. **QC** — `quality-check-service.php` łączy wynik modelu z walidacjami
   deterministycznymi, hard blockami i wersjonowanym `quality_check_runs`.
   `repair-router-service.php` kieruje ograniczone naprawy.
6. **Obrazy** — `article-image-service.php` buduje VisualPlan/sloty, wyszukuje
   kandydatów, pobiera pliki, tworzy warianty i zapisuje `article_images`.
   `image-rights-service.php` sprawdza licencję, źródło, credit i manifest
   praw; wymagane grafiki muszą przejść także ocenę multimodalną.
7. **Preview** — `admin-post-preview.php` renderuje niepubliczny podgląd z
   wersji szkicu i bloków artykułu.
8. **Ręczna publikacja** — panel propozycji wywołuje
   `change_post_editorial_status(..., 'published')`. Generowanie samo nie
   publikuje; planowanie publikacji obsługuje osobny scheduler.
9. **Public output** — `admin-database.php` renderuje strony, a
   `publication-service.php` zapisuje je atomowo. `admin-database.php`
   synchronizuje `index.html`, `pages/post-*.html` i strony kategorii,
   natomiast `discovery-service.php` generuje `feed.xml`, `sitemap.xml` oraz
   `robots.txt`.

### Główne moduły i entrypointy

| Obszar | Pliki / symbole |
| --- | --- |
| Panel i studio | `php/admin-content-studio.php`, `php/admin-editorial-queue.php`, `php/admin-proposals.php` |
| RSS i odkrycia | `php/feed-ingestion-service.php` → `fetch_remote_feed()`, `php/discovery-service.php` |
| Tematy | `php/topic-grouping-service.php` → `group_discovered_feed_item()`, `php/topic-scoring-service.php` |
| Research | `php/research-package-service.php`, `php/source-enrichment-service.php` |
| Generowanie | `php/generation-service.php` → `execute_generation_operation()`, `php/generation-batch-service.php` → `generation_batch_process_item()`, `php/generation-batch-worker.php` |
| NarrativePlan | `php/narrative-plan-service.php` → `generate_narrative_plan()` |
| Draft | `php/article-draft-service.php` → `promote_article_draft_to_post()` |
| QC i naprawy | `php/quality-check-service.php` → `prepare_quality_check_operation()`, `php/repair-router-service.php` |
| Obrazy i prawa | `php/article-image-service.php`, `php/image-rights-service.php` |
| Renderowanie i publikacja | `php/admin-post-preview.php`, `php/admin-database.php` → `render_post_page_html()`, `php/publication-service.php` → `write_public_file_atomically()` |
| Harmonogram i CLI | `php/publish-scheduled.php`, `php/fetch-feeds.php`, `php/group-topics.php`, `php/score-topics.php`, `php/cli-*.php` |

### Statusy i bramki bezpieczeństwa

Kanonicznym statusem widoczności jest `posts.status`; wartości redakcyjne to:
`idea`, `research`, `draft`, `review`, `scheduled`, `published`, `rejected`.
`is_published` jest polem synchronizowanym/legacy. Publiczny może być tylko
wpis ze statusem `published` i bez `deleted_at`.

Statusy końcowe kolejki generowania obejmują `ready_for_preview`,
`ready_with_notes`, `ready`, `manual_review`, `waiting_review`, `failed`,
`cancelled`, `invalid`, `skipped_prerequisite` i `paused_by_operator`.
`ready*` oznacza gotowość do dalszego działania redakcyjnego, nie automatyczną
publikację.

Pipeline i publikacja są blokowane, gdy występuje między innymi:

- brak ukończonego i produkcyjnie kwalifikowanego QC, aktywny hard block albo
  wynik poniżej `QUALITY_PASS_SCORE` (75/100);
- brak zatwierdzonego researchu w generowaniu albo brak wymaganych grafik,
  lokalnych plików, praw, akceptacji multimodalnej lub kompletnego coverage
  przed publikacją;
- techniczny fallback obrazu, niezablokowany `core_text` albo stan
  `manual_review` wymagający decyzji człowieka;
- brak konfiguracji zaufania wymaganej dla publikacji produkcyjnej.

Feed i obrazy stosują ograniczenia transportu oraz SSRF. Quota, lease'y,
retry i audyt operacji są utrwalane w SQLite. Testowe mocki i operacje
mutujące używają izolowanej bazy wskazanej przez `CMS_TEST_DATABASE_FILE`.

## Struktura repozytorium

- `php/` — konfiguracja, entrypointy panelu, serwisy domenowe, renderery i CLI.
- `data/` — SQLite oraz manifesty danych generowanych.
- `pages/`, `index.html`, `feed.xml`, `sitemap.xml` — szablony i wynikowe pliki
  publiczne.
- `images/` — obrazy artykułów i warianty.
- `assets/` — CSS, JavaScript i zasoby interfejsu.
- `tests/` — smoke, kontrakty, regresje i E2E.
- `scripts/` — narzędzia operatorskie oraz testy providerów.
- `docs/` — stan MVP, architektura i bieżąca praca.

## Konfiguracja

Konfiguracja jest czytana ze zmiennych środowiskowych przez
`php/app-config.php`. `.env.example` dokumentuje dostępne zmienne, ale
aplikacja nie ładuje automatycznie pliku `.env`.

Minimalnie ustaw:

```text
CMS_ENV=development
CMS_PUBLIC_URL=http://localhost:8000
```

W produkcji skonfiguruj również dane witryny i zaufania, a dla generowania
odpowiedni provider oraz jego sekret, np. `GEMINI_API_KEY` lub
`OPENAI_API_KEY`. Nie zapisuj sekretów w repozytorium. Serwer WWW i worker CLI
muszą widzieć te same zmienne. Pełna lista opcji, timeoutów, limitów i
providerów znajduje się w `.env.example` oraz `OPERATIONS.md`.

## Uruchomienie

Wymagane są PHP 8.1+ oraz rozszerzenia PDO SQLite, mbstring, DOM/XML, cURL,
fileinfo i zlib. Proces musi mieć zapis do `data/`, `pages/`, `images/posts/`
oraz generowanych plików publicznych.

Przykład lokalnego serwera PHP:

```powershell
$env:CMS_ENV='development'
$env:CMS_PUBLIC_URL='http://localhost:8000'
C:\xampp\php\php.exe -S localhost:8000 -t C:\Projekty\mamona
```

Operacje workerów, schedulerów, backupów i providerów opisuje
[`OPERATIONS.md`](OPERATIONS.md).

## Testy

Testy uruchamiaj na izolowanej bazie testowej i bez live providerów, chyba że
konkretny test wymaga innego trybu. Przykładowe smoke testy:

```powershell
$env:CMS_ALLOW_CONTENT_STUDIO_SMOKE='1'; php tests/content-studio-smoke.php
$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php
$env:CMS_ALLOW_ARTICLE_IMAGE_SMOKE='1'; php tests/article-image-pipeline-smoke.php
$env:CMS_ALLOW_SSR_SMOKE='1'; php tests/server-rendered-feed-smoke.php
```

Dodatkowe testy kontraktów i bramek są w `tests/`, między innymi dla
NarrativePlan, draftów, QC, praw obrazów, publikacji, statusów i resetu.
Nie uruchamiaj testów live ani publikacji na produkcyjnej bazie. Aktualny stan
funkcji i ograniczeń znajduje się w [`docs/MVP_STATE.md`](docs/MVP_STATE.md),
potwierdzona mapa architektury w [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md),
a bieżący zakres pracy w [`docs/CURRENT_WORK.md`](docs/CURRENT_WORK.md).
