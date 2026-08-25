# Mamona V4.3 — Strict Local-First Routing

## Zasada

Codex = scheduler/koordynator.
Ollama = wykonanie.

## Local routing

- Qwen 9B no-think: testy, lint, checkpoint.
- Qwen 27B: diagnosis, architecture, implementation, review.

## Hard task

`27B architect -> 27B worker -> 9B executor`

Nie:
`Codex sam robi trudny task`.

## Dlaczego

Analiza V3/P3 pokazała, że targeted Qwen coding było szybkie, natomiast największe straty pochodziły z szerokich tester/recovery loops. V4.3 ogranicza zakres childów zamiast przenosić większość pracy na Codex.
