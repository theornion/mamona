# NEXT PROMPT — MAMONA-24 P3-C RESUME V4

Wklej do `mamona-coordinator` po instalacji V4.0.

```text
WZNÓW MAMONA-24 OD CANONICAL STATE V4.

Najpierw przeczytaj WYŁĄCZNIE:
- docs/research/MAMONA-24-V4-CANONICAL-STATE.md
- docs/research/MAMONA-24-SPEC-OVERRIDES-V4.md
- docs/CURRENT_WORK.md

P3-A i P3-B są COMPLETE. Nie wracaj do nich bez nowej regresji.
P3-C jest IN PROGRESS. Nie uruchamiaj P4.

PIERWSZY ATOM: P3-C production Gemini Vision integration.

Potwierdź w aktualnym kodzie, bez broad researchu:
- php/article-image-service.php::article_image_multimodal_assess()
- php/article-image-service.php::select_source_image_from_results()
- php/article-image-service.php::fulfill_article_source_images()
- najbliższą istniejącą abstrakcję Gemini transport/provider w php/generation-service.php
- article GeminiBudget w php/gemini-quota-service.php oraz jego aktualne integration points.

Oczekiwany blocker do POTWIERDZENIA, nie do założenia:
production fulfill_article_source_images() nie przekazuje realnego multimodal provider adaptera/context, a article_image_multimodal_assess() bez callbacka rzuca RuntimeException.

Jeżeli kod to potwierdza, wykonaj jeden targeted atom implementacyjny (sam, jeżeli zakres jest mały; inaczej mamona-worker):

CEL:
Podłączyć produkcyjną, mockowalną ocenę Gemini Vision do image pipeline tak, aby model oceniał RZECZYWISTĄ zawartość obrazu względem article/section/VisualSlot context.

WYMAGANIA:
1. metadata/token score pozostaje tylko prefilterem;
2. finalny semantic/editorial gate wymaga multimodal ACCEPT;
3. bez blacklist nazwisk/słów;
4. actual image content musi być wejściem modelu, nie tylko title/URL;
5. provider path ma być mockowalny w testach;
6. testy NIE wykonują live Gemini;
7. Vision należy do TEGO SAMEGO centralnego article GeminiBudget (max20, convergence16);
8. żadna nowa odpowiedź Gemini nie może ominąć budgetu;
9. call przy wyczerpanym budżecie nie może wykonać niepoliczonej dodatkowej odpowiedzi — sprawdź end-to-end admission, nie tylko sam increment helper;
10. cache hit nie jest nową odpowiedzią; transport retry bez nowej odpowiedzi nie jest nową logical response;
11. preserve rights/license/SSRF protections;
12. nie pobieraj/ufaj arbitrary URL poza istniejącym bezpiecznym mechanizmem obrazu.

Nie projektuj całego pipeline od nowa. Wykorzystaj istniejące abstractions.

Po implementacji NIE uruchamiaj szerokiego testera.
Uruchom mamona-executor tylko dla:
- php -l zmienionych PHP;
- tests/article-image-pipeline-smoke.php;
- tests/p3c-vision-gate-test.php;
- najmniejszego testu budget integration, jeśli został dodany/zmieniony.

Jeżeli FAIL:
-> mamona-diagnoser dla jednego root-cause cluster
-> mamona-worker exact fix
-> mamona-executor targeted retest.

Po image integration PASS:
1. krótki audit pozostałych iconv w image preselection heuristics (`source_image_candidate_is_suitable_for_role`, `source_image_candidate_matches_query`) pod cross-platform UTF-8;
2. pozostałe P3-C: manual_review publication behavior, renderer/gallery, hard/soft gates, mojibake scan;
3. P3-D dry-run/fixture reset;
4. final P3 regression;
5. CHECKPOINT_P3;
6. STOP przed P4.
```
