# MAMONA-24 — P0 Repository Map

> Wygenerowane 2026-08-06 na podstawie raportów SUBTASK_RESULT P0-A2, P0-B2, P0-C2, P0-D2.
> Źródła: repo-scout na qwen3.6:27b/balanced. Tylko odczyt. Bez edycji kodu.

---

## 1. Typy tekstów i limity (P0-A2 — COMPLETE)

### Tryby kompozycji
- Stała `ARTICLE_COMPOSITION_MODES` definiuje dwa tryby:
  - `informational` — min 3000, max 5000 znaków
  - `problem_discovery_return` — min 4000, max 5000 znaków
- `article_draft_length_policy()` zwraca min/max na podstawie trybu.
- Liczenie: `mb_strlen(implode(...))` w `article_draft_main_content_length()`.

### Pola i limity
| Pole | Min | Max | Walidacja |
|---|---|---|---|
| Treść główna (informational) | 3000 | 5000 | `validate_article_draft_output()` |
| Treść główna (problem_discovery_return) | 4000 | 5000 | `validate_article_draft_output()` |
| Brief | 80 | 220 | `validate_article_draft_output()` L1125-1128 |
| Tytuł | 35 | 100 | `article_title_surface_error()` L167-172 |
| SEO description | — | 160 | `editorial-editor-service.php` |
| image_alt | — | 250 | `editorial-editor-service.php` |
| ai_disclosure | — | 1000 | `editorial-editor-service.php` |

### Reklamy inline
- <1800 znaków → 0 slotów; <2600 → 1; <3700 → 2; ≥3700 → 3
- `advertising_article_inline_limit()` w `php/advertising.php` L229-243

### Prompt generowania
- `build_generation_prompt()` w `php/generation-service.php` L295-302
- Szablon: typ operacji + dane wejściowe JSON + schemat odpowiedzi JSON
- Walidacja długości przez `minLength`/`maxLength` ze schematu JSON → `GenerationFieldConstraintException`

### Kluczowe pliki P0-A2
| Plik | Symbol | Rola |
|---|---|---|
| `php/article-draft-service.php` | `ARTICLE_COMPOSITION_MODES`, `article_draft_length_policy()`, `article_draft_main_content_length()`, `validate_article_draft_output()` | Limity, liczenie, walidacja |
| `php/generation-service.php` | `GenerationFieldConstraintException`, `validate_generation_value()`, `build_generation_prompt()` | Schemat JSON, prompt, wyjątki |
| `php/quality-check-service.php` | `deterministic_quality_checks()` | Kontrola długości w QC |
| `php/advertising.php` | `advertising_article_inline_limit()`, `advertising_block_text_length()` | Sloty reklamowe |
| `php/editorial-editor-service.php` | Walidacja pól edytora | SEO, alt, disclosure |
| `php/admin-post-editor.php` | `mb_strlen` checks | Tytuł ≤160, excerpt ≤500, content ≤8000 |

---

## 2. Call graph Gemini (P0-B2 — COMPLETE)

### Główny przepływ
```
editorial_topic → create_generation_batch() → generation_batch_dispatch_stage()
  → execute_generation_operation() [generation-service.php:1105]
    → gemini_quota_acquire() [gemini-quota-service.php:79]
    → gemini_cached_call() [cache hit shortcut]
    → $transport($payload, $apiKey, $operationKey, $model)
      → gemini_curl_transport() [generation-service.php:890]
        → curl POST do GEMINI_API_BASE_URL/models/{model}:generateContent
    → gemini_extract_output() [generation-service.php:160]
    → complete_generation_with_title_repair() [generation-service.php:688]
    → gemini_quota_release() [gemini-quota-service.php:152]
  → repair_router_assess() [repair-router-service.php:9]
  → quality_check_auto_repair_decision()
  → salvage_execute_safe_composer() [salvage-service.php:46]
```

