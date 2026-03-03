param(
    [string]$TaskName = 'ADCES AI Service',
    [ValidateSet('AtStartup','AtLogOn')]
    [string]$Trigger = 'AtLogOn'
)

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$startScript = Join-Path $here 'start_ai_service.ps1'

if (!(Test-Path $startScript)) {
    throw "Missing: $startScript"
}

# Use powershell.exe to run the existing starter script headlessly.
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument (
    "-NoProfile -ExecutionPolicy Bypass -File `"$startScript`" -Headless"
)

$trg = switch ($Trigger) {
    'AtStartup' { New-ScheduledTaskTrigger -AtStartup }
    'AtLogOn'   { New-ScheduledTaskTrigger -AtLogOn }
}

# Run with highest privileges so it can bind port and access venv reliably.
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

$task = New-ScheduledTask -Action $action -Trigger $trg -Principal $principal -Settings $settings

try {
    # Replace if it already exists
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue | Out-Null
} catch {
    # ignore
}

Register-ScheduledTask -TaskName $TaskName -InputObject $task | Out-Null

Write-Host "Created scheduled task: $TaskName ($Trigger)" -ForegroundColor Green
Write-Host "It will run: $startScript" -ForegroundColor Cyan
Write-Host "To run it now: Start-ScheduledTask -TaskName `"$TaskName`"" -ForegroundColor Yellow
