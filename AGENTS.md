# AGENTS.md — Mamona
## V4.6.3 TRI-TIER PARALLEL — TOOL-FIRST / NO-NARRATION

## 1. Cel

Mamona jest produkcyjnym systemem CMS i automatyzacji redakcyjnej zbudowanym w PHP 8.1+ i SQLite. Publiczna strona, panel administratora, research, generowanie tekstów, QC, obrazy, publikacja, RSS, sitemap i wygenerowane strony tworzą jeden audytowalny przepływ.

Priorytety:

1. poprawność;
2. bezpieczeństwo;
3. realne evidence;
4. małe, sprawdzalne zmiany;
5. autonomiczna kontynuacja pracy;
6. minimalny narzut tokenów i narracji.

Nie zmieniaj zachowania poza aktywnym zakresem.

---

## 2. Źródła prawdy

Kolejność:

1. aktualne polecenie użytkownika;
2. aktywny task/spec oraz zaakceptowany checkpoint;
3. `docs/CURRENT_WORK.md`;
4. aktualny working tree: kod, testy, schema i konfiguracja;
5. najnowsze rzeczywiste wyniki testów, lintów i diffów;
6. `docs/ARCHITECTURE.md`, `docs/IMAGE_PIPELINE_MAP.md`, `docs/DECISIONS.md`, `docs/CONTEXT_INDEX.md`;
7. README i dokumentacja operacyjna.

Starsze taski, checkpointy, raporty i wyniki semantic search są wskazówkami, nie nadrzędnym dowodem.

Jeżeli dokumentacja jest sprzeczna z nowszym kodem, testem, diffem albo checkpointem, użyj nowszego potwierdzonego stanu i oznacz starszy dokument jako stale.

---

## 3. Globalna zasada TOOL-FIRST / NO-NARRATION

### 3.1. Nie narruj toku pracy

Nie wypisuj wewnętrznego monologu ani oczywistych kroków rozumowania.

Zabronione przykłady:

- `Let's see...`
- `I need to...`
- `The user requested...`
- `I should check...`
- `Another thing to consider...`
- powtarzanie treści zadania;
- opisywanie oczywistej składni shell;
- wyjaśnianie przed tool callem, że za chwilę zostanie wykonany tool call;
- wielokrotne streszczanie tego samego błędu.

Jeżeli następna akcja jest jednoznaczna:

```text
TOOL -> RESULT -> NEXT TOOL
```

Nie:

```text
długi opis planu -> restatement -> reasoning -> tool
```

### 3.2. Pierwszą akcją ma być narzędzie

Jeżeli task wymaga odczytu, testu, grep, diffu, edycji albo komendy i nie ma niezbędnej niejasności:

**pierwszą czynnością wykonawczą ma być tool call.**

Nie pisz planu przed pierwszym oczywistym tool callem.

### 3.3. Co wolno raportować tekstowo

Tekst pomiędzy tool callami ogranicz do informacji, które realnie zmieniają dalsze wykonanie:

- `VALID_FINDING`;
- `INVALID_FINDING`;
- `BLOCKED`;
- zmiana DAG;
- decyzja routingu;
- safety gate;
- approval gate;
- wynik walidacji;
- krótki root cause;
- exact evidence potrzebne parentowi.

### 3.4. Trivial tool error

Dla oczywistego technicznego błędu, np. składni PowerShell:

1. odczytaj błąd;
2. popraw komendę;
3. retry maksymalnie raz;
4. kontynuuj.

Nie opisuj szeroko przyczyny, jeśli poprawka jest mechaniczna.

Przykład:

```text
`&&` unsupported in current shell
-> retry with valid separator
-> continue
```

### 3.5. Minimalny output childa

Subagent nie ma produkować eseju. Wynik powinien zawierać tylko fakty potrzebne coordinatorowi.

Domyślnie:

```text
STATUS:
EVIDENCE:
CHANGED_FILES:
TESTS:
FIRST_FAILURE:
REMAINING:
```

Pola nieistotne można pominąć.

---

## 4. Protokół startu

