param([string]$ProjectRoot = "C:\Projekty\mamona")
$ErrorActionPreference = "Stop"
$marker = Join-Path $ProjectRoot ".kilo\V4-LAST-BACKUP.txt"
if (!(Test-Path $marker)) { throw "Brak .kilo\V4-LAST-BACKUP.txt" }
$backup = (Get-Content $marker -Raw).Trim()
if (!(Test-Path $backup)) { throw "Backup nie istnieje: $backup" }

Write-Host "Przywracam: $backup" -ForegroundColor Yellow

# Remove V4.5-only runtime roles/routing before restoring backup.
foreach ($name in @("mamona-heavy-coder.md", "mamona-quick-worker.md")) {
  $p = Join-Path $ProjectRoot ".kilo\agents\$name"
  if (Test-Path $p) { Remove-Item $p -Force }
}
$v45Routing = Join-Path $ProjectRoot "docs\TRI-TIER-ROUTING-V4.5.md"
if (Test-Path $v45Routing) { Remove-Item $v45Routing -Force }

foreach ($item in @(
  "AGENTS.md",
  ".kilo\kilo.jsonc",
  ".kilo\agents",
  "docs\AGENT_EXECUTION_PROTOCOL.md",
  "docs\HYBRID-ROUTING-V4.1.md",
  "docs\PARALLEL-EXECUTION-V4.2.md",
  "docs\HYBRID-ROUTING-V4.3.md",
  "docs\TRI-TIER-ROUTING-V4.5.md"
)) {
  $src = Join-Path $backup $item
  if (Test-Path $src) {
    $dst = Join-Path $ProjectRoot $item
    if ($item -eq ".kilo\agents" -and (Test-Path $dst)) { Remove-Item $dst -Recurse -Force }
    New-Item -ItemType Directory -Force -Path (Split-Path $dst -Parent) | Out-Null
    Copy-Item $src $dst -Recurse -Force
  }
}

Write-Host "Rollback gotowy. CURRENT_WORK i research checkpoints nie były ruszane przez V4.5." -ForegroundColor Green
Write-Host "Developer: Reload Window"
