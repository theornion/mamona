#!/usr/bin/env bash
set -Eeuo pipefail

# Mamona Vast.ai setup
# Installs Ollama on a fresh Linux instance, keeps the API bound to localhost,
# downloads the selected models and configures one loaded model at a time.
# The project repository is NOT copied to the server.

CODER_MODEL="${1:-qwen3-coder:30b}"
FAST_MODEL="${2:-qwen3.5:9b}"
BASE_DIR="${MAMONA_LLM_DIR:-/workspace/mamona-llm}"
MODEL_DIR="${OLLAMA_MODELS:-$BASE_DIR/models}"
LOG_DIR="$BASE_DIR/logs"
OLLAMA_LOG="$LOG_DIR/ollama.log"
PID_FILE="$BASE_DIR/ollama.pid"
START_SCRIPT="$BASE_DIR/start-ollama.sh"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

command -v curl >/dev/null 2>&1 || die "curl is required. Use a Vast.ai PyTorch/Ubuntu template."
command -v nvidia-smi >/dev/null 2>&1 || die "nvidia-smi was not found. This does not look like a CUDA GPU instance."

info "Detected GPU"
nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader

GPU_MEMORY_MIB="$(nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d ' ')"
if [[ -n "$GPU_MEMORY_MIB" ]] && (( GPU_MEMORY_MIB < 22000 )); then
  warn "Detected ${GPU_MEMORY_MIB} MiB VRAM. qwen3-coder:30b is intended here for a 24 GB-class GPU."
fi

mkdir -p "$MODEL_DIR" "$LOG_DIR"

if ! command -v ollama >/dev/null 2>&1; then
  info "Installing Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
else
  info "Ollama is already installed: $(ollama --version 2>/dev/null || true)"
fi

# Some base images start Ollama as a service. Stop it so our localhost-only
# process with predictable environment settings owns port 11434.
if command -v systemctl >/dev/null 2>&1; then
  systemctl stop ollama.service >/dev/null 2>&1 || true
fi
if [[ -f "$PID_FILE" ]]; then
  old_pid="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "$old_pid" ]] && kill -0 "$old_pid" 2>/dev/null; then
    kill "$old_pid" || true
    sleep 1
  fi
fi
pkill -f '[o]llama serve' >/dev/null 2>&1 || true

cat > "$START_SCRIPT" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
export OLLAMA_HOST="127.0.0.1:11434"
export OLLAMA_MODELS="$MODEL_DIR"
export OLLAMA_MAX_LOADED_MODELS="1"
export OLLAMA_NUM_PARALLEL="1"
export OLLAMA_MAX_QUEUE="8"
export OLLAMA_KEEP_ALIVE="2m"
export OLLAMA_CONTEXT_LENGTH="32768"
export OLLAMA_FLASH_ATTENTION="1"
export OLLAMA_KV_CACHE_TYPE="q8_0"
exec ollama serve
EOF
chmod +x "$START_SCRIPT"

info "Starting localhost-only Ollama server"
nohup "$START_SCRIPT" >"$OLLAMA_LOG" 2>&1 &
echo $! > "$PID_FILE"

for _ in $(seq 1 60); do
  if curl -fsS http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
curl -fsS http://127.0.0.1:11434/api/tags >/dev/null 2>&1 || {
  tail -n 100 "$OLLAMA_LOG" >&2 || true
  die "Ollama API did not start."
}

info "Downloading coder model: $CODER_MODEL"
ollama pull "$CODER_MODEL"

info "Downloading fast model: $FAST_MODEL"
ollama pull "$FAST_MODEL"

info "Installed models"
ollama list

run_smoke_test() {
  local model="$1"
  info "Smoke-testing $model"
  python3 - "$model" <<'PYTEST'
import json
import sys
import urllib.request

model = sys.argv[1]
payload = json.dumps({
    "model": model,
    "prompt": "Reply with exactly: OK",
    "stream": False,
    "keep_alive": "0",
    "options": {"num_ctx": 4096, "temperature": 0}
}).encode("utf-8")
request = urllib.request.Request(
    "http://127.0.0.1:11434/api/generate",
    data=payload,
    headers={"Content-Type": "application/json"},
    method="POST",
)
with urllib.request.urlopen(request, timeout=900) as response:
    result = json.load(response)
text = (result.get("response") or "").strip()
print(f"Response: {text[:200]}")
print(f"Prompt tokens: {result.get('prompt_eval_count', 'n/a')}")
print(f"Output tokens: {result.get('eval_count', 'n/a')}")
if not text:
    raise SystemExit("Model returned an empty response")
PYTEST
}

command -v python3 >/dev/null 2>&1 || die "python3 is required for the smoke tests."
run_smoke_test "$CODER_MODEL"
run_smoke_test "$FAST_MODEL"

info "Final GPU and Ollama status"
nvidia-smi --query-gpu=name,memory.used,memory.total,utilization.gpu --format=csv,noheader
ollama ps || true

cat <<EOF

Setup complete.

Ollama is listening only inside the rented machine:
  http://127.0.0.1:11434

Models:
  Coder: $CODER_MODEL
  Fast:  $FAST_MODEL

Keep this instance running, then create an SSH tunnel from Windows:
  ssh -N -L 11434:127.0.0.1:11434 -p <SSH_PORT> root@<VAST_IP>

Roo Code will use:
  Base URL: http://localhost:11434

Useful server commands:
  ollama list
  ollama ps
  tail -f "$OLLAMA_LOG"
  "$START_SCRIPT"

The repository was not copied to this server.
EOF
