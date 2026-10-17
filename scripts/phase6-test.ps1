$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    $project= docker compose --env-file .env.phase6 -f docker-compose.phase6.yml config --format json | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0 -or $project.name -ne 'attendance-phase6-test') { throw 'Isolated project required' }
    docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T web php /phase6-tests/lint.php
    if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' }
    docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T nginx nginx -t
    if ($LASTEXITCODE -ne 0) { throw 'Nginx config failed' }
    $integration=docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T web php /phase6-tests/integration.php
    if ($LASTEXITCODE -ne 0) { throw 'Integration failed' }
    $integration | Write-Output
    [IO.File]::WriteAllText((Join-Path (Get-Location) 'docs/phase6-integration-results.txt'),($integration -join "`n"),(New-Object Text.UTF8Encoding($false)))
    $result=docker compose --env-file .env.phase6 -f docker-compose.phase6.yml exec -T web php /phase6-tests/phase6.php
    if ($LASTEXITCODE -ne 0) { throw 'Phase 6 regression failed' }
    $parsed=($result -join "`n") | ConvertFrom-Json
    [IO.File]::WriteAllText((Join-Path (Get-Location) 'docs/phase6-test-results.json'),($result -join "`n"),(New-Object Text.UTF8Encoding($false)))
    Write-Output "Phase 6: $($parsed.passed) passed; $($parsed.failed) failed"
} finally { Pop-Location }
