# V3.4 — Task subagent edit inheritance fix

Potwierdzone zachowanie:
- parent uruchamia custom child przez `task` z `subagent_type`;
- child `mamona-tester` miał effective permission `edit: deny`;
- toolset childa nie zawierał edit/write/apply_patch mimo child config `edit: allow`.

Root cause/workaround:
- parent `edit: deny` jest dziedziczony do Task child session;
- zmiana `mamona-orchestrator` z `edit: deny` na `edit: ask`;
- writing children zachowują `edit: allow`;
- Orchestrator ma twardy instrukcyjny zakaz używania file-edit tools.

Auto-compaction i modele bez zmian.
