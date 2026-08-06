# Mamona — Architecture Map

## Status

```text
CONFIRMED — MAMONA-23-P0 completed 2026-08-05
```

Ten plik przechowuje trwałą, potwierdzoną mapę architektury. Nie zapisuj tutaj hipotez bez oznaczenia.

## Confirmed baseline

- PHP 8.1+ i SQLite/PDO.
- Publiczne strony i panel administratora są częścią jednego przepływu redakcyjnego.
- `editorial_status` steruje publiczną widocznością.
- Wygenerowane pliki publiczne muszą być zapisywane atomowo.
- Obrazy wymagają śledzenia praw, źródła i creditu.

## Entry points

| Obszar | Plik/symbol | Odpowiedzialność | Dowód | Status |
|---|---|---|---|---|
| Publiczna strona artykułu | `php/admin-database.php` → `render_post_page_html()` (linia 1596) | Renderuje pełny HTML strony artykułu z obrazami | Semantic scan + kod | potwierdzone |
| Atomowy zapis pliku publicznego | `php/publication-service.php` → `write_public_file_atomically()` (linia 28) | Atomic write generated public pages | Semantic scan + kod | potwierdzone |
| Renderer obrazu pojedynczego | `php/article-image-service.php` → `render_article_image_record()` (linia 1453) | Renderuje `<figure>` z fallback placeholderem lub pusty string | Semantic scan + kod | potwierdzone |
| Składanie bloków treści z obrazami | `php/article-image-service.php` → `render_article_blocks()` (linia 1596) | Łączy bloki `illustration` i `gallery` po ID obrazu | Semantic scan + kod | potwierdzone |
| Publiczny URL obrazu | `php/admin-database.php` → `post_absolute_image_url()` (linia 1462) | Zwraca publiczny URL lub pusty string | Semantic scan | potwierdzone |
| Selekcja — zapytania semantic | `php/article-image-service.php` → `article_image_semantic_queries()` (linia 116) | Buduje zapytania semantic cascade dla providerów | Semantic scan + kod | potwierdzone |
| Selekcja — wyszukiwanie kandydatów | `php/article-image-service.php` → `search_source_images()` (linia 753) | Przeszukuje providerów i zwraca kandydatów | Semantic scan + kod | potwierdzone |
| Selekcja — wybór zwycięzcy | `php/article-image-service.php` → `select_source_image_from_results()` (linia 780) | Wybiera najlepszy kandydat z wyników | Semantic scan + kod | potwierdzone |
| Pobieranie obrazu | `php/article-image-service.php` → `download_source_image()` (linia 999) | Atomowy zapis pliku, tworzenie wariantów | Semantic scan + kod | potwierdzone |
| Zapis rekordu obrazu | `php/article-image-service.php` → `persist_article_image()` (linia 1101) | Zapisuje wszystkie metadane i status do bazy | Semantic scan + kod | potwierdzone |
| Odrzucenie obrazu | `php/article-image-service.php` → `reject_article_source_image()` (linia 1230) | Czyści metadane, usuwa plik jeśli brak referencji | Semantic scan + kod | potwierdzone |
| Walidacja praw — manifest | `php/image-rights-service.php` → `image_rights_manifest_from_record()` (linia 153) | Buduje manifest praw z rekordu obrazu | Semantic scan + kod | potwierdzone |
| Walidacja praw — validate | `php/image-rights-service.php` → `validate_image_rights_manifest()` (linia 105) | Waliduje manifest praw przed renderowaniem | Semantic scan + kod | potwierdzone |

## Modules

