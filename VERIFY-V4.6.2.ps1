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
  if ($ct -notmatch 'edit:\s*deny') { Bad "coordinator edit is not DENY" } else { Good "coordinator is strict non-writer" }
  if ($ct -match 'edit:\s*allow') { Bad "coordinator still contains edit allow" }
  if ($ct -notmatch 'agent_manager:\s*deny') { Bad "coordinator agent_manager not denied" } else { Good "coordinator uses Task-only routing" }
  if ($ct -notmatch '"mamona-quick-worker": allow') { Bad "quick-worker not routable" } else { Good "quick-worker routing present" }
  if ($ct -notmatch '"mamona-worker": allow') { Bad "worker not routable" } else { Good "worker routing present" }
  if ($ct -notmatch '"mamona-heavy-coder": allow') { Bad "heavy-coder not routable" } else { Good "heavy routing present" }
  if ($ct -notmatch '"checkpoint-writer": allow') { Bad "checkpoint-writer not routable" } else { Good "checkpoint routing present" }
  if ($ct -match 'bash:\s*\r?\n\s+"\*": allow') { Bad "coordinator has unrestricted bash" } else { Good "coordinator bash is not globally allowed" }
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
Write-Host "VERIFY PASS — MAMONA V4.6.2 COORDINATOR SEPARATION READY" -ForegroundColor Green
exit 0
