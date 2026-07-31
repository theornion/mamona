# Backup przed zagęszczeniem widoku Tematy (2026-07-31)

Zmiana dotyczy wyłącznie `php/admin-editorial-topics.php`, `assets/js/admin-editorial-topics.js`, `assets/css/admin.css`, fixture i testu kontraktowego.

Przed zmianą:

- formularz `topic-trash-bulk-form` był osobnym widocznym rzędem pod `topic-bulk-toolbar`;
- każda karta miała osobny checkbox `topic-trash-bulk-check`;
- formularz `merge_topics` znajdował się poza panelem `<details>`;
- panel źródeł miał etykietę „Źródła i ręczne rozdzielanie”.

Powrót jest łatwy przez odwrócenie pojedynczego diffu tych plików; historyczny blok reguł CSS zapisano w `admin-topic-rules.css`.
