# Mamona V4.2 — Parallel Execution

Codex (cloud/frontier) i lokalna Ollama korzystają z niezależnych zasobów.

## FRONTIER LANE
Codex:
- planowanie;
- ciężka diagnoza;
- architektura;
- review;
- integracje cross-cutting.

## LOCAL LANE
Jeden Qwen naraz:
- 9B executor: test/lint;
- 27B worker: exact targeted fix;
- 9B checkpoint: zapis stanu.

Lokalne modele są serializowane z powodu jednej GPU i runtime ustawionego na jeden model / jedno żądanie.

## Wzorzec
1. Coordinator identyfikuje dwa niezależne atomy.
2. Uruchamia równolegle frontier lane + jeden local lane.
3. Zbiera oba wyniki.
4. Barrier.
5. Uruchamia krok zależny.

## Konflikty
Nie uruchamiaj dwóch writerów w jednym working tree z nakładającym się write-setem.
Dla równoległych zmian kodu użyj Agent Manager z osobnymi git worktrees.
