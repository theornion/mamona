# Mamona Kilo Agent Pack V5.1.2 — V4.5 Base + Anti-Loop

Ta paczka jest **przebudowana na bazie architektury V4/V4.5**, a nie na bazie błędnego 5.1.1.

## Najważniejsza zmiana

Primary to znowu:

```text
mamona-coordinator
```

Nie ma aktywnego `mamona-orchestrator` ani `mamona-heavy-auditor`.

Płaska pętla:

```text
Coordinator -> Executor -> (FAIL) Diagnoser -> Worker -> Executor -> phase gate
```

Coordinator może sam wykonać mały jednoznaczny fix. Reviewer i checkpoint są używane na granicach faz. 30B jest wyłącznie ciężkim coderem.

## Modele V4.5

Paczka używa aliasów tworzonych przez server setup V4.5:

```text
mamona-coder30-128k -> qwen3-coder:30b -> 128K
mamona-qwen14-64k   -> qwen3:14b       -> 64K
mamona-qwen9-64k    -> qwen3.5:9b     -> 64K
```

Jeżeli te aliasy już istnieją na serwerze, **nie restartuj Ollamy i nie pobieraj nic ponownie**.

## Aktywni agenci

```text
mamona-coordinator   14B  primary
mamona-executor       9B  subagent
mamona-diagnoser     14B  subagent
mamona-worker        14B  subagent
mamona-reviewer      14B  subagent
mamona-architect     14B  subagent
mamona-heavy-coder   30B  subagent, heavy exclusive
checkpoint-writer     9B  subagent
```

Każdy subagent ma `task: deny`.

## Instalacja

Rozpakuj paczkę i uruchom z PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-5.1.2.ps1
```

Domyślny repo root:

```text
C:\Projekty\mamona
```

Możesz podać inny:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-5.1.2.ps1 -RepoRoot "D:\repo\mamona"
```

Installer:

- robi backup konfiguracji i starych agentów do `.mamona-backups\5.1.2-<timestamp>`;
- podmienia komplet nowych agentów i `.kilo\kilo.jsonc`;
- usuwa z aktywnego registry stare/conflicting agent files po wykonaniu backupu;
- nie dotyka kodu PHP, bazy, `docs/CURRENT_WORK.md`, `.env`, modeli ani procesu Ollamy.

## Po instalacji

1. W VS Code wykonaj **Developer: Reload Window**.
2. Otwórz nową sesję Kilo.
3. W selektorze wybierz bezpośrednio **`mamona-coordinator`**.
4. Uruchom:

```powershell
powershell -ExecutionPolicy Bypass -File .\verify-mamona-5.1.2.ps1
```

5. Wklej `PROMPT_RESUME_P4_AFTER_5_1_2.md`.

## Anti-loop

- max 2 faktyczne próby per atom;
- brak Attempt 3;
- `NO_FINDING` kończy audit;
- brak automatycznej eskalacji do 30B;
- 30B tylko heavy write i zawsze solo;
- dwa kroki bez nowego evidence kończą eksplorację;
- nie ma obowiązkowego Result JSON do pliku;
- prelaunch permission failure nie zużywa attemptu;
- child nie może odpalać childa.
