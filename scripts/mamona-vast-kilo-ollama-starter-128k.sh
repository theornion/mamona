#!/usr/bin/env bash
set -Eeuo pipefail

# Mamona / Vast.ai / Kilo starter
#
# Chat models:
#   - qwen3.6:27b -> orchestrator, architect, coder, tester, reviewer
#   - qwen3.5:9b  -> repo-scout, quick-maintainer and fast subtasks
#
# Indexing model:
#   - nomic-embed-text -> local codebase embeddings for Kilo
#
# All models are installed directly through `ollama pull`.
# No Hugging Face token, GGUF download or `ollama create` import is used.

PRIMARY_MODEL="${PRIMARY_MODEL:-qwen3.6:27b}"
FAST_MODEL="${FAST_MODEL:-qwen3.5:9b}"
EMBEDDING_MODEL="${EMBEDDING_MODEL:-nomic-embed-text}"

BASE_DIR="${MAMONA_LLM_DIR:-/workspace/mamona-llm}"
OLLAMA_STORE="${OLLAMA_MODELS:-$BASE_DIR/ollama-models}"
LOG_DIR="$BASE_DIR/logs"
OLLAMA_LOG="$LOG_DIR/ollama.log"
SETUP_LOG="$LOG_DIR/setup.log"
START_SCRIPT="$BASE_DIR/start-ollama.sh"
PID_FILE="$BASE_DIR/ollama.pid"

OLLAMA_PORT="${OLLAMA_PORT:-11435}"

# Default server-side context for agentic work.
# Kilo declares 65,536 for qwen3.6:27b and 32,768 for qwen3.5:9b.
# Individual requests may still send a smaller num_ctx.
CONTEXT_LENGTH="${OLLAMA_CONTEXT_LENGTH:-131072}"

# Quantized KV cache lowers VRAM use at longer context lengths.
KV_CACHE_TYPE="${OLLAMA_KV_CACHE_TYPE:-q8_0}"

PULL_RETRIES="${PULL_RETRIES:-3}"
SKIP_SMOKE_TESTS="${SKIP_SMOKE_TESTS:-0}"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

mkdir -p "$BASE_DIR" "$OLLAMA_STORE" "$LOG_DIR"
exec > >(tee -a "$SETUP_LOG") 2>&1
trap 'rc=$?; printf "\nERROR: setup failed on line %s with exit code %s\n" "$LINENO" "$rc" >&2; exit "$rc"' ERR

install_base_packages() {
  command -v apt-get >/dev/null 2>&1 || die "apt-get is unavailable."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl procps
}

for command_name in curl pkill nvidia-smi; do
  command -v "$command_name" >/dev/null 2>&1 || install_base_packages
done

info "Detected GPU"
nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader

info "Removing leftovers from obsolete Hugging Face/GGUF setups"
rm -rf \
  "$BASE_DIR/gguf-downloads" \
  "$BASE_DIR/huggingface" \
  "$BASE_DIR/hf-venv"
rm -f "$BASE_DIR"/Modelfile.*

if ! command -v ollama >/dev/null 2>&1; then
  info "Installing Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
else
  info "Ollama already installed: $(ollama --version 2>/dev/null || true)"
fi

info "Stopping an existing Ollama server"
if command -v systemctl >/dev/null 2>&1; then
  systemctl stop ollama.service >/dev/null 2>&1 || true
fi

if [[ -f "$PID_FILE" ]]; then
  old_pid="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "${old_pid:-}" ]] && kill -0 "$old_pid" 2>/dev/null; then
    kill "$old_pid" >/dev/null 2>&1 || true
  fi
fi

pkill -f '[o]llama serve' >/dev/null 2>&1 || true

for _ in $(seq 1 30); do
  if ! pgrep -f '[o]llama serve' >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

cat > "$START_SCRIPT" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail

export OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
export OLLAMA_MODELS="${OLLAMA_STORE}"
export OLLAMA_CONTEXT_LENGTH="${CONTEXT_LENGTH}"
export OLLAMA_FLASH_ATTENTION="1"
export OLLAMA_KV_CACHE_TYPE="${KV_CACHE_TYPE}"
export OLLAMA_MAX_LOADED_MODELS="1"
export OLLAMA_NUM_PARALLEL="1"
export OLLAMA_MAX_QUEUE="8"
export OLLAMA_KEEP_ALIVE="5m"

exec ollama serve
EOF
chmod +x "$START_SCRIPT"

export OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
export OLLAMA_MODELS="$OLLAMA_STORE"

info "Starting Ollama on ${OLLAMA_HOST} with default context ${CONTEXT_LENGTH}"
nohup "$START_SCRIPT" >"$OLLAMA_LOG" 2>&1 &
echo $! > "$PID_FILE"

for _ in $(seq 1 120); do
  if curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null \
  || { tail -n 200 "$OLLAMA_LOG" >&2 || true; die "Ollama API did not start."; }

ollama prune >/dev/null 2>&1 || true

pull_model() {
  local model="$1"
  local attempt

  if ollama show "$model" >/dev/null 2>&1; then
    info "Model already installed: $model"
    return
  fi

  for attempt in $(seq 1 "$PULL_RETRIES"); do
    info "ollama pull $model — attempt $attempt/$PULL_RETRIES"
    if ollama pull "$model"; then
      ollama show "$model" >/dev/null
      return
    fi
    warn "Pull interrupted. Ollama will resume the partial download."
    sleep $((attempt * 5))
  done

  die "Could not download $model."
}

pull_model "$PRIMARY_MODEL"
pull_model "$FAST_MODEL"
pull_model "$EMBEDDING_MODEL"

info "Installed models"
ollama list

if [[ "$SKIP_SMOKE_TESTS" != "1" ]]; then
  info "Chat smoke test: $FAST_MODEL"
  curl -fsS "http://${OLLAMA_HOST}/api/generate" \
    -H "Content-Type: application/json" \
    -d "{\"model\":\"${FAST_MODEL}\",\"prompt\":\"Odpowiedz dokładnie: OK\",\"stream\":false,\"think\":false,\"keep_alive\":\"0\",\"options\":{\"num_ctx\":4096,\"num_predict\":16,\"temperature\":0}}" \
    | head -c 500
  printf '\n'

  info "Embedding smoke test: $EMBEDDING_MODEL"
  curl -fsS "http://${OLLAMA_HOST}/api/embed" \
    -H "Content-Type: application/json" \
    -d "{\"model\":\"${EMBEDDING_MODEL}\",\"input\":\"Mamona indexing test\",\"keep_alive\":\"0\"}" \
    | head -c 300
  printf '\n'
fi

cat <<EOF

Setup complete.

Ollama server:
  http://127.0.0.1:${OLLAMA_PORT}

Server default context:
  ${CONTEXT_LENGTH}

Models:
  ${PRIMARY_MODEL}
  ${FAST_MODEL}
  ${EMBEDDING_MODEL}

Windows SSH tunnel:
  ssh -N -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
    -L 11436:127.0.0.1:${OLLAMA_PORT} \
    -p <SSH_PORT> root@<VAST_IP>

Kilo chat Base URL:
  http://127.0.0.1:11436/v1

Kilo indexing Base URL:
  http://127.0.0.1:11436
EOF
