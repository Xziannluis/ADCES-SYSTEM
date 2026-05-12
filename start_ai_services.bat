@echo off
title ADCES AI Service Launcher
echo ============================================
echo   ADCES AI Service Launcher
echo ============================================
echo.
powershell -ExecutionPolicy Bypass -NoProfile -File "%~dp0start_ai_services.ps1"
echo.
if %ERRORLEVEL% neq 0 (
    echo [ERROR] AI service failed to start. See messages above.
) else (
    echo [OK] Done.
)
echo.
pause
