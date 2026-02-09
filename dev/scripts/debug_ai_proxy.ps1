$ErrorActionPreference = 'Stop'

$uri = 'http://localhost/ADCES-SYSTEM/controllers/ai_generate.php'

$payload = [ordered]@{
  subject = 'Math'
  ratings = [ordered]@{
    communications = [ordered]@{
      indicator1 = [ordered]@{ rating = 4; comment = 'Clear explanations.' }
    }
    management = [ordered]@{
      indicator1 = [ordered]@{ rating = 3; comment = 'Room control ok.' }
    }
    assessment = [ordered]@{
      indicator1 = [ordered]@{ rating = 3; comment = 'Needs more checks.' }
    }
  }
}

$json = $payload | ConvertTo-Json -Depth 20

Write-Host "POST $uri" -ForegroundColor Cyan
Write-Host "Payload JSON:" -ForegroundColor Cyan
Write-Host $json

$resp = Invoke-WebRequest -UseBasicParsing -Method Post -Uri $uri -ContentType 'application/json' -Body $json

Write-Host "Status: $($resp.StatusCode)" -ForegroundColor Green
Write-Host "Response:" -ForegroundColor Green
$resp.Content
