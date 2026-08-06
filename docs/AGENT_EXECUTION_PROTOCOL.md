# Agent Execution Protocol — Mamona

Ten dokument jest trwałym kontraktem wykonawczym dla agentów Kilo. Task określa zakres produktu; ten dokument określa sposób wykonania.

## 1. Definicja subtasku

```text
SUBTASK
- ID:
- Cel:
- Agent:
- Model:
- Wariant / poziom:
- Tryb: read-only / edit / test / review / mechanical / checkpoint
- Maksymalna liczba nowych plików:
- Dozwolone narzędzia:
- Zakazane operacje:
- Punkt startowy:
- Pytania obowiązkowe:
- Wymagane dowody:
- Warunek COMPLETE:
- Warunek BLOCKED:
- Format raportu:
```

## 2. Dobór modelu

### Reasoning

Użyj `ollama/qwen3.6:27b`:

- `balanced` — orkiestracja, implementacja, testy i rozpoznanie;
- `deep` — architektura, trudne decyzje i review.

### No-think

Użyj `ollama/qwen3.6-no-think` tylko do:

- mechanicznego zapisu;
- aktualizacji pojedynczych dokumentów;
- checkpointu na podstawie gotowych danych;
- krótkiego handoffu;
- markerów sukcesu.

No-think nie może:

- prowadzić nowego researchu;
- uzupełniać luk;
- zmieniać decyzji;
- projektować kontraktów;
- implementować;
- debugować;
- prowadzić review.

Gdy no-think napotka brak danych, zwraca blocker zamiast zgadywać.

## 3. Start subagenta

Subagent:

1. rozpoczyna od narzędzia;
2. ustala root repo/worktree;
3. zaczyna od indeksu albo symboli;
4. potwierdza ścieżkę przed odczytem;
5. nie przekracza limitu plików;
6. nie wykonuje niedozwolonych działań;
7. kończy raportem.

Sama deklaracja planu jest nieudanym startem.

## 4. Ścieżki

- `semantic_search` może zwrócić stary wynik.
- Przed `read` potwierdź ścieżkę przez `glob`, `git ls-files`, symbol albo root.
- Preferuj ścieżki względne.
- `File not found` nie dowodzi braku pliku w innym worktree.
- Druga próba wymaga nowej, potwierdzonej ścieżki.

## 5. Budżet odpowiedzi

```text
70–80%: tool calle i analiza
20–30%: wynik użytkowy
```

Przy zbliżaniu się do limitu:

1. przerwij nowe odczyty;
2. nie zaczynaj nowego skanu;
3. oznacz braki;
4. zwróć raport;
5. nie zużywaj reszty na reasoning.

## 6. Raport reasoningowego subagenta

```text
SUBTASK_RESULT
- Status: COMPLETE albo BLOCKED
- Zakres:
- Potwierdzone ustalenia:
- Pliki i symbole:
- Dowody:
- Brakujące odpowiedzi:
- Liczba otwartych plików:
- Nierozstrzygnięte pytania:
```

## 7. Walidacja przez rodzica

Rodzic sprawdza:

- raport;
- status;
- checklistę;
- dowody;
- limit plików;
- brak zakazanych operacji;
- rozdzielenie kodu od starej dokumentacji;
- nazwane braki.

Dopiero potem uruchamia następny subtask.

## 8. Recovery

### Kontynuacja

Jeżeli istniejąca sesja ma użyteczny kontekst:

```text
Nie wykonuj nowych odczytów.
Zwróć wyłącznie wymagany raport na podstawie już zebranych danych.
Braki oznacz jawnie.
```

### Celowany recovery

Jeżeli kontynuacja jest niedostępna:

- jeden nowy subtask;
- tylko brakujące pytania;
- bez pełnego powtórzenia;
- po kolejnym niepowodzeniu `BLOCKED`.

## 9. Równoległość

Przy `OLLAMA_NUM_PARALLEL=1`:

```text
subtask A → walidacja → subtask B → walidacja
```

Równoległość wymaga realnej pojemności backendu i niezależnych zadań.

## 10. Mechanical finalization

Wejście:

- zaakceptowany raport;
- dokładny plik;
- dokładny zakres;
- oczekiwany marker.

Wykonanie:

1. `quick-maintainer`;
2. model `ollama/qwen3.6-no-think`;
3. jeden plik albo atomowy pakiet;
4. bez researchu, grep, glob i task;
5. brak nowych decyzji;
6. marker sukcesu;
7. blocker przy braku danych.

Przykładowe markery:

```text
SPEC_SAVED
DECISIONS_SAVED
ARCHITECTURE_SAVED
IMAGE_MAP_SAVED
CONTEXT_INDEX_SAVED
CURRENT_WORK_SAVED
IMPLEMENTATION_LOG_SAVED
TEST_REPORT_SAVED
```

## 11. Checkpoint

Do checkpointu użyj `checkpoint-writer` na `ollama/qwen3.6-no-think`.

Wejście musi zawierać:

- gotowe dokumenty;
- format checkpointu;
- maksymalną długość;
- dokładny tekst akceptacji.

`checkpoint-writer`:

- nie wykonuje researchu;
- nie podejmuje decyzji;
- nie edytuje;
- syntetyzuje wyłącznie przekazane źródła;
- zwraca checkpoint i kończy.

## 12. Warunek zamknięcia fazy

Faza nie może zostać zamknięta, gdy:

- brakuje raportu;
- status jest `BLOCKED`;
- checklisty są niekompletne;
- dowody pochodzą tylko ze starej dokumentacji;
- nie zapisano stanu;
- finalizacja została ucięta.

Każdy checkpoint kończy się dokładnym tekstem wymaganej zgody użytkownika.
