# AI Service Virtual Environment Setup

## ✅ Completed Setup

Your Python virtual environment has been created at:
```
.venv/
```
(Root directory - required for auto-start mechanism)

Python version: **3.14.3**

## 📦 Installed Packages

The following packages are being installed in the virtual environment:
- **FastAPI** - Web framework for the AI API
- **Uvicorn** - ASGI server for running FastAPI
- **Sentence-Transformers** - For text embeddings (semantic search)
- **PyTorch** - Deep learning framework
- **Transformers** - Pre-trained models from HuggingFace
- **NumPy** - Numerical computing
- **Pydantic** - Data validation
- **PyMySQL** - MySQL database connection

## 🚀 Quick Start

### Activate Virtual Environment

**PowerShell:**
```powershell
cd c:\xampp\htdocs\ADCES-SYSTEM
.\.venv\Scripts\Activate.ps1
```

**Command Prompt (cmd):**
```cmd
cd c:\xampp\htdocs\ADCES-SYSTEM
.venv\Scripts\activate.bat
```

**Git Bash:**
```bash
cd c:\xampp\htdocs\ADCES-SYSTEM
source .venv/Scripts/activate
```

### Run the AI Service

**Auto-start (recommended):**
The AI service automatically starts when you visit the landing page (index.php). No manual intervention needed!

**Manual start (if needed):**
```powershell
# From root directory with venv activated
(venv) PS> cd ai_service
(venv) PS> uvicorn app:app --host 127.0.0.1 --port 8001
```

The service runs on **port 8001** (health check: `http://127.0.0.1:8001/health`)

### Deactivate Virtual Environment

```powershell
deactivate
```

## 🔗 Configuration

The AI service connects to:
- **MySQL Database** - For feedback templates and caching
- **HuggingFace Models** - For text embeddings (works offline after first download)

Make sure your `.env` file has the correct database credentials:
```
AI_SERVICE_URL=http://127.0.0.1:8000
```

## 📝 Common Commands

```powershell
# List installed packages
pip list

# Install additional packages
pip install package-name

# Freeze current environment
pip freeze > requirements.txt

# Check Python version in venv
python --version
```

## ✨ Important Notes

- The venv is tied to this specific machine/installation
- Virtual environment activates automatically when you run scripts
- Large ML models download on first use (~500MB total)
Test from root directory:
```powershell
.\.venv\Scripts\python.exe ai_service\test_ai_setup.py
```

Or from ai_service directory:
```powershell
.\..\venv\Scripts\python.exe test_ai_setup.py
```

## 🚀 Auto-Start Behavior

When you visit the web application:
1. `index.php` loads and includes `ai_autostart.php`
2. A health check is performed on `http://127.0.0.1:8001/health`
3. If the AI service is not running, it automatically starts in the background
4. A lock file prevents duplicate start attempts during boot
5. The service is ready to serve AI requests within seconds

**Manual start if needed:**
```powershell
start_ai_services.ps1
# or
start_ai_services.bat
```
.\venv\Scripts\python.exe test_ai_setup.py
```

This will verify all dependencies are installed correctly.