### Miejsca wywołań Gemini
| # | Plik | Symbol | Rola |
|---|---|---|---|
| 1 | `php/generation-service.php` | `execute_generation_operation()` L1105 | Główny dispatcher, rozdziela gemini vs openai |
| 2 | `php/generation-service.php` | `gemini_curl_transport()` L890 | Jedyny transport HTTP do Gemini API Free Tier |
| 3 | `php/generation-service.php` | `execute_openai_generation_operation()` L982 | Alternatywny provider OpenAI |
| 4 | `php/generation-service.php` | Mock transport L1151-1174 | Budowany inline gdy `gemini_mock=1` |
| 5 | Skrypty testowe | `scripts/gemini-free-tier-smoke.php`, `scripts/full-auto-gemini-canary.php`, `scripts/gemini-article-draft-smoke.php` | Smoke/canary |

---

## 3. Retry, quota, salvage i warunki końca (P0-B2 — COMPLETE)

### Retry
| Mechanizm | Lokalizacja | Limit | Szczegóły |
|---|---|---|---|
| Transport retry (inline loop) | `generation-service.php` L1200-1324 | `$maximumAttempts = gemini_max_attempts` (domyślnie 3, +1 za każdy fallback model) | Exponential backoff: `gemini_initial_backoff_ms * 2^(attempt-1)`, max 10s. Retryable: HTTP 0/408/429/5xx, timeout, network error |
| Contract repair retry | `generation-service.php` L1270-1287 | Max 1 dodatkowa próba | Dodaje poprzednią odpowiedź + repair message. Tylko dla `research_package` i `article_draft` |
| Batch-level auto_retry_scheduled | `generation-batch-service.php` L1642-1719 | Exponential backoff max 86400s | `GeminiQuotaWaitException` → `auto_retry_scheduled`; rate limit/timeout; validation contract failure |
| Research retry | `generation-batch-service.php` L1094-1116 | Max 2 próby (`auto_repair_count < 2`) | Wymaga zmiany fingerprintu źródeł |
| QC auto-repair loop | `generation-batch-service.php` L1482-1560 | Max 2 korekty modelowe | Próba 1: `targeted_repair`, próba 2: `fresh_conservative_rewrite`. Po wyczerpaniu → safe composer |

### Liczniki i quota
| Budżet | Lokalizacja | Limit | Szczegóły |
|---|---|---|---|
| RPM | `gemini-quota-service.php` L101-109 | `gemini_rpm_target` (domyślnie 10) | Okno 60s |
| TPM | `gemini-quota-service.php` L111-114 | `gemini_tpm_target` | Okno 60s |
| RPD | `gemini-quota-service.php` L115-121 | `gemini_rpd_target` (domyślnie 500) | Reset dzienny |
| Tematyczny | `gemini-quota-service.php` L131-134 | Max 15 wywołań Gemini na temat | Przy 13 blokuje poza `article_draft`; przy 14 blokuje poza `quality_check` |
| Concurrency lease | `gemini-quota-service.php` L96-100 | Max 1 aktywny request na model | `gemini_model_leases` |
| Cache | `gemini-quota-service.php` L183, L191 | Nieograniczony TTL? | `gemini_call_cache` po fingerprint |
| Batch item counters | — | `retry_count`, `auto_repair_count` | Całkowita liczba ponowień etapu |
| Repair router budget | `repair-router-service.php` L5-6 | Stage: 3, Global: 9 | `REPAIR_ROUTER_STAGE_BUDGET`, `REPAIR_ROUTER_GLOBAL_BUDGET` |

### Tabela quota events
- `gemini_quota_events`: project_key, model, operation_id, topic_id, batch_id, item_id, stage, attempt, call_reason, fingerprint, estimated_tokens, actual_tokens, status, created_at, completed_at
- Rejestracja w `gemini_quota_acquire()` L140-142

