$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$aiServiceDir = Join-Path $root 'ai_service'
$pythonExe = Join-Path $root '.venv\Scripts\python.exe'
$healthUrl = 'http://127.0.0.1:8001/health'

if (-not (Test-Path $pythonExe)) {
    Write-Host 'Python virtual environment executable not found:' -ForegroundColor Red
    Write-Host "  $pythonExe" -ForegroundColor Red
    exit 1
}

try {
    $healthy = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 3
    if ($healthy.ok -eq $true) {
        Write-Host 'AI service is already running at http://127.0.0.1:8001' -ForegroundColor Green
        exit 0
    }
} catch {
}

Write-Host 'Starting AI service...' -ForegroundColor Cyan
Start-Process -FilePath $pythonExe `
    -ArgumentList '-m', 'uvicorn', 'app:app', '--host', '127.0.0.1', '--port', '8001' `
    -WorkingDirectory $aiServiceDir

$started = $false
for ($i = 0; $i -lt 20; $i++) {
    Start-Sleep -Milliseconds 750
    try {
        $healthy = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 3
        if ($healthy.ok -eq $true) {
            $started = $true
            break
        }
    } catch {
    }
}

if ($started) {
    Write-Host 'AI service is running at http://127.0.0.1:8001' -ForegroundColor Green
    exit 0
}

Write-Host 'AI service did not become ready in time.' -ForegroundColor Red
exit 1
