# P10 — Minimal VisualPlan slot_id fix

Status: COMPLETE (local fix only)

- Root cause: a legacy-shaped draft hero could omit `slot_id` although the persisted canonical VisualPlan identified the one required hero slot.
- Fix: `article_draft_normalize_narrative_visual_slot_identity()` restores only a missing identity selected by exactly one canonical `(role, section_id)` match; ambiguity remains a deterministic validation failure.
- Scope: `php/article-draft-service.php`, `tests/draft-visual-plan-schema-smoke.php`.
- Validation: PHP lint and `CMS_ALLOW_DRAFT_VISUAL_PLAN_SCHEMA_SMOKE=1 php tests/draft-visual-plan-schema-smoke.php` PASS.
- Live Gemini/Vision calls: 0. Post #287 was not resumed or modified.
- Next: manual approval before any live resume of #287.
