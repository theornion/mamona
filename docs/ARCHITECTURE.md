# Mamona — Architecture Map

## Status

```text
CONFIRMED — MAMONA-24-P1 completed 2026-08-06
Previous: MAMONA-24-P0 completed 2026-08-06; MAMONA-23-P0 completed 2026-08-05 (image pipeline only)
Source: docs/research/MAMONA-24-P0-repository-map.md, docs/research/MAMONA-24-P1-approved-handoff.md
Spec: docs/specs/MAMONA-24-article-generation-visual-narrative-v3.md
```

Ten plik przechowuje trwałą, potwierdzoną mapę architektury. Nie zapisuj tutaj hipotez bez oznaczenia.

## Confirmed baseline

- PHP 8.1+ i SQLite/PDO.
- Publiczne strony i panel administratora są częścią jednego przepływu redakcyjnego.
- `editorial_status` steruje publiczną widocznością.
- Wygenerowane pliki publiczne muszą być zapisywane atomowo.
- Obrazy wymagają śledzenia praw, źródła i creditu.
- Pipeline generowania NIGDY nie publikuje — kończy się na `ready_for_preview` / `ready_with_notes` / `ready`.
- Świadoma publikacja tylko przez `change_post_editorial_status(..., 'published')` z panelu admina.

---

## Stan aktualny — potwierdzony w kodzie (P0-A2/B2/C2/D2)

### Pełny przepływ generowania artykułu

```
editorial_topic → create_generation_batch() → generation_batch_dispatch_stage()
  → execute_generation_operation() [php/generation-service.php:1105]
    → gemini_quota_acquire() [php/gemini-quota-service.php:79]
    → gemini_cached_call() [cache hit shortcut]
    → $transport($payload, $apiKey, $operationKey, $model)
      → gemini_curl_transport() [php/generation-service.php:890]
        → curl POST do GEMINI_API_BASE_URL/models/{model}:generateContent
    → gemini_extract_output() [php/generation-service.php:160]
    → complete_generation_with_title_repair() [php/generation-service.php:688]
    → gemini_quota_release() [php/gemini-quota-service.php:152]
  → repair_router_assess() [php/repair-router-service.php:9]
  → quality_check_auto_repair_decision() [php/quality-check-service.php:656]
  → salvage_execute_safe_composer() [php/salvage-service.php:46] — deterministyczny fallback
```

### Pipeline obrazów (dziedziczony z TASK-23, potwierdzony w P0-C2)

```
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
                              → render_post_page_html() [php/admin-database.php:1596]
                                → write_public_file_atomically() [php/publication-service.php:28]
                                  → pages/*.html
```

### Entry points

