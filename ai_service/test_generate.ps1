# Quick smoke test for the AI service.
# Usage:
#   1) Ensure the service is running: .\start_ai_service.ps1
#   2) Run: .\test_generate.ps1

$payload = @{
  faculty_name = 'Test Teacher'
  department = 'Test Department'
  subject_observed = 'Test Subject'
  observation_type = 'Classroom'
  ratings = @{
    communications = @(
      @{ rating = 5; comment = 'Clear explanations and good pacing.' },
      @{ rating = 4; comment = '' }
    )
    management = @(
      @{ rating = 4; comment = 'Classroom routines were consistent.' }
    )
    assessment = @(
      @{ rating = 3; comment = 'Add more frequent checks for understanding.' }
    )
  }
  averages = @{
    communications = 4.5
    management = 4.0
    assessment = 3.0
    overall = 3.8
  }
}

$json = $payload | ConvertTo-Json -Depth 20

try {
  $resp = Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:8008/generate' -ContentType 'application/json' -Body $json
  $resp | ConvertTo-Json -Depth 10
} catch {
  Write-Host 'Request failed:' -ForegroundColor Red
  Write-Host $_
  if ($_.Exception.Response) {
    try {
      $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
      $body = $reader.ReadToEnd()
      Write-Host 'Response body:' -ForegroundColor Yellow
      Write-Host $body
    } catch {}
  }
  exit 1
}
