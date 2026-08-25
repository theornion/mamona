# Current Work — Mamona MVP Cleanup

**Aktualizacja:** 2026-08-11
**Tryb pracy:** Codex / ChatGPT Codex. Lokalne agenty Kilo/Ollama są tymczasowo wstrzymane.  
**Priorytet:** po cleanupie doprowadzić MVP do jednego jakościowego, kompletnego przejścia RSS → research → tekst → komplet grafik → kompozycja → final QC → ręczna publikacja.

## Nadrzędny cel

Na tym etapie nie rozwijamy kolejnych warstw automatyzacji. Najpierw doprowadzamy projekt do małego, czytelnego baseline:

```text
RSS
→ zapis wpisu źródłowego
→ temat
→ research
→ narrative plan / szkic
→ QC
→ grafiki
→ gotowa propozycja
→ ręczna publikacja
→ publiczny HTML + feed + sitemap
```

Minimalne kryterium MVP: **co najmniej jeden realny materiał przechodzi poprawnie cały powyższy przepływ**.

## Zmiana priorytetu względem poprzednich checkpointów

Poprzedni kierunek MAMONA-24 P5/P6 i stare taski resetu nie są już aktywnym źródłem następnego kroku.

Nie wracaj automatycznie do:
- P5/P6 reset-invalid-articles,
- dawnych promptów Kilo,
- kolejnych wersji konfiguracji agentów,
- starych checkpointów jako aktywnych tasków.

Zachowuj je wyłącznie jako historyczne evidence do czasu zakończenia cleanupu.

## Stan repozytorium z audytu `mamona.zip`

Snapshot użyty do audytu:
- 8042 wpisy w ZIP;
- ok. **465 MB skompresowane**;
- ok. **3.34 GB nieskompresowane**.

Największe źródła rozrostu:
- `data/` — ok. **2.15 GB**;
- `_handoff/` — ok. **736.95 MB**;
- `.git/` — ok. **117.5 MB**;
- `images/` — ok. **84.5 MB**, w tym `images/posts/` ok. **72.65 MB**;
- `.kilo/` — ok. **52.8 MB**, praktycznie całe `node_modules`;
- `backups/` — ok. **18.72 MB**;
- `subagent-exports/` — ok. **6.84 MB**;
- `qa/` — ok. **6.3 MB**.

Szczegóły: `docs/MVP_CLEANUP_AUDIT.md`.

## Stan bazy z audytu

`data/cms.sqlite`:
- rozmiar: **761,446,400 B (~726 MiB)**;
- `posts`: **379**;
- `posts.status`:
  - `idea`: 358,
  - `draft`: 17,
  - `rejected`: 3,
  - `review`: 1;
- `is_published=1`: **0**;
- `discovered_feed_items`: 377;
- `editorial_topics`: 375;
- `generation_operations`: 3028;
- `generation_batches`: 128;
- `generation_batch_audit`: 5656;
- `topic_score_history`: 11514;
- `article_images`: 88;
- `article_draft_versions`: 35;
- `quality_check_runs`: 32.

SQLite ma **162113 wolnych stron z 185900**, czyli około **633 MiB** pliku to już wolna przestrzeń po wcześniejszych operacjach. Live content zajmuje około **92.9 MiB**. Po kontrolowanym purge + `VACUUM` baza powinna spaść radykalnie.

## Potwierdzone istotne zmiany obecne w working tree

W bieżącym snapshotcie istnieją m.in. następujące zmiany, których cleanup nie może zgubić:

1. `php/narrative-plan-service.php`
   - `generate_narrative_plan(..., ?callable $transport = null)`;
   - mock narrative plan o kontrakcie zgodnym z aktualnym schema.

2. `php/feed-ingestion-service.php`
   - bounded retry SQLite lock/busy został rozszerzony do 5 prób;
   - opóźnienia między próbami: 200/300/450/675 ms.

3. `tests/content-studio-smoke.php`
   - istnieje regresja wyczerpania retry dla trwałego `database is busy`.

4. `tests/generation-batch-smoke.php`
   - istnieje regresja dla wywołania batch bez jawnego transportu.

5. W working tree są również większe zmiany MAMONA-24 dotyczące image pipeline, QC, quota, batch i resetu.

**Nie używaj `git reset`, `git restore`, `git checkout --` ani podobnego czyszczenia working tree.** Cleanup musi zachować prawidłowe bieżące zmiany źródłowe.

## Aktywne zadania dla Codexa

### C1 — Repo cleanup audit + usunięcie oczywistych artefaktów

Usunąć po weryfikacji:
- `_handoff/`;
- stare `data/backups/` po utworzeniu jednego nowego backupu poza repo;
- historyczne `backups/`;
- `subagent-exports/`;
- `.kilo/node_modules/`;
- stare root-level eksporty sesji P2/P3;
- stare instalatory/prompty/changelogi konfiguracji lokalnych agentów;
- nieaktualne taski/checkpointy po skonsolidowaniu ich unikalnych faktów;
- historyczne screenshoty/logi/metryki QA, jeśli nie są wejściem aktywnego testu.