| Obszar | Plik/symbol | Odpowiedzialność | Dowód | Status |
|---|---|---|---|---|
| Dispatcher generacji | `php/generation-service.php` → `execute_generation_operation()` (L1105) | Główny dispatcher, rozdziela gemini vs openai | P0-B2 | potwierdzone |
| Transport Gemini | `php/generation-service.php` → `gemini_curl_transport()` (L890) | Jedyny transport HTTP do Gemini API Free Tier | P0-B2 | potwierdzone |
| Transport OpenAI | `php/generation-service.php` → `execute_openai_generation_operation()` (L982) | Alternatywny provider OpenAI | P0-B2 | potwierdzone |
| Mock transport | `php/generation-service.php` (L1151-1174) | Budowany inline gdy `gemini_mock=1` | P0-B2 | potwierdzone |
| Quota admission | `php/gemini-quota-service.php` → `gemini_quota_acquire()` (L79) | RPM/TPM/RPD, lease concurrency, cache | P0-B2 | potwierdzone |
| Budżet tematyczny | `php/gemini-quota-service.php` (L131-134) | Max 15 wywołań Gemini na temat; przy 13 blokuje poza draft; przy 14 poza QC | P0-B2 | potwierdzone |
| Batch dispatch | `php/generation-batch-service.php` → `generation_batch_dispatch_stage()` | Orkiestracja pipeline'u, retry batch-level, auto-repair loop | P0-B2 | potwierdzone |
| Repair router | `php/repair-router-service.php` → `repair_router_assess()` (L9) | Konwertuje wynik QC na gates i strategie naprawy; budżet stage:3 / global:9 | P0-B2 | potwierdzone |
| Salvage — safe composer | `php/salvage-service.php` → `salvage_execute_safe_composer()` (L46) | Deterministyczny fallback draftu bez Gemini | P0-B2 | potwierdzone |
| Salvage — obrazy | `php/salvage-service.php` → `salvage_local_editorial_images()` (L85) | Fallback obrazów: SVG CC0, search_audit level='local_fallback' | P0-C2 | potwierdzone |
| QC — przygotowanie | `php/quality-check-service.php` → `prepare_quality_check_operation()` (L141) | Tworzy operację QC; wymaga statusu 'completed' szkicu | P0-C2 | potwierdzone |
| QC — auto-repair routing | `php/quality-check-service.php` → `quality_check_auto_repair_decision()` (L656) | Ryzyko prawne/medyczne → człowiek; brak źródeł → research; pozostałe → auto-repair | P0-C2 | potwierdzone |
| QC — human review | `php/quality-check-service.php` → `review_quality_risk()` (L605) | Decyzja człowieka nad hard block 'high_risk_without_human_approval' | P0-C2 | potwierdzone |
| Draft — limity | `php/article-draft-service.php` → `article_draft_length_policy()`, `validate_article_draft_output()` | Min/max znaków, brief 80-220, tytuł 35-100 | P0-A2 | potwierdzone |
| Draft — promocja | `php/article-draft-service.php` → `promote_article_draft_to_post()` (L1421) | Materializuje szkic jako post (bez published) | P0-B2 | potwierdzone |
| Publikacja świadoma | `php/admin-proposals.php` → `change_post_editorial_status(..., 'published')` (L106) | Jedyna ścieżka publikacji z panelu admina | P0-B2/D2 | potwierdzone |
| Renderer bloków | `php/article-image-service.php` → `render_article_blocks()` (L1596) | Typy: heading, paragraph, quote, list, section, illustration, gallery | P0-C2 | potwierdzone |
| Renderer obrazu | `php/article-image-service.php` → `render_article_image_record()` (L1453) | `<figure>` z fallback placeholderem lub pusty string | P0-C2 | potwierdzone |
| Renderer publiczny | `php/admin-database.php` (L1650) | Używa wersji z advertising wrapperem | P0-C2 | potwierdzone |
| Preview renderer | `php/admin-post-preview.php` (L32) | Używa czystego `render_article_blocks()` | P0-C2 | potwierdzone |
| Atomowy zapis | `php/publication-service.php` → `write_public_file_atomically()` (L28) | Atomic write generated public pages | TASK-23 | potwierdzone |
| Zmiana statusu | `php/admin-database.php` → `change_post_editorial_status()` (L1956) | Transakcyjna zmiana z audytem, walidacją przejść, blokadą bez QC | P0-D2 | potwierdzone |

### Typy tekstów i limity

| Pole | Min | Max | Walidacja |
|---|---|---|---|
| Treść główna (informational) | 3000 | 5000 | `validate_article_draft_output()` |
| Treść główna (problem_discovery_return) | 4000 | 5000 | `validate_article_draft_output()` |
| Brief | 80 | 220 | `validate_article_draft_output()` L1125-1128 |
| Tytuł | 35 | 100 | `article_title_surface_error()` L167-172 |
| SEO description | — | 160 | `editorial-editor-service.php` |
| image_alt | — | 250 | `editorial-editor-service.php` |
| ai_disclosure | — | 1000 | `editorial-editor-service.php` |

Liczenie: `mb_strlen(implode(...))` w `article_draft_main_content_length()`.

### Retry i ponowienia

