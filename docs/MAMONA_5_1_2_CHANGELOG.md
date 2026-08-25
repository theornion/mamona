# Mamona 5.1.2 — V4.5 Base Restoration + Anti-Loop

## Co naprawia

5.1 i 5.1.1 odeszły od architektury V4/V4.5 przez ponowne wprowadzenie `mamona-orchestrator` jako dispatcher-only oraz dodatkowego `mamona-heavy-auditor`. 5.1.2 cofa tę regresję i dokłada tylko mechanizmy anti-loop.

## Przywrócone z V4/V4.5

- główny agent: `mamona-coordinator` (`mode: primary`);
- coordinator jest pełnonarzędziowym primary i może robić małe, dokładne fixy;
- płaska pętla `Coordinator -> Executor -> Diagnoser -> Worker -> Executor -> phase gate`;
- reviewer i checkpoint tylko na granicy fazy;
- 14B jest podstawową warstwą reasoning;
- 9B wykonuje konkretne testy i checkpointy;
- 30B jest ciężkim coderem i działa solo;
- używane są runtime aliasy V4.5: `mamona-qwen14-64k`, `mamona-qwen9-64k`, `mamona-coder30-128k`;
- auto-compaction 65%.

## Dodane z 5.1

- maksymalnie 2 faktyczne próby na ACTIVE_ATOM;
- fingerprint próby;
- `NO_FINDING` jako poprawny wynik terminalny;
- brak Attempt 3;
- brak automatycznej eskalacji do 30B;
- loop guard po dwóch krokach bez nowego evidence;
- brak broad recovery po braku Result JSON;
- każdy child ma `task: deny`;
- permission/registry prelaunch failure nie zużywa attemptu.

## Usunięte z aktywnego registry

Installer backupuje, a następnie usuwa jeśli istnieją:

- `mamona-orchestrator`
- `mamona-heavy-auditor`
- `mamona-quick-worker`
- `mamona-tester`
- `mamona-coder`
- `repo-scout`
- `quick-maintainer`

Dane i kod aplikacji nie są dotykane.