Bez automatycznego przepisywania historii Git.

### C2 — Usunięcie legacy `cats`

Wysoki poziom pewności:
- `php/cats.php` jest legacy endpointem;
- `php/admin-cats.php` nie występuje w aktualnym admin nav;
- tabela `cats` jest pusta;
- funkcje cat CRUD są izolowane głównie w `php/admin-database.php`.

UWAGA:
`assets/js/cat-gallery.js` **nie jest w całości legacy** — jest używany przez obecne zwykłe galerie z `data-gallery-source`. Nie usuwać go bez zastąpienia. Usunąć wyłącznie cat-specific fallback/kontrakt, jeśli potwierdzi to grep i testy.

### C3 — Pełne wyczyszczenie danych artykułowych

Użytkownik chce wyzerować **wszystkie pobrane i przetwarzane artykuły**, bez względu na stan:
- niezaczęte;
- rozpoczęte;
- draft;
- review;
- rejected;
- finished;
- stare batch/operation/audit;
- pobrane obrazy i artefakty artykułów.

Zachować konfigurację aplikacji i źródła RSS.

Reset ma być wykonany osobnym, audytowalnym mechanizmem:
- preflight;
- jeden backup poza repo + SHA256;
- exact counts;
- transakcja;
- purge dynamic content;
- usunięcie artefaktów filesystem;
- `PRAGMA integrity_check`;
- `VACUUM`;
- post-reset counts = 0 dla dynamic article pipeline.

### C4 — Git/repo hygiene

Po cleanupie:
- poprawić `.gitignore`;
- poprawić `pack_mamona_repo.ps1`, aby nowe handoffy nie zawierały runtime DB, backupów, handoffów, node_modules i wygenerowanych mediów;
- usunąć z bieżącego tree tracked runtime artefakty (`images/posts`, stare `backups`, stare exporty);
- NIE wykonywać `git filter-repo`/BFG/history rewrite bez osobnej zgody.

### C5 — Dokumentacja

Kanoniczny jednoplikowy stan produktu:
- `docs/MVP_STATE.md`.

`README.md` i `docs/CURRENT_WORK.md` mają prowadzić do tego pliku.

Stare taski/checkpointy można usunąć dopiero po upewnieniu się, że jedyne nadal potrzebne fakty zostały skonsolidowane.

### C6 — MVP E2E

Po czystym baseline:
1. uruchomić testowy/mock E2E na disposable DB;
2. potwierdzić RSS → proposal → preview bez publikacji;
3. dopiero później wykonać jeden kontrolowany realny flow na świeżym rekordzie;
4. publikacja ma pozostać świadomą akcją użytkownika.

## Co zachować podczas resetu danych

Co najmniej:
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

Inne statyczne/globalne tabele można zachować, o ile nie zawierają article-run state.

## Aktualizacja runtime — 2026-08-11

Wprowadzono i zweryfikowano:

- zgodność widoku propozycji i prywatnego preview ze szkicami `frozen` po QC;
- klasyfikację niekompletnych grafik jako „Wymagające akcji”, bez fałszywego
  oznaczania materiału jako gotowego;
- komunikat UI dla `completed_with_warnings` jako „Wymaga uwagi — grafiki”;
- odzyskanie rekordu grafiki `downloaded`, gdy plik lokalny już nie istnieje;
- obniżenie domyślnego budżetu zapytań źródłowych z 12 do 4 na slot, aby ograniczyć
  limity Wikimedia;
- dopasowanie metadanych kandydatów do udokumentowanego zapytania źródłowego przed
  końcową oceną Gemini Vision.

Rzeczywiste ponowne wyszukiwanie dwóch lokalnych artykułów wykonało się 2026-08-11:

- post 40: 2/4 pobrane grafiki;
- post 41: 1/4 pobrane grafiki.

Brakujące sloty nie są gotowe do publikacji; przyczyny zapisano w `search_audit_json`
rekordów `article_images` (m.in. brak odpowiedniego kandydata, przekroczony rozmiar,
niedopuszczony format lub odrzucenie Vision).

## Safety

- Nie dotykać produkcyjnej bazy.
- Nie wykonywać cleanupu na niejednoznacznym DB path.
- Nie usuwać jedynego backupu.
- Nie uruchamiać providerów podczas cleanupu.
- Nie publikować podczas cleanupu.
- Nie omijać fail-closed guardów.
- Nie zapisywać sekretów do repo.
- `.env` nie jest automatycznie ładowany przez aplikację; klucze API są pobierane z process/server environment.
- Nie używać lokalnych agentów Kilo/Ollama w tym etapie.

## Stan wykonania cleanupu

Wykonano 2026-08-10:

