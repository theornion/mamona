# Agent Execution Protocol — stability additions

## Subagent stability

1. Subagenci działają sekwencyjnie przy `OLLAMA_NUM_PARALLEL=1`.
2. `doom_loop` dla każdego subagenta ma być `deny`, nie `ask`.
3. Subagent nie może uruchamiać kolejnego subagenta.
4. Subagent nie powinien oczekiwać na pytanie do użytkownika; brak danych oznacza `BLOCKED`.
5. Duże `write`, `edit` i `apply_patch` są dzielone na małe atomowe operacje.
6. Nie generuj ogromnego JSON tool calla, jeżeli tę samą zmianę można wykonać kilkoma małymi edycjami.
7. Przy 70–75% budżetu odpowiedzi nie rozpoczynaj nowej dużej jednostki pracy.
8. Zarezerwuj co najmniej 25% odpowiedzi na `SUBTASK_RESULT`.
9. Techniczny abort nie oznacza rollbacku zmian już zapisanych do worktree.
10. Recovery zaczyna się od aktualnego diffu i brakującego zakresu, nigdy od pełnego powtórzenia.
11. Maksymalnie jedna celowana próba recovery; potem `BLOCKED`.

## Provider stability

Kilo/OpenCode dla lokalnego Ollamy ma pracować z:
- provider `timeout: false`;
- `chunkTimeout: 1800000` ms;
- `OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX=65536` w środowisku procesu VS Code/Kilo.

Po zmianie zmiennej środowiskowej wszystkie procesy VS Code muszą zostać zamknięte i uruchomione ponownie.