| Mechanizm | Lokalizacja | Limit | Szczegóły |
|---|---|---|---|
| Transport retry (inline loop) | `generation-service.php` L1200-1324 | `gemini_max_attempts` (domyślnie 3, +1 za fallback model) | Exponential backoff max 10s; HTTP 0/408/429/5xx |
| Contract repair retry | `generation-service.php` L1270-1287 | Max 1 dodatkowa próba | Tylko dla `research_package` i `article_draft` |
| Batch-level auto_retry_scheduled | `generation-batch-service.php` L1642-1719 | Exponential backoff max 86400s | Quota wait, rate limit, validation failure |
| Research retry | `generation-batch-service.php` L1094-1116 | Max 2 próby | Wymaga zmiany fingerprintu źródeł |
| QC auto-repair loop | `generation-batch-service.php` L1482-1560 | Max 2 korekty modelowe | Próba 1: targeted_repair; próba 2: fresh_conservative_rewrite → safe composer |

### Liczniki i quota

| Budżet | Lokalizacja | Limit | Szczegóły |
|---|---|---|---|
| RPM | `gemini-quota-service.php` L101-109 | `gemini_rpm_target` (domyślnie 10) | Okno 60s |
| TPM | `gemini-quota-service.php` L111-114 | `gemini_tpm_target` | Okno 60s |
| RPD | `gemini-quota-service.php` L115-121 | `gemini_rpd_target` (domyślnie 500) | Reset dzienny |
| Tematyczny | `gemini-quota-service.php` L131-134 | Max 15 wywołań Gemini na temat | Przy 13 blokuje poza draft; przy 14 poza QC |
| Concurrency lease | `gemini-quota-service.php` L96-100 | Max 1 aktywny request na model | `gemini_model_leases` |
| Repair router budget | `repair-router-service.php` L5-6 | Stage: 3, Global: 9 | `REPAIR_ROUTER_STAGE_BUDGET`, `REPAIR_ROUTER_GLOBAL_BUDGET` |

Tabela `gemini_quota_events`: project_key, model, operation_id, topic_id, batch_id, item_id, stage, attempt, call_reason, fingerprint, estimated_tokens, actual_tokens, status, created_at, completed_at.

### Struktura narracji

7 sekcji narrative zdefiniowanych w `php/article-image-service.php` L28-34:
`narrative-opening-question`, `narrative-pursuit`, `narrative-topic-b`, `narrative-apparent-dead-end`, `narrative-return-to-topic-a`, `narrative-close-topic-b`, `narrative-answer-and-punchline`.

Schema draftu buduje dynamiczne pola narrative z `properties` i `required` na podstawie `composition_mode` (`article-draft-service.php` L510-604).

### QC — tabela i threshold

- Tabela `quality_check_runs` (schema L722): model_score, final_score, passed, hard_blocks_json, human_review_status, validation_json
- Threshold: `QUALITY_PASS_SCORE = 75`, `QUALITY_SCORE_TOTAL = 100`
- Deterministyczna kontrola długości: `deterministic_quality_checks()` L284-293

### Statusy redakcyjne

Wartości: `idea`, `research`, `draft`, `review`, `scheduled`, `published`, `rejected`
Definicja: `editorial_post_statuses()` w `php/editorial-repository.php` L5-8
Normalizacja: `normalize_editorial_status()` rzuca `InvalidArgumentException` dla nieznanej wartości (L23-32)

### Statusy pipeline'u generacji

| Status | Znaczenie |
|---|---|
| `ready_for_preview` | Sukces — autonomiczny, bez uwag |
| `ready_with_notes` | Sukces — autonomiczny, z safe composer/repair |
| `ready` | Nieautonomiczny — wymaga ręcznej publikacji |
| `auto_rejected`, `waiting_review`, `manual_review` | Odrzucenie / wstrzymanie |
| `failed`, `cancelled`, `invalid`, `skipped_prerequisite` | Błąd terminalny |
| `paused_by_operator` | Pełny rollback lease'ów i guardów |

