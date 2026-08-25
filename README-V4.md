# MAMONA Kilo Agents V4.5 — Tri-Tier Parallel

Docelowy układ:

- OpenAI GPT-5.6 Sol Pro LOW -> coordinator only
- Qwen3-Coder 30B-A3B -> heavy exclusive, 128K
- Qwen3 14B -> medium, 64K
- Qwen3.5 9B -> fast, 64K

Fast mode może uruchomić 14B + 9B równolegle, jeżeli taski są niezależne.
Heavy mode uruchamia 30B samodzielnie.

## Instalacja agentów

```powershell
powershell -ExecutionPolicy Bypass -File .\INSTALL-V4.ps1 -ProjectRoot "C:\Projekty\mamona"
```

Installer NIE nadpisuje `docs/CURRENT_WORK.md` ani `docs/research/*`.

## Nowy serwer

W paczce znajduje się `mamona-vast-kilo-ollama-v4.5-init.sh`.
Uruchom go na świeżej instancji przed P4.
