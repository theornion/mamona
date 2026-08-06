# Mamona — Article Image Pipeline Map

## Status

```text
CONFIRMED — MAMONA-24-P0 completed 2026-08-06
Previous: MAMONA-23-P0 completed 2026-08-05 (image pipeline only)
Source: docs/research/MAMONA-24-P0-repository-map.md
```

## End-to-end flow — potwierdzony w kodzie (P0-C2)

```text
article data → article_image_semantic_queries() [php/article-image-service.php:116]
  → search_source_images() [php/article-image-service.php:753]
    → providers (external)
      → kandydaci z metadanami
        → image_rights_manifest_from_record() [php/image-rights-service.php:153]
          → validate_image_rights_manifest() [php/image-rights-service.php:105]
            → article_image_license_is_auto_safe()
              → select_source_image_from_results() [php/article-image-service.php:780]
                → winner candidate
                  → download_source_image() [php/article-image-service.php:999]
                    → create_article_image_variants()
                      → persist_article_image() [php/article-image-service.php:1101]
                        → article_images record (status: downloaded)
                          → render_article_image_record() [php/article-image-service.php:1453]
                            → <figure> HTML lub placeholder lub pusty string
                              → render_article_blocks() [php/article-image-service.php:1596]
                                → render_article_blocks_with_advertising() [php/advertising.php:308]
                                  → render_post_page_html() [php/admin-database.php:1650]
                                    → write_public_file_atomically() [php/publication-service.php:28]
                                      → pages/*.html

WATERFALL FALLBACK (gdy brak trafnych obrazów):
  → salvage_local_editorial_images() [php/salvage-service.php:85]
    → generuje neutralne SVG CC0 w katalogu editorial-fallback/
    → search_audit level='local_fallback'
```

## Verified stages

