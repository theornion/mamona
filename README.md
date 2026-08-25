# Mamona

Lekki CMS redakcyjny w PHP 8.1+ i SQLite. Docelowy przepływ MVP to:

```text
RSS → temat → research → szkic → QC → grafiki → preview → ręczna publikacja
```

Aktualny stan projektu oraz ograniczenia są w [docs/MVP_STATE.md](docs/MVP_STATE.md).
Aktywna praca i następne kroki są w [docs/CURRENT_WORK.md](docs/CURRENT_WORK.md).
Procedury operacyjne opisuje [OPERATIONS.md](OPERATIONS.md).

## Konfiguracja

Konfiguracja jest pobierana ze zmiennych środowiskowych przez
`php/app-config.php`. Plik `.env.example` jest tylko dokumentacją — aplikacja
nie ładuje automatycznie `.env`.

Minimalne ustawienia produkcyjne:

```text
CMS_ENV=production
CMS_PUBLIC_URL=https://twoja-domena.pl
```

Nie zapisuj w repozytorium sekretów, w tym `GEMINI_API_KEY`, danych SMTP ani
poświadczeń administratora. Lokalny serwer PHP i worker muszą mieć te same
zmienne środowiskowe.

## Dane i publiczność

- Lokalna baza: `data/cms.sqlite`.
- Status `posts.status` jest źródłem prawdy dla widoczności.
- Tylko nieusunięty wpis o statusie `published` może mieć publiczny HTML,
  występować w RSS i sitemapie.
- Szkic oraz preview pozostają niepubliczne.
- Publikacja jest świadomą akcją redakcyjną; pipeline nie publikuje sam.

## Uruchomienie lokalne

Wymagane rozszerzenia PHP: PDO SQLite, mbstring, DOM/XML, cURL, fileinfo i
zlib. Aplikacja potrzebuje zapisu do `data/`, `pages/`, `images/posts/`,
`feed.xml`, `sitemap.xml`, `robots.txt` oraz `index.html`.

Przykład serwera lokalnego XAMPP:

```powershell
$env:CMS_ENV='development'
$env:CMS_PUBLIC_URL='http://localhost:8000'
C:\xampp\php\php.exe -S localhost:8000 -t C:\Projekty\mamona
```

## Pipeline i grafiki

Szczegółowy aktualny kontrakt pipeline'u, mapę promptów Gemini, klasyfikację
gotowości oraz zachowanie grafik opisuje [docs/MVP_STATE.md](docs/MVP_STATE.md).
Materiał z brakującą grafiką nie jest gotową propozycją ani kandydatem do publikacji.

## Bezpieczny test MVP

Do testów bez providerów używaj mocków i izolowanych baz wskazanych przez
smoke testy. Nie uruchamiaj Gemini ani publikacji podczas cleanupu.

```powershell
$env:CMS_ALLOW_CONTENT_STUDIO_SMOKE='1'; php tests/content-studio-smoke.php
$env:CMS_ALLOW_BATCH_SMOKE='1'; php tests/generation-batch-smoke.php
$env:CMS_ALLOW_ARTICLE_IMAGE_SMOKE='1'; php tests/article-image-pipeline-smoke.php
$env:CMS_ALLOW_SSR_SMOKE='1'; php tests/server-rendered-feed-smoke.php
```

Pełne wymagania uruchomieniowe, odzyskiwanie po błędzie, kopie zapasowe i
konfiguracja workerów pozostają w [OPERATIONS.md](OPERATIONS.md).
