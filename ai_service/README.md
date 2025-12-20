# ADCES AI Service (SBERT + Flan-T5)

This folder contains a small **Python API** that generates:
- Strengths
- Areas for improvement
- Recommendations

**Agreement is intentionally not generated** (kept manual).

## What it exposes
- `GET /health` → `{ ok: true }`
- `POST /generate` → returns `{ strengths, improvement_areas, recommendations }`

## Models
Configured via environment variables:
- `SBERT_MODEL` (default: `sentence-transformers/all-MiniLM-L6-v2`)
- `FLAN_T5_MODEL` (default: `google/flan-t5-base`)

## Run locally (Windows)
1. Create a venv and install deps
2. Run the API server

Example (PowerShell):

```powershell
cd c:\Users\Administrator\Documents\xampp\htdocs\ADCES-SYSTEM\ai_service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
uvicorn app:app --host 127.0.0.1 --port 8008
```

Keep this terminal running while you use the PHP app.

### Recommended (no activation required)
If PowerShell activation is blocked by execution policy, you can run everything using the venv executables directly:

```powershell
cd c:\Users\Administrator\Documents\xampp\htdocs\ADCES-SYSTEM\ai_service
.\.venv\Scripts\pip.exe install -r requirements.txt
.\.venv\Scripts\python.exe -m uvicorn app:app --host 127.0.0.1 --port 8008
```

### One-click starter
You can also run:

```powershell
cd c:\Users\Administrator\Documents\xampp\htdocs\ADCES-SYSTEM\ai_service
powershell -ExecutionPolicy Bypass -File .\start_ai_service.ps1
```

### Troubleshooting
- If the browser shows **“AI generation error”**, usually nothing is listening on `127.0.0.1:8008`.
- The **first** `/generate` call can be slow because it downloads and loads the models (SBERT + Flan‑T5).
	Keep the terminal open and wait for it to finish.
- Make sure **port 8008** isn’t used by another app.
- If you run the Python server on a different machine/port, set the PHP env var `AI_SERVICE_URL`.

## Quick smoke test
Once the server is running, you can POST a minimal payload and check the JSON response.

```powershell
$body = @{
	faculty_name = 'Test Teacher'
	department = 'CCIS'
	subject_observed = 'Programming 1'
	observation_type = 'Formal'
	averages = @{ communications = 3.2; management = 2.8; assessment = 3.0; overall = 3.0 }
	ratings = @{
		communications = @{ '0' = @{ rating = 3; comment = 'Voice is clear most of the time.' } }
		management = @{ '0' = @{ rating = 2; comment = 'Objectives need to be clearer.' } }
		assessment = @{ '0' = @{ rating = 3; comment = 'Checks understanding using questions.' } }
	}
} | ConvertTo-Json -Depth 6

Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8008/generate -ContentType 'application/json' -Body $body
```

## Next step
Create a PHP proxy endpoint (in `controllers/`) that calls `http://127.0.0.1:8008/generate` and returns JSON to the browser.
