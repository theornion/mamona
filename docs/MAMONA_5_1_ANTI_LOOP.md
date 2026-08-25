# Mamona 5.1 — Anti-Loop Release Notes

## Najważniejsze zmiany

1. Twardy `steps` limit na każdym subagencie.
2. `permission.task: deny` na wszystkich subagentach — brak rekurencyjnej delegacji.
3. `doom_loop: deny` — Kilo nie może kontynuować wykrytej pętli.
4. Maksymalnie 2 próby na ACTIVE_ATOM.
5. Druga próba wymaga nowej metody/evidence; brak Attempt 3.
6. `NO_FINDING`, `INVALID` i `DUPLICATE` są prawidłowymi wynikami końcowymi.
7. 30B został odcięty od audytów. `mamona-heavy-coder` jest wyłącznie do ciężkiej implementacji.
8. Nowy `mamona-heavy-auditor` działa na 14B, read-only, bounded.
9. `mamona-executor` ma bezpieczne uprawnienia do `php ...`, lint i odczytów git, aby targeted retesty nie blokowały się na permissions.
10. Orkiestrator ma allowlistę subagentów i nie może delegować do przypadkowych built-in agents.
11. Auto-compaction + pruning są włączone, żeby długie sesje nie rosły bez końca.

## Model routing

- coordinator: Terra Low / Sol low po stronie warstwy koordynującej; model nie jest twardo pinowany w pliku agenta,
- `mamona-heavy-coder`: `ollama/qwen3-coder:30b`, 128K,
- 14B roles: `ollama/qwen3:14b`, 64K,
- 9B roles: `ollama/qwen3.5:9b`, 64K.

## Równoległość

- 30B nigdy równolegle z 14B/9B.
- 14B + 9B tylko przy niezależnym zakresie i bez write overlap.

## Brak zmian serwera

5.1 jest paczką orchestration/agent-policy. Nie wymaga zatrzymywania Ollamy ani ponownego pobierania modeli, jeśli zestaw V4.5 jest już obecny.
