$ErrorActionPreference='Stop'
$passed=0
function Check($ok,$label) { if (!$ok) { throw "FAIL: $label" }; $script:passed++; Write-Output "PASS: $label" }
function Status($url) {
    try { return (Invoke-WebRequest -UseBasicParsing $url -TimeoutSec 10).StatusCode }
    catch { if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }; throw }
}
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    Check ((Status 'http://127.0.0.1:13001/api/dashboards/uid/attendance-system') -eq 401) 'Grafana dashboard API requires authentication'
    Check ((Status 'http://127.0.0.1:18087/stub_status') -eq 404) 'Nginx status not exposed through public website listener'
    $page=Invoke-WebRequest -UseBasicParsing http://127.0.0.1:18087/
    Check ($page.Headers['X-Frame-Options'] -eq 'DENY' -and $page.Headers['X-Content-Type-Options'] -eq 'nosniff' -and $page.Headers['Content-Security-Policy']) 'website security headers preserved'
    & "$PSScriptRoot/copy-web-test.ps1" -Source tests/monitoring-access.php -Name attendance-monitoring-access.php
    if ($LASTEXITCODE -ne 0) { throw 'Test copy failed' }
    docker compose exec -T web php /tmp/attendance-monitoring-access.php
    Check ($LASTEXITCODE -eq 0) 'Nginx status ACL rejects non-exporter proxy peer'
    Write-Output "RESULT: $passed monitoring access assertions passed"
} finally { Pop-Location }
