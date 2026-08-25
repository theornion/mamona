# Mamona Agent Pack 4.5.2 — Project Permission Ceiling Hotfix

Ta wersja jest minimalnym hotfixem V4.5 po realnym błędzie runtime:

```text
source: project
permission: bash
pattern: *
action: deny
```

## Co naprawia

4.5.1 poprawiała agent-level permissions, ale pozostawiała project-level permission jako `ask`. Jeżeli w projekcie istniała starsza/zdublowana reguła `bash:* = deny`, runtime mógł nadal zablokować PHP zanim agent-level allow został użyty.

4.5.2 ustawia jawny project capability ceiling:

- `bash:* = allow`
- `edit:* = allow`
- `task:* = allow`
- `external_directory:* = allow`

Rzeczywiste ograniczenia bezpieczeństwa pozostają na agentach:

- coordinator blokuje destructive git / delete commands;
- executor jest command-only, `edit: deny`, `task: deny`, shell deny-by-default z exact PHP/test allowlist;
- diagnoser/reviewer pozostają read-only;
- worker/heavy-coder zachowują zakres V4.5.

## Konflikty konfiguracji projektu

Installer:

1. zapisuje canonical `.kilo/kilo.jsonc`;
2. synchronizuje root `kilo.json[c]`, jeśli istnieje;
3. usuwa duplicate `.kilo/kilo.json` po backupie;
4. backupuje i wyłącza legacy `.kilocode` config files;
5. ostrzega o potencjalnym `bash deny` w `opencode.json[c]`, ale go nie modyfikuje.

## Instalacja

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-4.5.2.ps1
powershell -ExecutionPolicy Bypass -File .\verify-mamona-4.5.2.ps1
```

Potem:

```text
Ctrl+Shift+P -> Developer: Reload Window
NEW Kilo session -> mamona-coordinator
```

Wklej `PROMPT_RESUME_P4_AFTER_4_5_2.md`.
