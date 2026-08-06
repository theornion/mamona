# MAMONA-24 — Article Generation & Visual Narrative Pipeline — P1 onward

> Aktywna faza po akceptacji P0: **P1**.  
> P0 zostało wykonane i udokumentowane.  
> Nie powtarzaj P0 bez konkretnego, zaakceptowanego blockera.

## 0. Warunek startu

Nie rozpoczynaj P1, dopóki użytkownik nie poda:

```text
AKCEPTUJĘ P0 — URUCHOM P1
```

Przed P1 przeczytaj:

1. `AGENTS.md`;
2. `docs/AGENT_EXECUTION_PROTOCOL.md`;
3. `docs/CURRENT_WORK.md`;
4. `docs/research/MAMONA-24-P0-repository-map.md`;
5. `docs/ARCHITECTURE.md`;
6. `docs/IMAGE_PIPELINE_MAP.md`;
7. `docs/DECISIONS.md`;
8. `docs/CONTEXT_INDEX.md`.

Sprawdź `git status --short`. Nie nadpisuj zmian użytkownika.

## 1. Modele i podział odpowiedzialności

### Reasoning

- orkiestrator: `mamona-orchestrator`, `qwen3.6:27b`, `balanced`;
- architekt: `mamona-architect`, `qwen3.6:27b`, `deep`;
- reviewer: `mamona-reviewer`, `qwen3.6:27b`, `deep`;
- coder: `mamona-coder`, `qwen3.6:27b`, `balanced`;
- tester: `mamona-tester`, `qwen3.6:27b`, `balanced`.

### No-think

- zapis dokumentów: `quick-maintainer`, `qwen3.6-no-think`;
- checkpoint: `checkpoint-writer`, `qwen3.6-no-think`.

No-think jest zabroniony do:

- root cause;
- projektowania;
- rozstrzygania review;
- implementacji;
- testów;
- debugowania;
- uzupełniania braków.

Jeżeli no-think napotka brak danych, ma zwrócić blocker. Nie może zgadywać.

## 2. Nienaruszalne wymagania produktu

### Tekst

- zachowaj minima;
- zwiększ każde maksimum o 2000 znaków;
- jedna kanoniczna funkcja liczenia znaków;
- ten sam wynik w generatorze, QC, logach, diagnostyce i testach.

### Gemini

- maksymalnie 20 wywołań na przebieg artykułu;
- jeden centralny `GeminiBudget`;
- każde retry zużywa budżet;
- od pozycji 16 tryb zbieżności;
- po 20 koniec;
- brak obniżania QC;
- niezgodny materiał trafia do `manual_review`.

### Tryb zbieżności

Od pozycji 16:

- zamroź zaakceptowane artefakty;
- bez pełnego rewrite’u;
- bez ponownego researchu bez twardego blockera;
- tylko najmniejsza naprawa konkretnego blockera;
- dwie iteracje bez postępu kończą automatykę.

### Narracja

Po researchu i przed draftem powstaje `NarrativePlan` z:

- `reader_promise`;
- `main_thesis`;
- `narrative_archetype`;
- `archetype_rationale`;
- `target_chars`;
- `sections`;
- `transitions`;
- `rhythm`;
- `visual_slots`;
- `ending`;
- opcjonalnym `supplemental_topics`.

Nie narzucaj jednej matrycy każdemu artykułowi.

### Grafiki

- każdy ukończony artykuł ma prawidłową grafikę;
- maksymalnie 5 z hero;
- hero odpowiada A;
- inline odpowiada sekcji;
- prawa i trafność są osobnymi bramkami;
- placeholder i fallback nie są finalnym assetem;
- brak grafik blokuje ukończenie i publikację;
- negatywny fixture: polityk-zombie jedzący mózg nie przechodzi do artykułu o neuroplastyczności;
- kanoniczna funkcja slotów uwzględnia naturalne sekcje;
- około 1250 znaków może wymagać jednej grafiki;
- liczba slotów zostaje zamrożona po akceptacji planu;
- maksimum 5 nie może zostać przekroczone.

### Moduły B/C

Jeżeli A jest zaakceptowane, ale brakuje grafik:

- A pozostaje frozen;
- można dodać B, potem C;
- B/C pogłębiają temat;
- nie są fillerem;
- nie przekraczają maksimum;
- po B/C brak grafiki oznacza `manual_review`.

### Reset istniejących artykułów

Po naprawie generatora:

- audyt deterministyczny;
- bez Gemini i providerów obrazów;
- `--dry-run` i `--apply`;
- manifest, backup, checksum, idempotencja;
- reset tylko wadliwych;
- zachowaj ID, seed, brief, typ, kategorię, język, ustawienia i historię;
- wyczyść artefakty pochodne;
- zdejmij wadliwy publiczny rekord;
- nie regeneruj automatycznie.

---

# P1 — Architektura, root cause i specyfikacja

## P1-A — Projekt architektury

Agent:

