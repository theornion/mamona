# Mamona 5.1.1 — Permission Hotfix

## Dlaczego powstał hotfix

W 5.1 orchestrator miał poprawny logicznie model bezpieczeństwa, ale zbyt restrykcyjne runtime permissions. W aktualnym Kilo restrykcje parent/caller mogą przechodzić do child sessions. Efekt: orchestrator był w stanie odczytać stan, ale `task:*` i część `bash` kończyły się `deny` zanim właściwy subagent wystartował.

## Zmiany 5.1.1

1. `mamona-orchestrator`: `task: allow` zamiast pattern allowlisty.
2. Logical Task allowlist pozostaje twardą instrukcją w promptcie orchestratora.
3. Orchestrator dostaje runtime capability ceiling dla read/edit/write/bash, żeby nie ograniczać legalnych child sessions.
4. Niebezpieczne komendy (`git reset/clean/restore/commit/push`, destructive delete) pozostają runtime-denied.
5. Każdy subagent nadal ma `task: deny`; dodatkowo `question/websearch/webfetch: deny`.
6. Read/search permissions są jawne na każdym subagencie.
7. Installer robi backup i usuwa znane legacy agenty V4.x, żeby szerokie `task: allow` koordynatora nie kierowało pracy do starego workflow.
8. Permission failure przed startem child session nie zużywa attempt budgetu.
9. Anti-loop, max 2 attempts i brak 30B audit fallback pozostają bez zmian.

## Modele

Bez zmian względem 5.1:
- heavy coder: `ollama/qwen3-coder:30b`;
- reasoning/audit/work: `ollama/qwen3:14b`;
- fast executor/mechanical: `ollama/qwen3.5:9b`.

Nie trzeba restartować Ollamy ani pobierać modeli ponownie.
