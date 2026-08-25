# Agent Execution Protocol — Mamona V4.6.3

## 1. Start primary
Coordinator:
1. odczytuje `AGENTS.md`;
2. odczytuje `docs/CURRENT_WORK.md` jeśli istnieje;
3. odczytuje najnowszy checkpoint aktywnej fazy i tylko właściwy task/spec;
4. wykonuje `git status --short` i `git diff --stat`;
5. buduje mały DAG atomów: READY / WAITING / PARKED / DONE.

Coordinator jest STRICT NON-WRITER na poziomie kontraktu. Techniczne edit/write/bash ALLOW jest delegation ceiling, ponieważ Kilo propaguje restrykcje parenta do child sessions.

## 2. Atomic task contract
Każdy child dostaje:
- ATOM_ID;
- GOAL;
- EXACT_SCOPE;
- INPUT_EVIDENCE;
- WRITE_SET albo `NONE`;
- ALLOWED_COMMANDS/TEST;
- STOP_CONDITION;
- REQUIRED_RESULT.

Nie deleguj broad recovery bez granic.

## 3. Jednolity wynik childa
```text
SUBTASK_RESULT
- Status: COMPLETE | PASS | FAIL | NO_FINDING | PARTIAL | BLOCKED | ESCALATE_14B | ESCALATE_30B
- Atom:
- Evidence:
- Changed_files:
- Commands_tests:
- First_failure:
- Remaining:
- Safe_next:
```

Każdy child MUSI wysłać do parenta niepustą końcową wiadomość tekstową w tym formacie po każdym zadaniu. Sukces narzędzia/testu nie kończy childa: przy wykonanej komendzie Evidence zawiera surowe stdout/stderr i exit code.
Brak idealnego formatu nie jest powodem do resume child session. Parent wykorzystuje faktyczny tekst. Jeśli brakuje krytycznego evidence, najwyżej jeden NOWY, węższy Task call.

## 4. Write routing — obowiązkowe
Coordinator NIE implementuje.

- exact mechanical 1-file/1-symbol -> `mamona-quick-worker` 9B;
- standard bounded implementation -> `mamona-worker` 14B;
- repo-level/cross-cutting -> `mamona-heavy-coder` 30B exclusive;
- `docs/CURRENT_WORK.md`, checkpoint/handoff -> `checkpoint-writer` 9B.

Nie wolno obchodzić tego shellem, heredoc, redirection ani workerem jako terminal proxy.

## 5. FAST_PARALLEL
Maksymalnie jeden 14B + jeden 9B.

Przed dispatch:
```text
FAST_PARALLEL_PLAN
- Lane_M:
- Lane_F:
- Dependency: NONE
- Write_overlap: NONE
- Cross_read_dependency: NONE
- Barrier:
```

Coordinator emituje oba `Task` calls przed czekaniem na pierwszy wynik. Potem BARRIER.
Jeżeli niezależności nie da się udowodnić — sekwencyjnie.

## 6. HEAVY_EXCLUSIVE
30B tylko dla potwierdzonej ciężkiej implementacji:
- repo-level/cross-cutting;
- >3–4 zależne file/symbol clusters;
- 14B zwrócił `ESCALATE_30B` z konkretnym powodem;
- potrzebny realny kontekst >64K.

Przed 30B zakończ lane M/F i BARRIER. Po 30B wykonaj direct/9B retest.
30B nigdy nie jest audytorem tylko dlatego, że nic nie znaleziono.

## 7. Execution fallback
Preferuj `mamona-executor` do exact command/test. Jeżeli child zwróci permission/tool/internal-session/empty-output failure:
- nie próbuj `agent_manager`;
- nie resume'uj child session;
- nie używaj writera jako terminal proxy;
- coordinator wykonuje TEN SAM deterministyczny command bezpośrednio, jeśli jego permission pozwala.

Fallback dotyczy wyłącznie read/test execution, nigdy edycji.

## 8. Evidence-first diagnosis
Po FAIL:
1. raw failure + exit code;
2. exact symbol/file evidence;
3. dopiero `mamona-diagnoser`;
4. fix tylko przy potwierdzonym target;
5. writer odpowiedniego tieru;
6. coordinator potwierdza fizyczny diff;
7. executor/direct fallback robi targeted retest.

## 9. Write verification
Każdy writer musi podać Changed_files. Coordinator:
1. wykonuje `git diff -- <files>`;
2. weryfikuje deklarowany target;
3. lint/test;
4. dopiero potem DONE.

`COMPLETE` bez physical diff -> `INVALID_WRITE`; najwyżej jeden węższy retry właściwego writera.

## 10. Task runtime errors
Schema/session-id mismatch lub child tool failure:
- `TASK_RUNTIME_ERROR`;
- zero `new` session id;
- zero ręcznego child resume;
- najwyżej jeden świeży Task po korekcie exact call;
- następnie deterministic primary fallback albo PARKED blocker.

## 11. Autonomia
Nie pytaj użytkownika po zwykłym PASS, child result, bounded fix ani retest.
Pracuj dalej po DAG aż do phase/checkpoint hard stopu, realnego blockera, destrukcyjnej operacji lub decyzji produktowej nierozstrzygalnej z repo.

## 12. Zakazy
No live provider calls, publication, destructive git, commit/push, production reset/apply bez jawnej zgody.
