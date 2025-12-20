$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $here

$python = Join-Path $here '.venv\Scripts\python.exe'
if (!(Test-Path $python)) {
    Write-Host "Missing venv python at: $python" -ForegroundColor Red
    Write-Host "Create it first:" -ForegroundColor Yellow
    Write-Host "  python -m venv .venv" -ForegroundColor Yellow
    exit 1
}

Write-Host "Starting ADCES AI service on http://127.0.0.1:8008" -ForegroundColor Cyan
Write-Host "This will open a new window and keep it running (close that window to stop the AI service)." -ForegroundColor Cyan

$logPath = Join-Path $here 'uvicorn.log'
if (Test-Path $logPath) {
    try {
        $stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
        $bak = Join-Path $here ("uvicorn.$stamp.log")
        Move-Item -LiteralPath $logPath -Destination $bak -Force
    } catch {
        # If it's locked or move fails, we'll just append.
    }
}

"==== $(Get-Date -Format o) Starting uvicorn ====" | Out-File -FilePath $logPath -Encoding utf8

$uvicornCmd = "`"$python`" -m uvicorn app:app --host 127.0.0.1 --port 8008 --log-level info"

"command: $uvicornCmd" | Out-File -FilePath $logPath -Encoding utf8 -Append

# Spawn a dedicated window so the dev server doesn't get torn down by parent process behavior.
# Use cmd.exe redirection for the most consistent logging behavior on Windows.
# Also keep the window open if uvicorn exits so user can see it.
$cmdLine = "cd /d `"$here`" && $uvicornCmd >> `"$logPath`" 2>>&1 && echo uvicorn exit code: %ERRORLEVEL% >> `"$logPath`""

Start-Process -FilePath 'cmd.exe' -ArgumentList @(
    '/k',
    $cmdLine
) -WindowStyle Normal

Write-Host "AI service launch requested." -ForegroundColor Green
Write-Host "Log: $logPath" -ForegroundColor Yellow
