param([int]$Port = 18085, [int]$DebugPort = 19227)
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$evidence = Join-Path $root 'docs/evidence'
New-Item -ItemType Directory -Force $evidence | Out-Null
$chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
if (!(Test-Path $chrome)) { throw 'Google Chrome required' }
$profile = Join-Path $root ('tests/.browser-profile-' + [guid]::NewGuid().ToString('N'))
$prefix = 'B' + [guid]::NewGuid().ToString('N').Substring(0,24)
$script:seq=0; $script:passed=0; $socket=$null; $process=$null; $fixture=$null
function Check($ok, $label) { if (!$ok) { throw "FAIL: $label" }; $script:passed++; Write-Output "PASS: $label" }
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
    docker compose cp tests/browser-fixture.php web:/tmp/attendance-browser-fixture.php
    if ($LASTEXITCODE -ne 0) { throw 'Fixture copy failed' }
    $fixture=docker compose exec -T web php /tmp/attendance-browser-fixture.php create $prefix | ConvertFrom-Json
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
    foreach ($width in @(390,768,1440)) {
        Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -eq 390)} | Out-Null
        foreach ($path in @('/index.php','/classes.php','/students.php',"/attendance.php?session_id=$($fixture.session)",'/history.php','/statistics.php')) {
            Navigate $path
            $layout=Eval '({width:innerWidth,scroll:document.documentElement.scrollWidth,css:document.querySelector("main").getBoundingClientRect().width,lang:document.documentElement.lang,heading:!!document.querySelector("h1"),broken:[...document.querySelectorAll("link[rel=stylesheet]")].some(x=>!x.sheet)})'
            Check ($layout.scroll -le $layout.width+1 -and $layout.css -gt 0 -and $layout.lang -eq 'vi' -and $layout.heading -and !$layout.broken) "layout ${width}px $path (scroll=$($layout.scroll), viewport=$($layout.width))"
        }
        Screenshot "statistics-$width.png"
        Navigate "/attendance.php?session_id=$($fixture.session)"
        Check (Eval 'document.querySelector("tbody td:nth-child(2)").getBoundingClientRect().width>=160') "readable attendance name column ${width}px"
        Screenshot "attendance-$width.png"
        if ($width -eq 390) {
            Check (Eval '(()=>{const t=document.querySelector(".table-scroll");t.scrollLeft=t.scrollWidth;return t.scrollLeft>0})()') 'mobile attendance table scrolls to status controls'
            Screenshot 'attendance-390-scrolled.png'
        }
    }
    # Submit the actual rendered form and verify persisted states after browser navigation.
    $formValid=Eval '(()=>{let s=[...document.querySelectorAll("select[name^=status]")];["present","absent","late"].forEach((v,i)=>s[i].value=v);return s.length===3&&s[0].form.checkValidity()})()'
    Check $formValid 'browser attendance form accepts three states'
    Eval 'document.querySelector("select[name^=status]").form.requestSubmit(); true' | Out-Null
    Start-Sleep -Milliseconds 500
    Navigate "/attendance.php?session_id=$($fixture.session)"
    Check ((Eval 'JSON.stringify([...document.querySelectorAll("select[name^=status]")].map(s=>s.value))') -eq '["present","absent","late"]') 'browser submit persists present/absent/late'
    Navigate '/classes.php'
    Check (Eval 'document.querySelector("form input[name=code]").value=""; !document.querySelector("form").checkValidity()') 'browser required input validation'
    $errorState=Eval '(async()=>{let r=await fetch("/classes.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"csrf=wrong&action=create&code=bad&name=bad"});return r.status})()'
    Check ($errorState -eq 403) 'browser rejects invalid CSRF'
    Navigate '/classes.php'
    $csrfHtml=Eval '(async()=>{let r=await fetch("/classes.php",{method:"POST",body:new URLSearchParams({csrf:"wrong",action:"create"})});let h=await r.text();return h.includes("role=\"alert\"")&&h.includes("class=\"button\"")})()'
    Check $csrfHtml 'CSRF error has visible alert and recovery link'
    Navigate '/history.php?from=2099-02-30'
    Check (Eval '!!document.querySelector(".alert.error")') 'browser validation error visible'
    Screenshot 'validation-error.png'
    Navigate "/history.php?class_id=$($fixture.class)&from=2000-01-01&to=2000-01-02"
    Check (Eval '!!document.querySelector(".empty") && document.querySelectorAll("tbody tr").length===1') 'browser empty filtered history visible'
    Screenshot 'empty-history.png'
    Write-Output "RESULT: $script:passed browser assertions passed"
} finally {
    if ($socket -and $socket.State -eq 'Open') { try { Cdp 'Browser.close' | Out-Null } catch {} }
    if ($socket) { $socket.Dispose() }
    if ($process -and !$process.HasExited) { Stop-Process -Id $process.Id -ErrorAction SilentlyContinue }
    if ($fixture) { docker compose exec -T web php /tmp/attendance-browser-fixture.php cleanup $prefix }
}
