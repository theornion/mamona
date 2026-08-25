# P10 — Canonical VisualPlan normalizer

Status: COMPLETE (local only)

- Canonical persisted P02 VisualPlan is the source of truth for draft slot identity and policy.
- Missing legacy fields (`slot_id`, role/section identity, direct/related policy and query contracts) are restored only after an exact canonical match.
- Explicit policy divergence is `visual_plan_policy_conflict`; ambiguous mapping is `visual_plan_slot_mapping_ambiguous`.
- Validation: PHP lint and `CMS_ALLOW_DRAFT_VISUAL_PLAN_SCHEMA_SMOKE=1 php tests/draft-visual-plan-schema-smoke.php` PASS.
- No live Gemini/Vision call and no change to post #287.
- Next: resume #287 only after user approval.
