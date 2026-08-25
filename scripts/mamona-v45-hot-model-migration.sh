#!/usr/bin/env bash
set -Eeuo pipefail

# MAMONA V4.5 — HOT MODEL MIGRATION
# Nie zatrzymuje ani nie restartuje procesu `ollama serve`.
# Jedynie:
#   1) robi remanent,
#   2) wyładowuje i usuwa stary 27B,
#   3) pobiera Qwen3-Coder 30B + Qwen3 14B,
#   4) upewnia się, że 9B + nomic istnieją,
#   5) tworzy aliasy kontekstowe wymagane przez paczkę V4.5,
#   6) robi remanent końcowy.
#
# UWAGA:
# Jeżeli bieżący `ollama serve` został uruchomiony z
# OLLAMA_MAX_LOADED_MODELS=1, samo wykonanie tego skryptu NIE włączy
# równoległego 14B+9B. To wymaga późniejszego restartu Ollamy z
# OLLAMA_MAX_LOADED_MODELS=2. Modele i aliasy będą już jednak gotowe.

export OLLAMA_HOST="${OLLAMA_HOST:-127.0.0.1:11435}"

HEAVY_BASE="qwen3-coder:30b"
MEDIUM_BASE="qwen3:14b"
FAST_BASE="qwen3.5:9b"
EMBED_MODEL="nomic-embed-text"

HEAVY_ALIAS="mamona-coder30-128k"
MEDIUM_ALIAS="mamona-qwen14-64k"
FAST_ALIAS="mamona-qwen9-64k"

TMPDIR_V45="/tmp/mamona-v45-hot"
mkdir -p "$TMPDIR_V45"

blue()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
yellow(){ printf '\n\033[1;33m==> %s\033[0m\n' "$*"; }

has_model() {
  ollama list 2>/dev/null | awk 'NR>1 {print $1}' | grep -Fxq "$1"
}

remove_if_present() {
  local model="$1"
  if has_model "$model"; then
    yellow "Wyładowuję model z VRAM (nie zatrzymuję Ollamy): $model"
    ollama stop "$model" >/dev/null 2>&1 || true
    yellow "Usuwam z dysku: $model"
    ollama rm "$model"
  fi
}

blue "REMANENT PRZED"
echo "--- disk ---"
df -h / /workspace 2>/dev/null || df -h
echo
echo "--- ollama list ---"
ollama list
echo
echo "--- ollama ps ---"
ollama ps || true

blue "USUWANIE STAREGO 27B"
# Aktualnie używana nazwa + bezpieczne historyczne warianty.
remove_if_present "qwen3.6:27b"
remove_if_present "qwen3.6-no-think"
remove_if_present "qwen3.5:27b"
remove_if_present "qwen3.5-no-think-27b"
remove_if_present "mamona-qwen27-128k"
remove_if_present "mamona-qwen27-64k"

blue "DYSK PO USUNIĘCIU 27B"
df -h / /workspace 2>/dev/null || df -h

pull_if_missing() {
  local model="$1"
  if has_model "$model"; then
    blue "Już jest: $model"
  else
    blue "Pobieram: $model"
    ollama pull "$model"
  fi
}

# Największy najpierw — od razu wiemy, czy mamy wystarczająco miejsca.
pull_if_missing "$HEAVY_BASE"
pull_if_missing "$MEDIUM_BASE"
pull_if_missing "$FAST_BASE"
pull_if_missing "$EMBED_MODEL"

blue "TWORZENIE ALIASÓW KONTEKSTOWYCH V4.5"

cat >"$TMPDIR_V45/Modelfile.coder30" <<EOF
FROM ${HEAVY_BASE}
PARAMETER num_ctx 131072
EOF

cat >"$TMPDIR_V45/Modelfile.qwen14" <<EOF
FROM ${MEDIUM_BASE}
PARAMETER num_ctx 65536
EOF

cat >"$TMPDIR_V45/Modelfile.qwen9" <<EOF
FROM ${FAST_BASE}
PARAMETER num_ctx 65536
EOF

ollama create "$HEAVY_ALIAS" -f "$TMPDIR_V45/Modelfile.coder30"
ollama create "$MEDIUM_ALIAS" -f "$TMPDIR_V45/Modelfile.qwen14"
ollama create "$FAST_ALIAS" -f "$TMPDIR_V45/Modelfile.qwen9"

blue "WERYFIKACJA ALIASÓW"
ollama show "$HEAVY_ALIAS" >/dev/null
ollama show "$MEDIUM_ALIAS" >/dev/null
ollama show "$FAST_ALIAS" >/dev/null

blue "REMANENT PO"
echo "--- disk ---"
df -h / /workspace 2>/dev/null || df -h
echo
echo "--- ollama list ---"
ollama list
echo
echo "--- ollama ps ---"
ollama ps || true

cat <<'EOF'

GOTOWE — V4.5 MODELE SĄ NA SERWERZE.

Oczekiwane runtime aliases:
  mamona-coder30-128k -> qwen3-coder:30b -> 128K
  mamona-qwen14-64k   -> qwen3:14b        -> 64K
  mamona-qwen9-64k    -> qwen3.5:9b      -> 64K

Ollama NIE została zatrzymana ani zrestartowana.

WAŻNE:
Jeżeli obecny proces Ollamy nadal ma:
  OLLAMA_MAX_LOADED_MODELS=1
to 14B + 9B będą na razie kolejkowane, a nie wykonywane równolegle.

Do prawdziwego V4.5 FAST PARALLEL przy następnym restarcie Ollamy:
  OLLAMA_MAX_LOADED_MODELS=2
  OLLAMA_NUM_PARALLEL=1
  OLLAMA_FLASH_ATTENTION=1
  OLLAMA_KV_CACHE_TYPE=q8_0
EOF
