#!/usr/bin/env bash
set -Eeuo pipefail

# MAMONA V4.6 — TRI-TIER PARALLEL SERVER INIT
# Fresh/idempotent Vast/Linux setup for:
#   30B heavy-exclusive  -> mamona-coder30-128k
#   14B medium lane      -> mamona-qwen14-64k
#   9B fast lane         -> mamona-qwen9-64k
#   nomic-embed-text     -> indexing
#
# Runtime policy:
#   - max 2 loaded models
#   - 14B + 9B may run concurrently when VRAM allows
#   - 30B is scheduler-exclusive by agent policy
#
# Ollama listens only on 127.0.0.1:11435 and is exposed to Windows
# through an SSH local-forward to 127.0.0.1:11436.

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
CLEAN_OLD_27B="${CLEAN_OLD_27B:-1}"
SKIP_SMOKE_TESTS="${SKIP_SMOKE_TESTS:-0}"

HEAVY_BASE="${HEAVY_BASE:-qwen3-coder:30b}"
MEDIUM_BASE="${MEDIUM_BASE:-qwen3:14b}"
FAST_BASE="${FAST_BASE:-qwen3.5:9b}"
EMBED_MODEL="${EMBED_MODEL:-nomic-embed-text}"

HEAVY_ALIAS="${HEAVY_ALIAS:-mamona-coder30-128k}"
MEDIUM_ALIAS="${MEDIUM_ALIAS:-mamona-qwen14-64k}"
FAST_ALIAS="${FAST_ALIAS:-mamona-qwen9-64k}"

SSH_PORT_HINT="14296"
SSH_HOST_HINT="194.26.196.159"

