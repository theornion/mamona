param(
    [string]$ProjectRoot = "C:\Projekty\mamona",
    [string]$SinceLocal = "2026-08-07 13:00:00",
    [int]$MaxSessions = 500
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

Write-Host "=== KILO V3.4 SESSION EXPORT V2 ===" -ForegroundColor Cyan
Write-Host "Project: $ProjectRoot"
Write-Host "Since:   $SinceLocal (local time)"
Write-Host "Output:  $out"

# Diagnostics / metadata
try { & kilo.cmd --version 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-version.txt") } catch {}
try { & kilo.cmd agent list 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-agent-list.txt") } catch {}
try { & kilo.cmd stats --days 1 --models 50 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-stats-today.txt") } catch {}
try { & kilo.cmd db path 2>&1 | Out-File -Encoding utf8 (Join-Path $out "kilo-db-path.txt") } catch {}
try { & kilo.cmd db "SELECT sql FROM sqlite_master WHERE type='table' AND name='session';" --format tsv 2>&1 |
    Out-File -Encoding utf8 (Join-Path $out "session-table-schema.txt") } catch {}

# kilo session list is broken in this Kilo build, so query SQLite through the official kilo db command.
# time_created is normally epoch milliseconds; CASE also tolerates epoch seconds.
$sinceEsc = $SinceLocal.Replace("'", "''")
$query = @"
SELECT
    id,
    COALESCE(parent_id, '') AS parent_id,
    COALESCE(title, '') AS title,
    COALESCE(directory, '') AS directory,
    datetime(
        CASE
            WHEN time_created > 100000000000 THEN time_created / 1000
            ELSE time_created
        END,
        'unixepoch',
        'localtime'
    ) AS created_local
FROM session
WHERE datetime(
        CASE
            WHEN time_created > 100000000000 THEN time_created / 1000
            ELSE time_created
        END,
        'unixepoch',
        'localtime'
      ) >= '$sinceEsc'
ORDER BY time_created ASC;
"@

$dbListPath = Join-Path $out "session-list-from-db.tsv"
Write-Host ""
Write-Host "Czytam sesje bezposrednio z bazy Kilo..." -ForegroundColor Cyan

try {
    $dbOutput = & kilo.cmd db $query --format tsv 2>&1
    $dbOutput | Tee-Object -FilePath $dbListPath | Out-Host
}
catch {
    Write-Host "Nie udalo sie wykonac zapytania do kilo db." -ForegroundColor Red
    $_ | Out-File -Encoding utf8 (Join-Path $out "db-query-error.txt")
    exit 2
}

$raw = Get-Content $dbListPath -Raw
$initialIds = [regex]::Matches($raw, 'ses_[A-Za-z0-9]+') |
    ForEach-Object { $_.Value } |
    Select-Object -Unique

if (-not $initialIds) {
    Write-Host ""
    Write-Host "Brak sesji od $SinceLocal." -ForegroundColor Red
    Write-Host "Sprawdz: $dbListPath"
    Write-Host "Mozesz uruchomic ponownie z wczesniejsza godzina, np.:"
    Write-Host 'powershell -ExecutionPolicy Bypass -File .\export_kilo_v34_sessions_v2.ps1 -SinceLocal "2026-08-07 12:00:00"'
    exit 3
}

Write-Host ""
Write-Host "Sesje znalezione w DB: $($initialIds.Count)" -ForegroundColor Green

# Export every matching session.
# Also recursively discover any referenced ses_* IDs from exports, to avoid losing nested child sessions.
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
        if ($LASTEXITCODE -ne 0 -or -not $content) {
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

# Git state
try { git status --short 2>&1 | Out-File -Encoding utf8 (Join-Path $out "git-status-short.txt") } catch {}
try { git diff --stat 2>&1 | Out-File -Encoding utf8 (Join-Path $out "git-diff-stat.txt") } catch {}
try { git diff --name-only 2>&1 | Out-File -Encoding utf8 (Join-Path $out "git-diff-name-only.txt") } catch {}
try { git diff 2>&1 | Out-File -Encoding utf8 (Join-Path $out "working-tree.patch") } catch {}

# Snapshot of agent config / results / checkpoints
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
KILO V3.4 HANDOFF EXPORT V2
Created: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss zzz")
Project: $ProjectRoot
Since local: $SinceLocal

WHY V2:
The current Kilo build crashes in `kilo session list` with:
undefined is not an object (evaluating 'f.id.length')

This exporter therefore uses the official `kilo db` command to query
the SQLite session table directly, then calls `kilo export <ses_...>`
for each discovered session.

Initial DB sessions: $($initialIds.Count)
Successfully exported: $($exported.Count)
Failed exports: $($failed.Count)
Discovered total incl. nested references: $($seen.Count)

Includes:
- parent + child/subagent session JSON exports
- DB session listing with timestamps/parent IDs/titles
- Kilo version / agent list / stats
- current agent configs and result files
- P2/P3 checkpoints/current work
- git status/diff metadata + working-tree.patch

Excluded:
- global auth stores
- .env
- API tokens / private keys
"@ | Out-File -Encoding utf8 (Join-Path $out "README-HANDOFF.txt")

$zip = "$out.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path "$out\*" -DestinationPath $zip -CompressionLevel Optimal

Write-Host ""
Write-Host "=== GOTOWE ===" -ForegroundColor Green
Write-Host "DB seed sessions:       $($initialIds.Count)"
Write-Host "Wyeksportowane:         $($exported.Count)"
Write-Host "Bledy eksportu:         $($failed.Count)"
Write-Host "Wszystkie odkryte IDs:  $($seen.Count)"
Write-Host ""
Write-Host "ZIP:" -ForegroundColor Cyan
Write-Host $zip -ForegroundColor Yellow