```text
mamona-architect
model: ollama/qwen3.6:27b
variant: deep
```

Tryb:

- read-only;
- bez implementacji;
- bez Gemini;
- bez providerów obrazów;
- bez migracji;
- bez mutacji bazy.

Zaprojektuj:

1. `NarrativePlan`;
2. `GenerationState`;
3. `GeminiBudget`;
4. `QcReport`;
5. `VisualSlot`;
6. `SupplementalTopic`;
7. kanoniczne liczenie znaków;
8. kanoniczne liczenie slotów;
9. maszynę stanów;
10. zamrażanie;
11. convergence mode;
12. publication gate;
13. brak finalnych fallbacków;
14. migrację;
15. zgodność wsteczną;
16. audyt/reset;
17. backup i rollback;
18. diagnostykę;
19. test matrix;
20. listę plików i symboli.

Wynik musi mieć format `P1_ARCHITECTURE_RESULT`.

Jeżeli wynik zostanie ucięty:

- nie uruchamiaj analizy od początku;
- spróbuj kontynuacji istniejącego tasku bez nowych odczytów;
- poproś wyłącznie o brakującą część raportu;
- maksymalnie jedna próba recovery.

## P1-B — Review architektury

Agent:

```text
mamona-reviewer
model: ollama/qwen3.6:27b
variant: deep
```

Wejście:

- `P1_ARCHITECTURE_RESULT`;
- P0 repository map;
- obowiązujące decyzje.

Sprawdź:

- kompletność budżetu;
- wszystkie ścieżki Gemini;
- convergence;
- stany i freeze;
- limity tekstów;
- sloty;
- B/C;
- zakaz fallbacków;
- publication gate;
- reset;
- migrację;
- test matrix;
- rollback;
- UTF-8.

Wynik: `P1_REVIEW_RESULT`.

## P1-C — Jedna runda korekty architektury

Jeżeli reviewer zwróci `CHANGES_REQUIRED`:

- wznów istniejącego architekta, jeżeli możliwe;
- przekaż wyłącznie findingi;
- nie powtarzaj całego P1-A;
- jedna runda korekty;
- potem jedno ponowne review.

Jeżeli po tej rundzie nadal istnieje blocker:

```text
P1 — BLOCKED
```

Zapisz stan i zatrzymaj pracę.

## P1-D — Mechanical finalization

Po statusie `APPROVED` uruchom sekwencyjnie `quick-maintainer` na `qwen3.6-no-think`.

Każdy krok ma jeden plik.

### P1-D1 — Specyfikacja

Utwórz:

```text
docs/specs/MAMONA-24-article-generation-visual-narrative-v3.md
```

Źródło:

- finalny `P1_ARCHITECTURE_RESULT`;
- finalny `P1_REVIEW_RESULT`.

Marker:

```text
SPEC_SAVED
```

### P1-D2 — Decyzje

Zaktualizuj:

```text
docs/DECISIONS.md
```

Dodaj tylko zaakceptowane trwałe decyzje.

Marker:

```text
DECISIONS_SAVED
```

### P1-D3 — Architektura

Zaktualizuj:

```text
docs/ARCHITECTURE.md
```

Oddziel stan obecny od projektu docelowego.

Marker:

```text
ARCHITECTURE_SAVED
```

### P1-D4 — Mapa grafik

Zaktualizuj:

```text
docs/IMAGE_PIPELINE_MAP.md
```

Zapisz projekt docelowych bramek i usunięcia finalnych fallbacków.

Marker:

```text
IMAGE_MAP_SAVED
```

### P1-D5 — Indeks kontekstu

Zaktualizuj:

```text
docs/CONTEXT_INDEX.md
```

Marker:

```text
CONTEXT_INDEX_SAVED
```

### P1-D6 — Stan pracy

Zaktualizuj:

```text
docs/CURRENT_WORK.md
```

Status:

```text
P1 zakończone — oczekiwanie na checkpoint i akceptację przed P2
```

Marker:

```text
CURRENT_WORK_SAVED
```

No-think nie może zmieniać decyzji. Brak danych oznacza `MECHANICAL_FINALIZATION_BLOCKED`.

## P1-E — Checkpoint

Agent:

```text
checkpoint-writer
model: ollama/qwen3.6-no-think
```

Źródła:

- specyfikacja;
- decisions;
- architecture;
- image pipeline map;
- current work;
- finalny review.

Format:

```text
CHECKPOINT_P1
- Status:
- Agenci, modele i warianty:
- Root cause:
- Kontrakty:
- Maszyna stanów:
- GeminiBudget:
- Algorytm grafik:
- Limity tekstów:
- Reset i migracja:
- Pliki do zmiany:
- Test matrix:
- Rollback:
- Ryzyka:
- Wynik review:
- Przewidywany diff:
- Wymagana akceptacja:
```

Maksymalnie 1200 słów.

Zakończ dokładnie:

```text
AKCEPTUJĘ P1 — URUCHOM P2
```

