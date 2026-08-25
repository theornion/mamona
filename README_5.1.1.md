# Mamona Kilo Agents V5.1.1 — Permission Hotfix

Hotfix do V5.1. Nie zmienia kodu aplikacji ani `docs/CURRENT_WORK.md`. Naprawia blokadę `task:*` / parent permission inheritance widoczną przy wznowieniu P4.

## Instalacja Windows

Repo domyślne: `C:\Projekty\Mamona`

W PowerShell, z katalogu rozpakowanej paczki:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-5.1.1.ps1
```

Skrypt:
- tworzy backup nadpisywanych plików w `.mamona-backups\5.1.1-<timestamp>`;
- instaluje komplet agentów 5.1.1;
- backupuje i usuwa znane legacy agenty V4.x (`repo-scout`, `mamona-coder`, `mamona-tester`, `quick-maintainer`);
- nie dotyka Ollamy ani modeli.

## Po instalacji

1. Zamknij obecną sesję Kilo.
2. Otwórz nową sesję, żeby agent registry i permissions zostały przeładowane.
3. Wklej `PROMPT_RESUME_P4_AFTER_5_1_1.md`.

## Ważne

Orchestrator ma szerokie runtime permissions wyłącznie jako capability ceiling dla child sessions. Jego prompt nadal zabrania bezpośredniej implementacji. Faktyczne ograniczenia są na subagentach.
