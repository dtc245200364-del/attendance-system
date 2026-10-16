param([string]$ResultPath='docs/phase3-test-results.json')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$prom='http://127.0.0.1:19090'
$graf='http://127.0.0.1:13001'
$script:passed=0; $script:failed=0; $checks=@(); $panels=@()
function Check($ok,$label) {
    if ($ok) { $script:passed++; Write-Host "PASS: $label" } else { $script:failed++; Write-Host "FAIL: $label" }
    $script:checks+=@{name=$label;pass=[bool]$ok}
}
function Query($expr) {
    $response=Invoke-RestMethod "$prom/api/v1/query?query=$([Uri]::EscapeDataString($expr))"
    if ($response.status -ne 'success') { throw 'Prometheus query failed' }
    return ,@($response.data.result)
}
function Value($expr) {
    $r=Query $expr
    if (!$r.Count) { return $null }
    return [double]::Parse($r[0].value[1],[Globalization.CultureInfo]::InvariantCulture)
}
try {
    $health=Invoke-RestMethod "$graf/api/health"
    Check ($health.database -eq 'ok') 'Grafana health/database'
    for ($attempt=0;$attempt -lt 12;$attempt++) {
        $targets=Invoke-RestMethod "$prom/api/v1/targets"
        $unready=@($targets.data.activeTargets | Where-Object health -ne 'up')
        if ($targets.data.activeTargets.Count -eq 4 -and $unready.Count -eq 0) { break }
        Write-Host 'Waiting for exporter readiness and initial scrape...'
        Start-Sleep -Seconds 5
    }
    Check ($targets.data.activeTargets.Count -eq 4) 'four configured Prometheus targets'
    foreach ($job in @('prometheus','cadvisor','nginx','mysql')) {
        $t=@($targets.data.activeTargets | Where-Object { $_.labels.job -eq $job })
        Check ($t.Count -eq 1 -and $t[0].health -eq 'up') "target UP: $job"
    }
    Check ((Value 'nginx_up{job="nginx"}') -eq 1) 'nginx_up = 1 (exporter reaches Nginx)'
    Check ((Value 'mysql_up{job="mysql"}') -eq 1) 'mysql_up = 1 (exporter authenticates to MySQL)'
    $vars=@{}
    foreach ($line in Get-Content (Join-Path $root '.env.grafana')) { if ($line -match '^([^=]+)=(.*)$') { $vars[$matches[1]]=$matches[2] } }
    $basic=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($vars['GF_SECURITY_ADMIN_USER']+':'+$vars['GF_SECURITY_ADMIN_PASSWORD']))
    $auth=@{Authorization="Basic $basic"}
    $datasource=Invoke-RestMethod "$graf/api/datasources/uid/attendance-prometheus" -Headers $auth
    Check ($datasource.url -eq 'http://prometheus:9090' -and $datasource.isDefault) 'provisioned default Prometheus datasource'
    $dsHealth=Invoke-RestMethod "$graf/api/datasources/uid/attendance-prometheus/health" -Headers $auth
    Check ($dsHealth.status -eq 'OK') 'Grafana datasource health'
    $dashboard=Invoke-RestMethod "$graf/api/dashboards/uid/attendance-system" -Headers $auth
    Check ($dashboard.dashboard.title -eq 'Attendance System Monitoring') 'dashboard provisioned with expected title'
    Check ($dashboard.dashboard.panels.Count -eq 9) 'nine real-metric panels provisioned'
    $nginxBefore=Value 'nginx_http_requests_total{job="nginx"}'
    $mysqlBefore=Value 'mysql_global_status_connections{job="mysql"}'
    $webCpuBefore=Value 'sum(container_cpu_usage_seconds_total{job="cadvisor",container_label_com_docker_compose_service="web"})'
    $webRxBefore=Value 'sum(container_network_receive_bytes_total{job="cadvisor",container_label_com_docker_compose_service="web",interface!="lo"})'
    for ($i=0;$i -lt 40;$i++) {
        $r=Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:18087/'
        if ($r.StatusCode -ne 200) { throw 'Website traffic generation failed' }
    }
    Check $true '40 real website requests generated'
    Write-Host 'Waiting for two scrapes to observe traffic and rate samples...'
    Start-Sleep -Seconds 32
    $nginxAfter=Value 'nginx_http_requests_total{job="nginx"}'
    $mysqlAfter=Value 'mysql_global_status_connections{job="mysql"}'
    Check ($null -ne $nginxBefore -and $nginxAfter -ge $nginxBefore+40) 'Nginx request counter increased by at least 40'
    Check ($null -ne $mysqlBefore -and $mysqlAfter -ge $mysqlBefore+40) 'MySQL connection counter increased with website traffic'
    $webCpuAfter=Value 'sum(container_cpu_usage_seconds_total{job="cadvisor",container_label_com_docker_compose_service="web"})'
    $webRxAfter=Value 'sum(container_network_receive_bytes_total{job="cadvisor",container_label_com_docker_compose_service="web",interface!="lo"})'
    Check ($null -ne $webCpuBefore -and $webCpuAfter -gt $webCpuBefore) 'web container CPU counter increased during real traffic'
    Check ($null -ne $webRxBefore -and $webRxAfter -gt $webRxBefore) 'web container network receive counter increased during real traffic'
    foreach ($service in @('web','nginx','db','phpmyadmin')) {
        foreach ($metric in @('container_cpu_usage_seconds_total','container_memory_working_set_bytes','container_network_receive_bytes_total')) {
            $series=Query ($metric+'{job="cadvisor",container_label_com_docker_compose_service="'+$service+'"}')
            Check ($series.Count -gt 0) "per-container metric $metric for $service"
        }
    }
    $foreign=Query 'container_memory_working_set_bytes{job="cadvisor",container_label_com_docker_compose_project!="attendance-phase1-fresh"}'
    Check ($foreign.Count -eq 0) 'cAdvisor stored memory series belong only to this project'
    foreach ($panel in $dashboard.dashboard.panels) {
        $expr=$panel.targets[0].expr
        $series=Query $expr
        $finite=@($series | Where-Object { $_.value[1] -notmatch 'NaN|Inf' })
        Check ($finite.Count -gt 0) "PromQL has real data: $($panel.title)"
        $body=@{from='now-5m';to='now';queries=@(@{refId='A';datasource=@{type='prometheus';uid='attendance-prometheus'};expr=$expr;instant=$true;range=$false;format='time_series';intervalMs=15000;maxDataPoints=1000})} | ConvertTo-Json -Depth 10
        $answer=Invoke-RestMethod "$graf/api/ds/query" -Method Post -ContentType 'application/json' -Body $body -Headers $auth
        $frames=@($answer.results.A.frames)
        $hasValues=@($frames | Where-Object { $_.data.values.Count -gt 1 -and $_.data.values[1].Count -gt 0 }).Count -gt 0
        Check $hasValues "Grafana panel datasource returns values: $($panel.title)"
        $panels+=@{title=$panel.title;expr=$expr;series=$finite.Count;grafanaHasValues=$hasValues}
    }
    $config=docker compose config --format json | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0) { throw 'Compose config failed' }
    foreach ($s in @('cadvisor','nginx-exporter','mysql-exporter')) { Check (!$config.services.$s.ports) "no host port on $s" }
    Check ($config.services.prometheus.ports[0].host_ip -eq '127.0.0.1' -and $config.services.grafana.ports[0].host_ip -eq '127.0.0.1') 'monitoring UIs bound only to loopback'
    Check (!$config.services.cadvisor.privileged) 'cAdvisor not privileged'
    $names=@($config.services.PSObject.Properties.Name)
    Check (($names -contains 'loki') -and ($names -contains 'promtail')) 'Phase 4 logging services configured alongside monitoring'
    $output=@{checkedAt=[DateTimeOffset]::Now.ToString('o');passed=$script:passed;failed=$script:failed;checks=$checks;panels=$panels;traffic=@{requests=40;nginxBefore=$nginxBefore;nginxAfter=$nginxAfter;mysqlBefore=$mysqlBefore;mysqlAfter=$mysqlAfter;webCpuBefore=$webCpuBefore;webCpuAfter=$webCpuAfter;webRxBefore=$webRxBefore;webRxAfter=$webRxAfter};targets=@($targets.data.activeTargets | ForEach-Object { @{job=$_.labels.job;health=$_.health;lastError=$_.lastError} })}
    $path=Join-Path $root $ResultPath
    [IO.File]::WriteAllText($path,($output | ConvertTo-Json -Depth 20),(New-Object Text.UTF8Encoding($false)))
    Write-Output "RESULT: $script:passed monitoring assertions passed; $script:failed failed"
    if ($script:failed) { exit 1 }
} catch {
    Write-Error $_
    exit 1
}
