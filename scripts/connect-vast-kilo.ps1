[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$HostAddress,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int]$SshPort,

    [string]$User = "root",

    [ValidateRange(1, 65535)]
    [int]$LocalPort = 11436,

    [string]$IdentityFile,

    [switch]$Background
)

$ErrorActionPreference = "Stop"

if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) {
    throw "Brak klienta OpenSSH w Windows."
}

$existing = Get-NetTCPConnection -LocalPort $LocalPort -State Listen -ErrorAction SilentlyContinue
if ($existing) {
    $pidValue = ($existing | Select-Object -First 1 -ExpandProperty OwningProcess)
    throw "Port $LocalPort jest zajęty przez PID $pidValue. Zamknij proces albo wybierz -LocalPort."
}

$args = @(
    "-N",
    "-o", "ExitOnForwardFailure=yes",
    "-o", "ServerAliveInterval=30",
    "-o", "ServerAliveCountMax=3",
    "-L", "${LocalPort}:127.0.0.1:11434",
    "-p", $SshPort.ToString()
)

if ($IdentityFile) {
    $args += @("-i", (Resolve-Path $IdentityFile).Path)
}

$args += "${User}@${HostAddress}"

Write-Host "Tunel:" -ForegroundColor Cyan
Write-Host "  http://127.0.0.1:$LocalPort -> Ollama na Vast" -ForegroundColor Cyan
Write-Host ""
Write-Host "Modele oczekiwane:" -ForegroundColor Green
Write-Host "  qwen3.6:27b"
Write-Host "  qwen3.5:9b"
Write-Host "  nomic-embed-text"
Write-Host ""

if ($Background) {
    $process = Start-Process -FilePath "ssh" -ArgumentList $args -PassThru
    Start-Sleep -Seconds 2
    if ($process.HasExited) {
        throw "Tunel SSH zakończył się kodem $($process.ExitCode)."
    }
    Write-Host "Tunel działa w tle. PID: $($process.Id)" -ForegroundColor Green
    Write-Host "Zatrzymanie: Stop-Process -Id $($process.Id)"
} else {
    Write-Host "Zostaw to okno otwarte. Ctrl+C zamyka tunel." -ForegroundColor Yellow
    & ssh @args
    if ($LASTEXITCODE -ne 0) {
        throw "SSH zakończył się kodem $LASTEXITCODE."
    }
}
