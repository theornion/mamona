# Mamona V4.1 — Hybrid Routing

## Zasada

Nie próbujemy matematycznie wymusić 50% tokenów ani 50% czasu na Ollamie.
Routing jest jakościowy, z miękkim celem 40–60% izolowalnych jednostek wykonawczych lokalnie.

## Frontier / Codex

- rozmowa z użytkownikiem;
- plan i state machine;
- architektura;
- kontrakty;
- niejednoznaczna diagnoza;
- cross-cutting changes;
- review fazy;
- przejęcie atomu po jednym nieudanym local attempt.

## Local / Ollama

### qwen3.5 9B no-think
- testy;
- lint;
- wykonanie dokładnych komend;
- checkpoint/handoff.

### qwen3.6 27B
- dokładny fix 1–2 plików;
- TEST_BUG z exact target;
- PRODUCTION_BUG z exact target;
- małe mechanical integration edits.

## Escalation

Local child kończy `BLOCKED` zamiast broad reasoning.
Po jednym nieudanym local attempt atom wraca do frontier coordinatora.
