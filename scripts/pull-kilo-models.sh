#!/usr/bin/env bash
set -Eeuo pipefail

MAIN_MODEL="${MAIN_MODEL:-qwen3.6:27b}"
FAST_MODEL="${FAST_MODEL:-qwen3.5:9b}"
EMBED_MODEL="${EMBED_MODEL:-nomic-embed-text}"
REMOVE_LEGACY_MODELS="${REMOVE_LEGACY_MODELS:-0}"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

if [[ "$REMOVE_LEGACY_MODELS" == "1" ]]; then
  info "Removing legacy models when present"
  ollama rm qwen3-coder:30b >/dev/null 2>&1 || true
  ollama rm gpt-oss:20b >/dev/null 2>&1 || true
fi

for model in "$MAIN_MODEL" "$FAST_MODEL" "$EMBED_MODEL"; do
  info "Pulling $model"
  ollama pull "$model"
done

info "Installed models"
ollama list
