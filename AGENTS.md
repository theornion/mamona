# AGENTS.md — Mamona

## 1. Cel

Mamona jest produkcyjnym systemem CMS i automatyzacji redakcyjnej w PHP 8.1+ i SQLite. Publiczna strona, panel administratora, research, generowanie tekstów, QC, obrazy, publikacja, RSS, sitemap i wygenerowane strony tworzą jeden audytowalny przepływ.

Preferuj małe, sprawdzalne zmiany. Nie zmieniaj zachowania poza aktywnym zakresem.

## 2. Źródła prawdy

Kolejność:
1. Aktualne polecenie użytkownika.
2. Aktywny task oraz zaakceptowany checkpoint/handoff.
3. `docs/CURRENT_WORK.md`.
4. Aktualny kod, schemat bazy, konfiguracja i testy.
5. `docs/ARCHITECTURE.md`, `docs/IMAGE_PIPELINE_MAP.md`, `docs/DECISIONS.md`, `docs/CONTEXT_INDEX.md`.
6. README i dokumentacja operacyjna.

Stare taski, archiwa i wcześniejsze raporty są wskazówkami. Trwałe ustalenie wymaga potwierdzenia w aktualnym kodzie, schemacie, konfiguracji albo teście.

## 3. Protokół startu

1. Przeczytaj aktywny task, aktualny checkpoint/handoff i właściwą część `docs/CURRENT_WORK.md`.
2. Sprawdź `git status --short`.
3. Domyślnie użyj `git diff --stat`, nie pełnego `git diff`.
4. Nie nadpisuj niezacommitowanych zmian użytkownika.
5. Nie skanuj całego repo bez konkretnego pytania.
6. Nie czytaj ponownie tego samego zakresu bez nowej hipotezy.

## 4. Modele i role

### `ollama/qwen3.6:27b`
Używaj do orkiestracji, architektury, implementacji, trudnych testów, review i root cause.

Warianty:
- `balanced` — orkiestracja, implementacja i testy;
- `deep` — architektura, trudne decyzje i krytyczne review.

### `ollama/qwen3.5:9b`
Używaj do:
- `quick-maintainer`;
- `checkpoint-writer`;
- krótkich handoffów;
- prostych, niekrytycznych operacji mechanicznych.

Dla mechaniki używaj wariantu `no-think`.

### `ollama/qwen3.5-no-think`
Dedykowany alias do automatycznego Context Condensing Kilo.

## 5. Role

- `mamona-orchestrator` — delegacja, checkpointy, kontrola kolejności i kontekstu.
- `repo-scout` — read-only rozpoznanie.
- `mamona-architect` — kontrakty, root cause, migracje i plan.
- `mamona-coder` — małe atomy implementacji.
- `mamona-tester` — testy, regresje i diagnostyka.
- `mamona-reviewer` — niezależne review.
- `quick-maintainer` — mechaniczny zapis dokumentacji 9B/no-think.
- `checkpoint-writer` — checkpoint/handoff 9B/no-think.

## 6. Atomowe subtaski

Domyślny subtask implementacyjny:
- 1–2 pliki produkcyjne;
- jedna logiczna zmiana albo jeden integration point;
- około 100–150 nowych/zmienionych linii;
- maksymalnie jeden nowy duży komponent.

Jeżeli nowy komponent przekroczy około 200 linii, oddziel:
1. schema/kontrakt;
2. implementację komponentu;
3. integrację;
4. test.

Nie zlecaj jednemu coderowi jednocześnie: migracji + dużego serwisu + integracji + dużych testów + dokumentacji.

## 7. DIRECT_TARGET_MODE

Jeżeli rodzic podał dokładny plik i symbol/funkcję:
1. nie czytaj całego taska ani całej specyfikacji;
2. nie wykonuj broad grep/glob;
3. nie wykonuj `git log`;
4. przeczytaj wskazany symbol i najwyżej jego bezpośrednie zależności;
5. cel: maks. 8–12 operacji rozpoznawczych przed pierwszą edycją;
6. po potwierdzeniu kontraktu przejdź do edycji;
7. po edycji uruchom najmniejszy wystarczający test.

## 8. Statusy subtasków

- `COMPLETE` — cały atom zakończony i zweryfikowany.
- `PARTIAL_COMPLETE` — bezpieczna, użyteczna część zapisana, ale pozostał jawny zakres.
- `BLOCKED` — brak bezpiecznej możliwości kontynuacji.

`PARTIAL_COMPLETE` musi podać:
- Completed;
- Remaining;
- Safe continuation point;
- Changed files;
- Tests.

Brak raportu nie oznacza sukcesu.

## 9. Orchestrator — minimalny rodzic

Orchestrator:
- nie implementuje kodu produkcyjnego;
- nie wykonuje `edit` zamiast codera;
- po `COMPLETE` nie czyta ponownie całego diffu;
- po `COMPLETE` przechodzi do wymaganej walidacji/testera;
- po `PARTIAL_COMPLETE` uruchamia jeden continuation task tylko dla `Remaining`;
- po pustym wyniku sprawdza wyłącznie `git diff --stat` i diff zmienionych plików;
- nie wykonuje własnego reverse engineeringu zamiast brakującego raportu;
- po drugim nieudanym continuation zwraca `BLOCKED`.

