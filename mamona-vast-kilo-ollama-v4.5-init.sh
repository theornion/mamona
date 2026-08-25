#!/usr/bin/env bash
set -Eeuo pipefail

# MAMONA V4.5 / Vast.ai / Kilo / RTX 3090 24GB
#
# Base models:
#   qwen3-coder:30b  -> heavy exclusive
#   qwen3:14b        -> medium lane
#   qwen3.5:9b       -> fast lane
#   nomic-embed-text -> Kilo indexing
#
# Runtime aliases (model-specific context for OpenAI-compatible /v1):
#   mamona-coder30-128k -> 131072
#   mamona-qwen14-64k   -> 65536
#   mamona-qwen9-64k    -> 65536
#
# Fast mode: 14B + 9B may coexist if VRAM allows.
# Heavy mode: coordinator must run 30B alone.

BASE_DIR="${MAMONA_LLM_DIR:-/workspace/mamona-llm}"
OLLAMA_STORE="${OLLAMA_MODELS:-$BASE_DIR/ollama-models}"
LOG_DIR="$BASE_DIR/logs"
OLLAMA_LOG="$LOG_DIR/ollama.log"
SETUP_LOG="$LOG_DIR/setup-v45.log"
START_SCRIPT="$BASE_DIR/start-ollama-v45.sh"
PID_FILE="$BASE_DIR/ollama.pid"
MODELFILE_DIR="$BASE_DIR/modelfiles"

OLLAMA_PORT="${OLLAMA_PORT:-11435}"
PULL_RETRIES="${PULL_RETRIES:-3}"
SKIP_SMOKE_TESTS="${SKIP_SMOKE_TESTS:-0}"
RUN_VRAM_CHECK="${RUN_VRAM_CHECK:-1}"

HEAVY_BASE="${HEAVY_BASE:-qwen3-coder:30b}"
MEDIUM_BASE="${MEDIUM_BASE:-qwen3:14b}"
FAST_BASE="${FAST_BASE:-qwen3.5:9b}"
EMBED_MODEL="${EMBED_MODEL:-nomic-embed-text}"

HEAVY_ALIAS="${HEAVY_ALIAS:-mamona-coder30-128k}"
MEDIUM_ALIAS="${MEDIUM_ALIAS:-mamona-qwen14-64k}"
FAST_ALIAS="${FAST_ALIAS:-mamona-qwen9-64k}"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

mkdir -p "$BASE_DIR" "$OLLAMA_STORE" "$LOG_DIR" "$MODELFILE_DIR"
exec > >(tee -a "$SETUP_LOG") 2>&1
trap 'rc=$?; printf "\nERROR: setup failed on line %s with exit code %s\n" "$LINENO" "$rc" >&2; exit "$rc"' ERR

install_base_packages() {
  command -v apt-get >/dev/null 2>&1 || die "apt-get unavailable"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl procps jq
}

for cmd in curl pkill nvidia-smi jq; do
  command -v "$cmd" >/dev/null 2>&1 || install_base_packages
done

info "GPU"
nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader || true

if ! command -v ollama >/dev/null 2>&1; then
  info "Installing Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
else
  info "Ollama present: $(ollama --version 2>/dev/null || true)"
fi

# Stop all previous Ollama launch modes.
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

cat > "$START_SCRIPT" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
export OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
export OLLAMA_MODELS="${OLLAMA_STORE}"
export OLLAMA_FLASH_ATTENTION="1"
export OLLAMA_KV_CACHE_TYPE="q8_0"
export OLLAMA_MAX_LOADED_MODELS="2"
export OLLAMA_NUM_PARALLEL="1"
export OLLAMA_MAX_QUEUE="16"
export OLLAMA_KEEP_ALIVE="5m"
exec ollama serve
EOF
chmod +x "$START_SCRIPT"

export OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
export OLLAMA_MODELS="$OLLAMA_STORE"

info "Starting Ollama at ${OLLAMA_HOST}"
nohup "$START_SCRIPT" >"$OLLAMA_LOG" 2>&1 &
echo $! > "$PID_FILE"

for _ in $(seq 1 120); do
  curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null 2>&1 && break
  sleep 1
done
curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null || {
  tail -n 200 "$OLLAMA_LOG" >&2 || true
  die "Ollama API did not start"
}

pull_model() {
  local model="$1"
  local attempt
  if ollama show "$model" >/dev/null 2>&1; then
    info "Already installed: $model"
    return
  fi
  for attempt in $(seq 1 "$PULL_RETRIES"); do
    info "ollama pull $model — attempt $attempt/$PULL_RETRIES"
    if ollama pull "$model"; then
      ollama show "$model" >/dev/null
      return
    fi
    warn "Pull interrupted; retry will resume partial download"
    sleep $((attempt * 5))
  done
  die "Could not download $model"
}