Hard stop. Bez implementacji.

---

# P2 — Implementacja

Warunek:

```text
AKCEPTUJĘ P1 — URUCHOM P2
```

Agent: `mamona-coder`, `qwen3.6:27b`, `balanced`.

Subfazy sekwencyjne:

1. P2-A — `GeminiBudget`, call audit, convergence i warunki końca;
2. P2-B — `NarrativePlan` i generowanie draftu;
3. P2-C — `QcReport`, blocker scope i freeze;
4. P2-D — `VisualSlot`, grafiki, B/C i zakaz fallbacków;
5. P2-E — limity tekstów i kanoniczne liczenie;
6. P2-F — publication gate, `manual_review`, diagnostyka;
7. P2-G — audyt/reset z `--dry-run` i `--apply`, bez uruchamiania apply.

Po każdej subfazie:

- minimalny test;
- raport kodera;
- mechaniczna aktualizacja implementation log przez `quick-maintainer`;
- brak automatycznego przejścia do kolejnej fazy numerowanej.

Checkpoint P2 przez `checkpoint-writer`. Bez realnych providerów i resetu.

Akceptacja:

```text
AKCEPTUJĘ P2 — URUCHOM P3
```

---

# P3 — Deterministyczne testy integracyjne

Agent: `mamona-tester`, `qwen3.6:27b`, `balanced`.

Bez płatnych API i publikacji.

Minimalne testy:

1. minima bez zmian;
2. maksima +2000;
3. jedna funkcja długości;
4. limit 20;
5. retry zużywa budżet;
6. pozycja 16 uruchamia convergence;
7. po 20 brak wywołania;
8. A pozostaje frozen;
9. 1250 znaków może mieć 1 grafikę;
10. maksimum 5;
11. hero odpowiada A;
12. inline odpowiada sekcji;
13. polityk-zombie odpada;
14. placeholder nie jest finalny;
15. brak grafiki blokuje publikację;
16. B/C nie są fillerem;
17. brak rozwiązania → manual review;
18. brak jednej obowiązkowej matrycy;
19. prawa i trafność osobno;
20. UTF-8;
21. reset bez Gemini;
22. dry-run nie mutuje;
23. reset idempotentny;
24. poprawne artykuły nie są resetowane.

Raport testów zapisuje mechanicznie `quick-maintainer`.

Checkpoint przez `checkpoint-writer`.

Akceptacja:

```text
AKCEPTUJĘ P3 — URUCHOM P4
```

---

# P4 — Niezależny review

Agent: `mamona-reviewer`, `qwen3.6:27b`, `deep`.

Maksymalnie dwie rundy:

```text
review → poprawka → retest
```

Po dwóch nierozwiązanych rundach blocker i stop.

Nie uruchamiaj `--apply`.

Checkpoint przez `checkpoint-writer`.

Akceptacja:

```text
AKCEPTUJĘ P4 — URUCHOM P5 DRY-RUN
```

---

# P5 — Audyt istniejących artykułów, tylko dry-run

Wykonanie: `mamona-tester`, balanced.  
Review manifestu: `mamona-reviewer`, deep.

Zakazy:

- Gemini;
- inne LLM runtime;
- providerzy obrazów;
- publikacja;
- regeneracja;
- mutacja rekordów.

Wynik:

- manifest;
- powód;
- status publiczny;
- assety;
- pola zachowywane;
- pola czyszczone;
- plan backupu;
- checksum;
- idempotencja.

Dokument zapisuje `quick-maintainer`.

Checkpoint krytyczny przez `checkpoint-writer`.

Akceptacja:

```text
AKCEPTUJĘ MANIFEST — URUCHOM P6 APPLY
```

---

# P6 — Kontrolowany reset

1. backup;
2. checksum;
3. walidacja backupu;
4. atomowe zdjęcie wadliwych publicznych rekordów;
5. `--apply`;
6. brak regeneracji;
7. walidacja seedów i historii;
8. walidacja braku publicznych wadliwych materiałów;
9. ponowny dry-run i idempotencja.

Wykonanie: coder.  
Walidacja: tester.  
Review: reviewer.

Checkpoint:

```text
AKCEPTUJĘ P6 — ZAMKNIJ DOKUMENTACJĘ
```

---

# P7 — Dokumentacja i handoff

`quick-maintainer` na `qwen3.6-no-think` aktualizuje sekwencyjnie:

- `docs/CURRENT_WORK.md`;
- `docs/ARCHITECTURE.md`;
- `docs/IMAGE_PIPELINE_MAP.md`;
- `docs/DECISIONS.md`;
- `docs/CONTEXT_INDEX.md`;
- specyfikację;
- implementation log;
- remediation log;
- test report.

`checkpoint-writer` tworzy finalny handoff dla kolejnej instancji Kilo albo Codex.

No-think nie dodaje nowych decyzji. Finalny handoff syntetyzuje wyłącznie zatwierdzone dokumenty.
