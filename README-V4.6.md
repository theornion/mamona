# MAMONA V4.6.3 — Tri-Tier Parallel Stable / Delegation Ceiling

Ta paczka jest czystym rebase na V4.5 Tri-Tier Parallel, bez agentów 5.1.x.

## Aktywni agenci
- mamona-coordinator — primary 14B, zero-direct-write by contract; technical edit/write/bash are delegation ceiling so child permissions work;
- mamona-executor — 9B exact command/test;
- mamona-quick-worker — 9B exact mechanical fix;
- mamona-diagnoser — 14B diagnosis;
- mamona-architect — 14B design;
- mamona-worker — 14B implementation;
- mamona-reviewer — 14B review;
- mamona-heavy-coder — 30B/128K heavy exclusive;
- checkpoint-writer — 9B docs/checkpoint.

## Najważniejsze zasady V4.6.3
1. `Task` jest jedynym mechanizmem child delegation. `agent_manager` jest jawnie DENY dla tej architektury.
2. Nigdy nie resume'ujemy child session ręcznym ID; znika klasa błędów `ses/new` wynikająca z prób ręcznego session handlingu.
3. Project permission jest capability ceiling = allow; ograniczenia siedzą w konkretnych agentach, więc child nie powinien być przypadkiem odcięty od PHP/edit.
4. Wszystkie local agent model IDs odpowiadają realnym aliasom serwera — bez fikcyjnych `*-fast-64k` aliasów.
5. Brak `ask` w normalnych child workflows. Albo konkretna operacja jest `allow`, albo `deny`, żeby praca nie czekała na człowieka.
6. Parallel = maks. jeden 14B + jeden 9B, wyłącznie przy niezależnych taskach.
7. 30B jest zawsze heavy-exclusive i ma hard anti-loop.

## Instalacja Windows
```powershell
powershell -ExecutionPolicy Bypass -File .\INSTALL-V4.6.3.ps1 -ProjectRoot "C:\Projekty\mamona"
```
Następnie `Developer: Reload Window`, wybierz `mamona-coordinator` i swój model primary w UI.

## Weryfikacja
```powershell
powershell -ExecutionPolicy Bypass -File .\VERIFY-V4.6.3.ps1 -ProjectRoot "C:\Projekty\mamona"
```

## Start pracy
Wklej `PROMPT_START_MAMONA_V4.6.3.md`.

## V4.6.3 delta
- Coordinator ma techniczne `edit/write/bash: allow` jako delegation ceiling wymagany przez aktualne dziedziczenie permissions Kilo, ale jego kontrakt systemowy zabrania direct write; używa bezpośrednio tylko read/evidence/test.
- Każdy write jest delegowany do quick-worker / worker / heavy-coder / checkpoint-writer.
- Executor failure ma direct deterministic fallback, ale nigdy write fallback.
- Scheduler, modele, indexing i server init bez zmian względem V4.6.1.

## Start pracy
Wklej `PROMPT_START_MAMONA_V4.6.3.md`.
