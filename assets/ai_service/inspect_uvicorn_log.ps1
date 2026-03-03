$ErrorActionPreference = 'Stop'

$p = Join-Path $PSScriptRoot 'uvicorn.log'
if (!(Test-Path $p)) {
  Write-Host "Missing: $p" -ForegroundColor Yellow
  exit 0
}

$bytes = [System.IO.File]::ReadAllBytes($p)
$nul = 0
foreach ($b in $bytes) { if ($b -eq 0) { $nul++ } }

$headLen = [Math]::Min(64, $bytes.Length)
$head = New-Object byte[] $headLen
[Array]::Copy($bytes, $head, $headLen)

Write-Host "File: $p"
Write-Host "Bytes: $($bytes.Length)"
Write-Host "NUL bytes: $nul"
Write-Host ("Head hex: {0}" -f ([BitConverter]::ToString($head)))

# Try reading as text using common encodings
Write-Host "\n--- As UTF8 ---"
try { [System.Text.Encoding]::UTF8.GetString($bytes) | Select-Object -First 20 } catch { Write-Host $_.Exception.Message }
Write-Host "\n--- As Unicode (UTF-16LE) ---"
try { [System.Text.Encoding]::Unicode.GetString($bytes) | Select-Object -First 20 } catch { Write-Host $_.Exception.Message }
