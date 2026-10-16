$ErrorActionPreference = 'Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    docker compose config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Compose validation failed' }
    docker compose cp tests/lint.php web:/tmp/attendance-lint.php
    if ($LASTEXITCODE -ne 0) { throw 'Copy lint failed' }
    docker compose exec -T web php /tmp/attendance-lint.php
    if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' }
    docker compose cp tests/integration.php web:/tmp/attendance-integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Copy tests failed' }
    docker compose exec -T web php /tmp/attendance-integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Integration tests failed' }
} finally { Pop-Location }
