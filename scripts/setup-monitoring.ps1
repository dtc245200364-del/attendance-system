$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$grafana=Join-Path $root '.env.grafana'
$secretDir=Join-Path $root 'secrets'
$client=Join-Path $secretDir 'mysql-exporter.cnf'
$sql=Join-Path $secretDir 'monitoring-user.sql'
if ((Test-Path $grafana) -or (Test-Path $client) -or (Test-Path $sql)) { throw 'Monitoring credentials already exist; nothing overwritten.' }
function New-Secret {
    $bytes=New-Object byte[] 32
    $rng=[Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
}
New-Item -ItemType Directory -Force $secretDir | Out-Null
$mysql=New-Secret
$admin=New-Secret
$utf8=New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText($grafana,"GF_SECURITY_ADMIN_USER=attendance_admin`nGF_SECURITY_ADMIN_PASSWORD=$admin`n",$utf8)
[IO.File]::WriteAllText($client,"[client]`nuser=attendance_monitor`npassword=$mysql`nhost=db`nport=3306`n",$utf8)
[IO.File]::WriteAllText($sql,"CREATE USER IF NOT EXISTS 'attendance_monitor'@'%' IDENTIFIED BY '$mysql' WITH MAX_USER_CONNECTIONS 3;`n",$utf8)
Write-Output 'Monitoring credentials created locally; .env unchanged. Do not commit .env.grafana or secrets/.'
