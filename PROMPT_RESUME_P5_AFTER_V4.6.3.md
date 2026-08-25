# RESUME MAMONA — V4.6.3 — P5 DRY-RUN ONLY

Pracuj jako `mamona-coordinator`. Aktywna paczka: **MAMONA V4.6.3 TRI-TIER PARALLEL — DELEGATION CEILING**.

## Potwierdzony handoff

P4 został zakończony i zapisany wcześniej:
- `docs/research/MAMONA-24-CHECKPOINT-P4.md` istnieje;
- finalna regresja P4/P3-D wcześniej przeszła;
- real-data P5 `--apply` wymaga zatwierdzonego dry-run manifestu;
- poprzednia próba P5 na V4.6.2 była technicznie zablokowana przez dziedziczenie parent `edit/bash` permissions do child sessions; ten blocker NIE jest findingiem produktu.

V4.6.3 naprawia ten runtime problem: coordinator ma techniczne edit/write/bash jako delegation ceiling, ale **ZERO DIRECT WRITES**. Każdy zapis ma być wykonany przez właściwego writera.

## Hard safety boundary

W tej sesji:
- wolno wykonać **P5 DRY-RUN / audit / manifest validation**;
- NIE WOLNO wykonać real-data `--apply`;
- NIE WOLNO commit/push/reset/clean;
- po przygotowaniu i review manifestu zatrzymaj się na user approval gate.

## Start

Najpierw przeczytaj tylko:
1. `AGENTS.md`
2. `docs/AGENT_EXECUTION_PROTOCOL.md`
3. `docs/CURRENT_WORK.md`
4. `docs/research/MAMONA-24-CHECKPOINT-P4.md`
5. aktualny MAMONA-24 task/spec opisujący P5/reset/dry-run
6. aktualny diff/status.

Następnie wykonaj bezpośrednio jako coordinator:
- `git status --short`
- `git diff --stat`

Nie wracaj do P4 bez nowego realnego regresyjnego evidence.

## P5 scheduler

Zbuduj DAG P5 z atomów READY/WAITING/PARKED/DONE.

Preferuj równoległość tylko dla niezależnych atomów:
- 1×14B lane + 1×9B lane;
- 30B zawsze SOLO;
- żadnego write overlap ani read-after-write race.

Przykład pierwszej pary, jeśli scope to pozwala:
- 14B `mamona-reviewer` lub `mamona-diagnoser`: read-only review reset contract / manifest criteria;
- 9B `mamona-executor`: exact dry-run/preflight command albo najbliższy deterministyczny test bez `--apply`.

Wyślij oba Task calls przed barrierem, jeśli są naprawdę niezależne.

## Execution fallback

`mamona-executor` powinien teraz mieć bash/PHP capability dzięki delegation ceiling.
Jeżeli zwróci tool/runtime/empty-output failure:
- NIE uruchamiaj drugiego executora;
- coordinator wykonuje dokładnie TEN SAM deterministic read/test/dry-run command bezpośrednio;
- to jest execution fallback, nie write fallback.

## Writes

Coordinator NIE wywołuje edit/write i nie zapisuje shellem.
Każdy zapis:
- mały mechaniczny fix -> `mamona-quick-worker` 9B;
- standardowy fix -> `mamona-worker` 14B;
- ciężki cross-cutting fix -> `mamona-heavy-coder` 30B SOLO;
- `CURRENT_WORK`, checkpoint, blocker/handoff -> `checkpoint-writer` 9B.

Po każdym writerze coordinator robi `git diff -- <changed files>` i walidację.

## P5 dry-run evidence

Nie zgaduj commandu ani manifestu. Ustal je z aktualnego task/spec/kodu.
Dry-run musi dostarczyć raw evidence:
- exact command;
- exit code;
- zakres rekordów / liczba kandydatów;
- manifest lub deterministyczny output pozwalający review;
- brak real-data mutation;
- brak `--apply`.

Jeżeli dry-run FAIL:
1. exact first failure;
2. deterministic evidence;
3. jeden bounded `mamona-diagnoser`;
4. zaakceptowany fix przez właściwego writera;
5. retest/dry-run.

Nie eskaluj do 30B tylko dlatego, że findingu brak.

## Manifest review

Po poprawnym dry-run uruchom `mamona-reviewer` read-only dla manifestu/evidence.
Reviewer musi potwierdzić co najmniej:
- zakres zgadza się z P5 contract;
- brak niezamierzonych rekordów;
- mutation/apply nie nastąpiły;
- dry-run jest wystarczający do decyzji użytkownika;
- findingi mają exact file/symbol/output evidence.

Sprzeczny lub abstrakcyjny finding = INVALID.

## Final docs

Po zakończeniu dry-run i review uruchom `checkpoint-writer` z gotowymi faktami. Ma zapisać wyłącznie wymagany `CURRENT_WORK` / P5 dry-run checkpoint/handoff wynikający ze standardu repo.

Jeżeli checkpoint-writer nie ma edit/write tool mimo V4.6.3, zwróć `DELEGATION_CEILING_NOT_LOADED` i STOP — nie rób direct write fallbacku coordinatora.

## Hard stop

Zatrzymaj się PRZED każdym real-data `--apply`.

Final output:

MAMONA_P5_DRY_RUN_RESULT
- Agent_pack: V4.6.3
- Primary_agent: mamona-coordinator
- P4_checkpoint_loaded:
- Parallel_pairs_executed:
- Executor_status:
- Dry_run_command:
- Dry_run_exit:
- Dry_run_manifest:
- Manifest_review:
- Valid_findings:
- Invalid_findings_rejected:
- Changed_files:
- Tests_run:
- CURRENT_WORK_updated_by_checkpoint_writer:
- P5_checkpoint_or_handoff_created:
- Real_data_apply_executed: NO
- Remaining_blockers:
- Next_action: USER_APPROVAL_REQUIRED_FOR_P5_APPLY | <exact blocker>