| Moduł | Kluczowe pliki/symbole | Wejścia | Wyjścia | Zależności |
|---|---|---|---|---|
| Selection pipeline | `php/article-image-service.php` — `article_image_semantic_queries()`, `search_source_images()`, `select_source_image_from_results()` | Dane artykułu (tytuł, kategoria, research, encje) | Lista kandydatów z rankingiem | image-rights-service (licencje), providerzy zewnętrzni |
| Download & processing | `php/article-image-service.php` — `download_source_image()`, `create_article_image_variants()` | Wygrany kandydat + URL źródłowy | Plik na dysku (`images/posts/sources/source-{sha256}.{ext}`) + warianty | Network fetcher (SSRF, timeouts), PHP GD lub Python Pillow |
| Persistence | `php/article-image-service.php` — `persist_article_image()`, `reject_article_source_image()` | Kandydat + plik lokalny + metadane | Rekord w `article_images` ze statusem (`planned`, `selected`, `downloaded`, `missing`, `manual_review`) | image-rights-service (manifest JSON) |
| Rights validation | `php/image-rights-service.php` — `image_rights_manifest_from_record()`, `validate_image_rights_manifest()`, `article_image_license_is_auto_safe()` | Rekord obrazu (`license`, `rights_manifest_json`) | Boolean safety + manifest | Brak zewnętrznych zależności |
| Rendering | `php/article-image-service.php` — `render_article_image_record()`, `render_article_blocks()` | Rekord obrazu z bazy + rights manifest | HTML `<figure>` lub placeholder lub pusty string | `app_path()`, `is_file()` dla checków pliku |
| Public page generation | `php/admin-database.php` — `render_post_page_html()`, `post_absolute_image_url()` | Post data + obraz(y) | Pełny HTML strony artykułu | article-image-service, publication-service |
| Atomic publish | `php/publication-service.php` — `write_public_file_atomically()` | Gotowy HTML | Plik w `pages/` zapisany atomowo | Brak zewnętrznych zależności |

## Data contracts

| Rekord/struktura | Producent | Konsument | Pola krytyczne | Inwarianty |
|---|---|---|---|---|
| `article_images` rekord | `persist_article_image()` (linia 1101) | `render_article_image_record()`, `post_absolute_image_url()` | `local_path`, `status`, `license`, `rights_manifest_json`, `attribution`, `alt`, `caption`, `source_page_url` | Status `downloaded` + auto-safe license wymagane do renderowania; fallback nie dziedziczy pól kandydata |
| Rights manifest JSON | `image_rights_manifest_from_record()` (linia 153) → zapis przez `persist_article_image()` (linia 1236) | `validate_image_rights_manifest()` (linia 105), renderer via `$image['rights_manifest']` | Pola praw, licencji, creditu | Manifest musi być valid przed renderowaniem; pusty manifest → fallback |
| Fallback placeholder HTML | `render_article_image_record()` (linia 1456-1463) | Publiczna strona artykułu | Klasa CSS `article-illustration--placeholder`, `data-image-status`, neutralny caption | Caption i alt muszą być własne, nie odziedziczone po odrzuconym kandydacie |
| Semantic query cascade | `article_image_semantic_queries()` (linia 116) | `search_source_images()` (linia 753) | Zapytania zbudowane z tematu, tytułu, kategorii, encji | Nie modyfikuje danych artykułu; tylko odczyt |

## Test map

| Obszar | Testy | Typ | Mutuje dane | Wymagane flagi |
|---|---|---|---|---|
| Image pipeline smoke | `tests/article-image-pipeline-smoke.php` | Smoke | Tak | `CMS_ALLOW_*` (sprawdzić w teście) |
| Image rights providers | `tests/image-rights-providers-smoke.php` | Smoke | Nie | — |
| Full auto selector | `tests/full-auto-selector-smoke.php` | Smoke | Nie | — |
| Post renderer | `tests/post-renderer-smoke.php` | Smoke | Nie | — |
| Generate all regression | `tests/generate-all-regression.php` | Regression | Tak | `CMS_ALLOW_*` (sprawdzić w teście) |
| Editorial pipeline E2E | `tests/editorial-pipeline-e2e.php` | E2E | Tak | `CMS_ALLOW_PIPELINE_E2E`, `CMS_IMAGE_PROCESSOR_PYTHON` |

## Update rule

Aktualizuj wyłącznie po potwierdzeniu kodem, testem albo konfiguracją. Podawaj ścieżkę i symbol.