### Schemat danych

**Tabela `posts`** (`php/admin-database.php` L128): id, category_id, title, excerpt, content, image_path, slug, is_published, created_at, updated_at, deleted_at. Kolumna `status` dodawana przez migrację `EDITORIAL_SCHEMA_MIGRATION`.

**Tabela `article_images`** (`php/editorial-schema.php` L879-914): id, post_id, role, section_id, visual_intent, expected_content, search_queries_json, source_page_url, source_file_url, local_path, author, license, license_url, attribution, alt, caption, layout, status, width, height, downloaded_at, created_at, updated_at. Unikalny indeks `(post_id, role, section_id)`. Dalsze migracje: `relationship`, `search_audit_json` (L943-948); `rights_manifest_json` (L951-968).

**Usuwanie:** `delete_post()` miękkie (deleted_at + is_published=0), `restore_post()`, `permanently_delete_post()` fizyczne.

---

## Ograniczenia obecnej architektury

1. **Brak centralnego budżetu 20 odpowiedzi Gemini na artykuł** — istnieje budżet tematyczny 15 wywołań (gemini-quota-service.php L131-134), ale nie ma hard limitu 20 z convergence mode od 16. odpowiedzi ani ścieżki do `manual_review` po wyczerpaniu.
2. **Brak NarrativePlan jako osobnego artefaktu** — narrative jest zakodowane jako 7 stałych sekcji bez uzasadnienia wyboru struktury.
3. **Fallback obrazów renderowany jako asset** — `salvage_local_editorial_images()` generuje SVG CC0, które może trafić do finalnego artykułu; brak blokady publikacji z fallbackiem.
4. **Brak bramki semantycznej/redakcyjnej dla obrazów** — ranking w `select_source_image_from_results()` nie odróżnia legalności od trafności redakcyjnej (TASK-23 R2).
5. **Fallback może dziedziczyć metadane kandydata** — caption/alt placeholderu mogą pochodzić z odrzuconego obrazu (TASK-23 R1).
6. **Limity znaków nie zwiększone o 2000** — max 5000 dla informational, 5000 dla problem_discovery_return; wymagane +2000.
7. **Brak narzędzia resetu wadliwych artykułów** — nie istnieje dedykowana funkcja do cofnięcia statusu i wyczyszczenia artefaktów pochodnych.
8. **Niejasne limity podsekcji draftu** — brak osobowych limitów dla lead.text, why_important.text, key_facts[*].text.

---

## Planowane zmiany MAMONA-24

| # | Zmiana | Zakres | Faza |
|---|---|---|---|
| 1 | Centralny `GeminiBudget` z limitem 20 odpowiedzi + convergence mode od 16. | `gemini-quota-service.php`, `generation-batch-service.php` | P2-A |
| 2 | `NarrativePlan` jako osobny artefakt z uzasadnieniem struktury | `article-draft-service.php`, `generation-service.php` | P2-B |
| 3 | Ustrukturyzowany QC z twarde/miękkie bramki i naprawami zakresowymi | `quality-check-service.php` | P2-C |
| 4 | `VisualSlot` z limitem 5, hero + inline, moduły B/C, brak fallbacków w finalnym artykule | `article-image-service.php`, `salvage-service.php` | P2-D |
| 5 | Maksimum każdego typu tekstu +2000 znaków | `article-draft-service.php` | P2-E |
| 6 | Diagnostyka i bezpieczny `manual_review` po wyczerpaniu budżetu | `generation-batch-service.php` | P2-F |
| 7 | Deterministyczne narzędzie audytu/resetu wadliwych artykułów z `--dry-run` i `--apply` | Nowy plik | P2-G |

---

## Modules

