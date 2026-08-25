# MVP Pipeline — Complete

Status: NOT COMPLETE — mock/disposable-DB contract passes; real orchestration proof remains open.

The implementation preserves the non-public safety boundary: a package can only reach `ready_for_manual_publish`; a human must explicitly choose `published`.

Verified scope:

- P01–P09 have focused mock/disposable-DB evidence.
- P10 component and regression suites establish the intended contract.
- No test in this checkpoint made a live Gemini request.

Why this checkpoint is not completion evidence:

- Provider connectivity was restored, but real post #121 spent its six remaining calls (#15–#20) in the legacy direct Vision waterfall for `lead`; all were rejected. This exhausted the budget from `14/20` to `20/20` before P06 planner transport.
- P06 operations #150/#156 have zero live provider requests and were terminalized as `failed`; #121 is `manual_review` with 2/4 coverage, not a valid P06–P09 proof.
- The prevention fix now reserves closure calls before direct Vision and terminalizes admission-gated operations. It is covered by targeted smoke tests but still needs a fresh real orchestration proof.

Required before changing this checkpoint to COMPLETE:

1. use a fresh eligible article with sufficient untouched article budget;
2. run the controlled P06 path without allowing direct Vision to consume reserved P06–P09 calls;
3. verify persisted NarrativePlan, bounded Vision audit records, coverage gate, final QC and preview;
4. keep publication manual.

Canonical active state: `docs/CURRENT_WORK.md`.
