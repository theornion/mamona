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


## Decyzje produktowe — MAMONA-24

```text
Zatwierdzone przez użytkownika 2026-08-06; wymagają potwierdzenia w kodzie podczas P0/P1.
```

| # | Decyzja | Kontekst | Powód | Data | Źródło |
|---|---|---|---|---|---|
| D9 | Brak placeholderów i fallbacków w finalnym artykule | Kontrakt grafik V2 | Każdy artykuł ma mieć rzeczywiste, trafne grafiki; asset zastępczy nie jest akceptowalnym wynikiem | 2026-08-06 | Decyzja użytkownika; MAMONA-24 §3 |
| D10 | D4 jest historyczne i zastąpione przez D9 dla finalnego renderu | Zgodność TASK-23 → MAMONA-24 | Fallback może pozostać wyłącznie wewnętrznym sygnałem błędu, ale nie może być renderowany ani publikowany | 2026-08-06 | MAMONA-24 §3 |
| D11 | Wadliwe istniejące artykuły zostaną wyzerowane bez Gemini po naprawie generatora | Remediacja danych | Użytkownik chce ponownie przetestować pełną generację treści i grafik na tych samych seedach | 2026-08-06 | MAMONA-24 §8 i P5 |
| D12 | Reset zachowuje seed i historię, czyści artefakty pochodne i nie uruchamia regeneracji | Bezpieczeństwo danych | Pozwala na audyt i ręczny test bez ponoszenia kosztu API podczas samej naprawy | 2026-08-06 | MAMONA-24 §8 i P5 |
