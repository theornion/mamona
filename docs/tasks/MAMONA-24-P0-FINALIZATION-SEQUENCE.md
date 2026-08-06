# MAMONA-24 — P0 Mechanical Finalization Sequence

## Cel

Dokończ mechanicznie fazę P0 na podstawie już istniejących wyników i dokumentacji.

Raporty:

- P0-A2 — COMPLETE
- P0-B2 — COMPLETE
- P0-C2 — COMPLETE
- P0-D2 — COMPLETE

Plik:

```text
docs/research/MAMONA-24-P0-repository-map.md
```

został już zapisany.

## Zasady wykonania

1. Wykonuj zadania poniżej dokładnie w podanej kolejności.
2. Po poprawnym zakończeniu jednego zadania od razu przejdź do następnego.
3. Nie czekaj na dodatkową wiadomość użytkownika pomiędzy zadaniami.
4. Nie uruchamiaj nowych subagentów.
5. Nie wykonuj nowego researchu.
6. Nie przeszukuj ponownie repozytorium.
7. Nie przechodź do P1.
8. Nie zmieniaj plików spoza listy.
9. Korzystaj wyłącznie z istniejących dokumentów P0.
10. Pracuj mechanicznie, bez rozbudowanego reasoning.
11. Jeden krok aktualizuje wyłącznie wskazany plik.
12. Po każdym zapisie potwierdź wewnętrznie marker i od razu wykonaj następny krok.
13. Nie kończ odpowiedzi po markerach pośrednich.
14. Zatrzymaj się wyłącznie:
    - po końcowym checkpointcie P0;
    - albo przy realnym blockerze uniemożliwiającym zapis.
15. Przy blockerze nie próbuj szerokiej diagnostyki. Zwróć:
    - nazwę kroku;
    - plik;
    - jeden konkretny powód;
    - ostatni poprawnie zakończony krok.
16. Zachowaj UTF-8 i polskie znaki.
17. Nie commituj i nie pushuj.

---

## TASK 1 — Aktualizacja architektury

Źródło:

```text
docs/research/MAMONA-24-P0-repository-map.md
```

Zaktualizuj wyłącznie:

```text
docs/ARCHITECTURE.md
```

Dodaj potwierdzony aktualny przepływ generatora artykułów:

- typy tekstów i limity;
- call graph Gemini;
- retry i quota;
- research;
- draft;
- QC;
- repair;
- salvage;
- obrazy;
- fallbacki;
- statusy;
- renderer;
- publikacja;
- najważniejsze zależności.

Wyraźnie oddziel:

- stan aktualny;
- ograniczenia;
- planowane zmiany MAMONA-24.

Po poprawnym zapisie ustaw marker:

```text
ARCHITECTURE_SAVED
```

Następnie automatycznie przejdź do TASK 2.

---

## TASK 2 — Aktualizacja mapy grafik

Źródło:

```text
docs/research/MAMONA-24-P0-repository-map.md
```

Zaktualizuj wyłącznie:

```text
docs/IMAGE_PIPELINE_MAP.md
```

Zapisz potwierdzony obecny przepływ:

- planowania grafik;
- wyszukiwania;
- generowania;
- walidacji praw;
- oceny trafności;
- fallbacków;
- zapisu assetów;
- renderowania;
- publikacji.

Wyraźnie oznacz:

- placeholdery;
- fallbacki techniczne;
- grafiki redakcyjne zastępcze;
- brak wymaganych grafik;

jako problemy do usunięcia w MAMONA-24.

Po poprawnym zapisie ustaw marker:

```text
IMAGE_MAP_SAVED
```

Następnie automatycznie przejdź do TASK 3.

---

## TASK 3 — Aktualizacja indeksu kontekstu

Źródła:

```text
docs/research/MAMONA-24-P0-repository-map.md
docs/ARCHITECTURE.md
docs/IMAGE_PIPELINE_MAP.md
```

Zaktualizuj wyłącznie:

```text
docs/CONTEXT_INDEX.md
```

Dodaj krótkie odwołania do:

- aktywnego zadania MAMONA-24;
- raportu P0;
- potwierdzonych plików i symboli;
- dokumentów wymaganych przez P1;
- aktualnego statusu checkpointu;
- lokalizacji dokumentacji technicznej.

Po poprawnym zapisie ustaw marker:

```text
CONTEXT_INDEX_SAVED
```

Następnie automatycznie przejdź do TASK 4.

---

## TASK 4 — Aktualizacja bieżącego stanu pracy

Zaktualizuj wyłącznie:

```text
docs/CURRENT_WORK.md
```

Wymagania:

1. Zachowaj historię TASK-23.
2. Ustaw bieżący stan MAMONA-24 na:

```text
P0 zakończone — oczekiwanie na checkpoint i akceptację użytkownika przed P1
```

3. Dodaj:
   - P0-A2 — COMPLETE;
   - P0-B2 — COMPLETE;
   - P0-C2 — COMPLETE;
   - P0-D2 — COMPLETE;
   - dokumenty utworzone lub zaktualizowane w P0;
   - najważniejsze luki;
   - ryzyka;
   - zakaz rozpoczęcia P1 bez akceptacji.

Po poprawnym zapisie ustaw marker:

```text
CURRENT_WORK_SAVED
```

Następnie automatycznie przejdź do TASK 5.

---

## TASK 5 — Walidacja mechanicznej finalizacji

Nie wykonuj nowego researchu.

Sprawdź wyłącznie:

```text
git diff -- docs/research/MAMONA-24-P0-repository-map.md docs/ARCHITECTURE.md docs/IMAGE_PIPELINE_MAP.md docs/CONTEXT_INDEX.md docs/CURRENT_WORK.md
```

Zweryfikuj:

- czy zmieniono tylko wymagane pliki;
- czy nie utracono historii TASK-23;
- czy P1 nie zostało oznaczone jako rozpoczęte;
- czy dokumenty nie zawierają placeholdera `<TASK-ID>`;
- czy polskie znaki są poprawne;
- czy nie występują typowe oznaki mojibake:
  - `�`;
  - `Ã`;
  - `Â`;
  - `Ä`;
  - `Å`;
  - `â€`.

Jeżeli walidacja przejdzie, ustaw marker:

```text
P0_DOCUMENTATION_VALIDATED
```

Następnie automatycznie przejdź do TASK 6.

---

## TASK 6 — Końcowy checkpoint P0

Nie wykonuj żadnych nowych narzędzi, odczytów ani subagentów.

Na podstawie zapisanej dokumentacji przygotuj krótki checkpoint, maksymalnie 1000 słów.

Format:

```text
CHECKPOINT_P0

- Status:
- Agenci, modele i poziomy:
- Typy tekstów i obecne limity:
- Potwierdzony call graph Gemini:
- Retry, quota, salvage i warunki zakończenia:
- Narracja i QC:
- Obrazy, fallbacki i renderer:
- Schemat danych i bezpieczny reset:
- Utworzone lub zmienione dokumenty:
- Luki:
- Ryzyka:
- Zakres P1:
- Wymagana akceptacja:
```

Nie rozpoczynaj P1.

Zakończ dokładnie:

```text
AKCEPTUJĘ P0 — URUCHOM P1
```

Po zwróceniu checkpointu zatrzymaj pracę.
