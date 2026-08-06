#!/usr/bin/env bash
set -Eeuo pipefail

# Mamona / Vast.ai / Ollama starter for Codex App
#
# Default stack:
#   - gpt-oss:20b       -> primary model for Codex App / agent work / reasoning
#   - qwen3-coder:30b   -> secondary coding model for comparison or other clients
#
# What this script does:
#   1. Installs the latest Ollama on a fresh Vast.ai Ubuntu/PyTorch instance.
#   2. Runs Ollama only on 127.0.0.1:11434.
#   3. Configures a 64k context window, Flash Attention and q8_0 KV cache.
#   4. Downloads the selected models directly with `ollama pull`.
#   5. Verifies gpt-oss through the OpenAI-compatible Responses API used by Codex.
#   6. Verifies the secondary model through the native Ollama API.
#
# No Hugging Face token is required.
# The project repository is NOT copied to the server.
#
# Optional Vast environment variables:
#   PRIMARY_MODEL=gpt-oss:20b
#   SECONDARY_MODEL=qwen3-coder:30b   # set to an empty string to skip it
#   MAMONA_LLM_DIR=/workspace/mamona-llm
#   OLLAMA_CONTEXT_LENGTH=65536
#   OLLAMA_KV_CACHE_TYPE=q8_0
#   OLLAMA_KEEP_ALIVE=5m
#   MIN_FREE_GIB=auto                 # or set an explicit integer
#   PULL_RETRIES=3
#   SKIP_SMOKE_TESTS=0
#   CLEAN_LEGACY_HF=1

PRIMARY_MODEL="${PRIMARY_MODEL:-${1:-gpt-oss:20b}}"
SECONDARY_MODEL="${SECONDARY_MODEL-${2:-qwen3-coder:30b}}"

BASE_DIR="${MAMONA_LLM_DIR:-/workspace/mamona-llm}"
OLLAMA_STORE="${OLLAMA_MODELS:-$BASE_DIR/ollama-models}"
LOG_DIR="$BASE_DIR/logs"
OLLAMA_LOG="$LOG_DIR/ollama.log"
SETUP_LOG="$LOG_DIR/setup.log"
PID_FILE="$BASE_DIR/ollama.pid"
START_SCRIPT="$BASE_DIR/start-ollama.sh"

CONTEXT_LENGTH="${OLLAMA_CONTEXT_LENGTH:-65536}"
KV_CACHE_TYPE="${OLLAMA_KV_CACHE_TYPE:-q8_0}"
KEEP_ALIVE="${OLLAMA_KEEP_ALIVE:-5m}"
MIN_FREE_GIB="${MIN_FREE_GIB:-auto}"
PULL_RETRIES="${PULL_RETRIES:-3}"
SKIP_SMOKE_TESTS="${SKIP_SMOKE_TESTS:-0}"
CLEAN_LEGACY_HF="${CLEAN_LEGACY_HF:-1}"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

mkdir -p "$BASE_DIR" "$OLLAMA_STORE" "$LOG_DIR"
exec > >(tee -a "$SETUP_LOG") 2>&1
trap 'rc=$?; printf "\nERROR: setup failed on line %s with exit code %s\n" "$LINENO" "$rc" >&2; exit "$rc"' ERR

install_base_packages() {
  command -v apt-get >/dev/null 2>&1 || die "apt-get is unavailable. Use a Vast Ubuntu/PyTorch template."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl procps python3
}

for required in curl python3 pkill; do
  if ! command -v "$required" >/dev/null 2>&1; then
    info "Installing base packages"
    install_base_packages
    break
  fi
done

command -v nvidia-smi >/dev/null 2>&1 || die "nvidia-smi was not found. This does not look like a CUDA GPU instance."

info "Detected GPU"
nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader

GPU_MEMORY_MIB="$(nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d ' ')"
if [[ "$GPU_MEMORY_MIB" =~ ^[0-9]+$ ]] && (( GPU_MEMORY_MIB < 22000 )); then
  warn "Detected ${GPU_MEMORY_MIB} MiB VRAM. This setup is intended for a 24 GB-class GPU."
fi

if [[ "$CONTEXT_LENGTH" =~ ^[0-9]+$ ]] && (( CONTEXT_LENGTH < 64000 )); then
  warn "OLLAMA_CONTEXT_LENGTH is $CONTEXT_LENGTH. Agentic coding works better at 64000+ tokens."
fi

