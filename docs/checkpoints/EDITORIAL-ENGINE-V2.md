# EDITORIAL ENGINE V2 — A+B+C LONGFORM

Status: COMPLETE (implementation + local contract verification; bez live provider proof).

## Contract

- Workflow metadata: `workflow_version = 2` w nowych operacjach research, NarrativePlan, draft, text QC, LayoutPlan i final multimodal QC.
- Research: primary A oraz opcjonalne kandydatury B/C są powiązane z zatwierdzonymi claim IDs i source map.
- Provider-safe `source_map` jest listą rekordów `{claim_id, source_ids[]}` zamiast obiektu z dynamicznymi kluczami; zachowuje ten sam fail-closed grounding.
- Hotfix topic #287 / post #313: odpowiedź operacji #383 z pustym `source_map` została odtworzona z `claims[].source_ids`, przeszła aktualny schema + deterministic research validation i została lokalnie sfinalizowana. ResearchPackage #47 jest approved; najnowszy batch item #37 zatrzymano bezpiecznie na początku draftu (`paused_by_operator`), a starszy duplikat #36 oznaczono jako superseded. Hotfix nie wykonał nowego provider calla i nie zmienił budżetu `6/30`.
- Cross-ID resolver hotfix: temat #287 kolidował z istniejącym postem #287, przez co post #313 otrzymał NarrativePlan #11 o zaćmieniu Słońca. `find_narrative_plan_for_post()` wybiera teraz wyłącznie `article_id = post_id`, a lookup po topic prowadzi przez audytowalną operację NarrativePlan. Błędny draft #95 pozostaje failed/non-active; batch item #38 jest zapauzowany przed wygenerowaniem poprawnego planu.
- Selection: NarrativePlan wybiera source-backed A/B/C, definiuje editorial thesis, reader journey i zmienną kolejność dynamicznych sekcji. C jest osobnym curiosity/history angle; brak wartościowego C wymaga `curiosity_omitted_reason`.
- Text: preferred 6000–8500, hard accepted 5000–10000 znaków; jeden spójny artykuł, bez filleru.
- Visuals: plan przed draftem jest preliminary visual directions i nie stanowi walidacji finalnej liczby slotów. Canonical `final_visual_plan` powstaje dopiero po text QC/repair i audytowalnym core locku; zawiera dokładnie `clamp(1 + floor(final_article_chars / 2000), 3, 6)` slotów z finalnymi anchorami i `topic_source`. Image pipeline V2 czyta finalny plan; legacy zachowuje adapter preliminary planu.
- Images/recovery: istniejące direct acquisition, rights/technical filters, Vision i P06/P07 są zachowane; recovery pozostaje per-slot i additive po core lock.
- Image retrieval: hard reject obejmuje wyłącznie prawa/techniczne śmieci/duplikaty, a semantic metadata służy do soft rankingu. Multi-query wyniki są scalane i deduplikowane w metadata pool przed bounded Vision shortlist (maks. 3/slot). Audit zapisuje liczność poolu, hard reject reasons, ranked count, Vision attempts, poziom exact/broader/related oraz A/B/C topic source.
- Coverage: target nadal wynosi `clamp(1 + floor(chars/2000), 3, 6)`, lecz publication floor to `3→3, 4→3, 5→4, 6→5`. Pipeline nadal próbuje osiągnąć target per-slot; brak jednej nadmiarowej ilustracji ponad floor nie wymusza manual review. Hero pozostaje bezwzględnie wymagane.
- Composition/finalization: nowe drafty V2 zapisują dynamiczne `sections[]`, a legacy shape pozostaje adapterem dla starych artykułów. P08 otrzymuje sekcje, content types, role A/B/C, headings, długość, floor i dostępne obrazy. Renderer zachowuje normalny prose flow, limituje card body do 500 znaków i nie dopuszcza więcej niż 2 cards z rzędu. P09 w istniejącym callu ocenia także card wall, image rhythm i dominację A przy czytelnym B/C.
- Publication: manual-only, fail-closed na text QC, core lock, hero, coverage/floor, persisted LayoutPlan, final multimodal QC i budget <=30.
- GeminiBudget: 0–23 normal, 24–26 convergence, 27–30 closure-only, request #31 blokowany przed transportem.