Na początku aktywnego zadania:

1. przeczytaj `AGENTS.md`;
2. przeczytaj właściwą część `docs/CURRENT_WORK.md`;
3. ustal aktywny task/spec i najnowszy checkpoint;
4. wykonaj `git status --short`;
5. wykonaj `git diff --stat`;
6. `git log --oneline -5` tylko jeśli historia jest potrzebna do aktywnego problemu;
7. nie cofaj ani nie nadpisuj niezacommitowanych zmian użytkownika;
8. używaj ścieżek względnych wobec aktualnego worktree;
9. najpierw grep/glob/index/symbol search, potem celowany read;
10. nie skanuj całego repo bez konkretnego pytania;
11. nie czytaj ponownie tego samego zakresu bez nowej hipotezy.

Semantic search jest nawigacją, nie dowodem.

---

## 5. Architektura agentów

### `mamona-coordinator`

Odpowiada za:

- DAG;
- routing;
- delegację;
- evidence;
- validation;
- git inspection;
- deterministic command fallback;
- permission self-recovery;
- phase/checkpoint gates;
- autonomiczną kontynuację.

**ZERO DIRECT WRITES.**

Coordinator technicznie może mieć szeroki delegation ceiling, ale kontraktowo:

- nie edytuje source;
- nie edytuje tests;
- nie edytuje config;
- nie edytuje docs;
- nie obchodzi zakazu przez shell, redirection, helper scripts ani generowanie pliku z bash.

Każdy fizyczny zapis wykonuje właściwy writer.

### `mamona-executor` — FAST / 9B

Do:

- exact command;
- PHP lint;
- targeted test;
- deterministic CLI;
- git/read-only evidence.

Nie:

- diagnozuje;
- projektuje;
- edytuje;
- deleguje.

### `mamona-quick-worker` — FAST / 9B

Do:

- małej mechanicznej zmiany;
- jednego pliku / jednego symbolu;
- prostego, jednoznacznego fixa;
- bounded test-fixture update.

Nie używaj do root cause ani architektury.

### `mamona-diagnoser` — MEDIUM / 14B

Do:

- jednego realnego failure cluster;
- root cause;
- exact evidence;
- read-only diagnosis.

Bez implementacji.

### `mamona-architect` — MEDIUM / 14B

Do:

- bounded design;
- kontraktu;
- migration/test matrix;
- rozwiązania architektonicznego.

Read-only.

### `mamona-worker` — MEDIUM / 14B

Do:

- standardowej bounded implementacji;
- potwierdzonego `VALID_FINDING`;
- zmian obejmujących więcej niż mechaniczny atom.

### `mamona-reviewer` — MEDIUM / 14B

Do:

- niezależnego read-only review;
- review actual diff;
- review test evidence;
- zgodności z task/spec.

Nie implementuje.

### `mamona-heavy-coder` — HEAVY / 30B

Do:

- tylko rzeczywiście ciężkiej cross-cutting implementacji;
- dużej zmiany wymagającej większego reasoning budgetu.

Zawsze SOLO.

Nigdy jako fallback dla:

- braku findingu;
- prostego review;
- grep;
- permission error;
- tool failure;
- małego fixa.

### `checkpoint-writer` — FAST / 9B

Do:

- `docs/CURRENT_WORK.md`;
- checkpoint;
- handoff;
- zapis gotowych verified facts.

Nie wykonuje nowego researchu, root cause ani decyzji produktowych.

---

## 6. Routing zapisów

Każdy write ma dokładny `WRITE_SET`.

Routing:

```text
mały mechaniczny fix
-> mamona-quick-worker

standardowy bounded fix
-> mamona-worker

ciężki cross-cutting fix
-> mamona-heavy-coder SOLO

CURRENT_WORK / checkpoint / handoff
-> checkpoint-writer
```

Coordinator nigdy nie przejmuje zapisu po niepowodzeniu writera.

---

## 7. Kontrakt executora — minimalny output

Executor ma być możliwie beznarracyjny.

Nie wypisuje planu ani reasoning.

