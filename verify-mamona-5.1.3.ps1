param(
    [string]$RepoRoot = "C:\Projekty\mamona"
)

$ErrorActionPreference = "Stop"
if (-not (Test-Path $RepoRoot)) { throw "Repo root does not exist: $RepoRoot" }

Write-Host "=== VERIFY MAMONA 5.1.3 ===" -ForegroundColor Cyan

$expected = @(
    "mamona-coordinator",
    "mamona-executor",
    "mamona-diagnoser",
    "mamona-worker",
    "mamona-reviewer",
    "mamona-architect",
    "mamona-heavy-coder",
    "checkpoint-writer"
)

foreach ($name in $expected) {
    $p = Join-Path $RepoRoot ".kilo\agents\$name.md"
    if (-not (Test-Path $p)) {
        Write-Host "[MISSING] $name" -ForegroundColor Red
        exit 2
    }
    Write-Host "[FILE OK] $name" -ForegroundColor Green
}

$obsolete = @(
    "mamona-orchestrator",
    "mamona-heavy-auditor",
    "mamona-quick-worker",
    "mamona-tester",
    "mamona-coder",
    "repo-scout",
    "quick-maintainer"
)
foreach ($name in $obsolete) {
    $p = Join-Path $RepoRoot ".kilo\agents\$name.md"
    if (Test-Path $p) {
        Write-Host "[OBSOLETE STILL PRESENT] $name" -ForegroundColor Red
        exit 3
    }
}

$coord = Get-Content (Join-Path $RepoRoot ".kilo\agents\mamona-coordinator.md") -Raw
if ($coord -notmatch 'Mamona Coordinator 5\.1\.3') { throw "Coordinator version marker is not 5.1.3" }
if ($coord -notmatch 'mode:\s*primary') { throw "Coordinator is not primary" }
if ($coord -notmatch 'model:\s*ollama/mamona-qwen14-64k') { throw "Coordinator model is not 14B V4.5 alias" }
if ($coord -notmatch 'task:\s*allow') { throw "Coordinator task permission is not allow" }
if ($coord -notmatch 'steps:\s*96') { throw "Coordinator autonomy step budget is not 96" }
if ($coord -match '"\*":\s*ask') { throw "Coordinator contains wildcard ask" }
Write-Host "[COORDINATOR OK] primary + 14B + task allow + autonomy rules" -ForegroundColor Green

foreach ($name in $expected | Where-Object { $_ -ne "mamona-coordinator" }) {
    $txt = Get-Content (Join-Path $RepoRoot ".kilo\agents\$name.md") -Raw
    if ($txt -notmatch 'task:\s*deny') { throw "$name missing task: deny" }
    if ($txt -match '"\*":\s*ask') { throw "$name contains wildcard ask" }
}
Write-Host "[CHILDREN OK] task deny + no wildcard ask" -ForegroundColor Green

$executor = Get-Content (Join-Path $RepoRoot ".kilo\agents\mamona-executor.md") -Raw
foreach ($needle in @('read:\s*deny', 'glob:\s*deny', 'grep:\s*deny', 'edit:\s*deny', 'steps:\s*5')) {
    if ($executor -notmatch $needle) { throw "Executor hardening missing: $needle" }
}
if ($executor -notmatch 'Execute, Report, Stop') { throw "Executor 5.1.3 contract marker missing" }
Write-Host "[EXECUTOR OK] command-only permissions" -ForegroundColor Green

$diag = Get-Content (Join-Path $RepoRoot ".kilo\agents\mamona-diagnoser.md") -Raw
if ($diag -notmatch 'Evidence First') { throw "Diagnoser evidence-first marker missing" }
if ($diag -notmatch 'NO_DEFINITION_EXISTS') { throw "Diagnoser negative-claim guard missing" }
Write-Host "[DIAGNOSER OK] evidence-first guards" -ForegroundColor Green

$protocol = Get-Content (Join-Path $RepoRoot "docs\AGENT_EXECUTION_PROTOCOL.md") -Raw
foreach ($marker in @('WRITE_VERIFICATION', 'EVIDENCE_BATCHES', 'REPORT_REPAIR', 'AUTONOMY_TARGET_MINUTES')) {
    if ($protocol -notmatch $marker) { throw "Protocol marker missing: $marker" }
}
Write-Host "[PROTOCOL OK] evidence/write/ledger/autonomy contracts present" -ForegroundColor Green

$config = Get-Content (Join-Path $RepoRoot ".kilo\kilo.jsonc") -Raw
foreach ($alias in @("mamona-coder30-128k", "mamona-qwen14-64k", "mamona-qwen9-64k")) {
    if ($config -notmatch [regex]::Escape($alias)) { throw "Missing model alias: $alias" }
}
if ($config -notmatch '"threshold_percent"\s*:\s*60') { throw "Compaction threshold is not 60" }
if ($config -notmatch '"preserve_recent_tokens"\s*:\s*8000') { throw "Preserve recent tokens is not 8000" }
Write-Host "[CONFIG OK] V4.5 aliases + long-session compaction" -ForegroundColor Green

$runbook = Get-Content (Join-Path $RepoRoot "docs\AUTONOMY_2H_RUNBOOK.md") -Raw
if ($runbook -notmatch 'ATOM BLOCKED -> PARK -> NEXT READY ATOM') { throw "2H autonomy runbook marker missing" }
Write-Host "[RUNBOOK OK] blocked atom continues to next READY atom" -ForegroundColor Green

$prompt = Get-Content (Join-Path $RepoRoot "PROMPT_RESUME_P4_AFTER_5_1_3.md") -Raw
if ($prompt -notmatch 'AUTONOMY_TARGET_MINUTES:\s*120') { throw "Resume prompt does not request 120-minute autonomy" }
Write-Host "[PROMPT OK] 120-minute autonomous recovery" -ForegroundColor Green

$kiloCmd = Get-Command kilo.cmd -ErrorAction SilentlyContinue
if ($null -ne $kiloCmd) {
    Write-Host ""
    Write-Host "Kilo registry (informational):" -ForegroundColor Cyan
    try {
        & kilo.cmd agent list
    } catch {
        Write-Host "kilo.cmd agent list failed; file-level verification above still passed." -ForegroundColor Yellow
    }
} else {
    Write-Host "[INFO] kilo.cmd not found in this shell; skipped live registry listing." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "VERIFY PASS" -ForegroundColor Green
Write-Host "Reload VS Code, select mamona-coordinator, paste PROMPT_RESUME_P4_AFTER_5_1_3.md, then let it run unattended." -ForegroundColor Cyan
