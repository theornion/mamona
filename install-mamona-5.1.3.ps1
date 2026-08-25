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
    "docs\MAMONA_5_1_3_CHANGELOG.md",
    "docs\AUTONOMY_2H_RUNBOOK.md",
    "PROMPT_RESUME_P4_AFTER_5_1_3.md"
)

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
$backupRoot = Join-Path $RepoRoot ".mamona-backups\5.1.3-$timestamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

Write-Host "=== Mamona Agent Pack 5.1.3 / Evidence-First Autonomy ===" -ForegroundColor Cyan
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
Write-Host "Sanity checks:" -ForegroundColor Cyan

$requiredAgents = @(
    "mamona-coordinator",
    "mamona-executor",
    "mamona-diagnoser",
    "mamona-worker",
    "mamona-reviewer",
    "mamona-architect",
    "mamona-heavy-coder",
    "checkpoint-writer"
)

foreach ($name in $requiredAgents) {
    $p = Join-Path $RepoRoot ".kilo\agents\$name.md"
    if (-not (Test-Path $p)) { throw "Required agent missing after install: $name" }
    Write-Host "[OK] $name"
}

$coord = Get-Content (Join-Path $RepoRoot ".kilo\agents\mamona-coordinator.md") -Raw
if ($coord -notmatch 'Mamona Coordinator 5\.1\.3') { throw "Coordinator is not V5.1.3" }
if ($coord -notmatch 'mode:\s*primary') { throw "mamona-coordinator is not primary" }
if ($coord -notmatch 'task:\s*allow') { throw "mamona-coordinator missing task: allow" }
if ($coord -match '"\*":\s*ask') { throw "Coordinator still contains bash wildcard ask; autonomy would require manual approval" }

foreach ($name in $requiredAgents | Where-Object { $_ -ne "mamona-coordinator" }) {
    $txt = Get-Content (Join-Path $RepoRoot ".kilo\agents\$name.md") -Raw
    if ($txt -notmatch 'mode:\s*subagent') { throw "$name is not subagent" }
    if ($txt -notmatch 'task:\s*deny') { throw "$name missing task: deny" }
    if ($txt -match '"\*":\s*ask') { throw "$name contains bash wildcard ask" }
}

$executor = Get-Content (Join-Path $RepoRoot ".kilo\agents\mamona-executor.md") -Raw
foreach ($needle in @('read:\s*deny', 'glob:\s*deny', 'grep:\s*deny', 'edit:\s*deny')) {
    if ($executor -notmatch $needle) { throw "Executor minimal-permission check failed: $needle" }
}

$config = Get-Content (Join-Path $RepoRoot ".kilo\kilo.jsonc") -Raw
foreach ($alias in @("mamona-coder30-128k", "mamona-qwen14-64k", "mamona-qwen9-64k")) {
    if ($config -notmatch [regex]::Escape($alias)) { throw "Missing model alias: $alias" }
}
if ($config -notmatch '"threshold_percent"\s*:\s*60') { throw "Expected compaction threshold 60 not found" }

Write-Host ""
Write-Host "Installed Mamona 5.1.3 successfully." -ForegroundColor Green
Write-Host "Ollama/models, PHP source, DB and .env were NOT touched." -ForegroundColor Green
Write-Host ""
Write-Host "NEXT:" -ForegroundColor Cyan
Write-Host "1. VS Code -> Developer: Reload Window"
Write-Host "2. Start a NEW Kilo session"
Write-Host "3. Select primary agent: mamona-coordinator"
Write-Host "4. Run verify-mamona-5.1.3.ps1"
Write-Host "5. Paste PROMPT_RESUME_P4_AFTER_5_1_3.md"
Write-Host "6. Leave the coordinator working; the prompt targets ~120 minutes of autonomous progress."