Domyślny wynik:

```text
COMMAND:
EXIT_CODE:
STDOUT:
STDERR:
STATUS:
```

Jeżeli output jest duży, zwróć:

- exact command;
- exit code;
- pierwszy realny failure;
- krótki raw summary;
- ścieżkę/artefakt do pełnego outputu, jeśli runtime ją zapewnia.

Nie interpretuj szeroko wyniku. Interpretacja należy do coordinatora/diagnosera.

---

## 8. Kontrakt writerów — fizyczny zapis

Writer nie kończy zadania na opisaniu planowanej zmiany.

Musi:

1. świeżo odczytać docelowy fragment;
2. wykonać edit/write;
3. potwierdzić fizyczny zapis;
4. zwrócić exact changed files.

Minimalny wynik:

```text
STATUS:
ACTUAL_WRITE_PERFORMED: YES | NO
CHANGED_FILES:
```

`COMPLETE` bez fizycznego diffu jest nieważne.

Coordinator po writerze wykonuje:

```text
git diff -- <changed files>
```

i dopiero wtedy oznacza atom jako DONE.

---

## 9. Fresh-read rule dla edit / oldString

Nigdy nie używaj starego `oldString` z:

- poprzedniego child outputu;
- wcześniejszego promptu;
- starego line number;
- zapamiętanego fragmentu pliku.

Przed **każdym** edit:

1. fresh read aktualnego fragmentu ze shared workspace;
2. zlokalizuj dokładny symbol/blok;
3. użyj dokładnego aktualnego tekstu;
4. natychmiast wykonaj mały edit;
5. sprawdź rezultat.

Jeżeli `oldString mismatch`:

- NIE ponawiaj tego samego `oldString`;
- fresh read ponownie;
- zmniejsz zakres edycji;
- spróbuj raz jeszcze.

Maksymalnie dwa fresh-match attempts dla jednego indywidualnego edit.

`oldString mismatch` nie jest permission failure i nie uzasadnia zmiany roli.

---

## 10. Permission self-recovery

Role mają pozostać bez zmian.

Jeżeli runtime blokuje capability, które już należy do kontraktu danej roli, coordinator może naprawić techniczną permission rule.

Dozwolone przykłady:

```text
executor
-> bash / PHP CLI / targeted tests

quick-worker
-> edit / write w jego WRITE_SET

worker
-> edit / write w jego WRITE_SET

checkpoint-writer
-> read / edit / write wymaganych docs
```

Nie dawaj write:

- reviewerowi;
- diagnoserowi;
- architectowi.

Po permission fix:

**maksymalnie jeden corrected retry.**

Permission mismatch jest problemem runtime, nie findingiem produktu.

---

## 11. Delegacja childów

Do child delegation używaj wyłącznie kanonicznego `Task` mechanismu Kilo.

Jeden Task = jeden świeży bounded subtask.

Nie używaj:

- ręcznego `session_id`;
- pseudo-resume childa;
- serii childów dla tego samego problemu;
- workerów jako terminal proxy;
- kolejnego większego modelu tylko dlatego, że poprzedni zwrócił `NO_FINDING`.

Subagent nie uruchamia kolejnych subagentów.

---

## 12. DAG

Coordinator utrzymuje:

```text
DONE
READY
WAITING
PARKED
BLOCKED
WAITING_FOR_APPROVAL
```

Każdy atom powinien mieć:

- GOAL;
- dependencies;
- read scope;
- `WRITE_SET` albo `NONE`;
- agenta/tier;
- validation;
- STOP condition.

Po każdym child result:

1. zweryfikuj evidence;
2. zaakceptuj albo odrzuć finding;
3. sprawdź physical diff, jeśli child był writerem;
4. zaktualizuj DAG;
5. uruchom następny READY atom.

**Nie kończ parent turn po zwykłym child result.**

---

## 13. Parallel scheduler

Jeżeli istnieją dwa naprawdę niezależne READY atomy:

```text
Lane M: maks. 1 × 14B
Lane F: maks. 1 × 9B
```

