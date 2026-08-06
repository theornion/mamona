# Instalacja plików przed P1

## Ścieżki do podmiany

Skopiuj całą zawartość paczki do:

```text
C:\Projekty\mamona
```

z nadpisaniem plików.

Najważniejsze pliki:

```text
AGENTS.md
.kilo\kilo.jsonc
.kilo\agents\mamona-orchestrator.md
.kilo\agents\repo-scout.md
.kilo\agents\mamona-architect.md
.kilo\agents\mamona-coder.md
.kilo\agents\mamona-tester.md
.kilo\agents\mamona-reviewer.md
.kilo\agents\quick-maintainer.md
.kilo\agents\checkpoint-writer.md
docs\AGENT_EXECUTION_PROTOCOL.md
NEXT_TASK_ARTICLE_PIPELINE.md
PROMPT_START_P1.md
```

## Limit outputu Kilo

Uruchom w PowerShellu:

```powershell
setx KILO_EXPERIMENTAL_OUTPUT_TOKEN_MAX 65536
```

Następnie zamknij wszystkie okna VS Code i uruchom VS Code ponownie.

## Ollama

Nie trzeba pobierać nowego modelu.

Alias:

```text
ollama/qwen3.6-no-think
```

wskazuje przez `id` na istniejący:

```text
qwen3.6:27b
```

Ollamy nie trzeba restartować, jeśli działa już z:

```text
OLLAMA_CONTEXT_LENGTH=131072
```

## Weryfikacja

Po otwarciu projektu:

1. sprawdź, czy selektor modeli pokazuje:
   - Qwen 3.6 27B — Mamona Main 128K / 64K output;
   - Qwen 3.6 27B — Mamona No-Think 128K;
   - Qwen 3.5 9B — Mamona Fast 128K;
2. sprawdź listę agentów:
   - Mamona Orchestrator;
   - Quick Maintainer;
   - Checkpoint Writer;
3. rozpocznij nową sesję `Mamona Orchestrator`;
4. wklej zawartość `PROMPT_START_P1.md`.
