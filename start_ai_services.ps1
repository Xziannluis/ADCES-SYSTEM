$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$aiServiceDir = Join-Path $root 'ai_service'
$venvDir = Join-Path $root '.venv'
$pythonExe = Join-Path $venvDir 'Scripts\python.exe'
$requirementsFile = Join-Path $aiServiceDir 'requirements.txt'
$healthUrl = 'http://127.0.0.1:8001/health'

# ── 1. Check if the AI service is already running ──
try {
    $healthy = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 3
    if ($healthy.ok -eq $true) {
        Write-Host 'AI service is already running at http://127.0.0.1:8001' -ForegroundColor Green
        exit 0
    }
} catch {
    # Not running yet – continue with setup
}

# ── 2. Find a working Python interpreter ──
$systemPython = $null

# Try 'py' launcher first (most reliable on Windows)
try {
    $pyVersion = & py -3 --version 2>&1
    if ($pyVersion -match 'Python \d') {
        $systemPython = 'py'
        Write-Host "Found Python via py launcher: $pyVersion" -ForegroundColor Cyan
    }
} catch { }

# Try 'python' command
if (-not $systemPython) {
    try {
        $pyVersion = & python --version 2>&1
        if ($pyVersion -match 'Python \d') {
            $systemPython = 'python'
            Write-Host "Found Python: $pyVersion" -ForegroundColor Cyan
        }
    } catch { }
}

# Try 'python3' command
if (-not $systemPython) {
    try {
        $pyVersion = & python3 --version 2>&1
        if ($pyVersion -match 'Python \d') {
            $systemPython = 'python3'
            Write-Host "Found Python: $pyVersion" -ForegroundColor Cyan
        }
    } catch { }
}

if (-not $systemPython) {
    Write-Host '' -ForegroundColor Red
    Write-Host '====================================================' -ForegroundColor Red
    Write-Host '  Python is NOT installed on this computer.' -ForegroundColor Red
    Write-Host '====================================================' -ForegroundColor Red
    Write-Host ''
    Write-Host 'Please install Python 3.10 or newer from:' -ForegroundColor Yellow
    Write-Host '  https://www.python.org/downloads/' -ForegroundColor Yellow
    Write-Host ''
    Write-Host 'IMPORTANT: Check "Add python.exe to PATH" during install.' -ForegroundColor Yellow
    Write-Host ''
    exit 1
}

# ── 3. Create virtual environment if missing ──
if (-not (Test-Path $pythonExe)) {
    Write-Host 'Creating Python virtual environment (.venv)...' -ForegroundColor Cyan

    if ($systemPython -eq 'py') {
        & py -3 -m venv $venvDir
    } else {
        & $systemPython -m venv $venvDir
    }

    if (-not (Test-Path $pythonExe)) {
        Write-Host 'Failed to create virtual environment.' -ForegroundColor Red
        exit 1
    }
    Write-Host 'Virtual environment created.' -ForegroundColor Green
}

# ── 4. Install / update dependencies ──
if (Test-Path $requirementsFile) {
    Write-Host 'Installing Python dependencies (this may take a few minutes the first time)...' -ForegroundColor Cyan
    & $pythonExe -m pip install --upgrade pip --quiet 2>&1 | Out-Null
    & $pythonExe -m pip install -r $requirementsFile --quiet
    Write-Host 'Dependencies installed.' -ForegroundColor Green
} else {
    Write-Host "Warning: requirements.txt not found at $requirementsFile" -ForegroundColor Yellow
}

# ── 5. Start the AI service ──
Write-Host 'Starting AI service...' -ForegroundColor Cyan
Start-Process -FilePath $pythonExe `
    -ArgumentList '-m', 'uvicorn', 'app:app', '--host', '127.0.0.1', '--port', '8001' `
    -WorkingDirectory $aiServiceDir

# ── 6. Wait for it to become healthy ──
$started = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Milliseconds 1000
    try {
        $healthy = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 3
        if ($healthy.ok -eq $true) {
            $started = $true
            break
        }
    } catch {
        # Still starting up...
    }
}

if ($started) {
    Write-Host 'AI service is running at http://127.0.0.1:8001' -ForegroundColor Green
    exit 0
}

Write-Host 'AI service did not become ready in time.' -ForegroundColor Red
Write-Host 'Check the uvicorn window for errors.' -ForegroundColor Yellow
exit 1