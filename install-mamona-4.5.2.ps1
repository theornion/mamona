param(
    [string]$ProjectRoot = "C:\Projekty\mamona"
)

$ErrorActionPreference = "Stop"
$PackRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$SourceKilo = Join-Path $PackRoot ".kilo"
$TargetKilo = Join-Path $ProjectRoot ".kilo"
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$BackupRoot = Join-Path $ProjectRoot "_agent_backups\mamona-4.5.2-$stamp"

if (-not (Test-Path $ProjectRoot)) { throw "ProjectRoot not found: $ProjectRoot" }
if (-not (Test-Path $SourceKilo)) { throw "Package .kilo directory missing: $SourceKilo" }

function Backup-Path([string]$Path) {
    if (-not (Test-Path $Path)) { return }
    $relative = $Path.Substring($ProjectRoot.Length).TrimStart('\','/')
    if ([string]::IsNullOrWhiteSpace($relative)) { $relative = "project-root" }
    $dest = Join-Path $BackupRoot $relative
    $parent = Split-Path -Parent $dest
    if ($parent) { New-Item -ItemType Directory -Force -Path $parent | Out-Null }
    if ((Get-Item $Path).PSIsContainer) {
        Copy-Item $Path $dest -Recurse -Force
    } else {
        Copy-Item $Path $dest -Force
    }
}

New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null

# Back up all Kilo project config locations that can participate in merging.
$projectConfigCandidates = @(
    (Join-Path $ProjectRoot ".kilo\kilo.jsonc"),
    (Join-Path $ProjectRoot ".kilo\kilo.json"),
    (Join-Path $ProjectRoot "kilo.jsonc"),
    (Join-Path $ProjectRoot "kilo.json"),
    (Join-Path $ProjectRoot ".kilocode\kilo.jsonc"),
    (Join-Path $ProjectRoot ".kilocode\kilo.json"),
    (Join-Path $ProjectRoot ".kilocode\config.jsonc"),
    (Join-Path $ProjectRoot ".kilocode\config.json")
)
foreach ($p in $projectConfigCandidates) { Backup-Path $p }
Backup-Path (Join-Path $ProjectRoot ".kilo\agents")

# Install canonical V4.5.2 .kilo config and agents.
New-Item -ItemType Directory -Force -Path (Join-Path $TargetKilo "agents") | Out-Null
Copy-Item (Join-Path $SourceKilo "kilo.jsonc") (Join-Path $TargetKilo "kilo.jsonc") -Force
Get-ChildItem (Join-Path $SourceKilo "agents") -File | ForEach-Object {
    Copy-Item $_.FullName (Join-Path $TargetKilo "agents\$($_.Name)") -Force
}

# Remove same-directory JSON duplicate so .kilo/kilo.jsonc is the single canonical .kilo project config.
$duplicateKiloJson = Join-Path $TargetKilo "kilo.json"
if (Test-Path $duplicateKiloJson) { Remove-Item $duplicateKiloJson -Force }

# If a root-level Kilo config already exists, synchronize it to the same canonical config.
# This prevents a stale root permission rule such as bash:* = deny from surviving the hotfix.
foreach ($rootConfig in @((Join-Path $ProjectRoot "kilo.jsonc"), (Join-Path $ProjectRoot "kilo.json"))) {
    if (Test-Path $rootConfig) {
        Copy-Item (Join-Path $SourceKilo "kilo.jsonc") $rootConfig -Force
    }
}

# Disable legacy .kilocode project config files after backing them up.
# Agent/rule files are left untouched; only legacy config files that can inject permissions are disabled.
$legacyConfigs = @(
    (Join-Path $ProjectRoot ".kilocode\kilo.jsonc"),
    (Join-Path $ProjectRoot ".kilocode\kilo.json"),
    (Join-Path $ProjectRoot ".kilocode\config.jsonc"),
    (Join-Path $ProjectRoot ".kilocode\config.json")
)
foreach ($legacy in $legacyConfigs) {
    if (Test-Path $legacy) {
        $disabled = "$legacy.mamona-4.5.2.disabled"
        if (Test-Path $disabled) { Remove-Item $disabled -Force }
        Move-Item $legacy $disabled -Force
    }
}

# Warn about OpenCode project configs because Kilo's runtime may also discover compatible project config sources.
$openCodeCandidates = @((Join-Path $ProjectRoot "opencode.json"), (Join-Path $ProjectRoot "opencode.jsonc"))
foreach ($oc in $openCodeCandidates) {
    if (Test-Path $oc) {
        $raw = Get-Content $oc -Raw
        if ($raw -match '(?is)"permission"\s*:\s*\{.*?"bash"\s*:\s*("deny"|\{.*?"\*"\s*:\s*"deny")') {
            Write-Host "WARNING: possible bash deny in $oc" -ForegroundColor Yellow
            Write-Host "The installer did NOT modify OpenCode config. Run verify-mamona-4.5.2.ps1 and inspect this file if Kilo still reports source: project." -ForegroundColor Yellow
        }
    }
}

Write-Host ""
Write-Host "MAMONA 4.5.2 PROJECT PERMISSION CEILING HOTFIX INSTALLED" -ForegroundColor Green
Write-Host "Project: $ProjectRoot"
Write-Host "Backup:  $BackupRoot"
Write-Host ""
Write-Host "What changed:" -ForegroundColor Cyan
Write-Host "- canonical project bash/edit/task/external_directory ceiling is explicit ALLOW"
Write-Host "- coordinator keeps destructive shell commands DENY at agent level"
Write-Host "- executor remains command-only with edit/task DENY and exact PHP/test allowlist"
Write-Host "- stale root Kilo config is synchronized if present"
Write-Host "- legacy .kilocode config files are backed up and disabled"
Write-Host ""
Write-Host "Next:" -ForegroundColor Cyan
Write-Host "1. Run: powershell -ExecutionPolicy Bypass -File .\verify-mamona-4.5.2.ps1"
Write-Host "2. Ctrl+Shift+P -> Developer: Reload Window"
Write-Host "3. Start a NEW Kilo session with mamona-coordinator"
Write-Host "4. Paste PROMPT_RESUME_P4_AFTER_4_5_2.md"
Write-Host ""
Write-Host "No PHP source, docs, DB, Ollama or models were modified by this installer."