### Salvage i repair
| Symbol | Plik | Rola |
|---|---|---|
| `salvage_prepare_safe_composer()` | `php/salvage-service.php` L6 | Deterministyczna wersja draftu z zwalidowanych claimów (confidence high/medium), bez Gemini |
| `salvage_execute_safe_composer()` | `php/salvage-service.php` L46 | Wywołuje safe composer + mock QC factual gate, buduje tytuł przez `repair_router_title_ladder()` |
| `salvage_local_editorial_images()` | `php/salvage-service.php` L85-86 | Fallback obrazów: generuje lokalne SVG z labelką "Ilustracja redakcyjna", CC0, search_audit level='local_fallback' |
| `repair_router_assess()` | `php/repair-router-service.php` L9 | Konwertuje wynik QC na gates i strategie naprawy |
| `repair_router_title_ladder()` | `php/repair-router-service.php` | Deterministyczna drabina tytułów |
| `repair_router_expansion_plan()` | `php/repair-router-service.php` | Plan struktury A-B-A-B-A / A-B-A-C-A |
| `repair_router_budget_state()` | `php/repair-router-service.php` | Śledzi zużycie budżetu napraw |

### Warunki zakończenia
| Status | Znaczenie |
|---|---|
| `ready_for_preview` | Sukces — autonomiczny, bez uwag |
| `ready_with_notes` | Sukces — autonomiczny, z safe composer/repair |
| `ready` | Nieautonomiczny — wymaga ręcznej publikacji |
| `auto_rejected`, `waiting_review`, `manual_review` | Odrzucenie / wstrzymanie |
| `failed`, `cancelled`, `invalid`, `skipped_prerequisite` | Błąd terminalny |
| `paused_by_operator` | Pełny rollback lease'ów i guardów |

### Publikacja
- Pipeline generowania NIGDY nie publikuje — kończy się na `ready_for_preview` / `ready_with_notes` / `ready`
- `promote_article_draft_to_post()` — `php/article-draft-service.php` L1421: materializuje szkic jako post (bez zmiany statusu na published)
- Świadoma publikacja: `change_post_editorial_status($postId, 'published', ...)` — `php/admin-proposals.php` L106
- `editorial_status` jest źródłem prawdy; publiczny tylko nieusunięty artykuł o statusie `published`

---

## 4. Narracja i QC (P0-C2 — COMPLETE)

### Struktura narracji
- 7 sekcji narrative zdefiniowanych w `php/article-image-service.php` L28-34:
  - `narrative-opening-question`
  - `narrative-pursuit`
  - `narrative-topic-b`
  - `narrative-apparent-dead-end`
  - `narrative-return-to-topic-a`
  - `narrative-close-topic-b`
  - `narrative-answer-and-punchline`
- Schema draftu buduje dynamiczne pola narrative z `properties` i `required` na podstawie `composition_mode` (`article-draft-service.php` L510-604)
- Fallback tytułu: `article_title_deterministic_fallback()` w `article-draft-service.php` L401 — 5 wariantów ze spadającymi score'ami

### QC
| Element | Szczegóły |
|---|---|
| Tabela | `quality_check_runs` (schema L722): model_score, final_score, passed, hard_blocks_json, human_review_status, validation_json |
| Przygotowanie | `prepare_quality_check_operation()` w `quality-check-service.php` L141 — tworzy operację generowania typu 'quality_check' i wpis w quality_check_runs; wymaga statusu 'completed' szkicu |
| Auto-repair routing | `quality_check_auto_repair_decision()` L656: ryzyko prawne/medyczne → człowiek; brak źródeł → powtórz research; pozostałe → auto-repair draft z feedbackiem |
| Human review | `review_quality_risk()` L605 — decyzja człowieka nad hard block 'high_risk_without_human_approval'; po aprobacie filtruje ten blok z `quality_active_hard_blocks()` |
| Threshold | `QUALITY_PASS_SCORE = 75`, `QUALITY_SCORE_TOTAL = 100` |
| Deterministyczna kontrola długości | `deterministic_quality_checks()` L284-293 używa `article_draft_length_policy()` + `article_draft_main_content_length()` |

