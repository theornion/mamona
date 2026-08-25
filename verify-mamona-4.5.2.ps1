param(
    [string]$ProjectRoot = "C:\Projekty\mamona"
)

$ErrorActionPreference = "Stop"
$fail = $false
function Check($cond, $ok, $bad) {
    if ($cond) { Write-Host "PASS  $ok" -ForegroundColor Green }
    else { Write-Host "FAIL  $bad" -ForegroundColor Red; $script:fail = $true }
}
function Warn([string]$text) { Write-Host "WARN  $text" -ForegroundColor Yellow }

$coord  = Join-Path $ProjectRoot ".kilo\agents\mamona-coordinator.md"
$exec   = Join-Path $ProjectRoot ".kilo\agents\mamona-executor.md"
$config = Join-Path $ProjectRoot ".kilo\kilo.jsonc"

Check (Test-Path $coord) "mamona-coordinator exists" "mamona-coordinator missing"
Check (Test-Path $exec) "mamona-executor exists" "mamona-executor missing"
Check (Test-Path $config) ".kilo/kilo.jsonc exists" ".kilo/kilo.jsonc missing"

if (Test-Path $coord) {
    $c = Get-Content $coord -Raw
    Check ($c -match '# Mamona Coordinator 4\.5\.2') "coordinator version 4.5.2" "coordinator is not 4.5.2"
    Check ($c -match 'edit:\s*allow') "coordinator edit allow" "coordinator edit allow missing"
    Check ($c -match 'task:\s*allow') "coordinator task allow" "coordinator task allow missing"
    Check ($c -match '(?ms)bash:\s*\r?\n\s+"\*":\s*allow') "coordinator bash default allow" "coordinator bash default allow missing"
    Check ($c -match 'git reset \*": deny') "coordinator destructive git deny present" "coordinator git reset deny missing"
    Check ($c -match 'C:/xampp/php/\*') "coordinator XAMPP external allow" "coordinator XAMPP external allow missing"
}

if (Test-Path $exec) {
    $e = Get-Content $exec -Raw
    Check ($e -match '# Mamona Executor 4\.5\.2') "executor version 4.5.2" "executor is not 4.5.2"
    Check ($e -match 'edit:\s*deny') "executor edit deny" "executor must remain edit deny"
    Check ($e -match 'task:\s*deny') "executor task deny" "executor must remain task deny"
    Check ($e -match 'C:/xampp/php/php\.exe tests/\*') "executor PHP test allow" "executor PHP test allow missing"
}

if (Test-Path $config) {
    try {
        $raw = Get-Content $config -Raw
        $json = $raw | ConvertFrom-Json
        Check $true ".kilo/kilo.jsonc parses as JSON" ""
        Check ($json.permission.bash.'*' -eq 'allow') "project bash:* allow" "project bash:* is not allow"
        Check ($json.permission.edit.'*' -eq 'allow') "project edit:* allow" "project edit:* is not allow"
        Check ($json.permission.task.'*' -eq 'allow') "project task:* allow" "project task:* is not allow"
        Check ($json.permission.external_directory.'*' -eq 'allow') "project external_directory:* allow" "project external_directory:* is not allow"
    } catch {
        Check $false "" ".kilo/kilo.jsonc parse failed: $_"
    }
}

# Active project config inventory. Any stale bash:* deny here is a hard failure.
$activeKiloConfigs = @(
    (Join-Path $ProjectRoot ".kilo\kilo.jsonc"),
    (Join-Path $ProjectRoot ".kilo\kilo.json"),
    (Join-Path $ProjectRoot "kilo.jsonc"),
    (Join-Path $ProjectRoot "kilo.json"),
    (Join-Path $ProjectRoot ".kilocode\kilo.jsonc"),
    (Join-Path $ProjectRoot ".kilocode\kilo.json"),
    (Join-Path $ProjectRoot ".kilocode\config.jsonc"),
    (Join-Path $ProjectRoot ".kilocode\config.json")
)

foreach ($p in $activeKiloConfigs) {
    if (-not (Test-Path $p)) { continue }
    $r = Get-Content $p -Raw
    $hasBashDeny = ($r -match '(?is)"bash"\s*:\s*"deny"') -or ($r -match '(?is)"bash"\s*:\s*\{.*?"\*"\s*:\s*"deny"')
    Check (-not $hasBashDeny) "no project bash:* deny in $p" "stale project bash deny found in $p"
}

Check (-not (Test-Path (Join-Path $ProjectRoot ".kilo\kilo.json"))) "no duplicate .kilo/kilo.json" "duplicate .kilo/kilo.json still active"

# Root config, if present, must be synchronized with canonical config.
foreach ($rootCfg in @((Join-Path $ProjectRoot "kilo.jsonc"), (Join-Path $ProjectRoot "kilo.json"))) {
    if (Test-Path $rootCfg -and Test-Path $config) {
        $h1 = (Get-FileHash $rootCfg -Algorithm SHA256).Hash
        $h2 = (Get-FileHash $config -Algorithm SHA256).Hash
        Check ($h1 -eq $h2) "root Kilo config synchronized: $rootCfg" "root Kilo config differs from .kilo/kilo.jsonc: $rootCfg"
    }
}

foreach ($oc in @((Join-Path $ProjectRoot "opencode.json"), (Join-Path $ProjectRoot "opencode.jsonc"))) {
    if (Test-Path $oc) {
        $r = Get-Content $oc -Raw
        if (($r -match '(?is)"bash"\s*:\s*"deny"') -or ($r -match '(?is)"bash"\s*:\s*\{.*?"\*"\s*:\s*"deny"')) {
            Warn "possible bash deny in $oc; Kilo may discover compatible project config sources. If runtime still says source: project, inspect this file."
        } else {
            Write-Host "PASS  no obvious bash deny in $oc" -ForegroundColor Green
        }
    }
}

$php = "C:\xampp\php\php.exe"
if (Test-Path $php) {
    & $php -v | Select-Object -First 1
    Check ($LASTEXITCODE -eq 0) "local XAMPP PHP executable works outside Kilo" "local XAMPP PHP execution failed"
} else {
    Warn "C:\xampp\php\php.exe not found on this machine"
}

if ($fail) {
    Write-Host "VERIFY FAIL" -ForegroundColor Red
    exit 1
}
Write-Host "VERIFY PASS — PROJECT PERMISSION CEILING READY" -ForegroundColor Green
