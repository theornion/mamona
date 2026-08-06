[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$HostAddress,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 65535)]
    [int]$SshPort,

    [string]$User = "root",

    [ValidateRange(1, 65535)]
    [int]$LocalPort = 11434,

    [string]$IdentityFile,

    [switch]$Background
)

$ErrorActionPreference = "Stop"

if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) {
    throw "OpenSSH client was not found. Install the Windows OpenSSH Client optional feature."
}

$existing = Get-NetTCPConnection -LocalPort $LocalPort -State Listen -ErrorAction SilentlyContinue
if ($existing) {
    throw "Local port $LocalPort is already in use. Close local Ollama or choose another -LocalPort."
}

$sshArguments = @(
    "-N",
    "-o", "ExitOnForwardFailure=yes",
    "-o", "ServerAliveInterval=30",
    "-o", "ServerAliveCountMax=3",
    "-L", "${LocalPort}:127.0.0.1:11434",
    "-p", $SshPort.ToString()
)

if ($IdentityFile) {
    $resolvedIdentity = (Resolve-Path $IdentityFile).Path
    $sshArguments += @("-i", $resolvedIdentity)
}

$sshArguments += "${User}@${HostAddress}"

Write-Host "Opening encrypted tunnel:" -ForegroundColor Cyan
Write-Host "  http://localhost:$LocalPort -> Vast.ai Ollama" -ForegroundColor Cyan
Write-Host ""
Write-Host "Roo Code settings:" -ForegroundColor Green
Write-Host "  Provider: Ollama"
Write-Host "  Base URL: http://localhost:$LocalPort"
Write-Host "  Coder model: qwen3-coder:30b"
Write-Host "  Fast model:  qwen3.5:9b"
Write-Host ""

if ($Background) {
    $process = Start-Process -FilePath "ssh" -ArgumentList $sshArguments -PassThru
    Start-Sleep -Seconds 2
    if ($process.HasExited) {
        throw "The SSH tunnel exited immediately with code $($process.ExitCode)."
    }
    Write-Host "Tunnel started in the background. SSH PID: $($process.Id)" -ForegroundColor Green
    Write-Host "Stop it later with: Stop-Process -Id $($process.Id)"
    Write-Host "Test with: Invoke-RestMethod http://localhost:$LocalPort/api/tags"
} else {
    Write-Host "Keep this window open. Press Ctrl+C to close the tunnel." -ForegroundColor Yellow
    & ssh @sshArguments
    if ($LASTEXITCODE -ne 0) {
        throw "SSH exited with code $LASTEXITCODE."
    }
}
