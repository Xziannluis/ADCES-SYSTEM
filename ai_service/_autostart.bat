@echo off
cd /d "C:\xampp\htdocs\ADCES-SYSTEM\ai_service"
"C:\xampp\htdocs\ADCES-SYSTEM\.venv\Scripts\python.exe" -m uvicorn app:app --host 127.0.0.1 --port 8001
