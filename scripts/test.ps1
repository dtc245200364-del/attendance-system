param([string]$BaseUrl = 'http://nginx:8080')
$ErrorActionPreference = 'Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    docker compose config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Compose validation failed' }
    & "$PSScriptRoot/copy-web-test.ps1" -Source tests/lint.php -Name attendance-lint.php
    if ($LASTEXITCODE -ne 0) { throw 'Copy lint failed' }
    docker compose exec -T web php /tmp/attendance-lint.php
    if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' }
    & "$PSScriptRoot/copy-web-test.ps1" -Source tests/integration.php -Name attendance-integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Copy tests failed' }
    docker compose exec -T -e "TEST_BASE_URL=$BaseUrl" web php /tmp/attendance-integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Integration tests failed' }
} finally { Pop-Location }
