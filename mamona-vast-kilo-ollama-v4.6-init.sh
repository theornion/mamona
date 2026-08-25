#!/usr/bin/env bash
set -Eeuo pipefail

BASE_DIR="${MAMONA_LLM_DIR:-/workspace/mamona-llm}"
OLLAMA_STORE="${OLLAMA_MODELS:-$BASE_DIR/ollama-models}"
LOG_DIR="$BASE_DIR/logs"
MODELFILE_DIR="$BASE_DIR/modelfiles"
OLLAMA_LOG="$LOG_DIR/ollama.log"
SETUP_LOG="$LOG_DIR/setup-v46.log"
START_SCRIPT="$BASE_DIR/start-ollama-v46.sh"
PID_FILE="$BASE_DIR/ollama.pid"
OLLAMA_PORT="${OLLAMA_PORT:-11435}"
PULL_RETRIES="${PULL_RETRIES:-3}"

HEAVY_BASE="${HEAVY_BASE:-qwen3-coder:30b}"
MEDIUM_BASE="${MEDIUM_BASE:-qwen3:14b}"
FAST_BASE="${FAST_BASE:-qwen3.5:9b}"
EMBED_MODEL="${EMBED_MODEL:-nomic-embed-text}"
HEAVY_ALIAS="mamona-coder30-128k"
MEDIUM_ALIAS="mamona-qwen14-64k"
FAST_ALIAS="mamona-qwen9-64k"

info(){ printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn(){ printf '\n\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die(){ printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

mkdir -p "$BASE_DIR" "$OLLAMA_STORE" "$LOG_DIR" "$MODELFILE_DIR"
exec > >(tee -a "$SETUP_LOG") 2>&1
trap 'rc=$?; echo "ERROR line=$LINENO rc=$rc" >&2; exit $rc' ERR

if ! command -v curl >/dev/null || ! command -v jq >/dev/null || ! command -v nvidia-smi >/dev/null; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl jq procps
fi

info "GPU"
nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader || true

if ! command -v ollama >/dev/null 2>&1; then
  info "Installing Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
fi

systemctl stop ollama.service >/dev/null 2>&1 || true
if [[ -f "$PID_FILE" ]]; then kill "$(cat "$PID_FILE")" >/dev/null 2>&1 || true; fi
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
export OLLAMA_MAX_QUEUE="32"
export OLLAMA_KEEP_ALIVE="3m"
exec ollama serve
EOF
chmod +x "$START_SCRIPT"
export OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
export OLLAMA_MODELS="$OLLAMA_STORE"
nohup "$START_SCRIPT" > "$OLLAMA_LOG" 2>&1 &
echo $! > "$PID_FILE"

for _ in $(seq 1 120); do
  curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null 2>&1 && break
  sleep 1
done
curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null || die "Ollama API did not start"

pull_model(){
  local model="$1"
  if ollama show "$model" >/dev/null 2>&1; then info "Already installed: $model"; return; fi
  for n in $(seq 1 "$PULL_RETRIES"); do
    info "Pull $model ($n/$PULL_RETRIES)"
    ollama pull "$model" && return
    sleep $((n*5))
  done
  die "Could not pull $model"
}

pull_model "$FAST_BASE"
pull_model "$MEDIUM_BASE"
pull_model "$HEAVY_BASE"
pull_model "$EMBED_MODEL"

cat > "$MODELFILE_DIR/heavy.Modelfile" <<EOF
FROM ${HEAVY_BASE}
PARAMETER num_ctx 131072
EOF
cat > "$MODELFILE_DIR/medium.Modelfile" <<EOF
FROM ${MEDIUM_BASE}
PARAMETER num_ctx 65536
EOF
cat > "$MODELFILE_DIR/fast.Modelfile" <<EOF
FROM ${FAST_BASE}
PARAMETER num_ctx 65536
EOF

info "Creating canonical aliases"
ollama create "$HEAVY_ALIAS" -f "$MODELFILE_DIR/heavy.Modelfile"
ollama create "$MEDIUM_ALIAS" -f "$MODELFILE_DIR/medium.Modelfile"
ollama create "$FAST_ALIAS" -f "$MODELFILE_DIR/fast.Modelfile"

api_generate(){
  local model="$1" out="$2"
  curl -fsS "http://${OLLAMA_HOST}/api/generate" -H 'Content-Type: application/json' \
    -d "$(jq -cn --arg m "$model" '{model:$m,prompt:"Odpowiedz dokładnie: OK",stream:false,keep_alive:"3m",options:{temperature:0,num_predict:8}}')" > "$out"
}

info "Concurrent FAST smoke: 14B + 9B"
set +e
api_generate "$MEDIUM_ALIAS" "$LOG_DIR/smoke-14b.json" & p14=$!
api_generate "$FAST_ALIAS" "$LOG_DIR/smoke-9b.json" & p9=$!
wait "$p14"; r14=$?
wait "$p9"; r9=$?
set -e
if [[ $r14 -eq 0 && $r9 -eq 0 ]]; then
  echo "FAST_PARALLEL_API=PASS"
  jq -r '.response // .error' "$LOG_DIR/smoke-14b.json" || true
  jq -r '.response // .error' "$LOG_DIR/smoke-9b.json" || true
else
  warn "FAST parallel smoke failed (14B=$r14, 9B=$r9). System can still run sequentially."
fi
ollama ps || true

info "Heavy smoke — scheduler-exclusive"
ollama stop "$MEDIUM_ALIAS" >/dev/null 2>&1 || true
ollama stop "$FAST_ALIAS" >/dev/null 2>&1 || true
set +e
api_generate "$HEAVY_ALIAS" "$LOG_DIR/smoke-30b.json"; rh=$?
set -e
if [[ $rh -eq 0 ]]; then echo "HEAVY_API=PASS"; jq -r '.response // .error' "$LOG_DIR/smoke-30b.json" || true; else warn "30B smoke failed"; fi
ollama ps || true
ollama stop "$HEAVY_ALIAS" >/dev/null 2>&1 || true

cat <<EOF

MAMONA V4.6 SERVER READY
Ollama server: http://127.0.0.1:${OLLAMA_PORT}
Windows Kilo Base URL through SSH tunnel: http://127.0.0.1:11436/v1

Aliases:
  ${HEAVY_ALIAS}  = 30B / 128K / HEAVY EXCLUSIVE
  ${MEDIUM_ALIAS} = 14B / 64K / MEDIUM LANE
  ${FAST_ALIAS}   = 9B  / 64K / FAST LANE

Windows tunnel:
  ssh -N -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -L 11436:127.0.0.1:${OLLAMA_PORT} -p <PORT> root@<IP>

If FAST_PARALLEL_API passed, use 14B+9B parallel. If not, the same agent pack remains functional sequentially.
EOF
