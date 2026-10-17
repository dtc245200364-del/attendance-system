param([string]$ResultPath='docs/phase4-test-results.json')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
Push-Location $root
$script:passed=0; $script:failed=0; $script:checks=@(); $queries=@()
function Check($ok,$name) {
    if ($ok) { $script:passed++; Write-Host "PASS: $name" } else { $script:failed++; Write-Host "FAIL: $name" }
    $script:checks+=@{name=$name;pass=[bool]$ok}
}
try {
    $vars=@{}
    Get-Content .env.grafana | ForEach-Object { if ($_ -match '^([^=]+)=(.*)$') { $vars[$matches[1]]=$matches[2] } }
    $auth=@{Authorization='Basic '+[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($vars.GF_SECURITY_ADMIN_USER+':'+$vars.GF_SECURITY_ADMIN_PASSWORD))}
    $graf='http://127.0.0.1:13001'
    function LogQuery([string]$expr,[bool]$instant=$false,[string]$lookback='15m') {
        $endpoint=if ($instant) {'query'} else {'query_range'}
        $url="$graf/api/datasources/proxy/uid/attendance-loki/loki/api/v1/${endpoint}?query=$([Uri]::EscapeDataString($expr))"
        if (!$instant) { $url+="&since=$lookback&limit=1000&direction=backward" }
        return Invoke-RestMethod $url -Headers $auth -TimeoutSec 30
    }
    docker compose config --quiet
    Check ($LASTEXITCODE -eq 0) 'Compose config valid'
    $config=docker compose config --format json | ConvertFrom-Json
    foreach ($service in @('loki','promtail')) {
        $id=docker compose ps -q $service
        $item=(docker inspect $id | ConvertFrom-Json)[0]
        Check ($item.State.Running -and !$item.State.OOMKilled -and $item.RestartCount -eq 0) "$service running without OOM/restart"
        Check (!$item.HostConfig.Privileged -and $item.HostConfig.CapDrop -contains 'ALL') "$service no privileged; all capabilities dropped"
        Check (!$item.HostConfig.PortBindings.PSObject.Properties.Count) "$service no published host ports"
        Check (@($item.NetworkSettings.Networks.PSObject.Properties.Name).Count -eq 1 -and $config.networks.monitoring.internal) "$service isolated on internal monitoring network"
        Check ($item.HostConfig.Memory -gt 0 -and $item.HostConfig.NanoCpus -gt 0) "$service bounded CPU and memory"
        Check ($item.HostConfig.ReadonlyRootfs) "$service read-only root filesystem"
    }
    $promtail=(docker inspect (docker compose ps -q promtail) | ConvertFrom-Json)[0]
    $loki=(docker inspect (docker compose ps -q loki) | ConvertFrom-Json)[0]
    Check ($loki.Config.User -eq '10001:10001') 'Loki non-root UID 10001'
    Check (@($loki.Mounts | Where-Object { $_.Destination -eq '/loki' -and $_.Type -eq 'volume' -and $_.RW }).Count -eq 1) 'Loki durable named volume'
    Check (@($promtail.Mounts | Where-Object { $_.Destination -eq '/positions' -and $_.Type -eq 'volume' }).Count -eq 1) 'Promtail durable positions volume'
    $logMounts=@($promtail.Mounts | Where-Object Destination -Like '/logs/*')
    Check ($logMounts.Count -eq 3 -and @($logMounts | Where-Object RW).Count -eq 0) 'exactly three read-only log file mounts'
    Check (@($promtail.Mounts | Where-Object { $_.Source -match 'docker\.sock|containerd\.sock' -or $_.Source -eq '/var/lib/docker/containers' -or $_.Source -eq '/' }).Count -eq 0) 'Promtail no socket or broad host directory mounts'
    foreach ($service in @('nginx','web','db')) {
        $item=(docker inspect (docker compose ps -q $service) | ConvertFrom-Json)[0]
        Check (@($logMounts | Where-Object { $_.Source -eq $item.LogPath -and $_.Destination -eq "/logs/$service/container-json.log" }).Count -eq 1) "log-file mount matches live $service container (no metadata directory)"
    }
    for ($attempt=0;$attempt -lt 12;$attempt++) {
        docker compose exec -T grafana sh -c 'wget -qO- http://loki:3100/ready'
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Seconds 2
    }
    Check ($LASTEXITCODE -eq 0) 'Loki /ready returns 200 from internal network'
    for ($attempt=0;$attempt -lt 12;$attempt++) {
        docker compose exec -T promtail /usr/bin/bash /etc/promtail/healthcheck.sh
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Seconds 2
    }
    Check ($LASTEXITCODE -eq 0) 'Promtail /ready returns 200'
    $health=Invoke-RestMethod "$graf/api/datasources/uid/attendance-loki/health" -Headers $auth
    Check ($health.status -eq 'OK') 'Grafana Loki datasource healthy'
    $ds=Invoke-RestMethod "$graf/api/datasources/uid/attendance-prometheus" -Headers $auth
    Check ($ds.isDefault -and $ds.url -eq 'http://prometheus:9090') 'default Prometheus datasource preserved'
    $metrics=Invoke-RestMethod "$graf/api/dashboards/uid/attendance-system" -Headers $auth
    Check ($metrics.dashboard.panels.Count -eq 9) 'existing nine-panel metrics dashboard preserved'
    $logs=Invoke-RestMethod "$graf/api/dashboards/uid/attendance-logs" -Headers $auth
    Check ($logs.dashboard.panels.Count -eq 4) 'four-panel log dashboard provisioned'
    Check (@($logs.dashboard.templating.list | Where-Object name -In @('service','level')).Count -eq 2) 'service and level filters provisioned'
    $canary='PHASE4_PRIVATE_'+[Guid]::NewGuid().ToString('N')
    $page=Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:18087/classes.php?private=$canary" -Headers @{'X-Request-ID'=$canary;'Cookie'="privacy=$canary"}
    $phpId=[string]$page.Headers['X-Request-ID']
    Check ($page.StatusCode -eq 200 -and $phpId -match '^[a-f0-9]{32}$') 'real PHP request; edge overwrites caller request ID'
    try { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:18087/phase4-log-proof' | Out-Null; throw 'Expected 404' }
    catch {
        if (!$_.Exception.Response) { throw }
        $status=[int]$_.Exception.Response.StatusCode
        $proofId=[string]$_.Exception.Response.Headers['X-Request-ID']
    }
    Check ($status -eq 404 -and $proofId -match '^[a-f0-9]{32}$') 'controlled real HTTP 404 with request correlation ID'
    try { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:18087/classes.php' -Method Post -Body @{csrf='invalid'} | Out-Null; throw 'Expected 403' }
    catch {
        if (!$_.Exception.Response) { throw }
        $deniedStatus=[int]$_.Exception.Response.StatusCode
        $deniedId=[string]$_.Exception.Response.Headers['X-Request-ID']
    }
    Check ($deniedStatus -eq 403) 'real invalid-CSRF request returns 403'
    $nginxExpr='{job="attendance",service="nginx"}'
    $notFoundExpr='{job="attendance",service="nginx"} | json | status = 404'
    $countExpr='sum by (service) (count_over_time({job="attendance"}[5m]))'
    for ($i=0;$i -lt 12;$i++) {
        $nginx=LogQuery ($nginxExpr+' |= "'+$proofId+'"')
        $php=LogQuery ('{job="attendance",service="php"} |= "'+$phpId+'"')
        if ($nginx.data.result.Count -gt 0 -and $php.data.result.Count -gt 0) { break }
        Start-Sleep -Seconds 5
    }
    Check ($nginx.data.result.Count -gt 0) 'new Nginx 404 delivered to Loki, exact request ID'
    Check ($php.data.result.Count -gt 0) 'new PHP application event delivered to Loki, exact request ID'
    $warning=LogQuery ('{job="attendance",service="php",level="warn",status="403"} |= "'+$deniedId+'"')
    Check ($warning.data.result.Count -gt 0) 'PHP warning/status labels filter the actual CSRF rejection'
    & "$PSScriptRoot/copy-web-test.ps1" -Source tests/logging-exception.php -Name attendance-logging-exception.php
    if ($LASTEXITCODE -ne 0) { throw 'Exception test copy failed' }
    $exceptionOutput=(docker compose exec -T web sh -c 'php /tmp/attendance-logging-exception.php 2>&1') -join "`n"
    Check ($exceptionOutput.Contains('"event":"unhandled_exception"') -and !$exceptionOutput.Contains('PHASE4_PRIVATE_EXCEPTION_CANARY') -and !$exceptionOutput.Contains('Stack trace')) 'CLI exception logger omits message and stack trace'
    # Send the sanitized CLI event to the main container stderr so the real
    # Promtail pipeline, not just the CLI capture, must preserve diagnostic fields.
    docker compose exec -T web sh -c 'php /tmp/attendance-logging-exception.php 2>/proc/1/fd/2 >/dev/null'
    if ($LASTEXITCODE -ne 0) { throw 'Diagnostic log delivery failed' }
    for ($attempt=0;$attempt -lt 12;$attempt++) {
        $diagnostic=LogQuery '{job="attendance",service="php"} | json | event = "unhandled_exception" | error_file = "attendance-logging-exception.php" | exception_type = "RuntimeException"'
        if ($diagnostic.data.result.Count -gt 0) { break }
        Start-Sleep -Seconds 2
    }
    Check ($diagnostic.data.result.Count -gt 0) 'sanitized exception type/file/line delivered through Promtail to Loki'
    foreach ($expr in @($nginxExpr,$notFoundExpr,$countExpr)) {
        $instant=$expr -eq $countExpr
        $answer=LogQuery $expr $instant
        Check ($answer.status -eq 'success' -and $answer.data.result.Count -gt 0) "LogQL returns real data: $expr"
        $queries+=@{query=$expr;resultType=$answer.data.resultType;series=$answer.data.result.Count}
    }
    $filtered=LogQuery ($notFoundExpr+' | request_id = "'+$proofId+'"')
    Check ($filtered.data.result.Count -gt 0) '404 LogQL result contains the controlled request'
    $mysql=LogQuery '{job="attendance",service="mysql"}' $false '2h'
    Check ($mysql.data.result.Count -gt 0) 'sanitized actual MySQL server events collected'
    $all=LogQuery '{job="attendance"}' $false '2h'
    $lines=@($all.data.result | ForEach-Object { $_.values | ForEach-Object { $_[1] } })
    Check (@($lines | Where-Object { $_ -like "*$canary*" }).Count -eq 0) 'query/cookie/forged-ID privacy canary absent from collected logs'
    $invalid=@(); $allowed=@('source','event','level','status','route','method','request_id','code','exception_type','error_file','error_line','sqlstate','driver_code')
    foreach ($line in $lines) {
        try {
            $parsed=$line | ConvertFrom-Json
            if (@($parsed.PSObject.Properties.Name | Where-Object { $_ -notin $allowed }).Count -gt 0) { $invalid+=$true }
        } catch { $invalid+=$true }
    }
    Check ($lines.Count -gt 0 -and $invalid.Count -eq 0) 'all sampled log lines are JSON with only approved fields'
    $services=@($all.data.result | ForEach-Object { $_.stream.service } | Select-Object -Unique)
    Check (@($services | Where-Object { $_ -notin @('nginx','php','mysql') }).Count -eq 0) 'only attendance nginx/php/mysql logs stored'
    foreach ($panel in $logs.dashboard.panels) {
        $expr=$panel.targets[0].expr.Replace('$service','.*').Replace('$level','.*')
        $body=@{from='now-15m';to='now';queries=@(@{refId='A';datasource=@{type='loki';uid='attendance-loki'};expr=$expr;queryType='range';intervalMs=15000;maxDataPoints=100;maxLines=100})} | ConvertTo-Json -Depth 8
        $response=Invoke-RestMethod "$graf/api/ds/query" -Method Post -ContentType 'application/json' -Headers $auth -Body $body -TimeoutSec 30
        $frames=@($response.results.A.frames)
        $hasData=@($frames | Where-Object { $_.data.values.Count -gt 1 -and $_.data.values[1].Count -gt 0 }).Count -gt 0
        Check $hasData "Grafana panel query returns real values: $($panel.title)"
    }
    $configText=Get-Content loki/config.yml -Raw
    Check ($configText -match 'retention_period: 168h' -and $configText -match 'retention_enabled: true' -and $configText -match 'delete_request_store: filesystem' -and $configText -match 'period: 24h') 'seven-day compactor retention configured (elapsed deletion not tested)'
    $output=@{checkedAt=[DateTimeOffset]::Now.ToString('o');passed=$script:passed;failed=$script:failed;checks=$script:checks;queries=$queries;proofRequestId=$proofId;phpRequestId=$phpId;sampledLines=$lines.Count;services=$services;collector='Promtail 3.5.12 (EOL; exam requirement)'}
    [IO.File]::WriteAllText((Join-Path $root $ResultPath),($output | ConvertTo-Json -Depth 15),(New-Object Text.UTF8Encoding($false)))
    Write-Output "RESULT: $script:passed logging assertions passed; $script:failed failed"
    if ($script:failed) { exit 1 }
} finally { Pop-Location }