| # | Etap | Producent / symbol | Dane wejściowe | Dane wyjściowe | Następny konsument | Testy | Ryzyko |
|---|---|---|---|---|---|---|---|
| 1 | Query context | `article_image_semantic_queries()` [php/article-image-service.php:116] | tytuł, kategoria, research, encje artykułu | Lista zapytań semantic cascade | `search_source_images()` | — | Brak negatywnych sygnałów w query builderze |
| 2 | Provider search | `search_source_images()` [php/article-image-service.php:753] | Zapytania z etapu 1 | Surowi kandydaci z providerów | `select_source_image_from_results()` | — | Niezawodność providerów zewnętrznych |
| 3 | Rights validation | `image_rights_manifest_from_record()` [php/image-rights-service.php:153] + `validate_image_rights_manifest()` [L105] + `article_image_license_is_auto_safe()` | Metadane kandydata (license, rights data) | Boolean safety flag + manifest JSON | `select_source_image_from_results()` | `tests/image-rights-providers-smoke.php` | Prawa ≠ trafność redakcyjna |
| 4 | Ranking & selection | `select_source_image_from_results()` [php/article-image-service.php:780] | Kandydaci z walidacją praw | Wygrany kandydat z relevance score | `download_source_image()` | — | **R2: ranking może premiuować pojedynczy token** |
| 5 | Download & processing | `download_source_image()` [php/article-image-service.php:999] + `create_article_image_variants()` | URL źródłowy, winner candidate | Plik na dysku `images/posts/sources/source-{sha256}.{ext}` + warianty | `persist_article_image()` | — | Nieudany download → częściowe metadane? |
| 6 | Persistence | `persist_article_image()` [php/article-image-service.php:1101] | Kandydat + plik + metadane + manifest JSON | Rekord w `article_images` (status: `planned`/`selected`/`downloaded`/`missing`/`manual_review`) | `render_article_image_record()` | `tests/article-image-pipeline-smoke.php` | Pola caption/alt/credit muszą należeć do finalnego assetu |
| 7 | Reject cleanup | `reject_article_source_image()` [php/article-image-service.php:1230] | Odrzucony kandydat | `local_path` cleared, `rights_manifest_json = "{}"`, plik usunięty jeśli brak referencji | — | — | Residualne metadane po odrzuceniu |
| 8 | Rendering check | `render_article_image_record()` [php/article-image-service.php:1453] L1455 | `$image['status']`, `$image['license']`, rights manifest | Decyzja: renderować obraz / placeholder / pusty string | — | `tests/post-renderer-smoke.php` | **R1: fallback może dziedziczyć caption kandydata** |
| 9 | Fallback HTML | `render_article_image_record()` [php/article-image-service.php:1456-1463] | Status (`manual_review`, `missing`) | `<figure class="article-illustration article-illustration--placeholder">` z komunikatem | — | — | Caption/alt muszą być neutralne, nie odziedziczone |
| 10 | Missing file | `render_article_image_record()` [php/article-image-service.php:1470] | `!is_file(app_path($path))` | Pusty string — rendering ignoruje obraz | — | — | Brak `<img>` w HTML, ale caption nie powinien pozostać |
| 11 | Blocks renderer | `render_article_blocks()` [php/article-image-service.php:1596] | Bloki (heading, paragraph, quote, list, section, illustration, gallery) | HTML fragmentów artykułu | `render_article_blocks_with_advertising()` lub preview | — | — |
| 12 | Advertising wrapper | `render_article_blocks_with_advertising()` [php/advertising.php:308] | Bloki + plan reklam | Bloki ze wstrzykniętymi reklamami na granicach | `render_post_page_html()` | — | Sloty reklam zależne od długości tekstu |
| 13 | Public page render | `render_post_page_html()` [php/admin-database.php:1650] + `post_absolute_image_url()` [L1462] | Post data + obraz(y) | Pełny HTML strony | `write_public_file_atomically()` | `tests/generate-all-regression.php` | — |
| 14 | Atomic publish | `write_public_file_atomically()` [php/publication-service.php:28] | Gotowy HTML | Plik w `pages/` zapisany atomowo | — | `tests/editorial-pipeline-e2e.php` | — |
| 15 | **Salvage fallback** | `salvage_local_editorial_images()` [php/salvage-service.php:85] | Brak trafnych obrazów po waterfallu | SVG CC0 w `editorial-fallback/`, search_audit level='local_fallback' | `persist_article_image()` → renderer | — | **PROBLEM MAMONA-24: fallback renderowany jako asset finalny** |

## Metadata lineage

| Pole | Gdzie powstaje | Gdzie może być zmienione | Finalny konsument | Czy związane z asset ID |
|---|---|---|---|---|
| asset id | Provider response → `search_source_images()` (L753) | Niezmieniany | `persist_article_image()`, renderer | Tak |
| local_path | `download_source_image()` (L999) — `images/posts/sources/source-{sha256}.{ext}` | `reject_article_source_image()` czyści | `render_article_image_record()` via `is_file()` | Tak |
| source_page_url | Provider metadata → `persist_article_image()` (L1101) | Niezmieniany po zapisie | Renderer HTML, diagnostyka | Tak |
| direct_file_url | Provider response → `download_source_image()` | Niezmieniany | Download etapu | Tak |
| creator/attribution | Provider metadata → `persist_article_image()` (L1101) | Niezmieniany po zapisie | Renderer HTML `<figcaption>` credit | Tak |
| caption | Provider description → `persist_article_image()` (L1101) | **R1: może być odziedziczony przez fallback** | Renderer HTML `<figcaption>` | Musi należeć do finalnego assetu |
| alt | Provider alt text → `persist_article_image()` (L1101) | **R1: może być odziedziczony przez fallback** | Renderer HTML `<img alt="">` | Musi należeć do finalnego assetu |
| license | Provider license → `persist_article_image()` (L1101) | Niezmieniany po zapisie | `article_image_license_is_auto_safe()`, renderer | Tak |
| rights_manifest_json | `image_rights_manifest_from_record()` (L153) → `persist_article_image()` (L1236) | `reject_article_source_image()` ustawia `"{}"` | `validate_image_rights_manifest()`, renderer | Tak |
| status | `persist_article_image()` ustawia; zmienia się przy download/reject/missing | `download_source_image()`, `reject_article_source_image()` | Renderer check L1455: `$image['status'] !== 'downloaded'` | Tak |
| fallback flag/type | Implicit — brak finalnego pliku lub status ≠ downloaded | Niejawny w rendererze | CSS class `article-illustration--placeholder`, `data-image-status` | Nie — to meta-flaga renderera |

