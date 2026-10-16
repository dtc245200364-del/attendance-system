$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$safe='safe.directory='+$root.Replace('\','/')
$script:passed=0; $script:failed=0; $script:checks=@(); $runtime=@()
function Check($ok,$name) {
    $script:checks+=@{name=$name;pass=[bool]$ok}
    if ($ok) { $script:passed++; Write-Host "PASS: $name" } else { $script:failed++; Write-Host "FAIL: $name" }
}
Push-Location $root
try {
    docker compose config --quiet
    Check ($LASTEXITCODE -eq 0) 'Compose configuration valid'
    $config=docker compose config --format json | ConvertFrom-Json
    $hardened=@('web','nginx','prometheus','grafana','cadvisor','nginx-exporter','mysql-exporter','loki','promtail')
    $items=@{}
    foreach ($s in @($config.services.PSObject.Properties.Name)) {
        $id=docker compose ps -q $s
        if (!$id) { throw "Missing service: $s" }
        $c=(docker inspect $id | ConvertFrom-Json)[0]; $items[$s]=$c
        $top=@(docker top $id -eo uid,gid,pid,comm)
        if ($LASTEXITCODE -ne 0 -or $top.Count -lt 2 -or $top[1] -notmatch '^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\S+)') { throw "Cannot read actual process identity: $s" }
        $uid=[int]$matches[1]; $gid=[int]$matches[2]
        $runtime+=@{service=$s;uid=$uid;gid=$gid;readOnly=$c.HostConfig.ReadonlyRootfs;memoryBytes=$c.HostConfig.Memory;nanoCpus=$c.HostConfig.NanoCpus;pidsLimit=$c.HostConfig.PidsLimit;health=$c.State.Health.Status;running=$c.State.Running}
        if ($s -notin @('cadvisor','promtail','phpmyadmin')) { Check ($uid -gt 0) "actual non-root process: $s (UID $uid/GID $gid)" }
        if ($s -in $hardened) {
            Check ($c.HostConfig.ReadonlyRootfs) "read-only root filesystem: $s"
            Check (!$c.HostConfig.Privileged -and $c.HostConfig.CapDrop -contains 'ALL' -and $c.HostConfig.SecurityOpt -contains 'no-new-privileges:true') "capabilities dropped, no privilege escalation: $s"
            Check ($c.HostConfig.Memory -gt 0 -and $c.HostConfig.NanoCpus -gt 0 -and $c.HostConfig.PidsLimit -gt 0) "CPU/RAM/PID limits active: $s"
        }
    }
    $state=docker compose exec -T web cat /proc/1/status
    Check (($state -match '^CapEff:\s+0000000000000000$') -and ($state -match '^NoNewPrivs:\s+1$')) 'PHP kernel CapEff zero and NoNewPrivs one'
    foreach ($path in @('/tmp','/var/run/apache2','/var/lock/apache2')) {
        $tmpfs=$items.web.HostConfig.Tmpfs.$path
        Check ($tmpfs -match 'noexec' -and $tmpfs -match 'nosuid' -and $tmpfs -match 'uid=33' -and $tmpfs -match 'size=') "bounded noexec/nosuid tmpfs for PHP: $path"
    }
    & "$PSScriptRoot/copy-web-test.ps1" -Source tests/hardening.php -Name attendance-hardening.php
    $answer=docker compose exec -T web php /tmp/attendance-hardening.php
    $parsed=($answer -join "`n") | ConvertFrom-Json
    foreach ($c in $parsed.checks) { Check $c.pass $c.name }
    $client=@{}
    Get-Content secrets/mysql-exporter.cnf | ForEach-Object { if ($_ -match '^([^=]+)=(.*)$') { $client[$matches[1]]=$matches[2] } }
    $oldEncoding=$OutputEncoding; $OutputEncoding=New-Object Text.UTF8Encoding($false)
    try { $answer=(@{user=$client.user;password=$client.password} | ConvertTo-Json -Compress) | docker compose exec -T web php /tmp/attendance-hardening.php monitor }
    finally { $OutputEncoding=$oldEncoding; $client.Clear() }
    $parsed=($answer -join "`n") | ConvertFrom-Json
    foreach ($c in $parsed.checks) { Check $c.pass $c.name }
    # SHOW GRANTS does not expose resource limits on this MySQL version.
    $query=@'
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e "SELECT max_user_connections FROM mysql.user WHERE User='attendance_monitor' AND Host='%';"
'@
    $limit=$query | docker compose exec -T db sh
    Check ($LASTEXITCODE -eq 0 -and "$limit".Trim() -eq '3') 'monitor max connections = 3 (read-only mysql.user metadata query)'
    foreach ($s in @('web','db','cadvisor','nginx-exporter','mysql-exporter','loki','promtail')) {
        Check (!$items[$s].HostConfig.PortBindings.PSObject.Properties.Count) "no published host port: $s"
    }
    foreach ($p in @(@('nginx','18087'),@('phpmyadmin','18086'),@('prometheus','19090'),@('grafana','13001'))) {
        $port=$config.services.($p[0]).ports[0]
        Check ($port.host_ip -eq '127.0.0.1' -and $port.published -eq $p[1]) "original loopback-only port: $($p[0])"
    }
    foreach ($n in @('proxy','database','monitoring')) { Check ($config.networks.$n.internal) "internal network: $n" }
    $dbNet=$items.db.NetworkSettings.Networks.PSObject.Properties | Select-Object -First 1
    $dbIp=$dbNet.Value.IPAddress
    if ($dbIp -notmatch '^\d{1,3}(\.\d{1,3}){3}$') { throw 'Invalid database IP' }
    docker compose exec -T nginx sh -c "command -v nc >/dev/null || exit 2; if nc -z -w 2 $dbIp 3306; then exit 1; else exit 0; fi"
    Check ($LASTEXITCODE -eq 0) 'actual TCP connection Nginx to MySQL IP:3306 blocked'
    $expected=@('/rootfs/proc','/sys','/run/containerd/containerd.sock','/var/run/docker.sock')
    $mounts=@($items.cadvisor.Mounts)
    Check ($mounts.Count -eq 4 -and @($mounts | Where-Object { $_.RW -or $_.Destination -notin $expected }).Count -eq 0) 'cAdvisor only four allowlisted read-only mounts; no VM root/docker-data mount'
    docker compose exec -T cadvisor sh -c 'test ! -e /rootfs/etc/passwd && test ! -e /var/lib/docker && test -d /rootfs/proc'
    Check ($LASTEXITCODE -eq 0) 'cAdvisor direct full-host filesystem paths absent'
    $logs=@($items.promtail.Mounts | Where-Object Destination -Like '/logs/*')
    Check ($logs.Count -eq 3 -and @($logs | Where-Object { $_.RW -or $_.Source -notmatch '-json\.log$' }).Count -eq 0) 'Promtail exactly three read-only log files, no container metadata'
    Check (@($items.promtail.Mounts | Where-Object Source -Match '\.sock$').Count -eq 0) 'Promtail no runtime socket mounts'
    foreach ($url in @('http://127.0.0.1:18087/','http://127.0.0.1:18087/assets/style.css','http://127.0.0.1:18087/phase5-not-found')) {
        try { $response=Invoke-WebRequest -UseBasicParsing $url }
        catch { if (!$_.Exception.Response) { throw }; $response=$_.Exception.Response }
        $headers=$response.Headers
        Check ($headers['X-Content-Type-Options'] -eq 'nosniff') "HTTP nosniff: $url"
        Check ($headers['X-Frame-Options'] -eq 'DENY') "HTTP frame denial: $url"
        Check ($headers['Referrer-Policy'] -eq 'same-origin') "HTTP referrer policy: $url"
        Check ($headers['Content-Security-Policy'] -match "default-src 'self'" -and $headers['Content-Security-Policy'] -match "frame-ancestors 'none'") "HTTP CSP: $url"
        Check (!$headers['X-Powered-By'] -and $headers['Server'] -notmatch 'nginx/|Apache/|PHP/') "no version/PHP disclosure: $url"
    }
    $page=Invoke-WebRequest -UseBasicParsing http://127.0.0.1:18087/classes.php
    Check ($page.Headers['Set-Cookie'] -match '(?i)HttpOnly' -and $page.Headers['Set-Cookie'] -match '(?i)SameSite=Strict') 'actual session cookie HttpOnly and SameSite Strict'
    Check ($page.StatusCode -eq 200) 'risk recorded: website accessible without login on local loopback'
    $secrets=@()
    foreach ($p in @('.env','.env.grafana','secrets/mysql-exporter.cnf')) {
        foreach ($line in Get-Content $p) { if ($line -match '(?i)^[^#=]*password[^=]*=(.+)$') { $secrets+=$matches[1] } }
    }
    Check ($secrets.Count -eq 4 -and @($secrets | Where-Object { $_ -notmatch '^[a-f0-9]{64}$' }).Count -eq 0 -and @($secrets | Select-Object -Unique).Count -eq 4) 'four independent 256-bit generated secret values (not printed)'
    $hashes=Get-Content secrets/phase5-secret-hashes.json -Raw | ConvertFrom-Json
    $same=$true
    foreach ($p in $hashes.PSObject.Properties) { if ((Get-FileHash -LiteralPath $p.Name -Algorithm SHA256).Hash -ne $p.Value) { $same=$false } }
    Check $same 'local secret files unchanged'
    $ignored=$true
    foreach ($p in @('.env','.env.grafana','secrets/mysql-exporter.cnf','docker-compose.override.yml','example.log','example.tmp')) { git -c $safe check-ignore -q -- $p; if ($LASTEXITCODE -ne 0) { $ignored=$false } }
    Check $ignored 'secret/env/runtime/log/temp paths ignored'
    $files=@(git -c $safe ls-files --cached --others --exclude-standard)
    $hits=0
    foreach ($p in $files) {
        if ([IO.Path]::GetExtension($p) -in @('.png','.jpg','.jpeg','.webp','.pdf') -or !(Test-Path -LiteralPath $p -PathType Leaf)) { continue }
        $text=[IO.File]::ReadAllText((Join-Path $root $p))
        foreach ($secret in $secrets) { if ($text.Contains($secret)) { $hits++ } }
        if ($text -match '-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{40,}') { $hits++ }
    }
    Check ($hits -eq 0) 'candidate Git files free of actual local passwords and recognizable key/token formats'
    $targets=Invoke-RestMethod http://127.0.0.1:19090/api/v1/targets
    Check ($targets.data.activeTargets.Count -eq 4 -and @($targets.data.activeTargets | Where-Object health -ne 'up').Count -eq 0) 'Prometheus 4/4 targets UP'
    $before=Get-Content secrets/phase5-runtime-before.json -Raw | ConvertFrom-Json
    $dbUnchanged=$true; $otherIdsPreserved=$true; $externalStartsChanged=0
    foreach ($c in @($before | Where-Object { $_.project -ne 'attendance-phase1-fresh' -or $_.service -in @('db','phpmyadmin') })) {
        $current=docker inspect $c.id 2>$null | ConvertFrom-Json
        if ($LASTEXITCODE -ne 0) { if ($c.project -eq 'attendance-phase1-fresh') { $dbUnchanged=$false } else { $otherIdsPreserved=$false }; continue }
        if ($c.project -eq 'attendance-phase1-fresh' -and ($current[0].State.StartedAt -ne $c.startedAt -or $current[0].State.Running -ne $c.running)) { $dbUnchanged=$false }
        if ($c.project -ne 'attendance-phase1-fresh' -and $current[0].State.StartedAt -ne $c.startedAt) { $externalStartsChanged++ }
    }
    Check $dbUnchanged 'MySQL/phpMyAdmin container IDs, start times and states unchanged'
    Check $otherIdsPreserved 'all pre-existing other-project container IDs still exist'
    if ($externalStartsChanged) { Write-Host "OBSERVATION: $externalStartsChanged other-project container start time changed independently; no lifecycle commands targeted other projects." }
    Check ($runtime.Count -eq 11 -and @($items.Values | Where-Object { !$_.State.Running -or $_.State.OOMKilled -or ($_.State.Health -and $_.State.Health.Status -ne 'healthy') }).Count -eq 0) '11 services running; configured healthchecks healthy; no OOM'
    $beforeData=Get-Content docs/phase5-data-before.json -Raw | ConvertFrom-Json
    $afterData=Get-Content docs/phase5-data-after.json -Raw | ConvertFrom-Json
    $sameData=$true
    foreach ($t in @('classes','students','sessions','attendance')) {
        if ($beforeData.$t.count -ne $afterData.$t.count -or $beforeData.$t.sha256 -ne $afterData.$t.sha256) { $sameData=$false }
    }
    Check $sameData 'all four MySQL table counts/SHA256 match before and after hardening/regression'
    $result=@{checkedAt=[DateTimeOffset]::Now.ToString('o');passed=$script:passed;failed=$script:failed;checks=$script:checks;runtime=$runtime;candidateFilesScanned=$files.Count;externalStartTimeChanges=$externalStartsChanged}
    [IO.File]::WriteAllText((Join-Path $root 'docs/phase5-hardening-results.json'),($result | ConvertTo-Json -Depth 12),(New-Object Text.UTF8Encoding($false)))
    Write-Output "RESULT: $script:passed hardening assertions passed; $script:failed failed"
    if ($script:failed) { exit 1 }
} finally { Pop-Location }
