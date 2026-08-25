# Mamona Agent Pack 4.5.1 — Permission Hotfix

To jest **minimalny hotfix paczki 4.5**. Nie wprowadza architektury 5.1.x.

## Co zmienia

- zachowuje `mamona-coordinator` jako primary;
- zachowuje płaski workflow V4.5;
- naprawia możliwość uruchamiania lokalnego PHP z `C:/xampp/php/php.exe`;
- naprawia `edit` u coordinatora, aby mógł wykonać mały DIRECT_TARGET fix, zaktualizować `CURRENT_WORK.md` i zapisać checkpoint;
- daje `mamona-executor` wyłącznie command-only dostęp do PHP/testów/lintu i bezpiecznego `git status/diff`;
- nie zmienia modeli V4.5 ani ról pozostałych agentów;
- nie dodaje attempt-ledgerów/anti-loop z 5.1.x.

## Dlaczego potrzebny był także `external_directory`

`C:/xampp/php/php.exe` znajduje się poza rootem repozytorium. Samo włączenie Bash w UI nie wystarcza, jeśli projektowa reguła `external_directory` blokuje ten target. 4.5.1 jawnie dopuszcza tylko `C:/xampp/php/*` jako zewnętrzny zakres wymagany do testów.

## Instalacja

Rozpakuj paczkę, a następnie z katalogu paczki:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-4.5.1.ps1
```

Domyślny projekt: `C:\Projekty\mamona`.

Installer robi backup obecnych `.kilo\kilo.jsonc` i `.kilo\agents` do `_agent_backups`.

Po instalacji:

1. `Ctrl+Shift+P`
2. `Developer: Reload Window`
3. nowa sesja Kilo
4. wybierz `mamona-coordinator`
5. opcjonalnie uruchom `verify-mamona-4.5.1.ps1`
6. wklej `PROMPT_RESUME_P4_AFTER_4_5_1.md`

## Czego installer NIE dotyka

- PHP aplikacji;
- `docs/CURRENT_WORK.md`;
- checkpointów;
- bazy;
- Ollamy;
- modeli;
- `.env` i sekretów.
