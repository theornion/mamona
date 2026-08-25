# CHANGELOG — 4.5.2

## Fixed

- project-level `bash:* deny` can no longer remain as the default ceiling in canonical `.kilo/kilo.jsonc`;
- project-level `edit`, `task` and `external_directory` are explicit capability ceilings instead of blockers;
- coordinator uses agent-level destructive-command denies while retaining deterministic PHP execution and editing;
- executor remains command-only with exact PHP/test allowlist;
- installer synchronizes active root Kilo config when present;
- legacy `.kilocode` project config files are backed up and disabled to remove stale permission injection;
- verifier now scans active project configs for stale `bash deny` rules;
- prompt stops immediately on a surviving `source: project / bash:* deny` instead of cycling through executor/worker/reviewer.

## Unchanged

- V4.5 roles and agent names;
- V4.5 model assignment;
- no 5.1.x attempt-ledger mechanics;
- no changes to PHP source, DB, Ollama or model files during installation.
