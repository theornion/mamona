# V4.6.3 package notes

Base: V4.6.1 Tri-Tier Parallel Schema Hotfix.

## Delegation Ceiling
- `mamona-coordinator` is zero-direct-write by contract, but keeps technical edit/write/bash ALLOW as a delegation ceiling because Kilo propagates parent restrictions to child sessions.
- Coordinator shell is allowlisted for deterministic git inspection and PHP test/lint only.
- Source/test/config writes route to `mamona-quick-worker`, `mamona-worker`, or `mamona-heavy-coder`.
- CURRENT_WORK/checkpoint writes route to `checkpoint-writer`.
- Executor tool/runtime/empty-output failure falls back to the same deterministic command on primary, never to a writer proxy.

## Unchanged
- Task-only child delegation.
- max one 14B + one 9B in parallel.
- 30B exclusive.
- model aliases, Ollama tunnel, indexing config and server init.
- no 5.1.x agents/contracts.
