param([string]$ProjectRoot = "C:\Projekty\mamona")
$ErrorActionPreference = "Stop"
$fail = $false
function Bad($m) { Write-Host "FAIL: $m" -ForegroundColor Red; $script:fail = $true }
function Good($m) { Write-Host "PASS: $m" -ForegroundColor Green }

$cfgA = Join-Path $ProjectRoot ".kilo\kilo.jsonc"
$cfgB = Join-Path $ProjectRoot "kilo.jsonc"
if (!(Test-Path $cfgA)) { Bad "missing .kilo/kilo.jsonc" } else { Good ".kilo/kilo.jsonc exists" }
if (!(Test-Path $cfgB)) { Bad "missing root kilo.jsonc" } else { Good "root kilo.jsonc exists" }
if ((Test-Path $cfgA) -and (Test-Path $cfgB)) {
  if ((Get-FileHash $cfgA).Hash -ne (Get-FileHash $cfgB).Hash) { Bad "root and .kilo config differ" } else { Good "project configs identical" }
}

$expected = @("mamona-coordinator","mamona-executor","mamona-quick-worker","mamona-diagnoser","mamona-architect","mamona-worker","mamona-reviewer","mamona-heavy-coder","checkpoint-writer")
foreach ($a in $expected) {
  $p = Join-Path $ProjectRoot ".kilo\agents\$a.md"
  if (!(Test-Path $p)) { Bad "missing agent $a" } else { Good "agent $a" }
}

$legacy = @("mamona-orchestrator","mamona-heavy-auditor","mamona-tester","mamona-coder","repo-scout","quick-maintainer")
foreach ($a in $legacy) {
  if (Test-Path (Join-Path $ProjectRoot ".kilo\agents\$a.md")) { Bad "legacy agent still active: $a" }
}

if (Test-Path $cfgA) {
  $txt = Get-Content $cfgA -Raw
  foreach ($id in @("mamona-coder30-128k","mamona-qwen14-64k","mamona-qwen9-64k")) {
    if ($txt -notmatch [regex]::Escape($id)) { Bad "model id missing: $id" } else { Good "model id $id" }
  }
  if ($txt -match 'mamona-qwen14-fast-64k|mamona-qwen9-fast-64k') { Bad "obsolete fake fast alias remains" } else { Good "no fake fast aliases" }
  if ($txt -notmatch '"agent_manager"\s*:\s*\{\s*"\*"\s*:\s*"deny"') { Bad "agent_manager is not project-denied" } else { Good "agent_manager denied; Task remains canonical" }
}

$coord = Join-Path $ProjectRoot ".kilo\agents\mamona-coordinator.md"
if (Test-Path $coord) {
  $ct = Get-Content $coord -Raw
  if ($ct -notmatch 'edit:\s*allow') { Bad "coordinator edit ceiling is not ALLOW" } else { Good "coordinator edit ceiling allows child writers" }
  if ($ct -notmatch 'write:\s*allow') { Bad "coordinator write ceiling is not ALLOW" } else { Good "coordinator write ceiling allows child file creation" }
  if ($ct -notmatch 'bash:\s*\r?\n\s+"\*": allow') { Bad "coordinator bash ceiling is not broad ALLOW" } else { Good "coordinator bash ceiling allows child executors" }
  if ($ct -notmatch 'NIGDY sam nie wywołuj `edit` ani `write`') { Bad "coordinator zero-direct-write contract missing" } else { Good "zero-direct-write behavioral contract present" }
  if ($ct -notmatch 'agent_manager:\s*deny') { Bad "coordinator agent_manager not denied" } else { Good "coordinator uses Task-only routing" }
  foreach ($a in @("mamona-quick-worker","mamona-worker","mamona-heavy-coder","checkpoint-writer","mamona-executor")) {
    if ($ct -notmatch ('"' + [regex]::Escape($a) + '": allow')) { Bad "$a not routable" } else { Good "$a routing present" }
  }
}

$cpw = Join-Path $ProjectRoot ".kilo\agents\checkpoint-writer.md"
if (Test-Path $cpw) {
  $wt = Get-Content $cpw -Raw
  if ($wt -notmatch 'write:\s*\r?\n\s+"\*": deny') { Bad "checkpoint-writer write map missing" } else { Good "checkpoint-writer has explicit write permission map" }
  if ($wt -notmatch '"docs/\*\*/\*.md": allow') { Bad "checkpoint-writer nested docs write allow missing" } else { Good "checkpoint-writer nested docs writes allowed" }
}

foreach ($a in @("mamona-quick-worker","mamona-worker","mamona-heavy-coder")) {
  $ap = Join-Path $ProjectRoot ".kilo\agents\$a.md"
  if (Test-Path $ap) {
    $at = Get-Content $ap -Raw
    if ($at -notmatch 'write:\s*allow') { Bad "$a cannot create new files (write allow missing)" } else { Good "$a write allow" }
  }
}

Write-Host ""
Write-Host "Kilo schema validation..." -ForegroundColor Cyan
Push-Location $ProjectRoot
try {
  $kiloCmd = Get-Command kilo.cmd -ErrorAction SilentlyContinue
  if (-not $kiloCmd) {
    Bad "kilo.cmd not found in PATH; cannot validate Kilo schema"
  } else {
    $schemaOutput = & kilo.cmd config check 2>&1
    if ($LASTEXITCODE -ne 0) {
      $schemaOutput | ForEach-Object { Write-Host $_ -ForegroundColor Red }
      Bad "kilo.cmd config check failed"
    } else {
      $schemaOutput | ForEach-Object { Write-Host $_ }
      Good "kilo.cmd config check"
    }
  }
}
finally { Pop-Location }

if ($fail) { Write-Host "VERIFY FAILED" -ForegroundColor Red; exit 1 }
Write-Host "VERIFY PASS — MAMONA V4.6.3 DELEGATION CEILING READY" -ForegroundColor Green
exit 0