### Renderer
| Symbol | Plik | Rola |
|---|---|---|
| `render_article_blocks()` | `php/article-image-service.php` L1596 | Główny renderer bloków; typy: heading, paragraph, quote, list, section (rekurencyjny), illustration, gallery |
| `render_article_image_record()` | `php/article-image-service.php` L1453 | Renderuje `<figure>` z fallback placeholderem lub pusty string |
| `render_article_blocks_with_advertising()` | `php/advertising.php` L308 | Wrapper reklamowy — iteruje bloki, wstrzykuje sloty reklam na granicach |
| Renderer publiczny | `php/admin-database.php` L1650 | Używa wersji z advertising |
| Preview renderer | `php/admin-post-preview.php` L32 | Używa czystego `render_article_blocks()` |

---

## 5. Obrazy, fallbacki i renderer (P0-C2 + ARCHITECTURE.md)

### Pipeline obrazów (potwierdzony w kodzie)
```
article data → article_image_semantic_queries() [article-image-service.php:116]
  → search_source_images() [article-image-service.php:753]
    → providers (external)
      → kandydaci z metadanami
        → image_rights_manifest_from_record() [image-rights-service.php:153]
          → validate_image_rights_manifest() [image-rights-service.php:105]
            → article_image_license_is_auto_safe()
              → select_source_image_from_results() [article-image-service.php:780]
                → winner candidate
                  → download_source_image() [article-image-service.php:999]
                    → create_article_image_variants()
                      → persist_article_image() [article-image-service.php:1101]
                        → article_images record (status: downloaded)
                          → render_article_image_record() [article-image-service.php:1453]
                            → <figure> HTML lub placeholder lub pusty string
                              → render_post_page_html() [admin-database.php:1596]
                                → write_public_file_atomically() [publication-service.php:28]
                                  → pages/*.html
```

### Fallback obrazów
- `salvage_local_editorial_images()` w `php/salvage-service.php` L85 — ostatni krok waterfallu
- Generuje neutralne SVG CC0 w katalogu `editorial-fallback/`
- Pełny manifest praw i search_audit level='local_fallback'
- Fallback modelu Gemini: konfigurowany przez `GEMINI_MODEL_FALLBACKS`, widoczny w UI (`admin-generation.php` L159)

---

## 6. Schemat danych, statusy i bezpieczny reset (P0-D2 — COMPLETE)

### Tabela `posts`
- Definicja: `php/admin-database.php` L128
- Kolumny: id, category_id, title, excerpt, content, image_path, slug, is_published, created_at, updated_at, deleted_at
- Kolumna `status` dodawana przez migrację `EDITORIAL_SCHEMA_MIGRATION`

### Tabela `article_images`
- Migracja: `ARTICLE_IMAGES_MIGRATION` w `php/editorial-schema.php` L879-914
- Unikalny indeks: `(post_id, role, section_id)`
- Kolumny: id, post_id, role, section_id, visual_intent, expected_content, search_queries_json, source_page_url, source_file_url, local_path, author, license, license_url, attribution, alt, caption, layout, status, width, height, downloaded_at, created_at, updated_at
- Dalsze migracje: `relationship`, `search_audit_json` (L943-948); `rights_manifest_json` (L951-968)

### Statusy redakcyjne
- Definicja: `editorial_post_statuses()` w `php/editorial-repository.php` L5-8
- Wartości: `idea`, `research`, `draft`, `review`, `scheduled`, `published`, `rejected`
- `normalize_editorial_status()` rzuca `InvalidArgumentException` dla nieznanej wartości (L23-32)
- Backfill przy pierwszej migracji: `is_published=1 AND deleted_at IS NULL → published`, reszta → draft (L225-242)

