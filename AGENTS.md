# AGENTS.md — Mamona

## 1. Cel projektu

Mamona jest produkcyjnym systemem CMS i automatyzacji redakcyjnej zbudowanym w PHP 8.1+ i SQLite. Publiczna strona, panel administratora, źródła, research, generowanie tekstów, kontrola jakości, obrazy, publikacja, RSS, sitemap i wygenerowane strony są jednym audytowalnym przepływem.

Preferuj małe, sprawdzalne zmiany. Nie zmieniaj zachowania poza bieżącym zakresem.

## 2. Stack

- PHP 8.1+
- SQLite przez PDO
- Apache/XAMPP jako główne środowisko Windows
- zwykły HTML, CSS i JavaScript
- PHP CLI dla workerów, utrzymania i testów
- opcjonalnie Python 3 + Pillow do obrazów

Projekt nie ładuje automatycznie `.env`. Sekrety pochodzą ze środowiska procesu, Apache, hostingu, crona lub Harmonogramu zadań Windows.

## 3. Kolejność źródeł prawdy

1. Aktualne polecenie użytkownika.
2. `docs/CURRENT_WORK.md`.
3. Aktualny kod i konfiguracja.
4. `docs/ARCHITECTURE.md` oraz `docs/PROJECT_CONTEXT.md`.
5. README i dokumentacja operacyjna.

Stare taski, archiwa i kopie zapasowe nie są źródłem prawdy.

## 4. Protokół startu zadania

Na początku zadania:

1. Przeczytaj raz `docs/CURRENT_WORK.md`.
2. Przeczytaj tylko potrzebne sekcje `docs/ARCHITECTURE.md` i `docs/PROJECT_CONTEXT.md`.
3. Sprawdź:
   - `git status --short`;
   - `git log --oneline -5`;
   - istniejące niezacommitowane zmiany.
4. Najpierw użyj `semantic_search`, `grep` i wyszukiwania symboli.
5. Otwórz tylko pliki wskazane przez wyniki wyszukiwania.
6. Nie wykonuj rekursywnego odczytu całego repozytorium.
7. Nie czytaj ponownie pliku bez konkretnego, nierozstrzygniętego pytania.

## 5. Ochrona przed zapętlaniem

- Nie próbuj ponownie otwierać pliku, który nie istnieje. Zapisz ten fakt i kontynuuj.
- Maksymalnie dwa równoważne wyszukiwania dla jednego zagadnienia.
- Po znalezieniu potwierdzonego przepływu wykonania zakończ ogólne rozpoznanie.
- Nie wracaj do początku repozytorium po znalezieniu właściwych symboli.
- Prowadź krótką listę już sprawdzonych plików.
- Jeżeli następny krok powtarza poprzedni bez nowej hipotezy, zatrzymaj się.
- Nie obchodź pytania `doom_loop`; podaj użytkownikowi powód powtórzenia.
- Gdy brakuje jednej decyzji produktowej, zadaj jedno konkretne pytanie zamiast rozszerzać skan.

## 6. Orkiestracja Kilo

Domyślny agent `mamona-orchestrator` klasyfikuje zadanie i deleguje je:

- `repo-scout` — szybkie, tylko do odczytu wyszukiwanie i mapa symboli;
- `mamona-architect` — root cause, specyfikacja, zależności i plan;
- `mamona-coder` — implementacja zaakceptowanego zakresu;
- `mamona-tester` — testy, odtwarzanie regresji i debug;
- `mamona-reviewer` — kontrola diffu, bezpieczeństwa i zgodności;
- `quick-maintainer` — wyłącznie proste, lokalne i mechaniczne zmiany.

Orkiestrator nie powinien bezpośrednio implementować kodu źródłowego.

Przy złożonym zadaniu kolejność jest obowiązkowa:

```text
repo-scout → architect → akceptacja/spec → coder → tester → reviewer
```

Można równolegle uruchomić kilka subagentów tylko wtedy, gdy ich zadania są niezależne i nie edytują tych samych plików.

## 7. Nienaruszalne reguły produktu

1. `editorial_status` jest źródłem prawdy dla widoczności artykułu.
2. Publiczny może być wyłącznie nieusunięty artykuł o statusie `published`.
3. `is_published` jest tylko kompatybilnym lustrem.
4. Podgląd szkicu musi pozostać prywatny, uwierzytelniony, `noindex` i bez cache.
5. Zapisy wygenerowanych plików publicznych muszą pozostać atomowe.
6. Zwykły zapis nie może publikować.
7. Research, draft, quality check i thumbnail muszą zachować audytowalne wersje.
8. Nie omijaj zabezpieczeń publikacji i harmonogramu.
9. Nie dodawaj do Git sekretów, danych produkcyjnych, sesji, logów ani bazy.
10. Nie loguj pełnych sekretów.
11. Automatyzacja obrazów musi zachować walidację praw, credit i źródło dla każdego assetu.
12. Legalność obrazu nie oznacza trafności redakcyjnej.
13. Fetchery muszą zachować ochronę SSRF, redirectów, schematów, portów, rozmiarów i timeoutów.
14. Testy mutujące dane wymagają udokumentowanych `CMS_ALLOW_*`, izolacji i sprzątania.
15. Nie uruchamiaj realnych płatnych API ani publikacji bez zgody.

## 8. Reguły edycji

- Zmień najmniejszy zestaw plików, który kompletnie rozwiązuje zadanie.
- Nie refaktoryzuj rzeczy niezwiązanych.
- Rozszerz istniejący service/repository zamiast duplikować logikę w controllerze lub view.
- Nie zmieniaj semantyki bazy, statusów, URL-i ani kontraktów bez specyfikacji kompatybilności.
- Kod, identyfikatory i komentarze techniczne zapisuj po angielsku.
- Komunikację z użytkownikiem prowadź po polsku.
- Nie dodawaj zależności bez wyraźnej wartości i zgody.
- Zachowaj zgodność Windows/XAMPP.

## 9. Walidacja

Najpierw przeczytaj początek testu i użyj wyłącznie flag, które sam dokumentuje.

PHP lint na Windows:

```powershell
Get-ChildItem -Recurse -Filter *.php |
  ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

Uruchamiaj najpierw test bezpośrednio związany ze zmianą. Pełny E2E uruchamiaj tylko przy zmianach przepływu redakcyjnego albo przed istotnym wydaniem.

Nigdy nie publikuj realnych treści podczas walidacji.

## 10. Zakończenie etapu

Przed zakończeniem:

1. Przejrzyj `git diff`.
2. Uruchom najmniejszy wystarczający zestaw testów.
3. Sprawdź, czy nie dodano sekretów, bazy, logów, sesji ani artefaktów.
4. Zaktualizuj `docs/CURRENT_WORK.md`.
5. Aktualizuj `docs/ARCHITECTURE.md` tylko o potwierdzoną, trwałą wiedzę.
6. Podaj zmienione pliki, wyniki testów, założenia i ryzyka.
7. Nie commituj i nie pushuj bez prośby użytkownika.