# Remove leftovers created by the previous Hugging Face/GGUF-based starter.
# This does not delete the Ollama model store.
if [[ "$CLEAN_LEGACY_HF" == "1" ]]; then
  info "Cleaning legacy Hugging Face/GGUF starter files"
  rm -rf \
    "$BASE_DIR/gguf-downloads" \
    "$BASE_DIR/huggingface" \
    "$BASE_DIR/hf-venv"
  rm -f "$BASE_DIR"/Modelfile.*
fi

show_disk_space() {
  info "Disk space"
  df -h "$BASE_DIR" | sed -n '1,2p'
}

free_gib() {
  df -Pk "$BASE_DIR" | awk 'NR==2 {printf "%d", $4 / 1024 / 1024}'
}

model_exists() {
  ollama show "$1" >/dev/null 2>&1
}

stop_ollama() {
  if command -v systemctl >/dev/null 2>&1; then
    systemctl stop ollama.service >/dev/null 2>&1 || true
  fi

  if [[ -f "$PID_FILE" ]]; then
    local old_pid
    old_pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ -n "$old_pid" ]] && kill -0 "$old_pid" 2>/dev/null; then
      kill "$old_pid" >/dev/null 2>&1 || true
      sleep 1
    fi
  fi

  pkill -f '[o]llama serve' >/dev/null 2>&1 || true
}

wait_for_ollama() {
  for _ in $(seq 1 120); do
    if curl -fsS http://127.0.0.1:11434/api/version >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  tail -n 200 "$OLLAMA_LOG" >&2 || true
  die "Ollama API did not start."
}

pull_model() {
  local role="$1"
  local model="$2"
  local attempt

  [[ -n "$model" ]] || return 0

  if model_exists "$model"; then
    info "$role model already exists: $model"
    return 0
  fi

  for attempt in $(seq 1 "$PULL_RETRIES"); do
    show_disk_space
    info "Downloading $role model with Ollama: $model (attempt $attempt/$PULL_RETRIES)"

    if ollama pull "$model"; then
      ollama show "$model" >/dev/null
      info "Downloaded successfully: $model"
      return 0
    fi

    warn "Pull failed for $model on attempt $attempt. Ollama can resume partial downloads."
    sleep $((attempt * 5))
  done

  die "Unable to download $model after $PULL_RETRIES attempts."
}

if ! command -v ollama >/dev/null 2>&1; then
  info "Installing the latest Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
else
  info "Ollama is already installed: $(ollama --version 2>/dev/null || true)"
fi

stop_ollama

cat > "$START_SCRIPT" <<EOF_START
#!/usr/bin/env bash
set -Eeuo pipefail
export OLLAMA_HOST="127.0.0.1:11434"
export OLLAMA_MODELS="$OLLAMA_STORE"
export OLLAMA_CONTEXT_LENGTH="$CONTEXT_LENGTH"
export OLLAMA_FLASH_ATTENTION="1"
export OLLAMA_KV_CACHE_TYPE="$KV_CACHE_TYPE"
export OLLAMA_MAX_LOADED_MODELS="1"
export OLLAMA_NUM_PARALLEL="1"
export OLLAMA_MAX_QUEUE="8"
export OLLAMA_KEEP_ALIVE="$KEEP_ALIVE"
exec ollama serve
EOF_START
chmod +x "$START_SCRIPT"

export OLLAMA_HOST="127.0.0.1:11434"
export OLLAMA_MODELS="$OLLAMA_STORE"

info "Starting localhost-only Ollama server"
nohup "$START_SCRIPT" >"$OLLAMA_LOG" 2>&1 &
echo $! > "$PID_FILE"
wait_for_ollama

info "Ollama version"
curl -fsS http://127.0.0.1:11434/api/version
printf '\n'

show_disk_space
CURRENT_FREE_GIB="$(free_gib)"

if [[ "$MIN_FREE_GIB" == "auto" ]]; then
  REQUIRED_FREE_GIB=2
  model_exists "$PRIMARY_MODEL" || REQUIRED_FREE_GIB=$((REQUIRED_FREE_GIB + 16))
  if [[ -n "$SECONDARY_MODEL" ]]; then
    model_exists "$SECONDARY_MODEL" || REQUIRED_FREE_GIB=$((REQUIRED_FREE_GIB + 22))
  fi
else
  [[ "$MIN_FREE_GIB" =~ ^[0-9]+$ ]] || die "MIN_FREE_GIB must be 'auto' or an integer."
  REQUIRED_FREE_GIB="$MIN_FREE_GIB"
