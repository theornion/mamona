param(
    [string]$ProjectRoot = "C:\Projekty\mamona"
)

$ErrorActionPreference = "Stop"
Set-Location $ProjectRoot

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$handoffRoot = Join-Path $ProjectRoot "_handoff"
$temp = Join-Path $handoffRoot "mamona-repo-$stamp"
$zip = "$temp.zip"

if (Test-Path $temp) { Remove-Item $temp -Recurse -Force }
New-Item -ItemType Directory -Force -Path $temp | Out-Null

Write-Host "Kopiuje aktualny working tree bez sekretow i ciezkich zaleznosci..." -ForegroundColor Cyan

$excludeDirs = @(
    ".git",
    "node_modules",
    "vendor",
    ".venv",
    "venv",
    "_handoff",
    "backups",
    "subagent-exports",
    "data",
    "analysis",
    ".kilo\\node_modules",
    "images\\posts"
)

$excludeFiles = @(
    ".env",
    ".env.*",
    "*.key",
    "*.pem",
    "*.pfx",
    "*.p12"
)

$args = @(
    $ProjectRoot,
    $temp,
    "/E",
    "/R:1",
    "/W:1",
    "/NFL",
    "/NDL",
    "/NJH",
    "/NJS",
    "/NP",
    "/XD"
) + $excludeDirs + @("/XF") + $excludeFiles

& robocopy @args | Out-Null
if ($LASTEXITCODE -gt 7) {
    throw "Robocopy zakonczyl sie kodem $LASTEXITCODE"
}

try { git status --short 2>&1 | Out-File -Encoding utf8 (Join-Path $temp "_GIT_STATUS.txt") } catch {}
try { git diff --stat 2>&1 | Out-File -Encoding utf8 (Join-Path $temp "_GIT_DIFF_STAT.txt") } catch {}
try { git diff --name-only 2>&1 | Out-File -Encoding utf8 (Join-Path $temp "_GIT_DIFF_NAME_ONLY.txt") } catch {}
try { git diff 2>&1 | Out-File -Encoding utf8 (Join-Path $temp "_WORKING_TREE.patch") } catch {}

@"
MAMONA REPO HANDOFF
Created: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss zzz")

This is the CURRENT working tree, including uncommitted source/test/doc changes.

Excluded:
- .git
- node_modules
- vendor
- virtualenvs
- _handoff
- runtime data and backups
- subagent exports and analysis artifacts
- generated article media
- .env / private-key files

Do NOT add credential files before sharing.
"@ | Out-File -Encoding utf8 (Join-Path $temp "_README_HANDOFF.txt")

if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path "$temp\*" -DestinationPath $zip -CompressionLevel Optimal

Write-Host ""
Write-Host "=== GOTOWE ===" -ForegroundColor Green
Write-Host $zip -ForegroundColor Yellow