- pełny reset `data/cms.sqlite` przez `php/cli-reset-mvp-content.php`;
- backup poza repo w `C:\Projekty\mamona-backups\` z SHA-256 i integrity check;
- dynamiczne dane pipeline'u są puste, a 22 `technical_sources` i konfiguracja pozostały;
- `VACUUM` zmniejszył bazę do 704 512 B;
- article media i `pages/post-*.html` zostały usunięte, a pusty publiczny feed zsynchronizowany;
- legacy `cats` (endpointy, CRUD, tabela) zostało usunięte bez usuwania ogólnego renderera galerii;
- smoke tests: editorial schema, content studio, generation batch, quality, image pipeline,
  SSR i publication lifecycle przechodzą bez wywołań live providerów.

## Następny krok

Cleanup MVP pozostaje ważnym baseline'em, ale po jego fizycznym domknięciu aktywnym programem rozwojowym jest **Program P — przebudowa pipeline'u Gemini do jakościowego MVP**, opisany poniżej.

Codex ma wznowić pracę od pierwszego nieukończonego tasku P01–P10 i sam utrzymywać status/checkpointy w tym pliku.


# Program P — przebudowa pipeline'u Gemini do jakościowego MVP

## Cel programu

Po zakończeniu cleanupu kolejnym priorytetem jest przebudowa pipeline'u generowania tak, aby w limicie **maksymalnie 20 faktycznych odpowiedzi Gemini na artykuł** system:

```text
RSS / temat
→ research
→ dobry core text
→ zamrożenie jakościowego tekstu
→ komplet grafik, z obowiązkowym hero
→ recovery przez grafiki pokrewne + addytywne moduły tekstowe
→ zróżnicowana kompozycja strony
→ finalna ocena multimodalna całego artykułu
→ ready_for_manual_publish
→ ręczna publikacja
```

Najważniejsza zasada:

> Jeżeli core text jest już jakościowy, problemy z grafikami NIE mogą powodować jego pełnego przepisywania ani degradacji. System może dodawać zweryfikowane moduły kontekstowe, captions i layout, ale ma zachować zaakceptowany rdzeń tekstu.

## Twarde wymagania programu

1. **Hero jest obowiązkowe.**
2. **Wszystkie required visual slots muszą być wypełnione** przed `ready_for_manual_publish`.
3. Grafika może być:
   - `direct_ok` — bezpośrednio trafna;
   - `related_supported` — pokrewna, ale wsparta zatwierdzonym modułem kontekstowym;
   - `missing/rejected` — nie może liczyć się do coverage.
4. Technical/local fallback nie może spełniać publication gate.
5. Po `core_text_locked=true` zabroniony jest pełny rewrite core article.
6. Gemini może planować layout, ale renderer pozostaje deterministyczny i allowlistowany.
7. Final QC ocenia **tekst + źródła + grafiki + captions + layout + spójność całości**.
8. Każda faktyczna odpowiedź Gemini, w tym retry/contract repair/Vision, zużywa jeden wspólny budżet.
9. Cache hit bez nowego requestu Gemini nie zużywa budżetu.
10. 21. request Gemini nie może wyjść do API.
11. Publikacja pozostaje ręczną akcją użytkownika.

---

## Protokół statusu i checkpointów

Codex ma traktować tę sekcję jako **żywy tracker wykonania**.

### Reguły statusu

- `[ ]` = `NOT_STARTED`
- `[~]` = `IN_PROGRESS`
- `[x]` = `DONE`
- `[!]` = `BLOCKED`

Codex ma sam aktualizować checkboxy w `docs/CURRENT_WORK.md`.

Task wolno oznaczyć `[x]` wyłącznie gdy:
- implementacja istnieje w actual diff;
- acceptance criteria są spełnione;
- wymagane lint/testy przechodzą;
- nie ma znanego niezweryfikowanego regresyjnego failure w zakresie tasku.

Jeżeli sesja kończy się w połowie tasku:
- pozostaw `[~]`;
- wpisz dokładny `Last verified state`;
- wpisz `Next exact action`;
- utwórz checkpoint.

Jeżeli task jest zablokowany:
- zmień `[~]` na `[!]`;
- zapisz blocker z exact evidence;
- nie oznaczaj go jako DONE.

### Checkpointy

Po **każdym** tasku zapisz:

```text
docs/checkpoints/MVP-PIPELINE-P01.md
...
docs/checkpoints/MVP-PIPELINE-P10.md
```

Checkpoint tasku zawiera minimum:
- task;
- status;
- zakres zmian;
- changed files;
- kontrakt przed/po;
- testy;
- PASS/FAIL;
- realne wywołania Gemini wykonane podczas testów;
- znane ryzyka;
- next exact task.

Dodatkowe major checkpoints:

```text
P04 → docs/checkpoints/MVP-PIPELINE-FOUNDATION.md
P07 → docs/checkpoints/MVP-PIPELINE-IMAGE-RECOVERY.md
P10 → docs/checkpoints/MVP-PIPELINE-COMPLETE.md
```

Checkpoint nie zastępuje aktualizacji `CURRENT_WORK.md`.

---

## Stan programu

- [x] **P01 — Centralny Gemini Budget**
- [x] **P02 — NarrativePlan + VisualPlan**
- [x] **P03 — Core Text Lock**
- [x] **P04 — Image Coverage State + obowiązkowy hero**
- [x] **P05 — Direct Image Acquisition**
- [x] **P06 — Image Shortage Recovery + Related Images**
- [x] **P07 — Additive Expansion Engine**
- [x] **P08 — LayoutPlan + zróżnicowany renderer**
- [x] **P09 — Final Multimodal Editorial QC + publication gate**
- [~] **P10 — E2E regression matrix + clean MVP proof**

**Current active task:** `P10 / REAL_PROOF_TRACE_STOP [~]`  
**Last completed task:** `P09`  
**Last checkpoint:** `docs/checkpoints/MVP-PIPELINE-P10.md`  
**Weekly usage:** not independently observable in this runtime; no quota claim is made here.  
**Last verified state:** Disposable controlled-transport D1–D7 evidence for P08/P09 is PASS. The repaired canonical consumer was verified before the only controlled resume of batch #27 for topic #261 / post #287. The original precheck was `2/20`; exactly three further live requests occurred (`article_draft`, `field_text_repair`, `article_draft`), so total budget is `5/20`. Draft version #88 was persisted, but draft operation #356 stopped with `validation_contract`: `Szkic zmienił wymagany hero VisualPlan: slot_id.` The model-generated illustration plan used the legacy hero representation without `slot_id`; this is a producer/output-schema failure after the canonical consumer repair. Audit rows #432–#437 evidence the run. No QC, images, layout or P09 was reached; post #287 remains unpublished.  
**Next exact action:** preserve this one-run trace and stop. Fix the prompt/output schema so the model-generated illustration plan preserves canonical required VisualPlan fields, or add a deterministic adapter before the assertion; add a controlled regression before any new real authorization.

---

## P01 — Centralny Gemini Budget

### Cel

Zapewnić, że **każda faktyczna odpowiedź Gemini** zużywa dokładnie 1 punkt jednego article-level budgetu.

Budżet:

```text
0–15  NORMAL
16–18 CONVERGENCE
19–20 CLOSURE_ONLY
>20   BLOCK / MANUAL_REVIEW
```

### Zakres

Zlokalizować wszystkie realne wywołania Gemini:
- research;
- research QC;
- research repair/enrichment;
- NarrativePlan;
- draft;
- contract/schema repair;
- text QC;
- text repair/rewrite;
- Vision;
- image recovery planner;
- additive module generation;
- layout;
- final multimodal QC.

Naliczanie ma być możliwie blisko faktycznego request/response transportu, aby wewnętrzny retry nie mógł zostać pominięty.

### Reguły

- realny request zakończony odpowiedzią → `+1`;
- realny retry → `+1`;
- contract repair generujący drugą odpowiedź → kolejne `+1`;
- Vision → ten sam budget;
- cache hit bez requestu → `+0`;
- wyszukiwanie Wikimedia/NASA/Openverse itd. → `+0`;
- 21. call → blokada **przed** requestem.

### Acceptance criteria

- test dowodzi, że internal contract repair zwiększa licznik o 2, jeśli faktycznie były 2 odpowiedzi;
- Vision i tekst używają jednego licznika;
- przy call #16 system przechodzi do convergence;
- call #19–20 może wykonywać tylko closure-safe operations;
- #21 nie dociera do transportu;
- brak double-counting;
- istnieją testy dla cache hit.

### Checkpoint

`docs/checkpoints/MVP-PIPELINE-P01.md`

---

## P02 — NarrativePlan + VisualPlan

### Cel

Przed wygenerowaniem core textu ustalić jednocześnie strukturę artykułu, wymagania graficzne i potencjalne moduły rozszerzające.

### Docelowy kontrakt

```text
core_article:
  promise_to_reader
  main_thesis
  narrative_arc
  sections[]
  target_length

