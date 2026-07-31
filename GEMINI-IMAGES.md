# Gemini Free Tier i legalne grafiki źródłowe

## Architektura

Import ręczny i API używają jednego kontraktu. Dostawcę wybiera
`CMS_GENERATION_PROVIDER` (domyślnie `gemini`). Gemini zwraca
`application/json` zgodny z `responseJsonSchema`, po czym wynik przechodzi ten
sam parser, formalny schemat i walidatory dziedzinowe co ręcznie wklejony JSON.
Model nie zapisuje HTML-u.

Szkic zawiera oddzielny plan hero i ilustracji inline: intencję wizualną,
oczekiwaną zawartość, zapytania, sekcję, alt, podpis i layout. Pola URL, autora,
licencji i atrybucji muszą pozostać puste. Uzupełnia się je wyłącznie z
rzeczywistych wyników Wikimedia Commons albo Openverse.

Automatyczna akceptacja obejmuje Public Domain, CC0 i CC BY. Inne licencje
(w tym CC BY-SA/NC) oraz „royalty-free” otrzymują `manual_review`. Brak trafnego
wyniku ma status `missing` i nie blokuje szkicu.

Downloader używa tylko HTTPS i publicznych IP, sprawdza każdy redirect, przypina
zweryfikowany adres IP do cURL, ogranicza czas/rozmiar, porównuje MIME, sygnaturę
i rozszerzenie, sprawdza rozdzielczość oraz deduplikuje pliki po SHA-256.

Renderer przyjmuje kontrolowane bloki: `heading`, `paragraph`, `list`, `quote`,
`section`, `illustration`, `gallery`. Obrazy mają wymiary, podpis, atrybucję i
licencję; inline jest lazy-loaded. Dotychczasowa treść i ręczne znaczniki obrazów
pozostają obsługiwane.

## Konfiguracja

Sekrety ustaw wyłącznie w środowisku procesu PHP:

```dotenv
CMS_GENERATION_MODE=api
CMS_GENERATION_PROVIDER=gemini
GEMINI_API_KEY=...
GEMINI_MODEL=gemini-3.1-flash-lite
GEMINI_API_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_TIMEOUT_SECONDS=60
GEMINI_MAX_ATTEMPTS=3
GEMINI_INITIAL_BACKOFF_MS=750
GEMINI_API_MOCK=false

CMS_AI_IMAGE_GENERATION_ENABLED=false
CMS_SOURCE_IMAGE_PROVIDER=wikimedia
```

Model jest konfigurowalny. Dla HTTP 429 klient wykonuje najwyżej
`GEMINI_MAX_ATTEMPTS` prób z backoffem i respektuje `Retry-After` (maksymalnie
10 sekund jednorazowo). Potem zapisuje czytelny błąd; operację można ponowić
później albo kontynuować importem ręcznym.

`GEMINI_API_MOCK=true` uruchamia lokalną atrapę bez sieci.

## Wyłączenie obrazów AI

`CMS_AI_IMAGE_GENERATION_ENABLED=false` jest wartością domyślną. Dawna ścieżka
Images API nie uruchamia wtedy transportu, tylko zapisuje `skipped` i komunikat.
Ręczny upload działa również po pominięciu. Flaga zachowuje miejsce na przyszłe,
świadome włączenie funkcji.

## Testy offline

```powershell
$env:CMS_ALLOW_GENERATION_SMOKE='1'
php tests/generation-service-smoke.php

$env:CMS_ALLOW_ARTICLE_IMAGE_SMOKE='1'
php tests/article-image-pipeline-smoke.php

$env:CMS_ALLOW_PIPELINE_E2E='1'
$env:CMS_IMAGE_PROCESSOR_PYTHON='C:\path\to\python.exe'
php tests/editorial-pipeline-e2e.php
```

Jedyny test z prawdziwym zapytaniem uruchamia się świadomie:

```powershell
$env:CMS_ALLOW_REAL_GEMINI_SMOKE='1'
$env:GEMINI_API_KEY='...'
$env:GEMINI_MODEL='gemini-3.1-flash-lite'
php scripts/gemini-free-tier-smoke.php
```

Kod wyjścia `3` oznacza limit Free Tier. Ten test nie należy do automatycznego
zestawu.

Pełny test kompozycji zapisujący wyłącznie szkic z zatwierdzonego researchu:

```powershell
$env:CMS_ALLOW_REAL_GEMINI_ARTICLE='1'
php scripts/gemini-article-draft-smoke.php --topics
php scripts/gemini-article-draft-smoke.php --topic=ID
php scripts/gemini-article-draft-smoke.php --list
php scripts/gemini-article-draft-smoke.php --package=ID
php scripts/gemini-article-draft-smoke.php --promote=ID_WERSJI_SZKICU
php scripts/gemini-article-draft-smoke.php --images=ID_POSTA
```

Wariant `--topic` wykonuje kolejno research i kompozycję szkicu. Bez `--package`
skrypt wybiera najnowszą zatwierdzoną paczkę. Poprawny wynik zapisuje jako post
`draft` widoczny w edytorze, ale go nie publikuje i nie uruchamia generatora
obrazów AI. `--promote` przenosi wcześniej utworzoną wersję bez wywołania API.
`--images` wyszukuje legalne obrazy źródłowe, pobiera zaakceptowane pliki
lokalnie i pozostawia nietrafione sloty jako `missing` lub `manual_review`.
