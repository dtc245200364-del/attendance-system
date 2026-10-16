$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$gitSafe = 'safe.directory=' + $root.Replace('\','/')
$passed=0
function Check($ok,$label) { if (!$ok) { throw "FAIL: $label" }; $script:passed++; Write-Output "PASS: $label" }
Push-Location $root
try {
    $config=docker compose config --format json | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0) { throw 'Compose config failed' }
    Check ($config.name -eq 'attendance-phase1-fresh') 'separate Compose project'
    Check (@($config.services.PSObject.Properties.Name).Count -eq 11) 'eleven Phase 4 services including Loki and Promtail'
    Check ($config.networks.database.internal -eq $true) 'internal database network'
    Check (!$config.services.db.ports) 'no database host port'
    Check ($config.services.nginx.ports[0].host_ip -eq '127.0.0.1' -and $config.services.phpmyadmin.ports[0].host_ip -eq '127.0.0.1' -and !$config.services.web.ports) 'Nginx and phpMyAdmin loopback; backend unpublished'
    $webPort=$config.services.nginx.ports[0].published
    $pmaPort=$config.services.phpmyadmin.ports[0].published
    Check ($webPort -ne $pmaPort -and $webPort -ne '8085' -and $pmaPort -ne '8086') 'new distinct host ports'
    $site=Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$webPort/"
    Check ($site.StatusCode -eq 200) 'host website HTTP 200'
    $pma=Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$pmaPort/" -SessionVariable pmaSession
    Check ($pma.StatusCode -eq 200) 'host phpMyAdmin HTTP 200'
    $form=@{}
    foreach ($m in [regex]::Matches($pma.Content,'<input\b[^>]*type="hidden"[^>]*>')) {
        $name=[regex]::Match($m.Value,'name="([^"]+)"').Groups[1].Value
        $value=[regex]::Match($m.Value,'value="([^"]*)"').Groups[1].Value
        if ($name) { $form[$name]=[Net.WebUtility]::HtmlDecode($value) }
    }
    $envValues=@{}
    foreach ($line in Get-Content .env) { if ($line -match '^([^#=]+)=(.*)$') { $envValues[$matches[1]]=$matches[2] } }
    $form['pma_username']='attendance_app'; $form['pma_password']=$envValues['DB_PASSWORD']; $form['server']='1'
    $login=Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$pmaPort/index.php" -Method Post -Body $form -WebSession $pmaSession
    Check ($login.StatusCode -eq 200 -and $login.Content.Contains('attendance_phase1') -and !$login.Content.Contains('name="pma_password"')) 'phpMyAdmin login and database access with app user'
    $form.Clear(); $login=$null
    Check ($envValues['DB_PASSWORD'].Length -ge 32 -and $envValues['DB_ROOT_PASSWORD'].Length -ge 32 -and $envValues['DB_PASSWORD'] -ne $envValues['DB_ROOT_PASSWORD']) 'independent strong database passwords'
    $hits=@()
    foreach ($file in Get-ChildItem -Recurse -File -Force) {
        if ($file.Name -eq '.env' -or $file.FullName -match '\\\.git\\|\\\.browser-profile[^\\]*\\' -or $file.Extension -in @('.png','.log')) { continue }
        $content=[IO.File]::ReadAllText($file.FullName)
        foreach ($secret in @($envValues['DB_PASSWORD'],$envValues['DB_ROOT_PASSWORD'])) { if ($content.Contains($secret)) { $hits+=$file.FullName } }
    }
    Check ($hits.Count -eq 0) 'no password copied into source docs or tests'
    $tracked=@(git -c $gitSafe ls-files -- .env '.env.*')
    if ($LASTEXITCODE -ne 0) { throw 'Cannot verify Git tracking' }
    Check (@($tracked | Where-Object { $_ -notlike '*.env.example' }).Count -eq 0) 'no secret env file tracked in Git'
    git -c $gitSafe check-ignore -q .env
    Check ($LASTEXITCODE -eq 0) '.env ignored by Git'
    $history=@(git -c $gitSafe log --all --format=%H -- .env)
    if ($LASTEXITCODE -ne 0) { throw 'Cannot verify Git history' }
    Check ($history.Count -eq 0) 'no .env commits in accessible Git history'
    $user=docker compose exec -T web id -u
    Check ($LASTEXITCODE -eq 0 -and $user -eq '33') 'web runs non-root uid 33'
    $dbId=docker compose ps -q db
    $dbState=docker inspect $dbId --format '{{.State.Health.Status}}'
    Check ($LASTEXITCODE -eq 0 -and $dbState -eq 'healthy') 'MySQL healthy'
    $dbInspect=docker inspect $dbId | ConvertFrom-Json
    $dataMount=$dbInspect[0].Mounts | Where-Object Destination -eq '/var/lib/mysql'
    Check ($dataMount.Name -eq 'attendance-phase1-fresh_phase1_data') 'database uses only new project volume'
    Write-Output "Git root: $(git -c $gitSafe rev-parse --show-toplevel)"
    Write-Output "RESULT: $passed infrastructure/security assertions passed"
} finally { Pop-Location }
