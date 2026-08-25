# Changelog V4.5 Tri-Tier Parallel

Względem V4.3/V4.2:
- Sol/Codex pozostaje strict orchestrator only;
- dodany Qwen3-Coder 30B-A3B jako HEAVY EXCLUSIVE 128K;
- Qwen3 14B przejmuje medium diagnosis/architecture/worker/review, 64K;
- Qwen3.5 9B wykonuje execution/quick-fix/checkpoint, 64K;
- jawny scheduler FAST_PARALLEL: jeden 14B + jeden 9B, dwa Task calls przed wait;
- 30B nigdy nie działa równolegle z fast lane;
- installer zachowuje CURRENT_WORK i research checkpoints;
- OpenAI-compatible Ollama używa model-specific aliases z Modelfile num_ctx.