## Changed files

- `php/gemini-quota-service.php`
- `php/editorial-schema.php`
- `php/research-package-service.php`
- `php/narrative-plan-service.php`
- `php/article-draft-service.php`
- `php/quality-check-service.php`
- `php/article-image-service.php`
- `php/generation-batch-service.php`
- `tests/p2a-gemini-budget-test.php`
- `tests/narrative-plan-contract-version-smoke.php`
- `docs/CURRENT_WORK.md`
- `docs/checkpoints/EDITORIAL-ENGINE-V2.md`

## Verification

- PHP lint: PASS dla wszystkich zmienionych PHP services oraz targeted budget test.
- `tests/narrative-plan-contract-version-smoke.php`: PASS.
- One-shot disposable research schema/contract validation: PASS (`EDITORIAL_ENGINE_V2_RESEARCH_CONTRACT_OK`).
- `tests/p2a-gemini-budget-test.php`: PASS (145 assertions), w tym convergence, closure-only i blokada call #31.
- `tests/p8-layout-plan-smoke.php`: PASS (15 assertions), w tym dynamic sections, prose fallback, max 2 cards i floor 8750 = 5.
- `tests/p6-image-recovery-smoke.php`: PASS (21 assertions), w tym soft semantic candidate, multi-query pool/dedupe, shortlist max 3 oraz target 5 → floor 4.
- `tests/article-image-pipeline-smoke.php`: PASS (`ARTICLE_IMAGE_PIPELINE_SMOKE_OK`) na disposable DB i kontrolowanych transportach.
- `tests/p4-image-coverage-smoke.php`: PASS (15 assertions), w tym V2 target/floor split, niezmieniony legacy full-coverage gate, obowiązkowy hero i source-backed related coverage.
- `tests/gemini-quota-smoke.php`: FAIL w istniejącym fallback fixture (`Gemini API nie zwróciło wyniku JSON`) przed assertions centralnego budgetu; nie naprawiano jako unrelated legacy smoke zgodnie z ograniczeniem celu.
- `tests/article-draft-smoke.php`: legacy fixture zatrzymuje się przed draftem, bo nie zawiera nowych wymaganych pól ResearchPackage (`primary_story` itd.); nie rozszerzano historycznego smoke matrix w tym celu.
- Live Gemini/Vision calls: 0.
- Wygenerowane/wznowione artykuły: 0.
- Subagents: 0.
- Minimal FinalVisualPlan fixture: preliminary 3 sloty + finalny tekst 9323 znaków przechodzi do locked core, target wynosi 5, publication floor 4, controlled transport zapisuje jedną odpowiedź operacji i image pipeline wybiera finalny plan. `tests/post-draft-visual-floor-smoke.php`: PASS; live calls 0.

## Remaining proof

Pierwszy ręczny artykuł V2 z panelu powinien potwierdzić jakość kompozycji A+B+C i realne zachowanie providerów. Nie jest to acceptance gate tego checkpointu.

## Minimal image-recovery state fix

- Zwalidowany i ukończony `image_recovery_replan` jest trwałym recovery override nad audytowalnym canonical P02 VisualPlan; kolejny retrieval czyta effective slot i oznacza query jako `recovery_replan`.
- Uproszczony coverage `missing_slot` jest przed klasyfikacją scalany z pełną polityką canonical/effective VisualSlot, więc nie gubi `acceptable_related`, related queries ani hero policy.
- Zakończona odpowiedź Vision jest deduplikowana po post + slot + identity/source + hash bajtów. Powtórzony niezmieniony asset reuse'uje ACCEPT/REJECT bez transportu i bez nowego punktu budżetu.
- Related hero z istniejącym zatwierdzonym additive context i stanem `context_pending` wznawia się bez nowego planowania/modułu, wyłącznie od finalnej walidacji Vision.
- Targeted local verification: `tests/p6-image-recovery-smoke.php` i `tests/p10-related-hero-recovery-smoke.php` PASS; live Gemini/Vision calls: 0.
