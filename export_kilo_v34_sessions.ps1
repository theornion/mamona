param(
    [string]$ProjectRoot = "C:\Projekty\mamona",
    [int]$RecentCount = 80,
    [int]$MaxSessions = 300
)

$ErrorActionPreference = "Continue"
Set-Location $ProjectRoot

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$handoffRoot = Join-Path $ProjectRoot "_handoff"
$out = Join-Path $handoffRoot "kilo-v34-sessions-$stamp"
$sessionsDir = Join-Path $out "sessions"
$snapshotDir = Join-Path $out "project-snapshot"

New-Item -ItemType Directory -Force -Path $sessionsDir | Out-Null
New-Item -ItemType Directory -Force -Path $snapshotDir | Out-Null

Write-Host "=== KILO V3.4 SESSION EXPORT ===" -ForegroundColor Cyan
Write-Host "Project: $ProjectRoot"
Write-Host "Output:  $out"

try { & kilo.cmd --version 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-version.txt") } catch {}
try { & kilo.cmd agent list 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-agent-list.txt") } catch {}
try { & kilo.cmd stats --days 1 --models 50 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-stats-today.txt") } catch {}

$listPath = Join-Path $out "session-list-recent.txt"
& kilo.cmd session list --all -n $RecentCount --format table 2>&1 |
    Tee-Object -FilePath $listPath |
    Out-Host

$rawList = Get-Content $listPath -Raw
$initialIds = [regex]::Matches($rawList, 'ses_[A-Za-z0-9]+') |
    ForEach-Object { $_.Value } |
    Select-Object -Unique

if (-not $initialIds) {
    Write-Host ""
    Write-Host "Nie znaleziono ID sesji w session-list-recent.txt." -ForegroundColor Red
    Write-Host "Otworz plik i sprawdz format listy: $listPath"
    exit 2
}

$queue = New-Object 'System.Collections.Generic.Queue[string]'
foreach ($id in $initialIds) { $queue.Enqueue($id) }

$seen = @{}
$failed = New-Object System.Collections.Generic.List[string]
$exportedCount = 0

while ($queue.Count -gt 0 -and $exportedCount -lt $MaxSessions) {
    $id = $queue.Dequeue()
    if ($seen.ContainsKey($id)) { continue }
    $seen[$id] = $true

    $dest = Join-Path $sessionsDir "$id.json"
    Write-Host "Export: $id"

    try {
        $content = & kilo.cmd export $id 2>$null
        if ($LASTEXITCODE -ne 0 -or -not $content) {
            $failed.Add($id)
            continue
        }

        $content | Out-File -Encoding utf8 $dest
        $exportedCount++

        $txt = Get-Content $dest -Raw
        $childIds = [regex]::Matches($txt, 'ses_[A-Za-z0-9]+') |
            ForEach-Object { $_.Value } |
            Select-Object -Unique

        foreach ($child in $childIds) {
            if (-not $seen.ContainsKey($child)) {
                $queue.Enqueue($child)
            }
        }
    }
    catch {
        $failed.Add($id)
    }
}

$seen.Keys | Sort-Object | Out-File -Encoding utf8 (Join-Path $out "session-ids-discovered.txt")
$failed | Sort-Object -Unique | Out-File -Encoding utf8 (Join-Path $out "session-export-failures.txt")

try { git status --short 2>&1 | Out-File -Encoding utf8 (Join-Path $out "git-status-short.txt") } catch {}
try { git diff --stat 2>&1 | Out-File -Encoding utf8 (Join-Path $out "git-diff-stat.txt") } catch {}
try { git diff --name-only 2>&1 | Out-File -Encoding utf8 (Join-Path $out "git-diff-name-only.txt") } catch {}
try { git diff 2>&1 | Out-File -Encoding utf8 (Join-Path $out "working-tree.patch") } catch {}

$copyItems = @(
    "AGENTS.md",
    "kilo.json",
    "kilo.jsonc",
    "opencode.json",
    "opencode.jsonc",
    ".kilo\agents",
    ".kilo\results",
    "docs\AGENT_EXECUTION_PROTOCOL.md",
    "docs\CURRENT_WORK.md"
)

foreach ($item in $copyItems) {
    $src = Join-Path $ProjectRoot $item
    if (Test-Path $src) {
        $target = Join-Path $snapshotDir $item
        $parent = Split-Path $target -Parent
        New-Item -ItemType Directory -Force -Path $parent | Out-Null
        Copy-Item $src $target -Recurse -Force
    }
}

$researchSrc = Join-Path $ProjectRoot "docs\research"
if (Test-Path $researchSrc) {
    $researchDst = Join-Path $snapshotDir "docs\research"
    New-Item -ItemType Directory -Force -Path $researchDst | Out-Null
    Get-ChildItem $researchSrc -File |
        Where-Object { $_.Name -match 'MAMONA-24|CHECKPOINT|P2|P3' } |
        ForEach-Object { Copy-Item $_.FullName (Join-Path $researchDst $_.Name) -Force }
}

@"
KILO V3.4 HANDOFF EXPORT
Created: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss zzz")
Project: $ProjectRoot

Seed sessions:
- $RecentCount most recent sessions from kilo session list --all
- table format intentionally used

Recursive discovery:
- every ses_* referenced in exported transcripts is also exported
- this is intended to capture Task-spawned subagents

Exported sessions: $exportedCount
Failed exports: $($failed.Count)
MaxSessions guard: $MaxSessions

NOTE:
This pack may contain a few sessions from before the V3.4 cutover.
That is intentional. Filter them later using transcript/session timestamps
rather than risking loss of a child session during collection.

Included:
- raw Kilo session JSON exports
- Kilo version / agent list / daily stats
- current agent configs and result files
- P2/P3 checkpoints/current-work docs
- git status/diff metadata and working-tree.patch

Excluded intentionally:
- global auth/credential stores
- .env files
- API tokens
"@ | Out-File -Encoding utf8 (Join-Path $out "README-HANDOFF.txt")

$zip = "$out.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path "$out\*" -DestinationPath $zip -CompressionLevel Optimal

Write-Host ""
Write-Host "=== GOTOWE ===" -ForegroundColor Green
Write-Host "Sesje wyeksportowane: $exportedCount"
Write-Host "Bledy eksportu:       $($failed.Count)"
Write-Host "ZIP:"
Write-Host $zip -ForegroundColor Yellow
