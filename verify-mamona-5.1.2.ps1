param(
    [string]$RepoRoot = "C:\Projekty\mamona"
)

$ErrorActionPreference = "Stop"
if (-not (Test-Path $RepoRoot)) { throw "Repo root does not exist: $RepoRoot" }

Write-Host "=== VERIFY MAMONA 5.1.2 ===" -ForegroundColor Cyan

$expected = @(
    "mamona-coordinator",
    "mamona-executor",
    "mamona-diagnoser",
    "mamona-worker",
    "mamona-reviewer",
    "mamona-architect",
    "mamona-heavy-coder",
    "checkpoint-writer"
)

foreach ($name in $expected) {
    $p = Join-Path $RepoRoot ".kilo\agents\$name.md"
    if (Test-Path $p) {
        Write-Host "[FILE OK] $name" -ForegroundColor Green
    } else {
        Write-Host "[MISSING] $name" -ForegroundColor Red
        exit 2
    }
}

$obsolete = @(
    "mamona-orchestrator",
    "mamona-heavy-auditor",
    "mamona-quick-worker",
    "mamona-tester",
    "mamona-coder",
    "repo-scout",
    "quick-maintainer"
)
foreach ($name in $obsolete) {
    $p = Join-Path $RepoRoot ".kilo\agents\$name.md"
    if (Test-Path $p) {
        Write-Host "[OBSOLETE STILL PRESENT] $name" -ForegroundColor Red
        exit 3
    }
}

$coord = Get-Content (Join-Path $RepoRoot ".kilo\agents\mamona-coordinator.md") -Raw
if ($coord -notmatch 'mode:\s*primary') { throw "Coordinator is not primary" }
if ($coord -notmatch 'model:\s*ollama/mamona-qwen14-64k') { throw "Coordinator model is not V4.5 14B alias" }
if ($coord -notmatch 'task:\s*allow') { throw "Coordinator task permission is not allow" }
Write-Host "[COORDINATOR OK] primary + task allow + 14B alias" -ForegroundColor Green

foreach ($name in $expected | Where-Object { $_ -ne "mamona-coordinator" }) {
    $txt = Get-Content (Join-Path $RepoRoot ".kilo\agents\$name.md") -Raw
    if ($txt -notmatch 'task:\s*deny') { throw "$name missing task: deny" }
}
Write-Host "[CHILDREN OK] all task: deny" -ForegroundColor Green

$config = Get-Content (Join-Path $RepoRoot ".kilo\kilo.jsonc") -Raw
foreach ($alias in @("mamona-coder30-128k", "mamona-qwen14-64k", "mamona-qwen9-64k")) {
    if ($config -notmatch [regex]::Escape($alias)) { throw "Missing model alias in kilo.jsonc: $alias" }
}
Write-Host "[CONFIG OK] V4.5 runtime aliases present" -ForegroundColor Green

$kiloCmd = Get-Command kilo.cmd -ErrorAction SilentlyContinue
if ($null -ne $kiloCmd) {
    Write-Host ""
    Write-Host "Kilo registry (informational):" -ForegroundColor Cyan
    try {
        & kilo.cmd agent list
    } catch {
        Write-Host "kilo.cmd agent list failed; file-level verification above still passed." -ForegroundColor Yellow
    }
} else {
    Write-Host "[INFO] kilo.cmd not found in this shell; skipped live registry listing." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "VERIFY PASS" -ForegroundColor Green
Write-Host "Now run Developer: Reload Window, select mamona-coordinator, then paste PROMPT_RESUME_P4_AFTER_5_1_2.md."