| Moduł | Kluczowe pliki/symbole | Wejścia | Wyjścia | Zależności |
|---|---|---|---|---|
| Generation dispatcher | `php/generation-service.php` — `execute_generation_operation()`, `gemini_curl_transport()`, `build_generation_prompt()`, `validate_generation_value()` | Operation data, schema JSON | Response + validation result | gemini-quota-service, repair-router-service |
| Quota & budget | `php/gemini-quota-service.php` — `gemini_quota_acquire()`, `gemini_quota_release()`, `GeminiTopicBudgetException`, `gemini_cached_call()` | RPM/TPM/RPD targets, topic ID | Admission decision + event record | SQLite (gemini_quota_events, gemini_model_leases, gemini_call_cache) |
| Batch orchestration | `php/generation-batch-service.php` — `generation_batch_dispatch_stage()`, `retry_generation_batch_item()`, auto-repair loop | Batch items, stage definitions | Status transitions, retry scheduling | generation-service, repair-router-service, salvage-service |
| Repair router | `php/repair-router-service.php` — `repair_router_assess()`, `repair_router_title_ladder()`, `repair_router_budget_state()` | QC result, budget state | Repair strategy + title fallback | quality-check-service, salvage-service |
| Salvage (fallback) | `php/salvage-service.php` — `salvage_prepare_safe_composer()`, `salvage_execute_safe_composer()`, `salvage_local_editorial_images()` | Validated claims, article context | Deterministic draft + SVG images | Brak Gemini — czysto lokalne |
| Quality Control | `php/quality-check-service.php` — `prepare_quality_check_operation()`, `quality_check_auto_repair_decision()`, `review_quality_risk()`, `deterministic_quality_checks()` | Draft, schema, threshold 75/100 | QC report with hard_blocks_json | generation-service (QC operation), repair-router-service |
| Article draft | `php/article-draft-service.php` — `ARTICLE_COMPOSITION_MODES`, `article_draft_length_policy()`, `validate_article_draft_output()`, `promote_article_draft_to_post()` | Composition mode, content | Validated draft or promotion to post | quality-check-service |
| Selection pipeline | `php/article-image-service.php` — `article_image_semantic_queries()`, `search_source_images()`, `select_source_image_from_results()` | Dane artykułu (tytuł, kategoria, research, encje) | Lista kandydatów z rankingiem | image-rights-service (licencje), providerzy zewnętrzni |
| Download & processing | `php/article-image-service.php` — `download_source_image()`, `create_article_image_variants()` | Wygrany kandydat + URL źródłowy | Plik na dysku + warianty | Network fetcher (SSRF, timeouts), PHP GD lub Python Pillow |
| Persistence | `php/article-image-service.php` — `persist_article_image()`, `reject_article_source_image()` | Kandydat + plik lokalny + metadane | Rekord w `article_images` ze statusem | image-rights-service (manifest JSON) |
| Rights validation | `php/image-rights-service.php` — `image_rights_manifest_from_record()`, `validate_image_rights_manifest()`, `article_image_license_is_auto_safe()` | Rekord obrazu (`license`, `rights_manifest_json`) | Boolean safety + manifest | Brak zewnętrznych zależności |
| Rendering | `php/article-image-service.php` — `render_article_image_record()`, `render_article_blocks()` | Rekord obrazu z bazy + rights manifest | HTML `<figure>` lub placeholder lub pusty string | `app_path()`, `is_file()` dla checków pliku |
| Advertising wrapper | `php/advertising.php` — `advertising_article_inline_limit()`, `render_article_blocks_with_advertising()` | Bloki artykułu, długość tekstu | Bloki ze wstrzykniętymi reklamami | article-image-service |
| Public page generation | `php/admin-database.php` — `render_post_page_html()`, `post_absolute_image_url()` | Post data + obraz(y) | Pełny HTML strony artykułu | article-image-service, advertising, publication-service |
| Atomic publish | `php/publication-service.php` — `write_public_file_atomically()` | Gotowy HTML | Plik w `pages/` zapisany atomowo | Brak zewnętrznych zależności |
| Editorial status | `php/editorial-repository.php` — `editorial_post_statuses()`, `normalize_editorial_status()`; `php/admin-database.php` — `change_post_editorial_status()` | Post ID, nowy status | Zmiana statusu z audytem | quality-check-service (blokada publikacji bez QC) |