### Zmiana statusu
- `change_post_editorial_status()` w `php/admin-database.php` L1956
- Transakcyjna zmiana z audytem w `post_status_history`
- Walidacja przejść (np. published→scheduled zabronione, rejected wymaga przyczyny)
- Blokada publikacji bez QC: `assert_post_quality_allows_publication`

### Usuwanie
| Funkcja | Plik | Rola |
|---|---|---|
| `delete_post()` | `admin-database.php` L2037 | Miękkie usuwanie: `deleted_at` + `is_published=0` |
| `restore_post()` | `admin-database.php` L2088 | Cofa miękkie usunięcie |
| `permanently_delete_post()` | `admin-database.php` L2134 | Fizyczne usunięcie |

### Bezpieczny reset — pola do zachowania vs. czyszczenia
- **Zachować:** article_id/post_id, category_id, pierwotny temat/seed/brief, typ tekstu (composition_mode), język, ustawienia wejściowe, audyt historii (`post_status_history`, `gemini_quota_events`)
- **Wyczyścić:** wygenerowany tytuł/body/excerpt, research i plan narracyjny, QC results, plan i przypisania grafik, caption/alt/credit/source, wynik renderowania, publiczny plik, hashe i statusy zakończonych przebiegów
- Status po resecie: cofnięcie do `draft` lub równoważny stan oczekujący na regenerację

---

## 7. Potwierdzone pliki i symbole — pełna mapa

| # | Plik | Kluczowe symbole | Rola | Pewność |
|---|---|---|---|---|
| 1 | `php/generation-service.php` | `execute_generation_operation()`, `gemini_curl_transport()`, `gemini_extract_output()`, `complete_generation_with_title_repair()`, `build_generation_prompt()`, `validate_generation_value()`, `GenerationFieldConstraintException` | Dispatcher Gemini, transport, prompt, walidacja schematu | 100% |
| 2 | `php/gemini-quota-service.php` | `gemini_quota_acquire()`, `gemini_quota_release()`, `GeminiQuotaWaitException`, `GeminiTopicBudgetException`, `gemini_cached_call()`, `gemini_calls_per_article()` | Quota RPM/TPM/RPD, lease, cache, budżet tematyczny 15 | 100% |
| 3 | `php/generation-batch-service.php` | `generation_batch_dispatch_stage()`, `retry_generation_batch_item()`, `generation_batch_queue_research_retry()`, `generation_batch_is_autonomous()` | Orkiestracja pipeline'u, retry batch-level, auto-repair loop | 100% |
| 4 | `php/article-draft-service.php` | `ARTICLE_COMPOSITION_MODES`, `article_draft_length_policy()`, `article_draft_main_content_length()`, `validate_article_draft_output()`, `article_title_deterministic_fallback()`, `promote_article_draft_to_post()` | Limity, liczenie, walidacja, fallback tytułu, promocja | 100% |
| 5 | `php/quality-check-service.php` | `prepare_quality_check_operation()`, `quality_check_auto_repair_decision()`, `review_quality_risk()`, `deterministic_quality_checks()`, `QUALITY_PASS_SCORE=75` | QC, auto-repair routing, human review, threshold | 95% |
| 6 | `php/article-image-service.php` | `article_image_semantic_queries()`, `search_source_images()`, `select_source_image_from_results()`, `download_source_image()`, `persist_article_image()`, `render_article_image_record()`, `render_article_blocks()` | Pełny pipeline obrazów: query → search → select → download → persist → render | 100% |
| 7 | `php/image-rights-service.php` | `image_rights_manifest_from_record()`, `validate_image_rights_manifest()`, `article_image_license_is_auto_safe()` | Walidacja praw, manifesty licencji | 100% |
| 8 | `php/salvage-service.php` | `salvage_prepare_safe_composer()`, `salvage_execute_safe_composer()`, `salvage_local_editorial_images()` | Deterministyczny fallback draftu i obrazów | 100% |
| 9 | `php/repair-router-service.php` | `repair_router_assess()`, `repair_router_title_ladder()`, `repair_router_budget_state()`, `repair_report_append()` | Ruting napraw QC, budżet repair (3/9), drabina tytułów | 100% |
| 10 | `php/editorial-schema.php` | `ARTICLE_IMAGES_MIGRATION`, `EDITORIAL_SCHEMA_MIGRATION`, `quality_check_runs` schema | Migracje schematu bazy | 100% |
| 11 | `php/editorial-repository.php` | `editorial_post_statuses()`, `normalize_editorial_status()`, `record_post_status_change()` | Statusy redakcyjne, normalizacja, audyt | 100% |
| 12 | `php/admin-database.php` | `CREATE TABLE posts`, `change_post_editorial_status()`, `delete_post()`, `restore_post()`, `permanently_delete_post()`, `render_post_page_html()` | Schema posts, zmiana statusu, usuwanie, render publiczny | 100% |
| 13 | `php/admin-proposals.php` | `change_post_editorial_status(..., 'published')` | Świadoma publikacja z panelu admina | 95% |
| 14 | `php/advertising.php` | `advertising_article_inline_limit()`, `render_article_blocks_with_advertising()` | Sloty reklamowe, wrapper reklamowy | 100% |
| 15 | `php/app-config.php` | `gemini_max_attempts`, `gemini_rpm_target`, `gemini_rpd_target`, `gemini_timeout_seconds`, `gemini_initial_backoff_ms` | Konfiguracja limitów i timeoutów Gemini | 100% |
| 16 | `php/publication-service.php` | `write_public_file_atomically()` | Atomic write generated public pages | 100% |