visual_plan:
  hero_slot
  inline_slots[]

visual slot:
  slot_id
  role
  section_anchor
  visual_need
  must_be_direct
  acceptable_related
  search_queries_direct[]
  search_queries_related[]

expansion_modules[]:
  module_id
  topic
  purpose
  suitable_visual_types[]
  preferred_placement
```

### Reguły

Hero:
- dokładnie jeden;
- `required=true`;
- domyślnie `must_be_direct=true`;
- related hero dopuszczalne tylko przez osobny recovery policy później.

Expansion modules są **planami awaryjnymi**, nie są jeszcze generowane do finalnego tekstu.

### Acceptance criteria

- schema odrzuca NarrativePlan bez hero;
- każdy slot ma stabilne `slot_id`;
- każdy slot zna section anchor;
- każdy slot ma direct search queries;
- jeśli `acceptable_related=true`, ma też related queries;
- plan zawiera sensowne 2–4 `expansion_modules` dla artykułu, jeśli research pozwala;
- wszystkie mock fixtures i testy są zgodne z kontraktem.

### Checkpoint

`docs/checkpoints/MVP-PIPELINE-P02.md`

---

## P03 — Core Text Lock

### Cel

Po zaakceptowaniu jakościowego tekstu zamrozić jego rdzeń.

### Stan

```text
core_text_locked = false
```

Po:

```text
draft
→ deterministic validation
→ text QC
→ targeted repair jeśli potrzebny
→ PASS
```

ustawić:

```text
core_text_locked = true
```

### Po locku zabronione

- full rewrite core article;
- wymiana głównej tezy;
- usuwanie poprawnych sekcji;
- przebudowa całego tekstu tylko dlatego, że brakuje grafiki.

### Po locku dozwolone

- caption;
- sidebar;
- context block;
- explainer;
- comparison box;
- reader-attention note;
- mały transition paragraph;
- bardzo mała targeted correction wskazana przez final QC.

### Acceptance criteria

- test: dobry core text + brak grafik nie zmienia core sections;
- próba full rewrite po locku jest odrzucana/routowana do dozwolonej operacji;
- stan locku jest audytowalny;
- additive modules są przechowywane osobno od core textu.

### Checkpoint

`docs/checkpoints/MVP-PIPELINE-P03.md`

---

## P04 — Image Coverage State + obowiązkowy hero

### Cel

Nie dopuścić ponownie do finalnego stanu `1/4`, `2/4`, `3/4` albo artykułu bez hero.

### Stan slotu

```text
direct_ok
related_candidate
related_supported
missing
rejected
fallback
```

### Stan artykułu

```text
required_slots
filled_slots
missing_slots
hero_status
coverage_complete
```

### Hard gate

```text
ready_for_manual_publish =
core_text_locked
AND hero_present
AND hero_is_allowed
AND all_required_slots_filled
AND no_required_slot_is_fallback
```

### Zakres

W tym tasku naprawić również lookup NarrativePlan w publication gate:
- nie wolno mylić `post_id` z `topic_id`;
- required slots muszą pochodzić z właściwego planu.

### Acceptance criteria

- 3/4 → BLOCK;
- 4/4 bez hero → BLOCK;
- technical/local fallback hero → BLOCK;
- wszystkie required slots `direct_ok` lub `related_supported` → coverage może być complete;
- publication gate pobiera właściwy NarrativePlan;
- UI nie opisuje incomplete coverage jako gotowego artykułu.

### Major checkpoint

Po P04:
`docs/checkpoints/MVP-PIPELINE-FOUNDATION.md`

### Checkpoint tasku

`docs/checkpoints/MVP-PIPELINE-P04.md`

---

## P05 — Direct Image Acquisition

### Cel

Najpierw maksymalizować liczbę grafik naprawdę odnoszących się do tekstu.

### Pipeline slotu

```text
direct queries
→ provider search
→ rights/license filter
→ format/size/technical filter
→ cheap semantic metadata prefilter
→ shortlist
→ Gemini Vision na rzeczywistych bajtach obrazu
→ direct_ok / rejected
```

### Reguły

- samo wyszukiwanie providerów nie zużywa GeminiBudget;
- Vision zużywa `+1`;
- Gemini Vision nie może oceniać tylko filename/title/metadata;
- hero ma wyższy próg trafności niż inline;
- odrzucenie pierwszego obrazu nie kończy slotu — system próbuje następnego bounded kandydata;
- liczba kandydatów wysyłanych do Vision musi być kontrolowana i zależna od pozostałego budgetu.

### Acceptance criteria

- każda `direct_ok` przeszła Vision na faktycznym obrazie;
- test reject → next candidate;
- test no-candidate → slot pozostaje `missing`, a nie fallback-ready;
- budget nalicza Vision poprawnie;
- rights/license gate pozostaje fail-closed.

### Checkpoint

`docs/checkpoints/MVP-PIPELINE-P05.md`

---

## P06 — Image Shortage Recovery + Related Images

### Cel

Jeżeli direct image coverage jest niepełne, wykorzystać wartościowe grafiki pokrewne, ale tylko w kontrolowany sposób.

### Trigger

```text
DIRECT_IMAGE_PHASE_COMPLETE
AND coverage_complete = false
→ IMAGE_SHORTAGE_RECOVERY
```

### Recovery planner dostaje

- locked core article;
- missing slots;
- related image candidates;
- available expansion modules;
- research/source map;
- remaining Gemini budget.

### Wynik

```text
missing_slot
→ related_image
→ expansion_module
→ placement
→ editorial_reason
```

### Reguły

- nie modyfikować core text;
- nie dobierać przypadkowego „ładnego” zdjęcia tylko dla coverage;
- related candidate musi być tematycznie uzasadniony research/source map;
- hero ma osobny, ostrzejszy recovery policy;
- jeśli nie istnieje wystarczająco dobra hero po recovery → `manual_review`.

### Acceptance criteria

- brak direct inline uruchamia recovery zamiast technicznego fallbacku;
- related planner nie zmienia core text;
- related candidate bez sensownego expansion module zostaje rejected;
- hero bez wartościowego recovery zatrzymuje automat.

### Checkpoint

`docs/checkpoints/MVP-PIPELINE-P06.md`

---

## P07 — Additive Expansion Engine

### Cel

Dodać wartościowy tekst wyjaśniający grafiki pokrewne **bez przepisywania jakościowego core article**.

### Docelowy kontrakt

```text
related_context_blocks[]:
  block_id
  module_id
  target_image_id
  target_slot_id
  placement_after_section
  type
  heading
  body
  caption
  reader_attention_note
  source_claim_ids[]