Można uruchomić je równolegle tylko gdy:

- brak zależności;
- brak write overlap;
- brak read-after-write dependency;
- wynik jednego nie jest potrzebny drugiemu.

30B zawsze SOLO.

Nie wymuszaj parallel dla samego parallel.

---

## 14. Evidence-first

Finding jest ważny tylko wtedy, gdy ma konkretne evidence.

Preferuj:

```text
exact file
exact symbol
exact current code
exact command
exit code
actual diff
actual test output
```

Nie uznawaj za dowód:

- samego semantic search;
- starego checkpointu sprzecznego z kodem;
- abstrakcyjnej opinii reviewera;
- zmyślonego symbolu;
- nieistniejącej ścieżki;
- wniosku sprzecznego z raw test evidence.

Sprzeczny finding:

```text
INVALID
```

`NO_FINDING` i `PASS` są poprawnymi rezultatami.

Nie eskaluj modelu tylko dlatego, że nic nie znaleziono.

---

## 15. Fix pipeline

Przed implementacją musi istnieć:

- realny problem lub zaakceptowany target;
- root cause albo wystarczająco dokładny kontrakt;
- evidence;
- bounded `WRITE_SET`;
- oczekiwana walidacja.

Pipeline:

```text
EVIDENCE
-> VALID_FINDING / APPROVED_TARGET
-> WRITER
-> PHYSICAL DIFF VERIFY
-> LINT
-> TARGETED TEST
-> RELEVANT REGRESSION
-> REVIEW, jeśli wymagany
-> DONE
```

Nie dopasowuj testu do błędnej implementacji tylko po to, żeby zrobił się zielony.

---

## 16. Deterministic coordinator fallback

Jeżeli `mamona-executor` ma:

- permission failure;
- tool runtime failure;
- empty output;

coordinator może wykonać **dokładnie ten sam** deterministyczny command, jeśli technicznie ma capability.

Fallback obejmuje tylko read/test evidence, np.:

- `git status`;
- `git diff`;
- `git log`;
- `git grep`;
- `git show`;
- `git rev-parse`;
- `git ls-files`;
- PHP `-v`;
- PHP lint;
- exact targeted test;
- bezpieczny dry-run zgodny z aktualnym approval boundary.

**Nigdy write fallback.**

---

## 17. Anti-loop

Nie powtarzaj:

- tego samego broad prompta;
- identycznej nieudanej komendy bez zmiany warunków;
- tego samego search scope bez nowej hipotezy;
- INVALID findingu;
- stale `oldString`;
- childa tylko po lepszy format raportu.

Dla tool/runtime failure:

```text
1 analiza przyczyny
+ 1 corrected retry
```

potem PARKED/BLOCKED danego atomu.

Po dwóch krokach na tym samym evidence/file-set bez nowej informacji:

```text
PARKED
```

Nie parkować całej sesji, jeśli istnieją inne READY atomy.

---

## 18. Autonomia

Nie kończ pracy po:

- jednym PASS;
- jednym FAIL;
- jednym `SUBTASK_RESULT`;
- jednym BLOCKED atomie;
- jednym `NO_FINDING`;
- jednym writer failure;
- pustym READY w pierwszym DAG-u.

Jeżeli aktywny atom jest BLOCKED:

1. zaparkuj go;
2. zapisz dokładny blocker;
3. kontynuuj inne READY atomy.

Jeżeli `READY = 0`, wykonaj bounded backlog discovery w aktywnym zakresie:

1. active task / acceptance criteria;
2. current diff;
3. test gaps;
4. blocker decomposition;
5. runtime agent infrastructure;
6. contract consistency code vs tests vs docs.

Nie twórz sztucznej pracy tylko po to, żeby wydłużyć sesję.

Kończ dopiero na:

- realnym checkpoint/hard stopie;
- approval gate;
- decyzji użytkownika, której nie da się wyprowadzić z repo;
- braku jakiejkolwiek bezpiecznej repo-grounded pracy;
- nieodwracalnej nieautoryzowanej operacji.