---

## 8. Luki i nierozstrzygnięte pytania

### Z P0-A2
1. Czy istnieją inne typy tekstów poza `informational` i `problem_discovery_return`?
2. Jakie są limity dla pól `lead.text`, `why_important.text`, poszczególnych `key_facts[*].text`?
3. Czy `build_generation_prompt()` wstrzykuje jawnie reguły długości do promptu, czy polegają wyłącznie na walidacji po stronie schematu?

### Z P0-B2
1. Dokładna implementacja `quality_check_auto_repair_decision()` — ciało funkcji nieotwarte
2. Implementacja `promote_article_draft_to_post()` — szczegóły promocji nieprzeczytane
3. Czy istnieje mechanizm logowania wywołań Gemini poza `gemini_quota_events`?
4. Szczegóły `generation_batch_worker.php` — jak worker decyduje o kolejności przetwarzania?
5. Czy cache `gemini_call_cache` ma TTL / politykę expiracji?

### Z P0-C2
1. Gdzie zdefiniowana jest stała `QUALITY_PASS_SCORE`? (użyta w L638, L724 — nie znaleziono definicji)
2. Jak wygląda pełna funkcja `quality_check_schema()`?
3. Czy `render_article_image_record($image, true)` dla hero generuje inną strukturę HTML?
4. Jak `validate_article_blocks()` weryfikuje bloki przed renderowaniem?

### Z P0-D2
1. Czy istnieje dedykowana funkcja "reset wadliwego artykułu"?
2. Jakie są dokładne warunki w `assert_post_quality_allows_publication()` blokujące publikację?
3. Jak `post_legacy_publication_flag()` mapuje status na is_published?

---

## 9. Podsumowanie budżetu P0

| Subtask | Agent | Model | Status | Pliki otwarte |
|---|---|---|---|---|
| P0-A2 | repo-scout | qwen3.6:27b/balanced | COMPLETE | 6 |
| P0-B2 | repo-scout | qwen3.6:27b/balanced (wznowiony) | COMPLETE | 10 |
| P0-C2 | repo-scout | qwen3.6:27b/balanced (wznowiony) | 9 |
| P0-D2 | repo-scout | qwen3.6:27b/balanced | COMPLETE | 5 |
