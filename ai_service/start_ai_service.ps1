param(
    [switch]$Foreground,
    [switch]$Headless
)

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
Write-Host "Tip: For debugging, you can run in this window: .\\start_ai_service.ps1 -Foreground" -ForegroundColor DarkCyan

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

if ($Headless) {
    # For Task Scheduler / background runs: no extra windows, log everything.
    $args = @('-m','uvicorn','app:app','--host','127.0.0.1','--port','8008','--log-level','info')

    # Start-Process will block only if -Wait is used; we want it to keep running.
    Start-Process -FilePath $python -ArgumentList $args -WorkingDirectory $here -WindowStyle Hidden -RedirectStandardOutput $logPath -RedirectStandardError $logPath
    Write-Host "AI service launched headlessly." -ForegroundColor Green
    Write-Host "Log: $logPath" -ForegroundColor Yellow
    exit 0
}

if ($Foreground) {
    Write-Host "Running uvicorn in foreground..." -ForegroundColor Cyan
    & $python -m uvicorn app:app --host 127.0.0.1 --port 8008 --log-level info
    exit $LASTEXITCODE
}

# Spawn a dedicated window and run uvicorn directly (no nested powershell -Command string).
# This is much more reliable (the server keeps running until the window is closed).
Start-Process -FilePath 'powershell.exe' -ArgumentList @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-NoExit',
    '-Command',
    "Set-Location -LiteralPath `"$here`"; " +
    "Write-Host 'ADCES AI service running on http://127.0.0.1:8008' -ForegroundColor Green; " +
    "& `"$python`" -m uvicorn app:app --host 127.0.0.1 --port 8008 --log-level info"
) -WindowStyle Normal

Write-Host "AI service launch requested." -ForegroundColor Green
Write-Host "Log: $logPath" -ForegroundColor Yellow
