param([Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference='Stop'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    & "$PSScriptRoot/copy-web-test.ps1" -Source tests/data-fingerprint.php -Name attendance-fingerprint.php
    if ($LASTEXITCODE -ne 0) { throw 'Cannot copy data fingerprint tool' }
    $json=docker compose exec -T web php /tmp/attendance-fingerprint.php
    if ($LASTEXITCODE -ne 0) { throw 'Data fingerprint command failed' }
    $parsed=($json -join "`n") | ConvertFrom-Json
    foreach ($table in @('classes','students','sessions','attendance')) {
        if ($null -eq $parsed.$table.count -or $parsed.$table.sha256 -notmatch '^[a-f0-9]{64}$') { throw 'Invalid fingerprint result' }
    }
    ($json -join "`n") | Set-Content -Encoding UTF8 $OutputPath
    Write-Output "Valid database fingerprint saved: $OutputPath"
} finally { Pop-Location }
