# Mamona Kilo Agent Pack V5.1.3 — Evidence-First Autonomy

V5.1.3 jest aktualizacją V5.1.2 na bazie architektury V4.5. Nie wraca do starego orchestratora. Primary pozostaje:

```text
mamona-coordinator
```

## Cel 5.1.3

Dwie rzeczy jednocześnie:

1. zwiększyć poprawność subagentów po problemach zaobserwowanych w P4;
2. pozwolić coordinatorowi pracować około **2 godzin bez ręcznej ingerencji użytkownika**, kontynuując kolejne niezależne atomy zamiast zatrzymywać całą sesję po pierwszym blockerze.

## Aktywni agenci

```text
mamona-coordinator   14B  primary / queue + deterministic evidence + ledger
mamona-executor       9B  exact command/test runner only
mamona-diagnoser     14B  evidence interpretation / bounded diagnosis
mamona-worker        14B  normal bounded implementation
mamona-reviewer      14B  phase-gate review
mamona-architect     14B  bounded architecture/contract
mamona-heavy-coder   30B  heavy implementation only, always solo
checkpoint-writer     9B  state compression only
```

## Najważniejsze zmiany względem 5.1.2

### Evidence-first

Repo-wide literalne fakty zbiera coordinator przez deterministyczne narzędzia (`git grep`, `rg`, `Select-String`, exact read). 9B executor nie prowadzi już evidence researchu.

### Diagnoser nie może „udowodnić braku” przez glob

Globalne negatywy wymagają jawnego coverage i uwzględnienia untracked files. Sprzeczny raport jest `INVALID_REPORT` nawet jeśli child sam nazwał go `VALID_FINDING`.

### Executor został mocno uproszczony

Executor ma uruchomić command, oddać wynik i zakończyć. Nie może czytać repo, grepować, edytować, diagnozować ani decydować o globalnych retry budgets.

### Verified write przed retestem

Po workerze coordinator zawsze sprawdza rzeczywisty diff/anchor. Test nie może zostać uruchomiony na starym tree tylko dlatego, że worker napisał `COMPLETE`.

### Oddzielne liczniki

Baseline test, evidence, diagnosis, fix i post-fix retest mają osobne budżety. Nie ma już błędu „jeden test + jedna diagnoza = wszystkie próby zużyte”.

### Blocker atomu != stop sesji

Blocked atom jest parkowany. Coordinator przechodzi do następnego READY atomu, jeśli dependencies pozwalają. Anti-loop działa lokalnie, a nie jako automatyczny koniec całej sesji.

### 120-min autonomy window

Prompt recovery ustawia 120 min target i 10 min rezerwy na finalizację. Coordinator nie pyta użytkownika o recoverable decyzje w środku sesji.

### Brak approval prompts dla typowych safe commands

Agent definitions nie używają `bash: ask`. Typowe read/test commands są jawnie allowlisted; nieznane bash commands są denied zamiast czekać na ręczne kliknięcie.

## Modele

Bez zmian względem V4.5/V5.1.2:

```text
mamona-coder30-128k
mamona-qwen14-64k
mamona-qwen9-64k
```

Paczka nie restartuje Ollamy i nie pobiera modeli.

## Instalacja

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-5.1.3.ps1
```

Domyślny repo root:

```text
C:\Projekty\mamona
```

Inny root:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-5.1.3.ps1 -RepoRoot "D:\repo\mamona"
```

Installer robi backup do:

```text
.mamona-backups\5.1.3-<timestamp>
```

Nie dotyka PHP source poza plikami agent/config/protocol/prompt, nie dotyka bazy, `.env`, modeli ani procesu Ollamy.

## Po instalacji

1. `Developer: Reload Window`
2. Nowa sesja Kilo
3. wybierz `mamona-coordinator`
4. uruchom:

```powershell
powershell -ExecutionPolicy Bypass -File .\verify-mamona-5.1.3.ps1
```

5. wklej `PROMPT_RESUME_P4_AFTER_5_1_3.md`

Potem system powinien sam przechodzić przez READY queue do końca pracy lub okna ~2h, bez potrzeby zatwierdzania kolejnego agenta po każdym atomie.