## 10. Tester

Tester:
- najpierw uruchamia istniejące testy;
- nowy test tworzy tylko dla brakującego coverage;
- preferuje krótki test tabelaryczny;
- nie buduje nowego mini-frameworka, gdy prostsza fixture wystarczy;
- nie wykonuje realnych płatnych API ani produkcyjnych mutacji;
- dla regresji semantycznych pozostaje na `qwen3.6:27b/balanced`.

## 11. Mechanical finalization

`quick-maintainer` i `checkpoint-writer` używają `qwen3.5:9b/no-think`.

Po udanym `write`/`edit`:
- nie zapisuj tego samego pliku drugi raz;
- nie czytaj ponownie pliku bez błędu narzędzia;
- zwróć wymagany marker sukcesu.

Orchestrator po markerze sukcesu nie weryfikuje mechanicznego zapisu przez 27B, chyba że narzędzie zgłosi błąd.


## 11A. Natywny zapis plików i trwały wynik subagenta

Kilo ma trzy natywne narzędzia modyfikacji plików: `edit`, `write`, `apply_patch`.

Reguła nadrzędna:
- jeżeli agent ma uprawnienie do ścieżki, używa natywnego file tool;
- brak file tool NIE jest zgodą na zapis przez bash/PowerShell/php/echo/redirection;
- shell nie jest mechanizmem tworzenia kodu, testów ani dokumentacji.

Macierz zapisu:
- `mamona-coder`: kod/testy zgodnie z taskiem + `.kilo/results/**`;
- `mamona-tester`: tylko `tests/**` + `.kilo/results/**`;
- `mamona-architect`: tylko `docs/**` + `.kilo/results/**`;
- `mamona-reviewer`: tylko `.kilo/results/**`;
- `repo-scout`: tylko `.kilo/results/**`;
- `quick-maintainer`: Markdown/docs + `.kilo/results/**`;
- `checkpoint-writer`: Markdown/docs + `.kilo/results/**`;
- `mamona-orchestrator`: tylko docs + `.kilo/results/**`, nigdy kod produkcyjny.

Każdy reasoning subagent dostaje od rodzica jawny:
`Result file: .kilo/results/<SUBTASK-ID>.json`

Przed finalną odpowiedzią zapisuje mały, maszynowo czytelny wynik. Dzięki temu pusty/ucięty tekst child session nie zmusza Orchestratora do ponownego reverse engineeringu.

`.kilo/results/*.json` są plikami runtime i nie trafiają do Git.

## 12. Auto-compaction i kontrola kontekstu

Kilo automatycznie kondensuje historię przy **65% kontekstu**.

Konfiguracja:
- `compaction.auto = true`;
- `compaction.threshold_percent = 65`;
- `compaction.prune = true`;
- `compaction.tail_turns = 2`;
- `compaction.preserve_recent_tokens = 8000`;
- `compaction.reserved = 20000`;
- model summary: `ollama/qwen3.5-no-think`.

Zasady:
- NIE rób handoffu do nowego Orchestratora tylko dlatego, że kontekst osiągnął 65%;
- pozwól Kilo wykonać automatyczny Context Condensing i kontynuuj w tej samej sesji;
- nie przerywaj aktywnego child tasku z powodu progu kontekstu;
- po compaction sprawdź tylko, czy zachowane są: aktywna faza, wykonany zakres, blockery i dokładny następny krok;
- nie odtwarzaj całej starej historii, jeżeli summary zawiera potrzebny stan;
- formalny handoff do pliku nadal wykonuj na checkpointach między dużymi fazami;
- awaryjny handoff wykonaj tylko, gdy auto-compaction nie zadziała, summary utraci krytyczny stan albo sesja pozostanie blisko limitu mimo compaction.

Awaryjny handoff:
- `quick-maintainer` na `qwen3.5:9b/no-think`;
- około 800–1600 słów;
- tylko potwierdzony stan;
- completed, changed files, tests, open issues, exact next step;
- bez ponownego researchu.

## 13. Równoległość

Przy `OLLAMA_NUM_PARALLEL=1` subagenci działają sekwencyjnie.

## 14. Ochrona przed zapętlaniem

- Nie powtarzaj identycznej nieudanej komendy więcej niż raz.
- Nie czytaj ponownie tego samego zakresu bez nowej hipotezy.
- Nie uruchamiaj trzeciego pełnego recovery.
- Przy wzroście zakresu zakończ `PARTIAL_COMPLETE`.
- Nie zużywaj końca odpowiedzi na reasoning kosztem raportu.

## 15. Reguły produktu

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

## 16. Kodowanie

- UTF-8.
- Kod bez BOM, jeśli format nie wymaga inaczej.
- Zachowuj polskie znaki.
- Nie konwertuj przez Windows-1250/1252.
- Przed checkpointem sprawdź `�`, `Ã`, `Â`, `Ä`, `Å`, `â€`.

## 17. Zakończenie etapu

1. Przejrzyj `git diff --stat`.
2. Pełny diff czytaj tylko dla plików wymaganych do decyzji.
3. Uruchom najmniejszy wystarczający zestaw testów.
4. Zaktualizuj dokumentację przez 9B/no-think.
5. Sprawdź UTF-8.
6. Zwróć krótki raport.
