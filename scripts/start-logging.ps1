$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    # Single log files only: container directories also hold secret environment metadata.
    $mounts=@()
    foreach ($service in @('nginx','web','db')) {
        $id=docker compose ps -q $service
        if ($LASTEXITCODE -ne 0 -or !$id) { throw "Missing running $service" }
        $item=(docker inspect $id | ConvertFrom-Json)[0]
        if ($item.Config.Labels.'com.docker.compose.project' -ne 'attendance-phase1-fresh' -or $item.Config.Labels.'com.docker.compose.service' -ne $service) { throw 'Unexpected container/project' }
        if ($item.HostConfig.LogConfig.Type -ne 'json-file' -or $item.LogPath -notmatch '^/var/lib/docker/containers/[a-f0-9]{64}/[a-f0-9]{64}-json\.log$') { throw 'Unsupported log path/driver' }
        if ($item.Name.TrimStart('/') -ne "attendance-phase1-fresh-$service-1") { throw 'Update static container labels before using a different container name' }
        $mounts+=@{type='bind';source=$item.LogPath;target="/logs/$service/container-json.log";read_only=$true;bind=@{create_host_path=$false}}
    }
    $path=Join-Path (Get-Location) 'docker-compose.override.yml'
    if (Test-Path $path) {
        $old=Get-Content $path -Raw | ConvertFrom-Json
        if ($old.'x-attendance-generated' -ne 'log-mounts-v1') { throw 'Refusing to overwrite an unrelated Compose override' }
    }
    $override=@{'x-attendance-generated'='log-mounts-v1';services=@{promtail=@{volumes=$mounts}}}
    [IO.File]::WriteAllText($path,($override | ConvertTo-Json -Depth 8),(New-Object Text.UTF8Encoding($false)))
    docker compose config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Invalid Compose configuration' }
    docker compose up -d --no-deps loki promtail
    if ($LASTEXITCODE -ne 0) { throw 'Logging startup failed' }
    Write-Output 'Exact log-file mounts refreshed. Re-run after container recreation; restart Promtail after Docker log rotation.'
} finally { Pop-Location }