```

Allowed block types:
- sidebar;
- context;
- explainer;
- comparison;
- why_it_matters;
- related_background.

### Reguły

- każdy blok wynika z researchu;
- brak source/claim support → nie generować finalnego bloku;
- related image + context block przechodzą ponowną editorial/Vision validation;
- dopiero po PASS slot staje się `related_supported`;
- core text pozostaje byte/section stable poza jawnie dozwolonym małym transition hookiem.

### Acceptance criteria

- test dowodzi, że hash/wersja core textu nie została nadpisana;
- każdy related block ma źródłowe claim IDs;
- related image bez zatwierdzonego bloku nie liczy się do coverage;
- orphaned block/image jest wykrywane;
- po recovery możliwe jest osiągnięcie 4/4 bez użycia technicznego fallbacku.

### Major checkpoint

Po P07:
`docs/checkpoints/MVP-PIPELINE-IMAGE-RECOVERY.md`

### Checkpoint tasku

`docs/checkpoints/MVP-PIPELINE-P07.md`

---

## P08 — LayoutPlan + zróżnicowany renderer

### Cel

Zastąpić jedną kompozycję dla wszystkich artykułów kontrolowanym planem layoutu generowanym przez Gemini.

Gemini **nie generuje dowolnego HTML/CSS**.

### Dozwolone rodziny layoutów MVP

```text
standard
visual_story
deep_dive
context_heavy
```

### LayoutPlan

```text
template_family
hero_style
section_layouts[]
image_placements[]
context_block_placements[]
callouts[]
reading_rhythm
caption_strategy
```

### Renderer

PHP renderer:
- waliduje allowlist;
- mapuje LayoutPlan na istniejące komponenty;
- ignoruje niedozwolone wartości;
- ma deterministic safe default.

### Acceptance criteria

- dwa fixtures mogą wygenerować różne poprawne layouty;
- Gemini nie dostarcza arbitralnego HTML/CSS;
- każda grafika występuje dokładnie raz;
- hero znajduje się w hero placement;
- context blocks są przy właściwych sekcjach/grafikach;
- istnieje mobile-safe rendering;
- invalid LayoutPlan → deterministic default + audit note.

### Checkpoint

`docs/checkpoints/MVP-PIPELINE-P08.md`

---

## P09 — Final Multimodal Editorial QC + publication gate

### Cel

Ostatnia ocena ma odpowiadać na pytanie: **czy tekst, źródła, grafiki i kompozycja razem tworzą jakościowy artykuł?**

### Input final QC

- locked core text;
- additive context blocks;
- research/source map;
- hero + inline images;
- Vision assessments;
- captions;
- LayoutPlan;
- coverage state.

### Ocena

```text
text_quality
factual_consistency
source_coverage
hero_fit
image_section_alignment
visual_completeness
related_module_naturalness
layout_coherence
reader_flow
repetition
editorial_value
```

### Wynik

```text
PASS
PASS_WITH_MINOR_NOTES
FAIL
```

### Targeted final repair

Dozwolone:
- caption;
- heading;
- small transition;
- placement;
- context block;
- drobne wyjaśnienie.

Niedozwolone:
- pełny rewrite locked core article.

### Deterministic pre-gates

Zanim final Gemini QC zostanie uznane:
- hero required;
- all required slots filled;
- brak fallbacków;
- source/rights gates PASS;
- text hard gates PASS.

Gemini final QC nie może override'ować deterministic gate.

### Acceptance criteria

- 3/4 → hard fail przed ready;
- brak hero → hard fail;
- fallback → hard fail;
- PASS z pełnym coverage → `ready_for_manual_publish`;
- FAIL poważny → `manual_review`;
- publikacja nadal wymaga osobnej ręcznej akcji.

### Checkpoint

`docs/checkpoints/MVP-PIPELINE-P09.md`

---

## P10 — E2E regression matrix + clean MVP proof

### Cel

Udowodnić działanie przebudowanego pipeline'u na disposable DB oraz przygotować pierwszy czysty realny MVP flow.

### Obowiązkowe scenariusze

| Scenariusz | Oczekiwany wynik |
|---|---|
| dobry tekst + komplet direct images | PASS |
| dobry tekst + brak 1 inline | related recovery → PASS |
| dobry tekst + brak 2 inline | additive modules → PASS albo manual review |
| brak direct hero, ale silny related hero | controlled hero recovery + final QC |
| brak sensownego hero | manual review |
| dobry tekst + słabe grafiki | core text pozostaje nienaruszony |
| related image bez source-supported context | reject |
| call #20 Gemini | domknięcie dozwolone |
| próba call #21 | transport BLOCK |
| 3/4 slotów | publication BLOCK |
| 4/4 + fallback | publication BLOCK |
| komplet + final QC PASS | ready_for_manual_publish |
| invalid LayoutPlan | safe deterministic layout |
| transient provider failure | bounded retry / audytowalny state |

### Mock E2E

```text
RSS fixture
→ research
→ NarrativePlan + VisualPlan
→ core draft
→ text QC
→ text lock
→ direct images
→ recovery jeśli potrzebne
→ layout
→ final multimodal QC
→ preview
```

Bez live publication.

### Real MVP proof

Dopiero po mock PASS:
- jeden świeży realny RSS item;
- jeden artykuł;
- monitorowany GeminiBudget;
- komplet grafik;
- final preview;
- ręczna publikacja dopiero po decyzji użytkownika.

### Aktualny dowód / blocker P10

- Controlled P08/P09 batch-gate evidence is green on disposable DB / injected transports (D1–D7). It does not make a live-provider claim.
- The original run consumed `2/20`; the canonical consumer mismatch was repaired and only batch #27 was resumed under controlled approval. Its precheck was `2/20`.
- The resume made exactly three additional live requests: `article_draft`, `field_text_repair`, `article_draft`. Total GeminiBudget is `5/20`; no other operation or batch was run.
- Draft version #88 was persisted. Draft operation #356 then stopped with `validation_contract`: `Szkic zmienił wymagany hero VisualPlan: slot_id.` Audit rows #432–#437 record the controlled resume.
- The repaired canonical consumer accepted the persisted NarrativePlan. The new failure is downstream: the model-generated illustration plan emitted the legacy hero representation without required canonical `slot_id`, and the draft assertion correctly rejected it.
- No QC, images, LayoutPlan or final P09 result was reached. Post #287 remains unpublished. Per the one-real-article rule, do not start another real attempt. P10 remains `[~]`; fix the prompt/output schema or insert a deterministic canonical adapter before the assertion, then prove it with a controlled regression before requesting new live authorization.
- Testy komponentowe i mock/disposable-DB przechodzą, ale nie dowodzą realnej orkiestracji providera.
- Dostęp TCP/443 do `generativelanguage.googleapis.com` został przywrócony dla kontrolowanego runu #121.
- W runie #121 legacy direct acquisition wykonał sześć prawdziwych ocen Vision (#15–#20) dla slotu `lead`; wszystkie zostały odrzucone. Zużyły one budżet `14/20 → 20/20` zanim wykonanie doszło do planera P06.
- Planer P06 nie otrzymał odpowiedzi: operacje `image_recovery` #150 i #156 miały `live_request_count=0`, a po odrzuceniu przez admission gate zostały terminalizowane jako `failed`.
- Post #121 pozostaje `manual_review`, coverage `2/4` (hero i lead missing; `why-important` i `fact-1` accepted). Nie jest to poprawny complete P06 proof i nie wolno resetować jego budżetu ani ponawiać transportu.
- Naprawa zapobiegawcza rezerwuje przed direct Vision budżet na P06 planner, P07 recovery oraz P08/P09; przy `14/20` i dwóch brakujących slotach direct Vision ma limit `0`. Admission budget failure kończy operację jako `failed`, zamiast zostawiać `running` orphan.
- P10 pozostaje `[~]` do czasu kontrolowanego real proofu na artykule z wystarczającym budżetem i potwierdzenia pełnej ścieżki do preview.

### Acceptance criteria

- focused suite PASS;
- mock E2E PASS;
- żadnego niejawnego 21. requestu;
- zero final-ready bez hero/coverage;
- stan całego artykułu jest audytowalny;
- `docs/MVP_STATE.md` zaktualizowane do nowej architektury;
- `docs/CURRENT_WORK.md` pokazuje P10 jako `[x]` dopiero po mock PASS **oraz** kontrolowanym realnym proofie bez pominięcia P06.

### Major checkpoint

`docs/checkpoints/MVP-PIPELINE-COMPLETE.md`

### Checkpoint tasku

`docs/checkpoints/MVP-PIPELINE-P10.md`

---

## Protokół autonomicznego przechodzenia między taskami

Po ukończeniu tasku Codex ma:

1. uruchomić wymagane testy;
2. sprawdzić actual diff;
3. utworzyć checkpoint tasku;
4. w `docs/CURRENT_WORK.md`:
   - `[~]` → `[x]`;
   - zaktualizować `Last completed task`;
   - zaktualizować `Last checkpoint`;
   - ustawić kolejny task jako `[~]`;
   - zaktualizować `Current active task`;
   - wpisać `Next exact action`;
5. kontynuować następny task, jeśli nie istnieje hard blocker wymagający decyzji użytkownika.

Nie wolno:
- oznaczać `[x]` tylko na podstawie planu lub deklaracji;
- przeskakiwać tasków zależnych;
- poprawiać testów tak, aby ukryły regresję;
- wykonywać live publication;
- używać produkcyjnej DB;
- wykonywać niekontrolowanych live provider calls w testach.

### Zalecane paczki pracy

```text
FOUNDATION:
P01 → P02 → P03 → P04
→ major checkpoint