fi

if [[ "$CURRENT_FREE_GIB" =~ ^[0-9]+$ ]] && (( CURRENT_FREE_GIB < REQUIRED_FREE_GIB )); then
  die "Only ${CURRENT_FREE_GIB} GiB is free; approximately ${REQUIRED_FREE_GIB} GiB is required for the missing models. Increase the Vast disk or set SECONDARY_MODEL='' to install only gpt-oss."
fi

# Pull the Codex-compatible reasoning model first, then the optional coding model.
pull_model "primary Codex/reasoning" "$PRIMARY_MODEL"
pull_model "secondary coding" "$SECONDARY_MODEL"

info "Installed models"
ollama list

smoke_test_responses_api() {
  local model="$1"
  info "Testing OpenAI Responses API for Codex compatibility: $model"

  python3 - "$model" <<'PY'
import json
import sys
import urllib.request

model = sys.argv[1]
payload = json.dumps({
    "model": model,
    "input": "Reply with exactly: OK",
    "stream": False,
    "max_output_tokens": 256,
}).encode("utf-8")

request = urllib.request.Request(
    "http://127.0.0.1:11434/v1/responses",
    data=payload,
    headers={"Content-Type": "application/json", "Authorization": "Bearer ollama"},
    method="POST",
)

with urllib.request.urlopen(request, timeout=1800) as response:
    result = json.load(response)

texts = []
for item in result.get("output", []):
    for content in item.get("content", []):
        text = content.get("text")
        if text:
            texts.append(text)

output = " ".join(texts).strip()
print(f"Response: {output[:300] or '[no text extracted]'}")
if not result.get("id"):
    raise SystemExit("Responses API returned no response id")
PY
}

smoke_test_native_api() {
  local model="$1"
  [[ -n "$model" ]] || return 0
  info "Testing native Ollama API: $model"

  python3 - "$model" <<'PY'
import json
import sys
import urllib.request

model = sys.argv[1]
payload = json.dumps({
    "model": model,
    "prompt": "Reply with exactly: OK",
    "stream": False,
    "keep_alive": "0",
    "options": {"num_ctx": 4096, "temperature": 0},
}).encode("utf-8")

request = urllib.request.Request(
    "http://127.0.0.1:11434/api/generate",
    data=payload,
    headers={"Content-Type": "application/json"},
    method="POST",
)

with urllib.request.urlopen(request, timeout=1800) as response:
    result = json.load(response)

text = (result.get("response") or "").strip()
print(f"Response: {text[:300]}")
print(f"Prompt tokens: {result.get('prompt_eval_count', 'n/a')}")
print(f"Output tokens: {result.get('eval_count', 'n/a')}")
if not text:
    raise SystemExit("Model returned an empty response")
PY
}

if [[ "$SKIP_SMOKE_TESTS" != "1" ]]; then
  smoke_test_responses_api "$PRIMARY_MODEL"
  smoke_test_native_api "$SECONDARY_MODEL"
else
  warn "Smoke tests skipped because SKIP_SMOKE_TESTS=1"
fi

info "Final GPU and Ollama status"
nvidia-smi --query-gpu=name,memory.used,memory.total,utilization.gpu --format=csv,noheader
ollama ps || true
show_disk_space

cat <<EOF_DONE

Setup complete.

Ollama API on the Vast server:
  http://127.0.0.1:11434

Models:
  Primary for Codex App: $PRIMARY_MODEL
  Secondary coder:       ${SECONDARY_MODEL:-not installed}

Server settings:
  Context length: $CONTEXT_LENGTH
  Flash Attention: enabled
  KV cache: $KV_CACHE_TYPE
  Max loaded models: 1

From Windows, keep this SSH tunnel open:
  ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -N \
    -L 11434:127.0.0.1:11434 -p <SSH_PORT> root@<VAST_IP>

Then, in a second Windows PowerShell:
  ollama list
  ollama launch codex-app --model $PRIMARY_MODEL

For Codex CLI instead of the desktop app:
  codex --oss -m $PRIMARY_MODEL

Useful server commands:
  ollama list
  ollama ps
  tail -f "$OLLAMA_LOG"
  tail -f "$SETUP_LOG"
  "$START_SCRIPT"

To install only the primary Codex model on a smaller disk:
  SECONDARY_MODEL='' ./$(basename "$0")

The project repository was not copied to this server.
EOF_DONE
