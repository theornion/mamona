[CmdletBinding()]
param(
    [switch]$SkipCli,
    [switch]$SkipVsCodeExtension
)

$ErrorActionPreference = "Stop"

if (-not $SkipCli) {
    if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
        throw "Nie znaleziono npm. Zainstaluj aktualny Node.js LTS."
    }

    Write-Host "Instaluję/aktualizuję Kilo CLI..." -ForegroundColor Cyan
    npm install -g @kilocode/cli
    kilo --version
}

if (-not $SkipVsCodeExtension) {
    if (Get-Command code -ErrorAction SilentlyContinue) {
        Write-Host "Instaluję/aktualizuję Kilo Code w VS Code..." -ForegroundColor Cyan
        code --install-extension kilocode.kilo-code --force
    } else {
        Write-Warning "Komenda 'code' nie jest w PATH. Zainstaluj rozszerzenie Kilo Code ręcznie w VS Code."
    }
}

Write-Host ""
Write-Host "Gotowe." -ForegroundColor Green
Write-Host "1. Upewnij się, że tunel do http://127.0.0.1:11436 działa."
Write-Host "2. Otwórz C:\Projekty\Mamona w VS Code."
Write-Host "3. Poczekaj na IDX Complete."
Write-Host "4. W Kilo wpisz /map-mamona."
