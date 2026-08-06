#!/usr/bin/env bash
set -Eeuo pipefail

# Fresh Vast.ai setup for Mamona + Kilo.
# Installs Ollama, serves it on localhost, and pulls:
#   qwen3.6:27b
#   qwen3.5:9b
#   nomic-embed-text

MAIN_MODEL="${MAIN_MODEL:-qwen3.6:27b}"
FAST_MODEL="${FAST_MODEL:-qwen3.5:9b}"
EMBED_MODEL="${EMBED_MODEL:-nomic-embed-text}"

BASE_DIR="${MAMONA_LLM_DIR:-/workspace/mamona-llm}"
MODEL_DIR="${OLLAMA_MODELS:-$BASE_DIR/models}"
LOG_DIR="$BASE_DIR/logs"
OLLAMA_LOG="$LOG_DIR/ollama.log"
SETUP_LOG="$LOG_DIR/setup.log"
PID_FILE="$BASE_DIR/ollama.pid"
START_SCRIPT="$BASE_DIR/start-ollama.sh"
PULL_SCRIPT="$BASE_DIR/pull-models.sh"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\n\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

mkdir -p "$MODEL_DIR" "$LOG_DIR"
exec > >(tee -a "$SETUP_LOG") 2>&1
trap 'rc=$?; printf "\nERROR: setup failed on line %s with exit code %s\n" "$LINENO" "$rc" >&2; exit "$rc"' ERR

command -v nvidia-smi >/dev/null 2>&1 || die "nvidia-smi not found. Use a CUDA GPU image."
command -v curl >/dev/null 2>&1 || {
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl python3 procps
}
command -v python3 >/dev/null 2>&1 || {
  apt-get update -qq
  apt-get install -y -qq python3
}

info "Detected GPU"
nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader

GPU_MEMORY_MIB="$(nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits | head -n1 | tr -d ' ')"
if [[ "$GPU_MEMORY_MIB" =~ ^[0-9]+$ ]] && (( GPU_MEMORY_MIB < 22000 )); then
  warn "Only ${GPU_MEMORY_MIB} MiB VRAM detected. qwen3.6:27b is intended for a 24 GB-class GPU."
fi

if ! command -v ollama >/dev/null 2>&1; then
  info "Installing Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
else
  info "Ollama already installed: $(ollama --version 2>/dev/null || true)"
fi

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
export OLLAMA_MAX_LOADED_MODELS="2"
export OLLAMA_NUM_PARALLEL="1"
export OLLAMA_MAX_QUEUE="16"
export OLLAMA_KEEP_ALIVE="10m"
export OLLAMA_CONTEXT_LENGTH="65536"
export OLLAMA_FLASH_ATTENTION="1"
export OLLAMA_KV_CACHE_TYPE="q8_0"
exec ollama serve
EOF
chmod +x "$START_SCRIPT"

export OLLAMA_HOST="127.0.0.1:11434"
export OLLAMA_MODELS="$MODEL_DIR"

info "Starting localhost-only Ollama"
nohup "$START_SCRIPT" >"$OLLAMA_LOG" 2>&1 &
echo $! > "$PID_FILE"

for _ in $(seq 1 90); do
  if curl -fsS http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

curl -fsS http://127.0.0.1:11434/api/tags >/dev/null 2>&1 || {
  tail -n 150 "$OLLAMA_LOG" >&2 || true
  die "Ollama API did not start."
}

cat > "$PULL_SCRIPT" <<EOF
#!/usr/bin/env bash
set -Eeuo pipefail
export OLLAMA_HOST="127.0.0.1:11434"
ollama pull "$MAIN_MODEL"
ollama pull "$FAST_MODEL"
ollama pull "$EMBED_MODEL"
ollama list
EOF
chmod +x "$PULL_SCRIPT"

info "Disk space before pulls"
df -h "$BASE_DIR" | sed -n '1,2p'

"$PULL_SCRIPT"

info "Smoke testing chat models and embeddings"
python3 - "$MAIN_MODEL" "$FAST_MODEL" "$EMBED_MODEL" <<'PY'
import json
import sys
import urllib.request

main_model, fast_model, embed_model = sys.argv[1:4]
base = "http://127.0.0.1:11434"

def post(path, payload, timeout=1200):
    req = urllib.request.Request(
        base + path,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as response:
        return json.load(response)

for model, think in [(main_model, False), (fast_model, False)]:
    result = post("/api/chat", {
        "model": model,
        "messages": [{"role": "user", "content": "Reply with exactly: OK"}],
        "stream": False,
        "think": think,
        "keep_alive": 0,
        "options": {"num_ctx": 4096, "temperature": 0},
    })
    text = (result.get("message") or {}).get("content", "").strip()
    print(f"{model}: {text[:100]}")
    if not text:
        raise SystemExit(f"{model} returned an empty response")

embedding = post("/api/embed", {
    "model": embed_model,
    "input": "Mamona article image pipeline",
    "keep_alive": 0,
})
vectors = embedding.get("embeddings") or []
if not vectors or not vectors[0]:
    raise SystemExit("Embedding model returned no vector")
print(f"{embed_model}: embedding dimension {len(vectors[0])}")
PY

info "Final status"
ollama list
nvidia-smi --query-gpu=name,memory.used,memory.total,utilization.gpu --format=csv,noheader
df -h "$BASE_DIR" | sed -n '1,2p'

cat <<EOF

Setup complete.

Server endpoint:
  http://127.0.0.1:11434

Models:
  Main:       $MAIN_MODEL
  Fast:       $FAST_MODEL
  Embeddings: $EMBED_MODEL

Windows tunnel (recommended local port 11436):
  ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -N \\
    -L 11436:127.0.0.1:11434 -p <SSH_PORT> root@<VAST_IP>

Kilo project config expects:
  http://127.0.0.1:11436

Useful commands:
  ollama list
  ollama ps
  tail -f "$OLLAMA_LOG"
  "$START_SCRIPT"
  "$PULL_SCRIPT"
EOF
