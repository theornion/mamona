# Codex w Kilo — Mamona V4.1 Hybrid

## Cel

Kilo jest interfejsem i routerem agentów.

Primary:
`mamona-coordinator`

Wybierz dla niego model z providera **OpenAI ChatGPT** po zalogowaniu OAuth.
Najlepiej użyć najmocniejszego dostępnego w Twoim katalogu Codex modelu.

To NIE jest ta sama sesja rozmowy co ChatGPT w przeglądarce/aplikacji.
Kilo korzysta z modeli Codex przez Twoją subskrypcję ChatGPT i dostaje kontekst z repo, AGENTS.md oraz dokumentów Mamony.

## Routing V4.1

Frontier / Codex:
- coordinator;
- diagnoser;
- reviewer;
- duże/cross-cutting implementacje robione bezpośrednio przez coordinatora.

Local / Ollama:
- executor -> qwen3.5 9B no-think;
- worker -> qwen3.6 27B;
- checkpoint-writer -> qwen3.5 9B no-think.

Daje to zwykle około połowy izolowalnej pracy wykonawczej lokalnie bez oddawania lokalnym modelom architektury i trudnej diagnozy.

## Po uruchomieniu

1. Upewnij się, że oba providery są connected: OpenAI ChatGPT + Ollama.
2. Wybierz agent `mamona-coordinator`.
3. W model pickerze wybierz model z OpenAI ChatGPT/Codex.
4. Rozpocznij NOWĄ sesję.
5. Wklej `docs/research/MAMONA-24-NEXT-PROMPT-P3-RESUME.md`.

Subagenci lokalni mają własne `model:` override, więc Task automatycznie przełączy ich na Ollama.