info(){ printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn(){ printf '\n\033[1;33mWARNING: %s\033[0m\n' "$*" >&2; }
die(){ printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

mkdir -p "$BASE_DIR" "$OLLAMA_STORE" "$LOG_DIR" "$MODELFILE_DIR"
exec > >(tee -a "$SETUP_LOG") 2>&1
trap 'rc=$?; printf "\nERROR line=%s rc=%s\n" "$LINENO" "$rc" >&2; exit "$rc"' ERR

install_packages(){
  command -v apt-get >/dev/null 2>&1 || die "apt-get unavailable"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl jq procps iproute2
}

for cmd in curl jq pkill ss; do
  command -v "$cmd" >/dev/null 2>&1 || install_packages
 done

info "GPU / VRAM"
if command -v nvidia-smi >/dev/null 2>&1; then
  nvidia-smi --query-gpu=name,memory.total,memory.free,driver_version --format=csv,noheader || true
else
  warn "nvidia-smi unavailable — continuing, but GPU verification is skipped"
fi

if ! command -v ollama >/dev/null 2>&1; then
  info "Installing Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
else
  info "Ollama present: $(ollama --version 2>/dev/null || true)"
fi

# Stop previous Ollama launch modes so runtime env is guaranteed.
info "Stopping previous Ollama process/service"
systemctl stop ollama.service >/dev/null 2>&1 || true
if [[ -f "$PID_FILE" ]]; then
  old_pid="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "${old_pid:-}" ]] && kill -0 "$old_pid" >/dev/null 2>&1; then
    kill "$old_pid" >/dev/null 2>&1 || true
  fi
fi
pkill -f '[o]llama serve' >/dev/null 2>&1 || true
sleep 1

cat > "$START_SCRIPT" <<EOF_START
#!/usr/bin/env bash
set -Eeuo pipefail
export OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
export OLLAMA_MODELS="${OLLAMA_STORE}"
export OLLAMA_FLASH_ATTENTION="1"
export OLLAMA_KV_CACHE_TYPE="q8_0"
export OLLAMA_MAX_LOADED_MODELS="2"
export OLLAMA_NUM_PARALLEL="1"
export OLLAMA_MAX_QUEUE="32"
export OLLAMA_KEEP_ALIVE="5m"
exec ollama serve
EOF_START
chmod +x "$START_SCRIPT"

export OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}"
export OLLAMA_MODELS="$OLLAMA_STORE"

info "Starting Ollama on ${OLLAMA_HOST}"
nohup "$START_SCRIPT" > "$OLLAMA_LOG" 2>&1 &
echo $! > "$PID_FILE"

for _ in $(seq 1 120); do
  if curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null 2>&1; then break; fi
  sleep 1
done
if ! curl -fsS "http://${OLLAMA_HOST}/api/version" >/dev/null 2>&1; then
  tail -n 200 "$OLLAMA_LOG" >&2 || true
  die "Ollama API did not start on ${OLLAMA_HOST}"
fi

has_model(){
  ollama list 2>/dev/null | awk 'NR>1 {print $1}' | grep -Fxq "$1"
}

remove_if_present(){
  local model="$1"
  if has_model "$model"; then
    info "Removing obsolete model/alias: $model"
    ollama stop "$model" >/dev/null 2>&1 || true
    ollama rm "$model" || true
  fi
}

if [[ "$CLEAN_OLD_27B" == "1" ]]; then
  info "Cleaning obsolete Mamona 27B models/aliases"
  for old in \
    'qwen3.6:27b' \
    'qwen3.6-no-think' \
    'qwen3.5:27b' \
    'qwen3.5-no-think-27b' \
    'mamona-qwen27-128k' \
    'mamona-qwen27-64k'; do
    remove_if_present "$old"
  done
fi

pull_model(){
  local model="$1" attempt
  if ollama show "$model" >/dev/null 2>&1; then
    info "Already installed: $model"
    return
  fi
  for attempt in $(seq 1 "$PULL_RETRIES"); do
    info "Pull $model — attempt $attempt/$PULL_RETRIES"
    if ollama pull "$model"; then
      ollama show "$model" >/dev/null
      return
    fi
    warn "Pull interrupted; retry will resume partial download"
    sleep $((attempt * 5))
  done
  die "Could not download $model"
}

info "Model inventory before pulls"
ollama list || true

# Pull heavy first so insufficient disk becomes visible immediately.
pull_model "$HEAVY_BASE"
pull_model "$MEDIUM_BASE"
pull_model "$FAST_BASE"
pull_model "$EMBED_MODEL"

cat > "$MODELFILE_DIR/heavy.Modelfile" <<EOF_MODEL
FROM ${HEAVY_BASE}
PARAMETER num_ctx 131072
EOF_MODEL

cat > "$MODELFILE_DIR/medium.Modelfile" <<EOF_MODEL
FROM ${MEDIUM_BASE}
PARAMETER num_ctx 65536
EOF_MODEL

cat > "$MODELFILE_DIR/fast.Modelfile" <<EOF_MODEL
FROM ${FAST_BASE}
PARAMETER num_ctx 65536
EOF_MODEL

info "Creating canonical V4.6 aliases"
ollama create "$HEAVY_ALIAS" -f "$MODELFILE_DIR/heavy.Modelfile"
ollama create "$MEDIUM_ALIAS" -f "$MODELFILE_DIR/medium.Modelfile"
ollama create "$FAST_ALIAS" -f "$MODELFILE_DIR/fast.Modelfile"

for alias in "$HEAVY_ALIAS" "$MEDIUM_ALIAS" "$FAST_ALIAS"; do
  ollama show "$alias" >/dev/null || die "Alias verification failed: $alias"
done

api_generate(){
  local model="$1" out="$2"
  curl -fsS "http://${OLLAMA_HOST}/api/generate" \
    -H 'Content-Type: application/json' \
    -d "$(jq -cn --arg m "$model" '{model:$m,prompt:"Odpowiedz dokładnie: OK",stream:false,keep_alive:"5m",options:{temperature:0,num_predict:8}}')" \
    > "$out"
}

if [[ "$SKIP_SMOKE_TESTS" != "1" ]]; then
  info "FAST PARALLEL smoke — 14B + 9B concurrently"
  set +e
  api_generate "$MEDIUM_ALIAS" "$LOG_DIR/smoke-14b.json" & p14=$!
  api_generate "$FAST_ALIAS" "$LOG_DIR/smoke-9b.json" & p9=$!
  wait "$p14"; r14=$?
  wait "$p9"; r9=$?
  set -e

  if [[ $r14 -eq 0 && $r9 -eq 0 ]]; then
    echo "FAST_PARALLEL_API=PASS"
    printf '14B: '; jq -r '.response // .error // "NO_RESPONSE"' "$LOG_DIR/smoke-14b.json" || true
    printf '9B : '; jq -r '.response // .error // "NO_RESPONSE"' "$LOG_DIR/smoke-9b.json" || true
  else
    warn "FAST parallel smoke failed: 14B=$r14 9B=$r9. Agent pack can still fall back to sequential execution."
  fi
  ollama ps || true

  info "HEAVY smoke — 30B scheduler-exclusive"
  ollama stop "$MEDIUM_ALIAS" >/dev/null 2>&1 || true
  ollama stop "$FAST_ALIAS" >/dev/null 2>&1 || true
  set +e
  api_generate "$HEAVY_ALIAS" "$LOG_DIR/smoke-30b.json"; rh=$?
  set -e
  if [[ $rh -eq 0 ]]; then
    echo "HEAVY_API=PASS"
    printf '30B: '; jq -r '.response // .error // "NO_RESPONSE"' "$LOG_DIR/smoke-30b.json" || true
  else
    warn "30B smoke failed with rc=$rh"
  fi
  ollama ps || true

  # Leave fast lane warm; heavy stays unloaded unless explicitly scheduled.
  ollama stop "$HEAVY_ALIAS" >/dev/null 2>&1 || true
  curl -fsS "http://${OLLAMA_HOST}/api/generate" \
    -H 'Content-Type: application/json' \
    -d "$(jq -cn --arg m "$FAST_ALIAS" '{model:$m,prompt:"OK",stream:false,keep_alive:"5m",options:{temperature:0,num_predict:1}}')" \
    >/dev/null 2>&1 || true
fi

info "Final model inventory"
ollama list
info "Final runtime state"
ollama ps || true

if ss -ltn 2>/dev/null | grep -qE '127\.0\.0\.1:8080|0\.0\.0\.0:8080|\[::\]:8080'; then
  echo "PORT_8080=LISTENING"
else
  warn "Nothing is currently listening on server port 8080. The SSH forward is still valid, but the web service/template must provide that port."
fi

cat <<EOF_DONE

============================================================
MAMONA V4.6 SERVER READY
============================================================

Ollama API:
  http://127.0.0.1:${OLLAMA_PORT}

Windows Kilo OpenAI-compatible Base URL:
  http://127.0.0.1:11436/v1

Runtime aliases:
  ${HEAVY_ALIAS}  -> ${HEAVY_BASE} -> 128K  [HEAVY / EXCLUSIVE]
  ${MEDIUM_ALIAS} -> ${MEDIUM_BASE} -> 64K   [MEDIUM lane]
  ${FAST_ALIAS}   -> ${FAST_BASE} -> 64K     [FAST lane]
  ${EMBED_MODEL}  -> indexing

Runtime:
  OLLAMA_MAX_LOADED_MODELS=2
  OLLAMA_NUM_PARALLEL=1
  OLLAMA_FLASH_ATTENTION=1
  OLLAMA_KV_CACHE_TYPE=q8_0

PowerShell / SSH tunnel for this Vast instance:
  ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -p ${SSH_PORT_HINT} -L 8080:127.0.0.1:8080 -L 11436:127.0.0.1:${OLLAMA_PORT} root@${SSH_HOST_HINT}

Tunnel-only variant:
  ssh -N -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -p ${SSH_PORT_HINT} -L 8080:127.0.0.1:8080 -L 11436:127.0.0.1:${OLLAMA_PORT} root@${SSH_HOST_HINT}

Restart Ollama later without re-running setup:
  ${START_SCRIPT}

Logs:
  ${SETUP_LOG}
  ${OLLAMA_LOG}
============================================================
EOF_DONE
