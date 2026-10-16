$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$gitSafe='safe.directory='+$root.Replace('\','/')
$script:passed=0; $checks=@()
function Check($ok,$label) {
    if (!$ok) { throw "FAIL: $label" }
    $script:passed++; $script:checks+=@{name=$label;pass=$true}; Write-Host "PASS: $label"
}
Push-Location $root
try {
    docker compose exec -T nginx nginx -t
    Check ($LASTEXITCODE -eq 0) 'nginx -t'
    docker compose exec -T prometheus promtool check config /etc/prometheus/prometheus.yml
    Check ($LASTEXITCODE -eq 0) 'promtool config validation'
    $before=Get-Content docs/phase4-data-before.json -Raw | ConvertFrom-Json
    $after=Get-Content docs/phase4-data-after.json -Raw | ConvertFrom-Json
    $equal=$true
    foreach ($t in @('classes','students','sessions','attendance')) {
        if ($before.$t.count -ne $after.$t.count -or $before.$t.sha256 -ne $after.$t.sha256) { $equal=$false }
    }
    Check $equal 'all four MySQL table counts and SHA256 unchanged'
    $vars=@{}
    Get-Content .env.grafana | ForEach-Object { if ($_ -match '^([^=]+)=(.*)$') { $vars[$matches[1]]=$matches[2] } }
    $auth=@{Authorization='Basic '+[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($vars.GF_SECURITY_ADMIN_USER+':'+$vars.GF_SECURITY_ADMIN_PASSWORD))}
    $result=Get-Content docs/phase4-test-results.json -Raw | ConvertFrom-Json
    $expr='{job="attendance",service="nginx"} |= "'+$result.proofRequestId+'"'
    $url='http://127.0.0.1:13001/api/datasources/proxy/uid/attendance-loki/loki/api/v1/query_range?since=2h&limit=100&query='+[Uri]::EscapeDataString($expr)
    $proof=Invoke-RestMethod $url -Headers $auth
    Check ($proof.data.result.Count -gt 0) 'real 404 evidence exists before logging restart'
    docker compose exec -T promtail /usr/bin/bash -ec 'test -s /positions/positions.yml'
    Check ($LASTEXITCODE -eq 0) 'positions persisted before restart'
    docker compose restart loki promtail
    if ($LASTEXITCODE -ne 0) { throw 'Logging restart failed' }
    for ($i=0;$i -lt 15;$i++) {
        $ready=docker compose exec -T grafana sh -c 'wget -qO- http://loki:3100/ready 2>/dev/null'
        if ($LASTEXITCODE -eq 0 -and $ready -match 'ready') { break }
        Start-Sleep -Seconds 3
    }
    Check ($LASTEXITCODE -eq 0 -and $ready -match 'ready') 'Loki ready after controlled restart'
    $proof=Invoke-RestMethod $url -Headers $auth
    Check ($proof.data.result.Count -gt 0) 'same real 404 survives Loki restart'
    docker compose exec -T promtail /usr/bin/bash -ec 'test -s /positions/positions.yml && /usr/bin/bash /etc/promtail/healthcheck.sh'
    Check ($LASTEXITCODE -eq 0) 'Promtail positions and readiness survive restart'
    $config=docker compose config --format json | ConvertFrom-Json
    $portsOK=$true
    foreach ($entry in @(@('nginx','18087'),@('phpmyadmin','18086'),@('prometheus','19090'),@('grafana','13001'))) {
        $p=$config.services.($entry[0]).ports[0]
        if ($p.host_ip -ne '127.0.0.1' -or $p.published -ne $entry[1]) { $portsOK=$false }
    }
    Check $portsOK 'all four original host ports preserved on loopback'
    $targets=Invoke-RestMethod 'http://127.0.0.1:19090/api/v1/targets'
    Check ($targets.data.activeTargets.Count -eq 4 -and @($targets.data.activeTargets | Where-Object health -ne 'up').Count -eq 0) 'four Prometheus targets remain UP'
    $runtime=@()
    foreach ($id in @(docker compose ps -q)) {
        $item=(docker inspect $id | ConvertFrom-Json)[0]
        $runtime+=@{service=$item.Config.Labels.'com.docker.compose.service';id=$item.Id;running=$item.State.Running;health=$item.State.Health.Status;startedAt=$item.State.StartedAt;restartCount=$item.RestartCount;oomKilled=$item.State.OOMKilled}
    }
    Check ($runtime.Count -eq 11 -and @($runtime | Where-Object { !$_.running -or $_.oomKilled -or ($_.health -and $_.health -ne 'healthy') }).Count -eq 0) '11 services running; every configured Docker healthcheck healthy; no OOM'
    $disk=@{}
    foreach ($letter in @('C','D')) { $disk[$letter]=(New-Object IO.DriveInfo($letter)).AvailableFreeSpace }
    Check ($disk.C -gt 2GB -and $disk.D -gt 5GB) 'free space above 2 GiB on C and 5 GiB on D'
    $settings=Get-Content (Join-Path $env:APPDATA 'Docker/settings-store.json') -Raw | ConvertFrom-Json
    Check ($settings.CustomWslDistroDir -eq 'D:\DockerDesktopData\DockerDesktopWSL' -and (Test-Path 'D:\DockerDesktopData\DockerDesktopWSL\disk\docker_data.vhdx')) 'Docker Desktop settings and disk image on D confirmed'
    $ignoredOK=$true
    foreach ($path in @('.env','.env.grafana','secrets/mysql-exporter.cnf','docker-compose.override.yml')) {
        git -c $gitSafe check-ignore -q -- $path
        if ($LASTEXITCODE -ne 0) { $ignoredOK=$false }
    }
    Check $ignoredOK 'secrets and generated log mounts ignored'
    $secretValues=@()
    foreach ($path in @('.env','.env.grafana','secrets/mysql-exporter.cnf')) {
        foreach ($line in Get-Content $path) {
            if ($line -match '(?i)^[^#=]*(?:password|token|secret)[^=]*=(.+)$' -and $matches[1].Length -ge 16) { $secretValues+=$matches[1] }
        }
    }
    $candidates=@(git -c $gitSafe ls-files --cached --others --exclude-standard)
    if ($LASTEXITCODE -ne 0) { throw 'Cannot enumerate project candidate files' }
    $hits=0
    foreach ($path in $candidates) {
        if ([IO.Path]::GetExtension($path) -in @('.png','.jpg','.jpeg','.webp','.pdf')) { continue }
        if (!(Test-Path -LiteralPath $path -PathType Leaf)) { continue }
        $text=[IO.File]::ReadAllText((Join-Path $root $path))
        foreach ($secret in $secretValues) { if ($text.Contains($secret)) { $hits++ } }
        if ($text -match '-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,}') { $hits++ }
    }
    Check ($secretValues.Count -ge 4 -and $hits -eq 0) 'candidate project files contain no actual local passwords or recognizable private tokens/keys'
    $staged=@(git -c $gitSafe diff --cached --name-only)
    Check ($LASTEXITCODE -eq 0 -and $staged.Count -eq 0) 'nothing staged or committed by Phase 4 work'
    $head=git -c $gitSafe rev-parse --short HEAD
    Check ($LASTEXITCODE -eq 0 -and $head -eq '344f50c') 'HEAD remains Commit 3 (344f50c)'
    $output=@{checkedAt=[DateTimeOffset]::Now.ToString('o');passed=$script:passed;failed=0;checks=$script:checks;services=$runtime;freeBytes=$disk;dockerDiskDirectory=$settings.CustomWslDistroDir;targetsUp=4;targetsDown=0;candidateFilesScanned=$candidates.Count}
    [IO.File]::WriteAllText((Join-Path $root 'docs/phase4-final-results.json'),($output | ConvertTo-Json -Depth 12),(New-Object Text.UTF8Encoding($false)))
    Write-Output "RESULT: $script:passed final assertions passed; 0 failed"
} finally { Pop-Location }
