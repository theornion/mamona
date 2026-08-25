param(
    [string]$RepoRoot = "C:\Projekty\Mamona"
)

$ErrorActionPreference = "Stop"
$PackageRoot = $PSScriptRoot

if (-not (Test-Path $RepoRoot)) {
    throw "Repo root does not exist: $RepoRoot"
}

$targets = @(
    "AGENTS.md",
    ".kilo\kilo.jsonc",
    ".kilo\agents\mamona-orchestrator.md",
    ".kilo\agents\mamona-heavy-auditor.md",
    ".kilo\agents\mamona-diagnoser.md",
    ".kilo\agents\mamona-architect.md",
    ".kilo\agents\mamona-worker.md",
    ".kilo\agents\mamona-heavy-coder.md",
    ".kilo\agents\mamona-reviewer.md",
    ".kilo\agents\mamona-executor.md",
    ".kilo\agents\mamona-quick-worker.md",
    ".kilo\agents\checkpoint-writer.md",
    "docs\AGENT_EXECUTION_PROTOCOL.md",
    "docs\MAMONA_5_1_1_PERMISSION_HOTFIX.md",
    "PROMPT_RESUME_P4_AFTER_5_1_1.md"
)

$legacyAgents = @(
    ".kilo\agents\repo-scout.md",
    ".kilo\agents\mamona-coder.md",
    ".kilo\agents\mamona-tester.md",
    ".kilo\agents\quick-maintainer.md"
)

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupRoot = Join-Path $RepoRoot ".mamona-backups\5.1.1-$timestamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

Write-Host "Mamona 5.1.1 permission hotfix"
Write-Host "Repo:   $RepoRoot"
Write-Host "Backup: $backupRoot"

foreach ($relative in $targets) {
    $source = Join-Path $PackageRoot $relative
    $dest = Join-Path $RepoRoot $relative

    if (-not (Test-Path $source)) {
        throw "Package file missing: $source"
    }

    if (Test-Path $dest) {
        $backupDest = Join-Path $backupRoot $relative
        $backupDir = Split-Path $backupDest -Parent
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        Copy-Item $dest $backupDest -Force
    }

    $destDir = Split-Path $dest -Parent
    New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    Copy-Item $source $dest -Force
    Write-Host "[OK] $relative"
}

foreach ($relative in $legacyAgents) {
    $dest = Join-Path $RepoRoot $relative
    if (Test-Path $dest) {
        $backupDest = Join-Path $backupRoot $relative
        $backupDir = Split-Path $backupDest -Parent
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        Copy-Item $dest $backupDest -Force
        Remove-Item $dest -Force
        Write-Host "[REMOVED LEGACY] $relative"
    }
}

Write-Host ""
Write-Host "Installed Mamona agent pack 5.1.1."
Write-Host "Ollama restart is NOT required."
Write-Host "Close the current Kilo session, start a fresh session, then paste PROMPT_RESUME_P4_AFTER_5_1_1.md."
