$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
Push-Location $root
try {
    foreach ($path in @('.env','.env.grafana','secrets/mysql-exporter.cnf','secrets/monitoring-user.sql')) {
        if (!(Test-Path -LiteralPath $path)) { throw "Missing $path; run the relevant setup script first." }
    }
    docker compose config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Compose configuration invalid' }
    $db=docker compose ps -q db
    if (!$db) { throw 'Start database first with docker compose up -d db, then wait for healthy.' }
    $state=docker inspect $db --format '{{.State.Health.Status}}'
    if ($state -ne 'healthy') { throw 'Database is not healthy; existing resources left unchanged.' }
    docker compose cp secrets/monitoring-user.sql db:/tmp/attendance-monitoring-user.sql
    if ($LASTEXITCODE -ne 0) { throw 'Cannot copy monitoring account provisioning SQL' }
    docker compose cp scripts/apply-monitoring-user.sh db:/tmp/attendance-monitoring-user.sh
    if ($LASTEXITCODE -ne 0) { throw 'Cannot copy provisioning script' }
    docker compose exec -T db sh /tmp/attendance-monitoring-user.sh
    if ($LASTEXITCODE -ne 0) { throw 'Cannot provision monitoring account' }
    docker compose up -d --no-deps nginx prometheus grafana cadvisor nginx-exporter mysql-exporter
    if ($LASTEXITCODE -ne 0) { throw 'Monitoring services failed to start' }
} finally { Pop-Location }
