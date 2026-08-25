param(
    [string]$ProjectRoot = "C:\Projekty\mamona"
)
$ErrorActionPreference = "Stop"
$fail = $false
function Check($cond, $ok, $bad) {
    if ($cond) { Write-Host "PASS  $ok" -ForegroundColor Green }
    else { Write-Host "FAIL  $bad" -ForegroundColor Red; $script:fail = $true }
}

$coord = Join-Path $ProjectRoot ".kilo\agents\mamona-coordinator.md"
$exec  = Join-Path $ProjectRoot ".kilo\agents\mamona-executor.md"
$config= Join-Path $ProjectRoot ".kilo\kilo.jsonc"

Check (Test-Path $coord) "mamona-coordinator exists" "mamona-coordinator missing"
Check (Test-Path $exec) "mamona-executor exists" "mamona-executor missing"
Check (Test-Path $config) "kilo.jsonc exists" "kilo.jsonc missing"

if (Test-Path $coord) {
    $c = Get-Content $coord -Raw
    Check ($c -match 'edit:\s*allow') "coordinator edit allow" "coordinator edit allow missing"
    Check ($c -match 'task:\s*allow') "coordinator task allow" "coordinator task allow missing"
    Check ($c -match 'C:/xampp/php/\*') "coordinator XAMPP external allow" "coordinator XAMPP external allow missing"
    Check ($c -match 'C:/xampp/php/php\.exe tests/\*') "coordinator PHP test allow" "coordinator PHP test allow missing"
}
if (Test-Path $exec) {
    $e = Get-Content $exec -Raw
    Check ($e -match 'C:/xampp/php/\*') "executor XAMPP external allow" "executor XAMPP external allow missing"
    Check ($e -match 'C:/xampp/php/php\.exe tests/\*') "executor PHP test allow" "executor PHP test allow missing"
    Check ($e -match 'edit:\s*deny') "executor edit deny" "executor must remain edit deny"
    Check ($e -match 'task:\s*deny') "executor task deny" "executor must remain task deny"
}
if (Test-Path $config) {
    try {
        $raw = Get-Content $config -Raw
        $null = $raw | ConvertFrom-Json
        Check $true "kilo.jsonc parses as JSON" ""
        Check ($raw -match 'C:/xampp/php/\*') "project XAMPP external allow present" "project XAMPP external allow missing"
    } catch { Check $false "" "kilo.jsonc parse failed: $_" }
}

$php = "C:\xampp\php\php.exe"
if (Test-Path $php) {
    & $php -v | Select-Object -First 1
    Check ($LASTEXITCODE -eq 0) "local XAMPP PHP executable works outside Kilo" "local XAMPP PHP execution failed"
} else {
    Write-Host "WARN  C:\xampp\php\php.exe not found on this machine" -ForegroundColor Yellow
}

if ($fail) { Write-Host "VERIFY FAIL" -ForegroundColor Red; exit 1 }
Write-Host "VERIFY PASS" -ForegroundColor Green
