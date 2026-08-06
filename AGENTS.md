# AGENTS.md — Mamona

## 1. Cel

Mamona jest produkcyjnym systemem CMS i automatyzacji redakcyjnej zbudowanym w PHP 8.1+ i SQLite. Publiczna strona, panel administratora, research, generowanie tekstów, QC, obrazy, publikacja, RSS, sitemap i wygenerowane strony tworzą jeden audytowalny przepływ.

Preferuj małe, sprawdzalne zmiany. Nie zmieniaj zachowania poza aktywnym zakresem.

## 2. Źródła prawdy

Kolejność:

1. Aktualne polecenie użytkownika.
2. Aktywny task oraz zaakceptowany checkpoint.
3. `docs/CURRENT_WORK.md`.
4. Aktualny kod, schemat bazy, konfiguracja i testy.
5. `docs/ARCHITECTURE.md`, `docs/IMAGE_PIPELINE_MAP.md`, `docs/DECISIONS.md`, `docs/CONTEXT_INDEX.md`.
6. README i dokumentacja operacyjna.

Stare taski, archiwa, wcześniejsze raporty i wynik indeksu są wskazówkami. Trwałe ustalenie wymaga potwierdzenia w aktualnym kodzie, schemacie, konfiguracji albo teście.

## 3. Protokół startu

1. Przeczytaj aktywny task i właściwą część `docs/CURRENT_WORK.md`.
2. Sprawdź `git status --short` i `git log --oneline -5`.
3. Ustal aktualny root repozytorium albo worktree.
4. Nie nadpisuj niezacommitowanych zmian użytkownika.
5. Najpierw użyj indeksu, `grep`, `glob` i wyszukiwania symboli.
6. Przed `read` potwierdź ścieżkę przez `glob`, `git ls-files` albo aktualny kod.
7. Używaj ścieżek względnych wobec bieżącego worktree.
8. Nie skanuj całego repo bez konkretnego celu.
9. Nie czytaj ponownie tego samego zakresu bez nazwania nierozstrzygniętego pytania.

## 4. Modele i ich przeznaczenie

### `ollama/qwen3.6:27b`

Główny model do:

- orkiestracji;
- architektury;
- root cause;
- implementacji;
- trudnych testów;
- review.

Warianty:

- `balanced` — orkiestracja, implementacja, testy i kontrolowane rozpoznanie;
- `deep` — architektura, trudne decyzje, krytyczne review.

Nie używaj `deep` do mechanicznego przepisywania dokumentów.

### `ollama/qwen3.6-no-think`

Alias tego samego fizycznego modelu `qwen3.6:27b`, ale z wyłączonym reasoningiem.

Używaj wyłącznie do:

- mechanicznego zapisu gotowych ustaleń;
- aktualizacji pojedynczych dokumentów;
- formatowania;
- markerów sukcesu;
- generowania końcowego checkpointu na podstawie gotowych danych;
- prostego handoffu.

Nie używaj do:

- root cause;
- projektowania architektury;
- podejmowania decyzji;
- implementacji;
- debugowania;
- review;
- uzupełniania braków.

### `ollama/qwen3.5:9b`

Model szybki do krótkich, niekrytycznych zadań. Krytyczny tool calling wymaga wcześniejszego potwierdzenia stabilności.

## 5. Role

- `mamona-orchestrator` — fazy, delegacja, checkpointy i walidacja wyników.
- `repo-scout` — potwierdzone rozpoznanie repozytorium, tylko odczyt.
- `mamona-architect` — root cause, kontrakty, migracja i test matrix.
- `mamona-coder` — implementacja zaakceptowanego zakresu.
- `mamona-tester` — testy, reprodukcja i diagnostyka.
- `mamona-reviewer` — niezależne review.
- `quick-maintainer` — mechaniczna aktualizacja dokumentów na `qwen3.6-no-think`.
- `checkpoint-writer` — końcowy checkpoint na `qwen3.6-no-think`.

Dla złożonego zadania:

```text
repo-scout → architect → checkpoint → coder → tester → reviewer
```

Każdy task wskazuje: agenta, model, wariant, zakres, uprawnienia, limit i warunek zakończenia.

## 6. Kontrakt subagentów

Pełny protokół:

```text
docs/AGENT_EXECUTION_PROTOCOL.md
```

Minimum:

- pierwszą czynnością subagenta jest tool call, nie opis planu;
- subtask eksploracyjny otwiera domyślnie maksymalnie 12 nowych plików;
- wynik `semantic_search` jest śladem, nie dowodem;
- każdy subagent kończy `SUBTASK_RESULT`;
- brak raportu oznacza niekompletny subtask;
- orkiestrator nie zastępuje brakującego raportu własną analizą;
- najpierw kontynuacja istniejącego kontekstu, potem najwyżej jeden celowany recovery subtask;
- po drugim niepowodzeniu status `BLOCKED`;
- przy `OLLAMA_NUM_PARALLEL=1` subagenci działają sekwencyjnie;
- subagent nie uruchamia dalszych subagentów.

