# Mamona V4.6.3 — Tri-Tier Parallel / Coordinator Separation

```text
                  COORDINATOR 14B
          DAG / evidence / tests / validation
                       |
        +--------------+--------------+
        |              |              |
     QUICK FIX      NORMAL FIX      HEAVY FIX
       9B              14B           30B SOLO
 quick-worker        worker       heavy-coder
        |              |              |
        +--------------+--------------+
                       |
             coordinator verifies
                       |
              executor/direct retest
```

## Routing
| Work | Agent | Tier |
|---|---|---|
| exact command/test/lint | mamona-executor | 9B |
| exact mechanical 1-file fix | mamona-quick-worker | 9B |
| one failure root cause | mamona-diagnoser | 14B |
| bounded contract/design | mamona-architect | 14B |
| normal bounded implementation | mamona-worker | 14B |
| bounded independent review | mamona-reviewer | 14B |
| repo-level/cross-cutting implementation | mamona-heavy-coder | 30B exclusive |
| CURRENT_WORK/checkpoint/handoff | checkpoint-writer | 9B |

## Coordinator contract
Coordinator nie jest coderem i kontraktowo nie zapisuje plików. Ma techniczne edit/write/bash ALLOW wyłącznie jako delegation ceiling dla child sessions; bezpośrednio używa tylko read/evidence/test commands.

## Parallel policy
Maks. jeden 14B + jeden 9B. Równoległość tylko przy jawnej niezależności i bez write/read-after-write kolizji. 30B zawsze exclusive.

## Runtime fallback
Jeżeli executor nie zwróci technicznego wyniku, coordinator wykonuje ten sam deterministic command sam. Nie odpala drugiego executora, nie używa workera jako terminal proxy i nie edytuje sam.

## Kilo runtime rule
Custom subagents wyłącznie przez `Task`. `agent_manager` jest wyłączony dla tej architektury.
