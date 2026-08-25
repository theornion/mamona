# Podmiana agentów przed P3 — V3.1 Auto-Compaction

Skopiuj całą zawartość tej paczki do:

C:\Projekty\mamona

z nadpisaniem.

Podmieniane:
AGENTS.md
.kilo\kilo.jsonc
.kilo\agents\mamona-orchestrator.md
.kilo\agents\mamona-coder.md
.kilo\agents\mamona-tester.md
.kilo\agents\mamona-architect.md
.kilo\agents\mamona-reviewer.md
.kilo\agents\repo-scout.md
.kilo\agents\quick-maintainer.md
.kilo\agents\checkpoint-writer.md
docs\AGENT_EXECUTION_PROTOCOL.md

Dodatkowe:
docs\AGENT_PERFORMANCE_V3.md
docs\tasks\PROMPT_START_P3_OPTIMIZED.md
CHANGELOG-V3.1-AUTOCOMPACT.md

AUTO-COMPACTION:
- threshold_percent: 65
- prune: true
- tail_turns: 2
- preserve_recent_tokens: 8000
- reserved: 20000
- compaction model: ollama/qwen3.5-no-think

Przy 65% NIE przechodzimy automatycznie do nowego Orchestratora.
Kilo kondensuje starszy kontekst i ten sam Orchestrator jedzie dalej.

Handoff do pliku:
- na checkpointach między dużymi fazami;
- gdy compaction zawiedzie;
- gdy summary okaże się niewystarczające;
- gdy kontekst nadal pozostanie blisko limitu.

Po podmianie:
1. zamknij aktywny chat Kilo;
2. Ctrl+Shift+P → Developer: Reload Window;
3. rozpocznij nową sesję Mamona Orchestrator;
4. użyj `docs\tasks\PROMPT_START_P3_OPTIMIZED.md`.

Ollamy nie trzeba restartować tylko z powodu tej paczki.

Oczekiwane:
OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX=65536

Provider:
timeout=false
chunkTimeout=1800000

Chat:
http://127.0.0.1:11436/v1

Indexing:
http://127.0.0.1:11436


V3.1.1 FIX:
- checkpoint-writer może teraz tworzyć/edytować pliki Markdown w docs/ przez natywne edit/write Kilo.
- checkpoint-writer nadal nie ma bash i nie może obchodzić permissions przez terminal.


V3.2 WRITE ENABLED:
- globalnie włączone narzędzia `write` i `apply_patch` jako `ask`;
- każdy subagent ma jawny, ograniczony scope zapisu;
- tester: `tests/**`;
- architect/checkpoint/quick: `docs/**`;
- reviewer/scout: tylko `.kilo/results/**`;
- coder: file tools wg tasku;
- Orchestrator: docs/results, bez kodu produkcyjnego;
- każdy reasoning subagent zapisuje `.kilo/results/<SUBTASK-ID>.json`;
- `.kilo/results/*.json` są ignorowane przez Git.


V3.4 SUBAGENT EDIT INHERITANCE FIX:
- mamona-orchestrator: `edit: ask` zamiast `edit: deny`;
- jest to workaround runtime Task permission inheritance;
- Orchestrator nadal NIE edytuje plików;
- writing children zachowują `edit: allow`;
- przed P3 wymagany krótki P3-PREFLIGHT-WRITE.
