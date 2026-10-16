param([Parameter(Mandatory=$true)][string]$Source,[Parameter(Mandatory=$true)][string]$Name)
$ErrorActionPreference='Stop'
if ($Name -notmatch '^attendance-[a-z-]+\.php$') { throw 'Only named PHP test files in /tmp are allowed' }
$root=Split-Path $PSScriptRoot -Parent
$path=(Resolve-Path -LiteralPath (Join-Path $root $Source)).Path
if ([IO.Path]::GetDirectoryName($path) -ne (Join-Path $root 'tests')) { throw 'Source must be in project tests directory' }
# Docker cp rejects read_only containers even for tmpfs. Write as UID 33 via stdin.
# ASCII Base64 preserves exact UTF-8 bytes under Windows PowerShell 5.1 defaults.
[Convert]::ToBase64String([IO.File]::ReadAllBytes($path)) | docker compose exec -T web sh -c "base64 -d -i > /tmp/$Name"
if ($LASTEXITCODE -ne 0) { throw 'Cannot stream test to web tmpfs' }
$remote=docker compose exec -T web sha256sum "/tmp/$Name"
if ($LASTEXITCODE -ne 0 -or ($remote -split '\s+')[0].ToUpperInvariant() -ne (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash) { throw 'Transferred test hash mismatch' }
