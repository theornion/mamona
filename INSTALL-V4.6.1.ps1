param([string]$ProjectRoot = "C:\Projekty\mamona")
$ErrorActionPreference = "Stop"
$PackageRoot = $PSScriptRoot
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backup = Join-Path $ProjectRoot "_agent-backups\pre-v4.6.1-$stamp"

Write-Host "Mamona V4.6.1 Tri-Tier Parallel Schema Hotfix" -ForegroundColor Cyan
Write-Host "Project: $ProjectRoot"
Write-Host "Backup:  $backup"

New-Item -ItemType Directory -Force -Path $backup | Out-Null

# Backup current project configs/instructions/agents. Never touch CURRENT_WORK/research/code.
foreach ($rel in @("AGENTS.md", "kilo.jsonc", "kilo.json", ".kilo\kilo.jsonc", ".kilo\kilo.json", "docs\AGENT_EXECUTION_PROTOCOL.md", "docs\TRI-TIER-ROUTING-V4.5.md", "docs\TRI-TIER-ROUTING-V4.6.md")) {
  $src = Join-Path $ProjectRoot $rel
  if (Test-Path $src) {
    $dst = Join-Path $backup $rel
    New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
    Copy-Item $src $dst -Force
  }
}

$agentsDir = Join-Path $ProjectRoot ".kilo\agents"
if (Test-Path $agentsDir) {
  $agentsBackup = Join-Path $backup ".kilo\agents"
  New-Item -ItemType Directory -Force -Path $agentsBackup | Out-Null
  Get-ChildItem $agentsDir -Filter "*.md" -File | ForEach-Object { Copy-Item $_.FullName $agentsBackup -Force }
}

# Remove known Mamona legacy roles to avoid duplicate/contradictory routing.
$legacy = @(
  "mamona-orchestrator.md", "mamona-tester.md", "mamona-coder.md", "repo-scout.md", "quick-maintainer.md",
  "mamona-heavy-auditor.md", "mamona-heavy-worker.md", "mamona-coordinator.md", "mamona-executor.md",
  "mamona-quick-worker.md", "mamona-diagnoser.md", "mamona-architect.md", "mamona-worker.md",
  "mamona-reviewer.md", "mamona-heavy-coder.md", "checkpoint-writer.md"
)
New-Item -ItemType Directory -Force -Path $agentsDir | Out-Null
foreach ($name in $legacy) {
  $p = Join-Path $agentsDir $name
  if (Test-Path $p) { Remove-Item $p -Force }
}

# Install canonical files.
foreach ($rel in @("AGENTS.md", "kilo.jsonc", ".kilo\kilo.jsonc", ".kilo\results\.gitignore", "docs\AGENT_EXECUTION_PROTOCOL.md", "docs\TRI-TIER-ROUTING-V4.6.md")) {
  $src = Join-Path $PackageRoot $rel
  $dst = Join-Path $ProjectRoot $rel
  New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
  Copy-Item $src $dst -Force
}

Get-ChildItem (Join-Path $PackageRoot ".kilo\agents") -Filter "*.md" -File | ForEach-Object {
  Copy-Item $_.FullName (Join-Path $agentsDir $_.Name) -Force
}

# Remove alternate JSON config only after backup; keep two identical canonical JSONC copies to satisfy either loader location.
foreach ($rel in @("kilo.json", ".kilo\kilo.json")) {
  $p = Join-Path $ProjectRoot $rel
  if (Test-Path $p) { Remove-Item $p -Force }
}

$backup | Out-File -Encoding utf8 (Join-Path $ProjectRoot ".kilo\V4.6.1-LAST-BACKUP.txt")

Write-Host "" 
Write-Host "V4.6.1 installed." -ForegroundColor Green
Write-Host "CURRENT_WORK, checkpoints, research and source code were NOT overwritten."
Write-Host "Next:"
Write-Host "1. powershell -ExecutionPolicy Bypass -File .\VERIFY-V4.6.1.ps1 -ProjectRoot `"$ProjectRoot`""
Write-Host "2. Ctrl+Shift+P -> Developer: Reload Window"
Write-Host "3. New Kilo session -> mamona-coordinator"
Write-Host "4. Select your primary model in UI"
Write-Host "5. Paste PROMPT_START_MAMONA_V4.6.1.md"
