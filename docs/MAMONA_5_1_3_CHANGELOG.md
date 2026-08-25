# Mamona Agent Pack 5.1.3 — Changelog

## Evidence correctness

- coordinator zbiera deterministyczne raw evidence przed diagnozą problemów symbol/loader/caller/entrypoint;
- `glob`/semantic search nie mogą dowodzić globalnego braku;
- negative claims wymagają coverage + sprawdzenia istotnych untracked files;
- child status `VALID_FINDING` jest walidowany przez coordinatora pod kątem wewnętrznej spójności;
- sprzeczne reporty stają się `INVALID_REPORT`.

## Executor redesign

- 9B = exact command runner only;
- brak read/glob/grep/edit permissions;
- po commandzie natychmiast raport i STOP;
- executor nie zarządza attempt ledgerem i nie decyduje o globalnym BLOCKED.

## Diagnosis redesign

- domyślnie diagnoser interpretuje `EVIDENCE_PACKET` z `Search_authorization: NONE`;
- targeted search wymaga jawnej autoryzacji;
- literal patch/path musi być oznaczony VERIFIED albo NOT_VERIFIED.

## Write reliability

- `WRITE_VERIFICATION` coordinatora przed każdym post-fix retestem;
- worker/heavy coder muszą zwracać `Change_proof`;
- worker nie wykonuje oficjalnego retestu;
- one-time `WRITE_REPAIR` możliwy przed zużyciem kolejnego fix attemptu.

## Retry accounting

Oddzielne liczniki:
- baseline tests;
- evidence batches;
- diagnosis runs;
- fix attempts;
- retests after verified write;
- report repairs;
- reopen count.

## Anti-loop + autonomy

- run 3 nadal nie istnieje w tej samej kategorii;
- invalid result nie eskaluje automatycznie modelu;
- blocked atom jest parkowany zamiast kończyć całą sesję;
- coordinator kontynuuje następną READY pracę;
- 120-min target + 10-min finalization reserve;
- brak `bash: ask` w agent definitions dla typowych ścieżek pracy.

## Context hygiene

- child dostaje evidence packet zamiast pełnego transcriptu;
- coordinator kompresuje CLOSED atom do krótkiego summary;
- auto-compaction threshold obniżony z 65% do 60%, preserve recent zwiększone do 8000 tokenów.
