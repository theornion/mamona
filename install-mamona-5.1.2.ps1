param(
    [string]$RepoRoot = "C:\Projekty\mamona"
)

$ErrorActionPreference = "Stop"
$PackageRoot = $PSScriptRoot

if (-not (Test-Path $RepoRoot)) {
    throw "Repo root does not exist: $RepoRoot"
}

$installFiles = @(
    ".kilo\kilo.jsonc",
    ".kilo\agents\mamona-coordinator.md",
    ".kilo\agents\mamona-executor.md",
    ".kilo\agents\mamona-diagnoser.md",
    ".kilo\agents\mamona-worker.md",
    ".kilo\agents\mamona-reviewer.md",
    ".kilo\agents\mamona-architect.md",
    ".kilo\agents\mamona-heavy-coder.md",
    ".kilo\agents\checkpoint-writer.md",
    "docs\AGENT_EXECUTION_PROTOCOL.md",
    "docs\MAMONA_5_1_2_CHANGELOG.md",
    "PROMPT_RESUME_P4_AFTER_5_1_2.md"
)

# V3.x / erroneous V5.1.x definitions that conflict with the V4.5 flat workflow.
$obsoleteAgents = @(
    ".kilo\agents\mamona-orchestrator.md",
    ".kilo\agents\mamona-heavy-auditor.md",
    ".kilo\agents\mamona-quick-worker.md",
    ".kilo\agents\mamona-tester.md",
    ".kilo\agents\mamona-coder.md",
    ".kilo\agents\repo-scout.md",
    ".kilo\agents\quick-maintainer.md"
)

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupRoot = Join-Path $RepoRoot ".mamona-backups\5.1.2-$timestamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

Write-Host "=== Mamona Agent Pack 5.1.2 / V4.5 base ===" -ForegroundColor Cyan
Write-Host "Repo:   $RepoRoot"
Write-Host "Backup: $backupRoot"

function Backup-IfExists([string]$Relative) {
    $dest = Join-Path $RepoRoot $Relative
    if (Test-Path $dest) {
        $backupDest = Join-Path $backupRoot $Relative
        $backupDir = Split-Path $backupDest -Parent
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        Copy-Item $dest $backupDest -Force
    }
}

foreach ($relative in $installFiles) {
    $source = Join-Path $PackageRoot $relative
    if (-not (Test-Path $source)) {
        throw "Package file missing: $source"
    }

    Backup-IfExists $relative

    $dest = Join-Path $RepoRoot $relative
    $destDir = Split-Path $dest -Parent
    New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    Copy-Item $source $dest -Force
    Write-Host "[INSTALLED] $relative" -ForegroundColor Green
}

foreach ($relative in $obsoleteAgents) {
    $dest = Join-Path $RepoRoot $relative
    if (Test-Path $dest) {
        Backup-IfExists $relative
        Remove-Item $dest -Force
        Write-Host "[REMOVED OBSOLETE] $relative" -ForegroundColor Yellow
    }
}

Write-Host ""
Write-Host "Sanity check files:" -ForegroundColor Cyan
$required = @(
    ".kilo\agents\mamona-coordinator.md",
    ".kilo\agents\mamona-executor.md",
    ".kilo\agents\mamona-diagnoser.md",
    ".kilo\agents\mamona-worker.md"
)
foreach ($relative in $required) {
    $p = Join-Path $RepoRoot $relative
    if (-not (Test-Path $p)) { throw "Required installed file missing: $p" }
    Write-Host "[OK] $relative"
}

$coordinatorText = Get-Content (Join-Path $RepoRoot ".kilo\agents\mamona-coordinator.md") -Raw
if ($coordinatorText -notmatch 'mode:\s*primary') { throw "mamona-coordinator is not primary" }
if ($coordinatorText -notmatch 'task:\s*allow') { throw "mamona-coordinator does not have task: allow" }

foreach ($relative in @(
    ".kilo\agents\mamona-executor.md",
    ".kilo\agents\mamona-diagnoser.md",
    ".kilo\agents\mamona-worker.md",
    ".kilo\agents\mamona-reviewer.md",
    ".kilo\agents\mamona-architect.md",
    ".kilo\agents\mamona-heavy-coder.md",
    ".kilo\agents\checkpoint-writer.md"
)) {
    $txt = Get-Content (Join-Path $RepoRoot $relative) -Raw
    if ($txt -notmatch 'mode:\s*subagent') { throw "$relative is not a subagent" }
    if ($txt -notmatch 'task:\s*deny') { throw "$relative does not have task: deny" }
}

Write-Host ""
Write-Host "Installed Mamona 5.1.2 successfully." -ForegroundColor Green
Write-Host "Ollama/models were NOT touched." -ForegroundColor Green
Write-Host ""
Write-Host "NEXT:" -ForegroundColor Cyan
Write-Host "1. VS Code -> Developer: Reload Window"
Write-Host "2. Start a NEW Kilo session"
Write-Host "3. Select primary agent: mamona-coordinator"
Write-Host "4. Run verify-mamona-5.1.2.ps1"
Write-Host "5. Paste PROMPT_RESUME_P4_AFTER_5_1_2.md"
