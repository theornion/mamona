param(
    [string]$ProjectRoot = "C:\Projekty\mamona",
    [int]$RecentCount = 500,
    [int]$MaxSessions = 1000
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

Write-Host "=== KILO V3.4 SESSION EXPORT V3 ===" -ForegroundColor Cyan
Write-Host "Project: $ProjectRoot"
Write-Host "Output:  $out"
Write-Host "Seed:    $RecentCount most recent DB sessions"

try { & kilo.cmd --version 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-version.txt") } catch {}
try { & kilo.cmd agent list 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-agent-list.txt") } catch {}
try { & kilo.cmd stats --days 1 --models 50 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-stats-today.txt") } catch {}
try { & kilo.cmd db path 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-db-path.txt") } catch {}

# IMPORTANT:
# Keep SQL on ONE physical line. In this Kilo/PowerShell combination,
# multiline SQL was reaching sqlite as incomplete input.
$sql = "SELECT id, COALESCE(parent_id,''), COALESCE(title,''), time_created FROM session ORDER BY time_created DESC LIMIT $RecentCount;"

$dbListPath = Join-Path $out "session-list-from-db.tsv"

Write-Host ""
Write-Host "Czytam $RecentCount najnowszych sesji z bazy Kilo..." -ForegroundColor Cyan
Write-Host "SQL jest jednowierszowy, bez filtrowania daty w SQLite."

try {
    $dbOutput = & kilo.cmd db "$sql" --format tsv 2>&1
    $dbExit = $LASTEXITCODE
    $dbOutput | Tee-Object -FilePath $dbListPath | Out-Host

    if ($dbExit -ne 0) {
        throw "kilo db exit code: $dbExit"
    }
}
catch {
    Write-Host ""
    Write-Host "Zapytanie DB nadal nie przeszlo." -ForegroundColor Red
    Write-Host "Uruchom recznie ten test:" -ForegroundColor Yellow
    Write-Host 'kilo.cmd db "SELECT id FROM session ORDER BY time_created DESC LIMIT 5;" --format tsv'
    $_ | Out-File -Encoding utf8 (Join-Path $out "db-query-error.txt")
    exit 2
}

$raw = Get-Content $dbListPath -Raw
$initialIds = [regex]::Matches($raw, 'ses_[A-Za-z0-9]+') |
    ForEach-Object { $_.Value } |
    Select-Object -Unique

if (-not $initialIds) {
    Write-Host ""
    Write-Host "DB odpowiedziala, ale nie znaleziono zadnych ses_*." -ForegroundColor Red
    Write-Host "Sprawdz: $dbListPath"
    exit 3
}

Write-Host ""
Write-Host "Seed sessions znalezione: $($initialIds.Count)" -ForegroundColor Green

# Export seed sessions and recursively follow every ses_* reference found in the JSON.
# We deliberately over-collect. The later analysis will filter by timestamps / V3.4 cutover.
$queue = New-Object 'System.Collections.Generic.Queue[string]'
foreach ($id in $initialIds) { $queue.Enqueue($id) }

$seen = @{}
$failed = New-Object System.Collections.Generic.List[string]
$exported = New-Object System.Collections.Generic.List[string]

while ($queue.Count -gt 0 -and $seen.Count -lt $MaxSessions) {
    $id = $queue.Dequeue()
    if ($seen.ContainsKey($id)) { continue }
    $seen[$id] = $true

    $dest = Join-Path $sessionsDir "$id.json"
    Write-Host "Export: $id"

    try {
        $content = & kilo.cmd export $id 2>&1
        $exitCode = $LASTEXITCODE

        if ($exitCode -ne 0 -or -not $content) {
            $failed.Add($id)
            $content | Out-File -Encoding utf8 (Join-Path $sessionsDir "$id.ERROR.txt")
            continue
        }

        $content | Out-File -Encoding utf8 $dest
        $exported.Add($id)

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
        $_ | Out-File -Encoding utf8 (Join-Path $sessionsDir "$id.ERROR.txt")
    }
}

$exported | Sort-Object | Out-File -Encoding utf8 (Join-Path $out "session-ids-exported.txt")
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
        ForEach-Object {
            Copy-Item $_.FullName (Join-Path $researchDst $_.Name) -Force
        }
}

@"
KILO V3.4 HANDOFF EXPORT V3
Created: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss zzz")
Project: $ProjectRoot

Collection strategy:
- `kilo session list` is broken in the current Kilo build.
- V2 multiline SQL produced `incomplete input`.
- V3 uses a ONE-LINE SQLite query through official `kilo db`.
- It deliberately exports the $RecentCount most recent DB sessions.
- It recursively follows any ses_* IDs referenced inside exported sessions.
- Final analysis should filter to the actual V3.4 cutover using session timestamps/content.

Initial seed sessions: $($initialIds.Count)
Successfully exported: $($exported.Count)
Failed exports: $($failed.Count)
All discovered IDs: $($seen.Count)

Included:
- raw session JSON
- parent/subagent references
- Kilo version / agent list / daily stats
- current agent configs and .kilo/results
- P2/P3 checkpoints / CURRENT_WORK
- git state and working-tree.patch

Excluded:
- auth stores
- .env
- API tokens/private keys
"@ | Out-File -Encoding utf8 (Join-Path $out "README-HANDOFF.txt")

$zip = "$out.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path "$out\*" -DestinationPath $zip -CompressionLevel Optimal

Write-Host ""
Write-Host "=== GOTOWE ===" -ForegroundColor Green
Write-Host "Seed sessions:         $($initialIds.Count)"
Write-Host "Wyeksportowane:        $($exported.Count)"
Write-Host "Bledy eksportu:        $($failed.Count)"
Write-Host "Wszystkie odkryte:     $($seen.Count)"
Write-Host ""
Write-Host "ZIP:" -ForegroundColor Cyan
Write-Host $zip -ForegroundColor Yellow
