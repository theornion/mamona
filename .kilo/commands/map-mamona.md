---
description: Jednorazowo zmapuj repozytorium i pipeline obrazów po zakończeniu indeksowania
agent: mamona-orchestrator
model: ollama/qwen3.6:27b
variant: deep
---

Wykonaj wyłącznie fazę `MAMONA-23-P0` z `docs/CURRENT_WORK.md`.

Warunki:

1. Upewnij się, że indeks Kilo ma status `Complete`. Jeżeli nie, zatrzymaj się i poproś użytkownika o dokończenie indeksowania.
2. Uruchom dwa niezależne zadania `repo-scout`:
   - A: wybór obrazu — query, providerzy, prawa, kandydaci, ranking, zwycięzca;
   - B: zapis metadanych — fallback, plik finalny, caption, alt, credit, source, renderer HTML.
3. Nie skanuj całego repozytorium ręcznie.
4. Zsyntetyzuj wyłącznie potwierdzone informacje.
5. Uzupełnij:
   - `docs/ARCHITECTURE.md`;
   - `docs/IMAGE_PIPELINE_MAP.md`;
   - sekcję fazy P0 w `docs/CURRENT_WORK.md`.
6. Nie implementuj kodu i nie uruchamiaj testów.
7. Po zapisaniu mapy zatrzymaj się.
