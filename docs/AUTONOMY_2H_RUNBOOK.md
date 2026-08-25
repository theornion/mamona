# Mamona 5.1.3 — 2H Autonomous Work Runbook

## Cel

Primary `mamona-coordinator` ma wykonywać kolejne READY atomy bez ręcznej ingerencji przez około 120 minut, zachowując anti-loop i bezpieczeństwo working tree.

## Czas

```text
0–5 min     bootstrap: CURRENT_WORK/task/spec/protocol + status/diff + queue
5–100 min   normal atom execution
100–110 min do not start a new heavy atom; close bounded work
110–120 min finalization reserve: CURRENT_WORK / checkpoint / final handoff
```

To są limity orientacyjne, nie powód do sztucznego opóźniania pracy.

## Zasada ciągłości

`SUBTASK_RESULT` nie jest odpowiedzią końcową primary. Po jego walidacji coordinator ma przejść do następnego kroku/atomu w tym samym parent turn, dopóki nie zachodzi globalna stop condition.


```text
ATOM BLOCKED -> PARK -> NEXT READY ATOM
```

Nie:

```text
ATOM BLOCKED -> END WHOLE SESSION
```

Wyjątek: blocked atom jest obowiązkową zależnością wszystkich pozostałych.

## Role

```text
Coordinator 14B  facts + queue + ledger + small exact edits
Diagnoser  14B  interpret evidence packet
Worker     14B  bounded write
Executor    9B  command/test only
Reviewer   14B  phase gate
Architect  14B  contract only
Heavy      30B  heavy implementation only, solo
Checkpoint  9B  compress confirmed state
```

## Evidence pipeline

```text
1. coordinator deterministic evidence
2. validate consistency
3. diagnoser only if interpretation needed
4. coordinator validates finding
5. worker/direct fix
6. coordinator WRITE_VERIFICATION
7. executor exact retest
8. phase gate / next atom
```

## Interruption avoidance

Agent pack nie używa wildcard `bash: ask`. Typowe safe commands są allowlisted. Nieznane bash commands mają zostać odrzucone i zastąpione read/grep/edit tool albo atom ma zostać zaparkowany — system nie powinien czekać na kliknięcie użytkownika dla zwykłych czynności.

## Niedozwolone skróty

- bigger model as retry strategy;
- executor as evidence researcher;
- diagnoser claiming absence from glob;
- retest before write proof;
- third run in one retry category;
- repeated INVALID/DUPLICATE;
- broad repo reread after a concrete failure target exists;
- commit/push/reset/clean.

## Gdy child nie odda raportu

Wznów **tę samą** child session dokładnie raz:

```text
RETURN REPORT ONLY. DO NOT USE TOOLS.
```

Nie uruchamiaj kolejnego modelu do przepisywania wyniku.

## Gdy worker twierdzi COMPLETE, ale diff tego nie pokazuje

Nie retestuj.

```text
WRITE_NOT_VERIFIED
-> one same-session WRITE_REPAIR if exact edit is already known
-> verify diff again
-> only then retest
```

## Koniec sesji

Finalny handoff musi umożliwić natychmiastowe wznowienie bez ponownego researchu:

- CLOSED atoms;
- PARKED blockers;
- exact evidence anchors;
- changed files;
- tests + exit codes;
- separate ledgers;
- one next READY action.
