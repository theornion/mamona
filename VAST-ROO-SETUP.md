# Mamona: RTX 3090 + Ollama + Roo Code

This setup keeps the Mamona repository on the Windows computer. Vast.ai contains only Ollama and model files.

## Selected models

- Main implementation model: `qwen3-coder:30b`
- Fast low-risk model: `qwen3.5:9b`
- GPU: one RTX 3090 with 24 GB VRAM
- Loading policy: one model in VRAM at a time

## Before renting the GPU

1. Copy the files from this package into the root of the local `mamona` repository.
2. Open that repository folder in VS Code.
3. Review `docs/CURRENT_WORK.md` and enter one concrete next task.
4. Commit the setup files when they look correct.
5. Make sure the Windows OpenSSH client works:

```powershell
ssh -V
```

6. Add the public SSH key to Vast.ai.

## Rent the Vast.ai instance

Choose:

- RTX 3090, 24 GB VRAM;
- on-demand rather than interruptible for the first test;
- reliability around 98% or higher;
- PyTorch/Ubuntu template with SSH;
- at least 60 GB disk, preferably 80 GB for logs and comfortable headroom;
- good download speed.

Do not expose Ollama’s port publicly. The setup binds it to `127.0.0.1` and uses an encrypted SSH tunnel.

## Copy only the setup script to Vast.ai

Vast shows an SSH host, port and user. From the local Mamona repository run:

```powershell
scp -P <SSH_PORT> .\scripts\setup-vast.sh root@<VAST_IP>:/root/setup-vast.sh
```

Then connect:

```powershell
ssh -p <SSH_PORT> root@<VAST_IP>
```

On the server run:

```bash
chmod +x /root/setup-vast.sh
bash /root/setup-vast.sh
```

The script:

- verifies the NVIDIA GPU;
- installs Ollama;
- binds its API to localhost only;
- permits only one loaded model and one parallel request;
- enables Flash Attention and an 8-bit KV cache;
- downloads both selected models;
- smoke-tests both models;
- leaves the Ollama server running.

Model downloads may take several minutes. The repository itself is never copied to Vast.ai.

## Open the tunnel from Windows

Open a second PowerShell in the local Mamona repository:

```powershell
.\scripts\connect-vast.ps1 `
  -HostAddress "<VAST_IP>" `
  -SshPort <SSH_PORT>
```

Keep that PowerShell window open.

Test the connection in another PowerShell:

```powershell
Invoke-RestMethod http://localhost:11434/api/tags
```

You should see both models.

## Configure Roo Code

Create two Roo API profiles using the Ollama provider.

### Profile 1: Mamona Coder

```text
Provider: Ollama
Base URL: http://localhost:11434
Model: qwen3-coder:30b
```

Assign this profile to the `Mamona Coder` project mode from `.roomodes`.

### Profile 2: Mamona Fast

```text
Provider: Ollama
Base URL: http://localhost:11434
Model: qwen3.5:9b
```

Assign this profile to the `Mamona Fast` project mode.

Depending on the installed Roo Code version, profile/model selection may be stored as the most recently selected profile for each mode. Verify the selected model before sending the first task.

Do not enable unrestricted automatic approval at the beginning. Keep confirmation enabled for terminal commands, package installation, deletions and sensitive file changes.

## First task prompt

Use Coder for the first repository check:

```text
Read AGENTS.md, docs/PROJECT_CONTEXT.md and docs/CURRENT_WORK.md.
Check git status and the latest five commits.
Do not change code yet.
Summarize the current task, the smallest relevant file set and the validation plan.
```

After approving the plan, give the implementation command.

## Choosing the model

Use `Mamona Fast` when all of these are true:

- the expected result is obvious;
- the change is narrow and low risk;
- it does not touch publication, authentication, database migrations, source fetching, rights validation or paid APIs;
- one direct validation step can prove success.

Use `Mamona Coder` for everything else.

Ollama will unload the inactive model and load the requested one. The first request after switching models includes load time.

## During the session

Useful server checks through SSH:

```bash
ollama list
ollama ps
nvidia-smi
 tail -f /workspace/mamona-llm/logs/ollama.log
```

`ollama ps` should show whether the active model is loaded on the GPU.

## End the session

1. Follow `docs/END_SESSION.md` locally.
2. Commit and push only after reviewing the changes.
3. Close the PowerShell tunnel with `Ctrl+C`.
4. In Vast.ai click **Destroy**.
5. Confirm that the instance no longer exists.

Destroy removes the rented model files. The repository and agent-context files remain locally and on GitHub.
