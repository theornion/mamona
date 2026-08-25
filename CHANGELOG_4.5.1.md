# Changelog 4.5.1

## Permission hotfix only

1. `mamona-coordinator`
   - `edit: allow`;
   - `task: allow`;
   - safe direct Git/PHP execution;
   - XAMPP PHP external-directory allow;
   - destructive Git operations remain denied.

2. `mamona-executor`
   - XAMPP PHP external-directory allow;
   - only exact PHP/test/lint + git status/diff execution;
   - no read/research/edit/task.

3. `.kilo/kilo.jsonc`
   - project `external_directory` changed from a hard ceiling to `ask`, with explicit `C:/xampp/php/*: allow`;
   - Bash remains `ask` globally; agent-level safe allowlists decide unattended commands;
   - V4.5 model aliases and 65% compaction retained.

4. No 5.1.x attempt-ledger or orchestrator rename.
