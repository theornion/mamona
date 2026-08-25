param(
    [string]$ProjectRoot = "C:\Projekty\mamona"
)

$ErrorActionPreference = "Stop"
$PackRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$SourceKilo = Join-Path $PackRoot ".kilo"
$TargetKilo = Join-Path $ProjectRoot ".kilo"
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$BackupRoot = Join-Path $ProjectRoot "_agent_backups\mamona-4.5.1-$stamp"

if (-not (Test-Path $ProjectRoot)) { throw "ProjectRoot not found: $ProjectRoot" }
if (-not (Test-Path $SourceKilo)) { throw "Package .kilo directory missing: $SourceKilo" }

New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null
if (Test-Path (Join-Path $TargetKilo "kilo.jsonc")) {
    Copy-Item (Join-Path $TargetKilo "kilo.jsonc") (Join-Path $BackupRoot "kilo.jsonc") -Force
}
if (Test-Path (Join-Path $TargetKilo "agents")) {
    Copy-Item (Join-Path $TargetKilo "agents") (Join-Path $BackupRoot "agents") -Recurse -Force
}

New-Item -ItemType Directory -Force -Path (Join-Path $TargetKilo "agents") | Out-Null
Copy-Item (Join-Path $SourceKilo "kilo.jsonc") (Join-Path $TargetKilo "kilo.jsonc") -Force
Get-ChildItem (Join-Path $SourceKilo "agents") -File | ForEach-Object {
    Copy-Item $_.FullName (Join-Path $TargetKilo "agents\$($_.Name)") -Force
}

Write-Host ""
Write-Host "MAMONA 4.5.1 PERMISSION HOTFIX INSTALLED" -ForegroundColor Green
Write-Host "Project: $ProjectRoot"
Write-Host "Backup:  $BackupRoot"
Write-Host ""
Write-Host "Next:" -ForegroundColor Cyan
Write-Host "1. Ctrl+Shift+P -> Developer: Reload Window"
Write-Host "2. Start a NEW Kilo session with mamona-coordinator"
Write-Host "3. Run verify-mamona-4.5.1.ps1 (optional)"
Write-Host "4. Paste PROMPT_RESUME_P4_AFTER_4_5_1.md"
Write-Host ""
Write-Host "No PHP source, docs, DB, Ollama or models were modified by this installer."
