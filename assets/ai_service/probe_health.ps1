$ErrorActionPreference = 'Continue'

try {
  $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 -Uri 'http://127.0.0.1:8008/health'
  Write-Host $r.StatusCode
  Write-Host $r.Content
} catch {
  Write-Host $_.Exception.Message
  exit 1
}
