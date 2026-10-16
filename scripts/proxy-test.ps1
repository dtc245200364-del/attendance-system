$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
Push-Location $root
$faultStarted=$false
$stubStarted=$false
function Docker-Step([string[]]$DockerArgs) {
    & docker @DockerArgs
    if ($LASTEXITCODE -ne 0) { throw "Docker step failed: $($DockerArgs -join ' ')" }
}
try {
    Docker-Step @('compose','exec','-T','nginx','nginx','-t')
    # Generate test listeners from the actual proxy config; production remains untouched.
    $server=[IO.File]::ReadAllText((Join-Path $root 'nginx/default.conf'))
    $slow=$server.Replace('listen 8080 default_server','listen 18088').Replace('web:8080','web:18090').Replace('proxy_read_timeout 30s','proxy_read_timeout 1s')
    $down=$server.Replace('listen 8080 default_server','listen 18089').Replace('web:8080','127.0.0.1:9')
    $main=[IO.File]::ReadAllText((Join-Path $root 'nginx/nginx.conf'))
    $fault=$main.Replace('pid /tmp/nginx.pid','pid /tmp/attendance-fault-nginx.pid').Replace('include /etc/nginx/conf.d/*.conf;',"$slow`n$down").Replace('access_log /dev/stdout combined;','access_log off;').Replace('error_log /dev/stderr warn;','error_log /tmp/attendance-fault-nginx.log warn;')
    $path=Join-Path $root 'tests/proxy-fault.tmp'
    [IO.File]::WriteAllText($path,$fault,(New-Object Text.UTF8Encoding($false)))
    $fault | docker compose exec -T nginx sh -c 'cat > /tmp/attendance-fault-nginx.conf'
    if ($LASTEXITCODE -ne 0) { throw 'Cannot write test config into writable tmpfs' }
    Docker-Step @('compose','cp','tests/proxy-upstream.php','web:/tmp/attendance-proxy-upstream.php')
    Docker-Step @('compose','cp','scripts/proxy-fault-start.sh','web:/tmp/attendance-fault-start.sh')
    Docker-Step @('compose','cp','scripts/proxy-fault-stop.sh','web:/tmp/attendance-fault-stop.sh')
    Docker-Step @('compose','exec','-d','web','sh','/tmp/attendance-fault-start.sh')
    $stubStarted=$true
    Docker-Step @('compose','exec','-T','nginx','nginx','-c','/tmp/attendance-fault-nginx.conf')
    $faultStarted=$true
    Docker-Step @('compose','cp','tests/proxy-tests.php','web:/tmp/attendance-proxy-tests.php')
    Docker-Step @('compose','exec','-T','web','php','/tmp/attendance-proxy-tests.php')
    Docker-Step @('compose','cp','tests/untrusted-proxy.php','phpmyadmin:/tmp/attendance-untrusted-proxy.php')
    Docker-Step @('compose','exec','-T','phpmyadmin','php','-d','allow_url_fopen=1','/tmp/attendance-untrusted-proxy.php')
} finally {
    if ($faultStarted) { docker compose exec -T nginx nginx -c /tmp/attendance-fault-nginx.conf -s quit }
    if ($stubStarted) { docker compose exec -T web sh /tmp/attendance-fault-stop.sh }
    Pop-Location
}
