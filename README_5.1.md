# Mamona Kilo Agents V5.1 — Anti-Loop

Ta paczka jest nakładką na aktualny projekt Mamona. Nie zawiera kodu aplikacji i nie modyfikuje `docs/CURRENT_WORK.md`.

## Co wymienia

- `AGENTS.md`
- `.kilo/kilo.jsonc`
- `.kilo/agents/*.md`
- `docs/AGENT_EXECUTION_PROTOCOL.md`

## Co dodaje

- `docs/MAMONA_5_1_ANTI_LOOP.md`
- `PROMPT_RESUME_P4_AFTER_5_1.md`

## Instalacja Windows

Repo domyślne: `C:\Projekty\Mamona`

W PowerShell, z katalogu rozpakowanej paczki:

```powershell
powershell -ExecutionPolicy Bypass -File .\install-mamona-5.1.ps1
```

Skrypt tworzy backup wszystkich nadpisywanych plików w:

```text
C:\Projekty\Mamona\.mamona-backups\5.1-<timestamp>\
```

Możesz podać inny root:

```powershell
.\install-mamona-5.1.ps1 -RepoRoot "D:\sciezka\Mamona"
```

## Po instalacji

1. Nie zatrzymuj Ollamy — 5.1 nie wymaga migracji modeli.
2. Otwórz nową sesję Kilo/Codex, aby nie dziedziczyć starej pętli.
3. Opcjonalnie sprawdź `kilo agent list`.
4. Wklej treść `PROMPT_RESUME_P4_AFTER_5_1.md`.

## Ważne

Plik `.kilo/kilo.jsonc` definiuje modele i limity kontekstu, ale nie ustawia endpointu Ollama. Endpoint/autoryzacja mogą nadal pochodzić z globalnego `~/.config/kilo/kilo.jsonc`. Jeżeli obecny projektowy plik zawiera niestandardowy endpoint, backup utworzony przez installer pozwala go łatwo odzyskać.
