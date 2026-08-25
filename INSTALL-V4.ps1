param([string]$ProjectRoot = "C:\Projekty\mamona")
$ErrorActionPreference = "Stop"
$PackageRoot = $PSScriptRoot
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backup = Join-Path $ProjectRoot "_agent-backups\pre-v4.5-$stamp"

Write-Host "Mamona Kilo Agents V4.5 Tri-Tier Parallel" -ForegroundColor Cyan
Write-Host "Project: $ProjectRoot"
Write-Host "Backup:  $backup"

New-Item -ItemType Directory -Force -Path $backup | Out-Null

# Backup configuration/agents/protocol. CURRENT_WORK and research checkpoints are preserved and NOT overwritten.
foreach ($item in @(
  "AGENTS.md",
  ".kilo\kilo.jsonc",
  "docs\AGENT_EXECUTION_PROTOCOL.md",
  "docs\HYBRID-ROUTING-V4.1.md",
  "docs\PARALLEL-EXECUTION-V4.2.md",
  "docs\HYBRID-ROUTING-V4.3.md",
  "docs\TRI-TIER-ROUTING-V4.5.md"
)) {
  $src = Join-Path $ProjectRoot $item
  if (Test-Path $src) {
    $dst = Join-Path $backup $item
    New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
    Copy-Item $src $dst -Force
  }
}

$agentsSrc = Join-Path $ProjectRoot ".kilo\agents"
if (Test-Path $agentsSrc) {
  $agentsBackup = Join-Path $backup ".kilo\agents"
  New-Item -ItemType Directory -Force -Path $agentsBackup | Out-Null
  Copy-Item (Join-Path $agentsSrc "*.md") $agentsBackup -Force
}

# Remove superseded Mamona runtime roles only.
$legacy = @(
  "mamona-orchestrator.md",
  "mamona-tester.md",
  "mamona-coder.md",
  "quick-maintainer.md",
  "repo-scout.md",
  "mamona-heavy-worker.md"
)
foreach ($name in $legacy) {
  $p = Join-Path $ProjectRoot ".kilo\agents\$name"
  if (Test-Path $p) { Remove-Item $p -Force }
}

# Remove obsolete routing docs to avoid contradictory instructions; research/checkpoints remain untouched.
foreach ($name in @(
  "docs\HYBRID-ROUTING-V4.1.md",
  "docs\PARALLEL-EXECUTION-V4.2.md",
  "docs\HYBRID-ROUTING-V4.3.md"
)) {
  $p = Join-Path $ProjectRoot $name
  if (Test-Path $p) { Remove-Item $p -Force }
}

foreach ($item in @(
  "AGENTS.md",
  ".kilo\kilo.jsonc",
  ".kilo\results\.gitignore",
  "docs\AGENT_EXECUTION_PROTOCOL.md",
  "docs\TRI-TIER-ROUTING-V4.5.md",
  "docs\CODEX-IN-KILO-V4.md"
)) {
  $src = Join-Path $PackageRoot $item
  $dst = Join-Path $ProjectRoot $item
  New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
  Copy-Item $src $dst -Force
}

$targetAgents = Join-Path $ProjectRoot ".kilo\agents"
New-Item -ItemType Directory -Force -Path $targetAgents | Out-Null
Get-ChildItem (Join-Path $PackageRoot ".kilo\agents") -Filter "*.md" -File |
  ForEach-Object { Copy-Item $_.FullName (Join-Path $targetAgents $_.Name) -Force }

$marker = Join-Path $ProjectRoot ".kilo\V4-LAST-BACKUP.txt"
$backup | Out-File -Encoding utf8 $marker

Write-Host ""
Write-Host "V4.5 Tri-Tier Parallel zainstalowane." -ForegroundColor Green
Write-Host "UWAGA: docs\CURRENT_WORK.md i docs\research\* NIE zostały nadpisane."
Write-Host "1. Ctrl+Shift+P -> Developer: Reload Window"
Write-Host "2. kilo.cmd agent list"
Write-Host "3. Wybierz primary: mamona-coordinator"
Write-Host "4. W model pickerze: OpenAI / GPT-5.6 Sol Pro / Low"