pull_model "$FAST_BASE"
pull_model "$MEDIUM_BASE"
pull_model "$HEAVY_BASE"
pull_model "$EMBED_MODEL"

# OpenAI-compatible Ollama cannot set context size per request, so create model aliases.
cat > "$MODELFILE_DIR/Modelfile.heavy" <<EOF
FROM ${HEAVY_BASE}
PARAMETER num_ctx 131072
EOF

cat > "$MODELFILE_DIR/Modelfile.medium" <<EOF
FROM ${MEDIUM_BASE}
PARAMETER num_ctx 65536
EOF

cat > "$MODELFILE_DIR/Modelfile.fast" <<EOF
FROM ${FAST_BASE}
PARAMETER num_ctx 65536
EOF

info "Creating runtime aliases with fixed context windows"
ollama create "$HEAVY_ALIAS" -f "$MODELFILE_DIR/Modelfile.heavy"
ollama create "$MEDIUM_ALIAS" -f "$MODELFILE_DIR/Modelfile.medium"
ollama create "$FAST_ALIAS" -f "$MODELFILE_DIR/Modelfile.fast"

info "Installed models / aliases"
ollama list

api_generate() {
  local model="$1"
  local prompt="$2"
  local keep_alive="${3:-5m}"
  curl -fsS "http://${OLLAMA_HOST}/api/generate" \
    -H 'Content-Type: application/json' \
    -d "$(jq -cn --arg m "$model" --arg p "$prompt" --arg k "$keep_alive" '{model:$m,prompt:$p,stream:false,keep_alive:$k,options:{temperature:0,num_predict:8}}')"
}

if [[ "$SKIP_SMOKE_TESTS" != "1" ]]; then
  info "Smoke: 9B alias"
  api_generate "$FAST_ALIAS" 'Odpowiedz dokładnie: OK' '5m' | jq -r '.response // .error'

  info "Smoke: 14B alias"
  api_generate "$MEDIUM_ALIAS" 'Odpowiedz dokładnie: OK' '5m' | jq -r '.response // .error'

  if [[ "$RUN_VRAM_CHECK" == "1" ]]; then
    info "FAST MODE residency check: 14B + 9B should both remain loaded"
    ollama ps || true
    cat <<'CHECK'
Interpretation:
- ideal: both mamona-qwen14-64k and mamona-qwen9-64k show 100% GPU;
- if either shows CPU/GPU split or second model will not stay loaded, fast parallel is not safe at these contexts on this GPU.
CHECK

    info "Preparing HEAVY EXCLUSIVE check: unload fast models first"
    ollama stop "$MEDIUM_ALIAS" >/dev/null 2>&1 || true
    ollama stop "$FAST_ALIAS" >/dev/null 2>&1 || true

    info "Smoke: 30B heavy alias @ 128K"
    api_generate "$HEAVY_ALIAS" 'Odpowiedz dokładnie: OK' '5m' | jq -r '.response // .error'
    ollama ps || true
    cat <<'CHECK'
Interpretation:
- ideal: mamona-coder30-128k shows 100% GPU;
- if it shows CPU/GPU split, 128K is configured correctly but is too memory-heavy for full-GPU execution on this instance. Do not silently lower context; decide after benchmark.
CHECK

    # Leave the fast model warm for Kilo startup.
    ollama stop "$HEAVY_ALIAS" >/dev/null 2>&1 || true
    api_generate "$FAST_ALIAS" 'OK' '5m' >/dev/null || true
  fi
fi

info "Final Ollama status"
ollama ps || true

cat <<EOF

MAMONA V4.5 SERVER READY

Ollama:
  http://127.0.0.1:${OLLAMA_PORT}

Kilo OpenAI-compatible Base URL through tunnel:
  http://127.0.0.1:11436/v1

Runtime models:
  ${HEAVY_ALIAS}  -> ${HEAVY_BASE}  -> 128K (HEAVY EXCLUSIVE)
  ${MEDIUM_ALIAS} -> ${MEDIUM_BASE} -> 64K  (FAST lane 14B)
  ${FAST_ALIAS}   -> ${FAST_BASE}   -> 64K  (FAST lane 9B)
  ${EMBED_MODEL}  -> indexing

Server concurrency:
  OLLAMA_MAX_LOADED_MODELS=2
  OLLAMA_NUM_PARALLEL=1
  OLLAMA_FLASH_ATTENTION=1
  OLLAMA_KV_CACHE_TYPE=q8_0

Windows tunnel template:
  ssh -N -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \\
    -L 8080:127.0.0.1:8080 \\
    -L 11436:127.0.0.1:${OLLAMA_PORT} \\
    -p <SSH_PORT> root@<VAST_IP>

IMPORTANT:
  30B is scheduler-exclusive by policy.
  14B + 9B parallel is allowed only after the residency check confirms the setup is viable.
EOF