IMAGE RECOVERY:
P05 → P06 → P07
→ major checkpoint

COMPOSITION + FINALIZATION:
P08 → P09 → P10
→ major checkpoint
```

Jeżeli kontekst sesji robi się duży, zakończ po major checkpoint i wznowienie rozpocznij wyłącznie od pierwszego nieukończonego `[ ]`/`[~]` tasku z tego pliku.

---

## EDITORIAL ENGINE V2 — A+B+C LONGFORM

Status: `[x]` wdrożone dla nowych operacji workflow (`workflow_version = 2`).

- A = primary story; B = source-backed explanatory/context topic; C = osobny source-backed curiosity/history angle. Gdy approved research nie zawiera wartościowego C, NarrativePlan zapisuje jawne `curiosity_omitted_reason`.
- ResearchPackage odkrywa kandydatów A/B/C i wiąże ich claims z istniejącym source map; NarrativePlan wybiera tylko naturalną, udokumentowaną kompozycję.
- Canonical flow V2: `Research A+B+C → NarrativePlan + preliminary visual directions → draft → text QC/repair → core lock → FinalVisualPlan → images/P06/P07 → LayoutPlan → final multimodal QC → ręczna publikacja`.
- `source_map` ma provider-safe postać listy `{claim_id, source_ids[]}`; pusty lub legacy map jest deterministycznie normalizowany z już zwróconych `claims[].source_ids` przed walidacją.
- Preferowana długość artykułu: 6000–8500 znaków; twardy zakres: 5000–10000 znaków.
- GeminiBudget: hard max 30; convergence od 24; calls 27–30 są closure-only; request #31 jest blokowany przed transportem.
- Nowe drafty V2 używają canonical dynamic `sections[]` (`section_id`, `topic_role`, `content_type`, `heading`, `body`, opcjonalny slot); legacy shape pozostaje obsługiwany dla starych artykułów.
- Pre-draft VisualPlan jest wyłącznie preliminary visual directions i jego liczba slotów nie blokuje poprawnego draftu. Po QC i zamrożeniu core osobna, audytowalna operacja `final_visual_plan` wylicza target z finalnego tekstu i zwraca dokładnie wymagane sloty z `topic_source: A|B|C`; dopiero ten plan czyta image pipeline. Target to `clamp(1 + floor(final_article_chars / 2000), 3, 6)`.
- P06/P07 pozostają per-slot, source-backed recovery i nie zmieniają locked core.
- Retrieval V2 rozdziela hard legal/technical filtering od soft semantic ranking: słabe metadata obniża ranking, ale nie odrzuca legalnego, technicznie poprawnego kandydata przed Vision.
- Każdy slot przeszukuje kilka query z VisualPlan, scala i deduplikuje metadata pool przed Vision oraz przechodzi audytowalnie `exact_direct → broader_direct → related P06`. Role A/B/C są zachowane w retrieval audit.
- Direct i related Vision shortlist są ograniczone do maksymalnie 3 kandydatów na slot; search/ranking/dedupe nie zużywają GeminiBudget, a closure reserve nadal zatrzymuje kosztowne retry.
- Visual target pozostaje `clamp(1 hero + floor(chars/2000), 3, 6)`, natomiast publication floor wynosi `3→3, 4→3, 5→4, 6→5`; hero i wszystkie jakościowe hard gates pozostają obowiązkowe.
- P08 otrzymuje dynamiczne sekcje, role A/B/C, typy, headings, długość, visual floor i dostępne obrazy. Renderer zachowuje kolejność NarrativePlan, nie zamienia prose/explainer/history w cards, ogranicza card body do 500 znaków i maksymalnie 2 cards z rzędu.
- P09 w istniejącym wywołaniu ocenia także card wall, długie prose, rozłożenie obrazów, czytelność B/C oraz editorial rhythm.
- Publikacja pozostaje ręczna i wymaga text QC PASS, core lock, hero, pełnego visual coverage, LayoutPlan, final multimodal QC PASS oraz GeminiBudget <= 30.

Checkpoint: `docs/checkpoints/EDITORIAL-ENGINE-V2.md`.