## 7. Budżet odpowiedzi

Domyślnie:

```text
70–80% budżetu: narzędzia i analiza
20–30% budżetu: raport, zapis albo checkpoint
```

Przy zbliżaniu się do limitu:

1. zatrzymaj nowe odczyty;
2. nie zaczynaj dużego pliku;
3. zapisz stan;
4. oznacz braki;
5. zwróć wynik użytkowy.

Nie zużywaj całego outputu na reasoning.

## 8. Mechanical finalization

Po zakończeniu pracy merytorycznej utwórz osobną fazę:

```text
MECHANICAL_FINALIZATION
```

Zasady:

1. Użyj `quick-maintainer` na `ollama/qwen3.6-no-think`.
2. Jeden subtask aktualizuje jeden plik albo mały atomowy pakiet.
3. Przekaż gotową treść, raport albo jednoznaczny zakres.
4. Zabroń nowego researchu, grep, glob, task i szerokiej diagnostyki.
5. Po każdym zapisie wymagaj krótkiego markera sukcesu.
6. Checkpoint twórz dopiero po zapisaniu dokumentów.
7. Do checkpointu użyj `checkpoint-writer` na `ollama/qwen3.6-no-think`.
8. Nie łącz ciężkiej analizy, wielu edycji i checkpointu w jednej odpowiedzi.
9. Model no-think nie może podejmować nowych decyzji. Brak danych oznacza blocker i powrót do właściwego agenta reasoningowego.

## 9. Ochrona przed zapętleniem

- Maksymalnie dwie równoważne próby dla jednego problemu.
- Identyczna nieudana komenda może być ponowiona najwyżej dwa razy.
- Po drugim niepowodzeniu zgłoś blocker.
- Brakującego pliku nie odczytuj ponownie bez nowej, potwierdzonej ścieżki.
- Gdy kolejny krok powtarza poprzedni bez nowej hipotezy, zatrzymaj się.
- Nie obchodź `doom_loop: ask`.
- Przy 70% limitu kroków nie rozpoczynaj nowej dużej jednostki.
- Ucięcie odpowiedzi nie jest zgodą na pominięcie checkpointu.

## 10. Checkpointy

- Eksploracja nie przechodzi automatycznie do architektury.
- Architektura nie przechodzi automatycznie do implementacji.
- Implementacja nie uruchamia realnych providerów, publikacji ani mutacji bez osobnej zgody.
- Każdy wymagany checkpoint jest twardym stopem.
- Faza nie jest kompletna bez wymaganych raportów.

Checkpoint zawiera:

1. status;
2. agentów, modele i warianty;
3. potwierdzone fakty;
4. zmienione pliki;
5. testy;
6. luki;
7. ryzyka;
8. następną fazę;
9. dokładny tekst wymaganej akceptacji.

## 11. Reguły produktu

1. `editorial_status` jest źródłem prawdy widoczności.
2. Publiczny może być wyłącznie nieusunięty artykuł ze statusem `published`.
3. Zwykły zapis nie może publikować.
4. Publiczne zapisy plików muszą pozostać atomowe.
5. Research, draft, QC i obrazy zachowują audytowalne wersje.
6. Nie omijaj praw, licencji, creditu ani ochron SSRF.
7. Placeholder, fallback techniczny i grafika redakcyjna zastępcza nie mogą być finalnym assetem.
8. Artykuł bez wymaganych prawidłowych grafik nie może zostać ukończony ani opublikowany.
9. Nie uruchamiaj płatnych API, publikacji ani mutujących testów bez zgody.
10. Nie commituj i nie pushuj bez prośby użytkownika.

## 12. Kodowanie

- Zmieniane pliki tekstowe zapisuj jako UTF-8.
- Kod źródłowy zapisuj bez BOM, chyba że format wymaga inaczej.
- Zachowuj polskie znaki.
- Nie konwertuj przez Windows-1250 ani Windows-1252.
- Przed checkpointem sprawdź `�`, `Ã`, `Â`, `Ä`, `Å`, `â€`.

## 13. Zakończenie etapu

1. Przejrzyj `git diff`.
2. Uruchom najmniejszy wystarczający zestaw testów.
3. Sprawdź sekrety, logi, bazę i artefakty.
4. Zaktualizuj `docs/CURRENT_WORK.md`.
5. Zapisz tylko potwierdzoną wiedzę.
6. Sprawdź UTF-8.
7. Zwróć krótki raport.
