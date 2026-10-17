param([int]$Port=18097, [int]$DebugPort=19247, [string]$EvidenceDirectory='docs/ui-automated/modern-attendance', [string]$ComposeFile='docker-compose.phase6.yml', [string]$ComposeEnvFile='.env.phase6', [switch]$StudentActions, [string]$ResultPath='docs/modern-ui-browser-results.json', [int[]]$ViewportWidths=@(390,768,1024,1440), [switch]$CompactStudents)
$ErrorActionPreference = 'Stop'
$composeArgs=@('compose'); if ($ComposeFile) { $composeArgs+=@('-f',$ComposeFile) }
if ($ComposeEnvFile) { $composeArgs+=@('--env-file',$ComposeEnvFile) }
$root = Split-Path $PSScriptRoot -Parent
$evidence = Join-Path $root $EvidenceDirectory
New-Item -ItemType Directory -Force $evidence | Out-Null
$chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
if (!(Test-Path $chrome)) { throw 'Google Chrome required' }
$profile = Join-Path $root ('tests/.browser-profile-' + [guid]::NewGuid().ToString('N'))
$prefix = 'B' + [guid]::NewGuid().ToString('N').Substring(0,24)
$script:checks=@(); $script:browserErrors=@(); $script:seq=0; $script:passed=0; $socket=$null; $process=$null; $fixture=$null
function Check($ok, $label) { $script:checks+=@{name=$label;pass=[bool]$ok}; if (!$ok) { throw "FAIL: $label" }; $script:passed++; Write-Output "PASS: $label" }
function Cdp($method, $params=@{}) {
    $script:seq++; $requestId=$script:seq
    $payload=@{id=$requestId;method=$method;params=$params} | ConvertTo-Json -Depth 20 -Compress
    $bytes=[Text.Encoding]::UTF8.GetBytes($payload)
    $segment=[ArraySegment[byte]]::new($bytes)
    $cancel=[Threading.CancellationTokenSource]::new(15000)
    try {
        $socket.SendAsync($segment,[Net.WebSockets.WebSocketMessageType]::Text,$true,$cancel.Token).GetAwaiter().GetResult() | Out-Null
        do {
            $stream=[IO.MemoryStream]::new()
            do {
                $buffer=New-Object byte[] 65536
                $received=$socket.ReceiveAsync([ArraySegment[byte]]::new($buffer),$cancel.Token).GetAwaiter().GetResult()
                $stream.Write($buffer,0,$received.Count)
            } while (!$received.EndOfMessage)
            $reply=[Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
            if ($reply.method -eq 'Runtime.exceptionThrown') { $script:browserErrors+=$reply.params }
            if ($reply.method -eq 'Log.entryAdded' -and $reply.params.entry.level -eq 'error' -and $reply.params.entry.source -eq 'security') { $script:browserErrors+=$reply.params }
            $stream.Dispose()
        } while ($reply.id -ne $requestId)
        if ($reply.error) { throw ($reply.error | ConvertTo-Json -Compress) }
        return $reply.result
    } finally { $cancel.Dispose() }
}
function Eval($expression) {
    $result=Cdp 'Runtime.evaluate' @{expression=$expression;returnByValue=$true;awaitPromise=$true}
    if ($result.exceptionDetails) { throw ($result.exceptionDetails | ConvertTo-Json -Depth 10 -Compress) }
    return $result.result.value
}
function Navigate($path) {
    Cdp 'Page.navigate' @{url="http://127.0.0.1:$Port$path"} | Out-Null
    for ($attempt=0;$attempt -lt 60;$attempt++) {
        Start-Sleep -Milliseconds 100
        if ((Eval 'document.readyState') -eq 'complete' -and (Eval 'location.pathname + location.search') -eq $path) { return }
    }
    throw "Navigation timeout: $path"
}
function Screenshot($name) {
    $shot=Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$true}
    [IO.File]::WriteAllBytes((Join-Path $evidence $name),[Convert]::FromBase64String($shot.data))
}
try {
    $testConfig= & docker @composeArgs config --format json | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0 -or $testConfig.name -ne 'attendance-phase6-test') { throw 'UI write tests require the isolated test project' }
    & "$PSScriptRoot/copy-web-test.ps1" -Source tests/browser-fixture.php -Name attendance-browser-fixture.php -ComposeFile $ComposeFile -ComposeEnvFile $ComposeEnvFile
    if ($LASTEXITCODE -ne 0) { throw 'Fixture copy failed' }
    $fixture= & docker @composeArgs exec -T web php /tmp/attendance-browser-fixture.php create $prefix | ConvertFrom-Json
    if ($LASTEXITCODE -ne 0) { throw 'Fixture creation failed' }
    $browserArgs=@('--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--enable-logging=stderr',"--remote-debugging-port=$DebugPort",'--remote-debugging-address=127.0.0.1',"--user-data-dir=`"$profile`"",'about:blank')
    $process=Start-Process -FilePath $chrome -ArgumentList $browserArgs -WindowStyle Hidden -PassThru -RedirectStandardError (Join-Path $root 'tests/browser-stderr.log')
    $target=$null
    for ($i=0;$i -lt 60;$i++) {
        try {
            foreach ($candidate in (Invoke-RestMethod "http://127.0.0.1:$DebugPort/json/list")) {
                if ($candidate.type -eq 'page') { $target=$candidate; break }
            }
            if ($target) { break }
        } catch { $lastBrowserError=$_.Exception.Message }
        Start-Sleep -Milliseconds 200
    }
    if (!$target) { throw "Chrome debugging endpoint unavailable: $lastBrowserError" }
    $socket=[Net.WebSockets.ClientWebSocket]::new()
    $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
    Cdp 'Page.enable' | Out-Null
    Cdp 'Runtime.enable' | Out-Null
    Cdp 'Log.enable' | Out-Null
    $measurements=@()
    foreach ($width in @(390,768,1024,1366,1440,1920)) {
        Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1200;deviceScaleFactor=1;mobile=($width -eq 390)} | Out-Null
        Navigate '/index.php'
        $overview=Eval '(()=>{const m=document.querySelector("main"),r=m.getBoundingClientRect(),s=getComputedStyle(m);return {left:r.left+parseFloat(s.paddingLeft),right:document.documentElement.clientWidth-r.right+parseFloat(s.paddingRight)}})()'
        Screenshot "overview-$width.png"
        Navigate '/students.php'
        $panels=Eval '[...document.querySelectorAll(".student-create-panel,.student-directory-panel")].map(p=>{const r=p.getBoundingClientRect();return {left:r.left,right:document.documentElement.clientWidth-r.right,width:r.width}})'
        foreach ($p in $panels) { Check ([Math]::Abs($p.left-$overview.left) -lt 1 -and [Math]::Abs($p.right-$overview.right) -lt 1) "panels match overview content edges ${width}px" }
        Check (Eval 'document.documentElement.scrollWidth<=innerWidth') "no horizontal page overflow ${width}px"
        if ($CompactStudents -and $width -ge 1366) {
            Check (Eval '[...document.querySelectorAll(".student-actions")].every(a=>{const b=[...a.querySelectorAll("a,button")].map(x=>x.getBoundingClientRect());return b.length===3&&Math.max(...b.map(x=>x.top))-Math.min(...b.map(x=>x.top))<2&&b.every(x=>x.right<=a.getBoundingClientRect().right+1)})') "three actions on one row ${width}px"
            Check (Eval '(()=>{const h=[...document.querySelector(".student-head").children].map(x=>x.getBoundingClientRect().left);return [...document.querySelectorAll(".student-row")].every(r=>[...r.children].every((x,i)=>Math.abs(x.getBoundingClientRect().left-h[i])<1))})()') "five column headers aligned ${width}px"
            Check (Eval '[...document.querySelectorAll(".student-row")].every(r=>{const h=r.getBoundingClientRect().height;return h>=60&&h<=64})') "row height 60 to 64 ${width}px"
        }
        Screenshot "students-$width.png"
        $measurements+=@{viewport=$width;overview=$overview;panels=$panels}
    }
    @{passed=$script:passed;failed=0;measurements=$measurements;checks=$script:checks} | ConvertTo-Json -Depth 12 | Set-Content -Encoding UTF8 (Join-Path $root $ResultPath)
    Write-Output "RESULT: $script:passed layout assertions passed"
} finally {
    if ($socket -and $socket.State -eq 'Open') { try { Cdp 'Browser.close' | Out-Null } catch {} }
    if ($socket) { $socket.Dispose() }
    if ($process -and !$process.HasExited) { Stop-Process -Id $process.Id -ErrorAction SilentlyContinue }
    if ($fixture) { & docker @composeArgs exec -T web php /tmp/attendance-browser-fixture.php cleanup $prefix }
}
