# Decyzje procesowe — TASK-23

## Status

```text
Zatwierdzone 2026-08-05 po analizie P0
```

| # | Decyzja | Kontekst | Powód | Data | Źródło |
|---|---|---|---|---|---|
| D1 | Semantyczny indeks przed ręcznym skanowaniem plików | Eksploracja repozytorium | Zasada z AGENTS.md — minimalizacja odczytów, szybsze lokalizowanie symboli | 2026-08-05 | AGENTS.md §4 Protokół startu zadania |
| D2 | Analiza sekwencyjna, nie równoległe subagenty na tym samym kodzie | Orkiestracja P0 | Stabilność lokalnego modelu; unikanie konfliktów odczytu/edycji; AGENTS.md §6 | 2026-08-05 | AGENTS.md §6 Orkiestracja Kilo |
| D3 | Prawa/licencje osobne od trafności redakcyjnej | Separacja modułów | Legalność assetu nie oznacza przydatności redakcyjnej; AGENTS.md reguła 12 | 2026-08-05 | `php/image-rights-service.php` vs ranking w `select_source_image_from_results()` |
| D4 | Fallback musi mieć własne neutralne metadane | R1 regression | Caption i alt fallbacku nie mogą dziedziczyć danych odrzuconego kandydata; placeholder musi być jawny | 2026-08-05 | `render_article_image_record()` linia 1456-1463, struktura `article-illustration--placeholder` |
| D5 | Finalny asset = jedność pliku i metadanych | Spójność danych | Caption, alt, creator, credit, source URL i rights manifest muszą należeć do tego samego finalnego assetu; brak hybrydowych rekordów | 2026-08-05 | `persist_article_image()` linia 1101, wszystkie pola zapisywane atomowo |
| D6 | Implementacja po specyfikacji i akceptacji | Kolejność faz | Nie implementować kodu przed zapisaniem root cause i zaakceptowaniem specyfikacji w dokumentach | 2026-08-05 | CURRENT_WORK.md Zasady wykonania, faza P1 checkpoint |
| D7 | Brak auto-publikacji z niesprawdzonymi obrazami | Ochrona produkcji | Do zakończenia zadania nie publikować automatycznie kolejnych materiałów z niesprawdzonymi obrazami | 2026-08-05 | CURRENT_WORK.md Główny cel |
| D8 | Fixture DonkeyHotey jako test regresji, nie jako filtr | R2 regression | Przypadek `"Big Orange Zombie Eating Brains"` ma być deterministycznym fixture'em testowym, a nie jednorazowym filtrem na nazwisko | 2026-08-05 | CURRENT_WORK.md Funkcjonalne wymagania §2 |
