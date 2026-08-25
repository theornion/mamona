# MAMONA-24 — V4 Spec Overrides

Ten plik superseduje sprzeczne fragmenty `docs/specs/MAMONA-24-article-generation-visual-narrative-v3.md` do czasu formalnej synchronizacji specyfikacji po P3.

## Image semantic/editorial gate

Stary zapis, w którym `semantic_score >= 60` oznacza finalną akceptację obrazu, jest nieaktualny.

Obowiązuje:

1. rights/technical gate — deterministyczny;
2. metadata/token score — wyłącznie tani prefilter/preselection;
3. actual-image multimodal semantic/editorial assessment — finalna ocena dopasowania;
4. publication image gate wymaga multimodal ACCEPT oraz wszystkich technicznych/prawnych warunków.

`score < 60` może odrzucić oczywiście słabego kandydata przed Vision. `score >= 60` nie może sam zatwierdzić grafiki do publikacji.

## Negative fixtures

Trump/zombie/brain był regression fixture, nie blacklistą. System ma generalizować na różne tematy i różne semantycznie błędne obrazy.

## GeminiBudget

Vision korzysta z tego samego article GeminiBudget co reszta pipeline'u. Nie tworzyć osobnego budżetu dla obrazów.
