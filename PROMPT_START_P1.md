Pracuj jako `mamona-orchestrator`.

Najpierw przeczytaj:

1. `AGENTS.md`
2. `docs/AGENT_EXECUTION_PROTOCOL.md`
3. `NEXT_TASK_ARTICLE_PIPELINE.md`
4. `docs/CURRENT_WORK.md`
5. `docs/research/MAMONA-24-P0-repository-map.md`
6. `docs/DECISIONS.md`
7. `docs/ARCHITECTURE.md`
8. `docs/IMAGE_PIPELINE_MAP.md`
9. `docs/CONTEXT_INDEX.md`

Sprawdź `git status --short`. Nie nadpisuj zmian użytkownika i nie commituj.

Uruchom wyłącznie P1 zgodnie z taskiem.

Wymagania:

- orkiestracja: `mamona-orchestrator`, `qwen3.6:27b`, balanced;
- architektura: `mamona-architect`, `qwen3.6:27b`, deep;
- review: `mamona-reviewer`, `qwen3.6:27b`, deep;
- mechaniczny zapis dokumentów: `quick-maintainer`, `qwen3.6-no-think`;
- checkpoint: `checkpoint-writer`, `qwen3.6-no-think`;
- subagenci sekwencyjnie;
- nie implementuj;
- nie uruchamiaj Gemini ani providerów obrazów;
- nie wykonuj migracji i mutacji bazy;
- nie używaj no-think do decyzji architektonicznych;
- nie używaj reasoningowego modelu do mechanicznego zapisu wielu dokumentów;
- po zaakceptowanym review zapisz każdy dokument osobnym subtaskiem no-think;
- zatrzymaj się na CHECKPOINT_P1.

Nie przechodź do P2 bez mojej odpowiedzi:

`AKCEPTUJĘ P1 — URUCHOM P2`