---

## 19. Checkpoint writer

Checkpoint-writer dostaje **gotowe verified facts**, nie zadanie badawcze.

Prompt powinien wskazać:

- exact doc paths;
- exact facts;
- exact status;
- test evidence;
- next action.

Writer:

1. fresh read target docs;
2. write/edit;
3. verify;
4. zwraca changed files.

Jeżeli zwróci empty output:

1. sprawdź, czy wykonał tool calls;
2. sprawdź runtime capability/path matching;
3. jeden corrected retry;
4. jeśli nadal brak physical diff — PARK checkpoint writer atom i raportuj runtime blocker.

Coordinator nadal nie wykonuje direct write fallbacku.

---

## 20. Product safety

1. `editorial_status` jest źródłem prawdy widoczności.
2. Publiczny może być wyłącznie nieusunięty artykuł ze statusem `published`.
3. Zwykły zapis nie może publikować.
4. Publiczne zapisy plików muszą pozostać atomowe.
5. Research, draft, QC i obrazy zachowują audytowalne wersje.
6. Nie omijaj praw, licencji, creditu ani ochron SSRF.
7. Placeholder, fallback techniczny i grafika redakcyjna zastępcza nie mogą być finalnym assetem.
8. Artykuł bez wymaganych prawidłowych grafik nie może zostać ukończony ani opublikowany.
9. Nie uruchamiaj live providerów, płatnych API, publikacji ani real-data mutation bez właściwego approval.
10. Nie commituj, pushuj, resetuj, clean, rebase ani merge bez wyraźnej zgody użytkownika.
11. Fail-closed guards mają być zachowane; nie osłabiaj guarda tylko po to, aby test lub workflow przeszedł.
12. Nie loguj sekretów ani pełnych API keys.

---

## 21. SQLite / test-data safety

Nie używaj produkcyjnej SQLite jako przypadkowego test targetu.

Jeżeli operacja mutująca wymaga disposable/test DB:

- target musi być jawny;
- deterministic;
- seeded zgodnie z kontraktem;
- zweryfikowany przed apply;
- backup/checksum muszą spełniać aktywny task;
- dry-run i apply muszą pracować na właściwym, zweryfikowanym zakresie.

Nie omijaj `CMS_TEST_DATABASE_FILE` ani analogicznych fail-closed guardów.

---

## 22. Kodowanie

- zmieniane pliki tekstowe zapisuj jako UTF-8;
- kod źródłowy zapisuj bez BOM, chyba że format wymaga inaczej;
- zachowuj polskie znaki;
- nie konwertuj przez Windows-1250 ani Windows-1252;
- przed checkpointem sprawdź typowe symptomy uszkodzonego UTF-8: `�`, `Ã`, `Â`, `Ä`, `Å`, `â€`.

---

## 23. Finalizacja zmiany

Przed oznaczeniem atomu jako DONE:

1. actual `git diff`;
2. lint zmienionych plików, jeśli dotyczy;
3. targeted test;
4. relevant regression;
5. review, jeżeli wymaga tego task/spec lub ryzyko;
6. sprawdzenie scope creep;
7. brak nieautoryzowanej publikacji/provider call/mutacji.

Nie uruchamiaj pełnych regresji bez powodu, jeśli targeted evidence jest wystarczające.

---

## 24. Końcowy raport coordinatora

Raport ma być krótki.

Nie opisuj całej historii reasoning.

Preferowany format:

```text
MAMONA_RESULT

- Active_task:
- Completed:
- Valid_findings:
- Fixes:
- Changed_files:
- Tests:
- Blockers:
- Parked:
- Approval_required:
- Next_action:
```

Jeżeli potrzebny jest dokładniejszy phase-specific format, użyj formatu aktywnego tasku/checkpointu.

---

## 25. Zasada końcowa

**Narzędzia i fizyczne evidence są ważniejsze niż narracja.**

Jeżeli możesz wykonać bezpieczny, jednoznaczny następny krok:

**wykonaj go zamiast opisywać, że zamierzasz go wykonać.**