## Data contracts

| Rekord/struktura | Producent | Konsument | Pola krytyczne | Inwarianty |
|---|---|---|---|---|
| `posts` rekord | `CREATE TABLE posts` [admin-database.php:128] + migracja statusu | `render_post_page_html()`, `change_post_editorial_status()` | `status`, `is_published`, `deleted_at`, `title`, `content` | Status `published` tylko po QC; `editorial_status` jest źródłem prawdy widoczności |
| `article_images` rekord | `persist_article_image()` [article-image-service.php:1101] | `render_article_image_record()`, `post_absolute_image_url()` | `local_path`, `status`, `license`, `rights_manifest_json`, `attribution`, `alt`, `caption`, `source_page_url`, `role`, `section_id` | Status `downloaded` + auto-safe license wymagane do renderowania; fallback nie dziedziczy pól kandydata |
| Rights manifest JSON | `image_rights_manifest_from_record()` [image-rights-service.php:153] → zapis przez `persist_article_image()` | `validate_image_rights_manifest()` [L105], renderer via `$image['rights_manifest']` | Pola praw, licencji, creditu | Manifest musi być valid przed renderowaniem; pusty manifest → fallback |
| QC run rekord | `prepare_quality_check_operation()` [quality-check-service.php:141] | `quality_check_auto_repair_decision()`, `review_quality_risk()` | model_score, final_score, passed, hard_blocks_json, human_review_status | Threshold 75/100; hard blocks blokują publikację |
| Gemini quota event | `gemini_quota_acquire()` [gemini-quota-service.php:140-142] | Audyt, diagnostyka | project_key, model, operation_id, topic_id, batch_id, stage, attempt, status | Każdy wywołanie rejestrowane; budżet tematyczny max 15 |
| Batch item status | `generation_batch_dispatch_stage()` [generation-batch-service.php] | Worker, repair router, salvage | retry_count, auto_repair_count, status (ready_for_preview/ready_with_notes/manual_review/failed) | Pipeline nie publikuje; kończy się na ready_* lub manual_review |
| Narrative schema | `article-draft-service.php` L510-604 | `build_generation_prompt()`, Gemini response validation | 7 sekcji narrative, dynamiczne properties/required po composition_mode | Schema budowana przed generacją; walidacja po odpowiedzi |

## Test map

| Obszar | Testy | Typ | Mutuje dane | Wymagane flagi |
|---|---|---|---|---|
| Image pipeline smoke | `tests/article-image-pipeline-smoke.php` | Smoke | Tak | `CMS_ALLOW_*` (sprawdzić w teście) |
| Image rights providers | `tests/image-rights-providers-smoke.php` | Smoke | Nie | — |
| Full auto selector | `tests/full-auto-selector-smoke.php` | Smoke | Nie | — |
| Post renderer | `tests/post-renderer-smoke.php` | Smoke | Nie | — |
| Generate all regression | `tests/generate-all-regression.php` | Regression | Tak | `CMS_ALLOW_*` (sprawdzić w teście) |
| Editorial pipeline E2E | `tests/editorial-pipeline-e2e.php` | E2E | Tak | `CMS_ALLOW_PIPELINE_E2E`, `CMS_IMAGE_PROCESSOR_PYTHON` |
| Gemini smoke | `scripts/gemini-free-tier-smoke.php` | Smoke | Nie | — |
| Gemini canary | `scripts/full-auto-gemini-canary.php` | Canary | Nie | — |
| Gemini draft smoke | `scripts/gemini-article-draft-smoke.php` | Smoke | Nie | — |

## Update rule

Aktualizuj wyłącznie po potwierdzeniu kodem, testem albo konfiguracją. Podawaj ścieżkę i symbol.
