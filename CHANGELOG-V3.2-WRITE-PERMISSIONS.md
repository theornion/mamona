# V3.2 — Write permissions + durable subagent results

Naprawia problem, w którym custom subagenci nie dostawali `write` i próbowali obchodzić brak narzędzia przez shell.

Zmiany:
- globalne `write: ask`;
- globalne `apply_patch: ask`;
- jawne `edit/write/apply_patch` w agentach;
- scoped write:
  - coder: task scope + result;
  - tester: tests + result;
  - architect: docs + result;
  - reviewer/scout: result only;
  - quick/checkpoint: docs + result;
  - orchestrator: docs + result, bez produkcji;
- obowiązkowy `.kilo/results/<SUBTASK-ID>.json`;
- result JSON ignorowany przez Git;
- zakaz shell-write;
- auto-compaction 65% pozostaje bez zmian.