## Problemy do usunięcia w MAMONA-24

### Placeholdery renderowane jako assety
- `render_article_image_record()` generuje `<figure class="article-illustration article-illustration--placeholder">` z captionem, który może pochodzić z odrzuconego kandydata (R1).
- Placeholder nie powinien być renderowany w finalnym artykule ani zaliczać minimalnej liczby grafik.

### Fallbacki techniczne jako grafiki końcowe
- `salvage_local_editorial_images()` [php/salvage-service.php:85] generuje SVG CC0 z labelką "Ilustracja redakcyjna".
- Ten asset może trafić do finalnego renderu i być uznany za prawidłową grafikę.
- **Wymagane:** fallback jako wewnętrzny sygnał niepowodzenia, nigdy jako renderowany asset finalnego artykułu.

### Grafiki redakcyjne zastępcze
- Brak jawnej flagi odróżniającej rzeczywistą grafikę od zastępczej w `article_images`.
- `search_audit_json` i `search_audit level='local_fallback'` są dostępne, ale renderer nie blokuje publikacji z takim assetem.

### Brak wymaganych grafik
- Nie istnieje mechanizm wymuszający minimalną liczbę trafnych grafik przed uznaniem artykułu za ukończony.
- Pipeline kończy się na `ready_for_preview` bez sprawdzenia czy każdy slot ma rzeczywisty, legalny i redakcyjnie trafny asset.

### Ranking premiuje pojedynczy token (R2)
- `select_source_image_from_results()` [php/article-image-service.php:780] nie odróżnia legalności od trafności redakcyjnej.
- Brak bramki semantycznej/redakcyjnej odrzucającej satyrę, zombie, gore, memy.

## Confirmed regression hypotheses

| Hipoteza | Dowód | Status |
|---|---|---|
| **R1: fallback dziedziczy metadata kandydata** — caption i alt odrzuconego/niedostępnego obrazu są wyświetlane z placeholderem zamiast neutralnego tekstu | `render_article_image_record()` [php/article-image-service.php:1453] — struktura placeholdera (L1456-1463) sugeruje własny caption, ale wymaga potwierdzenia czy metadane kandydata nie są przekazywane do fallbacku przed odrzuceniem | hipoteza — wymaga P1 root cause |
| **R2: ranking premiuje pojedynczy token** — satyryczny obraz "Big Orange Zombie Eating Brains" wygrywa dla artykułu o neuroplastyczności przez token `brain` | `select_source_image_from_results()` [php/article-image-service.php:780] — funkcja rankingu nie sprawdza negatywnych sygnałów (polityka, satyra, zombie) ani semantycznej spójności z tematem artykułu | hipoteza — wymaga P1 root cause |
| renderer nie sprawdza finalnego pliku | `render_article_image_record()` [php/article-image-service.php:1470] — sprawdza `is_file(app_path($path))` i zwraca pusty string jeśli plik nie istnieje | ODRZUCONE — renderer sprawdza istnienie pliku |

## Key files summary

| Plik | Rola |
|---|---|
| `php/article-image-service.php` | Główny: selekcja, pobieranie, persistence, rendering obrazów (13+ funkcji) |
| `php/image-rights-service.php` | Walidacja praw, manifesty licencji, auto-safe check |
| `php/salvage-service.php` | Deterministyczny fallback: safe composer draftu + SVG editorial images |
| `php/admin-database.php` | Publiczne renderowanie stron artykułu, URL-e obrazów |
| `php/advertising.php` | Wrapper reklamowy nad rendererem bloków |
| `php/publication-service.php` | Atomowy zapis plików publicznych |
