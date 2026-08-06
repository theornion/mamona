---
description: Uruchom tylko aktywną fazę z docs/CURRENT_WORK.md
agent: mamona-orchestrator
model: ollama/qwen3.6:27b
variant: deep
---

Przeczytaj `docs/CURRENT_WORK.md` i wykonaj wyłącznie fazę oznaczoną jako `ACTIVE`.

- Nie rozpoczynaj następnej fazy automatycznie.
- Dobierz subagenta i wariant modelu według tabeli w CURRENT_WORK.
- Przekaż subagentowi minimalny kontekst: cel, kryteria, potwierdzone pliki i ograniczenia.
- Po zakończeniu zaktualizuj status, wyniki i kolejny checkpoint.
- Jeżeli faza wymaga akceptacji, zatrzymaj się.
