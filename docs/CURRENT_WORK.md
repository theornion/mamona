# Current Work

## Current goal

Do uzupełnienia przed pierwszą sesją Roo Code.

## Expected result

- Zadanie jest wykonane zgodnie z istniejącą architekturą projektu.
- Nie są zmieniane niepowiązane elementy.
- Odpowiednie testy przechodzą poprawnie.
- Agent podaje listę zmienionych plików.

## Current state

- Repozytorium Mamona jest dostępne lokalnie.
- Dodano konfigurację Roo Code i Vast.ai.
- Dodano `AGENTS.md` oraz dokumentację kontekstu.
- Nie rozpoczęto jeszcze implementacji nowego zadania.

## Completed

- [x] Przygotowano pliki kontekstu dla agentów.
- [x] Dodano skrypty konfiguracji Vast.ai.
- [x] Zapisano zmiany w GitHubie.
- [ ] Ustalono pierwsze zadanie programistyczne.
- [ ] Uruchomiono model na Vast.ai.
- [ ] Podłączono Roo Code do zdalnej Ollamy.

## Next steps

1. Ustalić pierwsze konkretne zadanie.
2. Uzupełnić sekcję `Current goal`.
3. Wynająć RTX 3090 24 GB.
4. Uruchomić `scripts/setup-vast.sh`.
5. Utworzyć tunel przez `scripts/connect-vast.ps1`.
6. Skonfigurować profile modeli w Roo Code.
7. Zlecić agentowi wykonanie zadania.

## Relevant files

- `AGENTS.md`
- `.roomodes`
- `docs/PROJECT_CONTEXT.md`
- `docs/CURRENT_WORK.md`
- `docs/END_SESSION.md`
- `scripts/setup-vast.sh`
- `scripts/connect-vast.ps1`

## Blockers

Pierwsze zadanie programistyczne nie zostało jeszcze określone.

## Validation status

Nie dotyczy — nie rozpoczęto zmian w kodzie aplikacji.

## Session handoff

Następny agent powinien:

1. Przeczytać `AGENTS.md`.
2. Przeczytać `docs/PROJECT_CONTEXT.md`.
3. Przeczytać ten plik.
4. Nie analizować całego repozytorium, dopóki nie zostanie określone pierwsze zadanie.
5. Nie modyfikować kodu bez konkretnego celu.