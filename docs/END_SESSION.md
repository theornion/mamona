# End-of-session checklist

Use this checklist before closing Roo Code or destroying the Vast.ai instance.

## 1. Stabilize the local repository

- [ ] Stop creating new changes.
- [ ] Run `git status`.
- [ ] Review `git diff` and `git diff --staged`.
- [ ] Confirm every changed file belongs to the requested task.
- [ ] Remove debug output, temporary files and abandoned experiments.
- [ ] Confirm no secret, token, credential, production database, session, lock or log was added.

## 2. Validate

- [ ] Run PHP syntax checks for changed PHP files, or all PHP files when appropriate.
- [ ] Run the closest targeted smoke/regression test.
- [ ] Run the full editorial pipeline E2E only when justified.
- [ ] Record the exact commands and results in `docs/CURRENT_WORK.md`.
- [ ] Record any skipped test and the reason.

## 3. Preserve context for the next agent

Update `docs/CURRENT_WORK.md` so it contains:

- [ ] the current goal;
- [ ] acceptance criteria;
- [ ] completed work;
- [ ] remaining work;
- [ ] relevant files;
- [ ] blockers and uncertainties;
- [ ] latest validation results;
- [ ] the single best next action.

Update `docs/PROJECT_CONTEXT.md` only when a durable architectural, security or operational decision changed.

## 4. Save work

- [ ] Ask the user before committing if commit permission was not already given.
- [ ] Use a focused commit message.
- [ ] Push only after confirming the correct branch and remote.
- [ ] Confirm `git status` is clean or deliberately document remaining local changes.

Suggested local checks:

```powershell
git status
git diff --stat
git log --oneline -5
```

## 5. Close the rented model server

The repository stays on the local computer. The Vast.ai machine contains only Ollama and model files.

- [ ] Finish all active Roo requests.
- [ ] Check loaded models if needed with `ollama ps` over the SSH session.
- [ ] Close the PowerShell window running the SSH tunnel.
- [ ] In Vast.ai, choose **Destroy**, not only Stop, when the session should be fully removed.
- [ ] Confirm the instance disappeared from the active instances list.

Destroying the instance deletes the rented machine’s temporary model files. It does not delete the local repository or its Git history.

## 6. Final handoff message

The agent should end with a compact report:

```text
Completed:
Changed files:
Validation:
Remaining:
Next action:
```
