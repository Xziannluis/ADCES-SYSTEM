@echo off
REM Run AI service completely hidden in background
setlocal enabledelayedexpansion
cd /d "C:\xampp\htdocs\ADCES-SYSTEM\ai_service"
start /b "" "C:\xampp\htdocs\ADCES-SYSTEM\.venv\Scripts\python.exe" -m uvicorn app:app --host 127.0.0.1 --port 8001 > nul 2>&1
