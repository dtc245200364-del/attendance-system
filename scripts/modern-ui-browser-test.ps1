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
    foreach ($width in $ViewportWidths) {
        Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -eq 390)} | Out-Null
        foreach ($path in @('/index.php','/classes.php','/students.php',"/attendance.php?session_id=$($fixture.session)",'/history.php','/statistics.php')) {
            Navigate $path
            $layout=Eval '({width:innerWidth,scroll:document.documentElement.scrollWidth,css:document.querySelector("main").getBoundingClientRect().width,lang:document.documentElement.lang,heading:!!document.querySelector("h1"),broken:[...document.querySelectorAll("link[rel=stylesheet]")].some(x=>!x.sheet)})'
            Check ($layout.scroll -le $layout.width+1 -and $layout.css -gt 0 -and $layout.lang -eq 'vi' -and $layout.heading -and !$layout.broken) "layout ${width}px $path (scroll=$($layout.scroll), viewport=$($layout.width))"
            Check (Eval 'document.querySelectorAll("nav a").length===6 && document.querySelectorAll("nav a[aria-current=page]").length===1 && [...document.querySelectorAll("nav a")].every(a=>!!a.querySelector("svg"))') "six navigation links and active state ${width}px $path"
            Check (Eval 'getComputedStyle(document.documentElement).getPropertyValue("--primary").trim()==="#167d70" && getComputedStyle(document.querySelector(".sidebar")).backgroundColor==="rgb(18, 61, 57)"') "design tokens and sidebar ${width}px $path"
            Check (Eval '[...document.querySelectorAll("script[src],link[rel=stylesheet]")].every(x=>new URL(x.src||x.href).origin===location.origin)') "local assets ${width}px $path"
            if ($StudentActions -and $path -eq '/students.php') {
                Check (Eval '[...document.querySelectorAll(".student-actions")].every(x=>x.querySelectorAll("a,button").length===3 && [...x.querySelectorAll("a,button")].every(b=>!!b.title && !!b.getAttribute("aria-label") && !!b.querySelector("svg")))') "three labeled actions with icons/tooltips ${width}px"
                Check (Eval '[...document.querySelectorAll(".student-actions a,.student-actions button")].every(x=>{const r=x.getBoundingClientRect();return r.width>0&&r.left>=0&&r.right<=innerWidth})') "student actions fit viewport ${width}px"
                Eval 'document.querySelector("[data-edit-student]").click();true' | Out-Null
                Check (Eval '[...document.querySelectorAll("details.student[open] input:not([type=hidden]),details.student[open] select")].every(x=>{const r=x.getBoundingClientRect();return r.width>0&&r.left>=0&&r.right<=innerWidth})') "populated edit form fits viewport ${width}px"
                Screenshot "student-edit-$width.png"
                Eval 'document.querySelector("details.student[open]").open=false;document.querySelector("[data-delete-student]").click();true' | Out-Null
                Check (Eval '(()=>{const d=document.querySelector("#delete-confirmation"),r=d.getBoundingClientRect();return d.open&&r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight})()') "confirmation dialog fits viewport ${width}px"
                Screenshot "delete-dialog-$width.png"
                Eval 'document.querySelector("[data-close-delete]").click();true' | Out-Null
            }
            $pageName=($path -split '\?')[0].Trim('/').Replace('.php','')
            Screenshot "$pageName-$width.png"
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
    Navigate '/students.php'
    Check (Eval ('(()=>{const s=document.querySelector("#student-search");s.value="'+$prefix+'2";s.dispatchEvent(new Event("input"));return [...document.querySelectorAll("[data-student-row]")].filter(x=>!x.hidden).length===1})()')) 'student client search filters matching code'
    Check (Eval '(()=>{const s=document.querySelector("#student-search");s.value="NO_SUCH_STUDENT";s.dispatchEvent(new Event("input"));return !document.querySelector("#student-search-empty").hidden})()') 'student search empty state'
    Check (Eval '(()=>{const s=document.querySelector("#student-search");s.value="";s.dispatchEvent(new Event("input"));return [...document.querySelectorAll("[data-student-row]")].filter(x=>!x.hidden).length===3})()') 'clearing search restores roster'
    Eval 'document.querySelector("details.student").open=true; true' | Out-Null
    Check (Eval 'document.querySelector("details.student[open] form [name=action][value=update]").getBoundingClientRect().height>0') 'student edit action accessible'
    if ($StudentActions) {
        Eval 'document.querySelector("details.student").open=false;document.querySelector("[data-edit-student]").click();true' | Out-Null
        Check (Eval '(()=>{const d=document.querySelector("details.student[open]");return !!d&&d.querySelector("input[name=name]").value==="Sinh viên kiểm thử 1"})()') 'edit button opens populated current profile'
        Eval 'document.querySelector("[data-delete-student]").click();true' | Out-Null
        Check (Eval '(()=>{const d=document.querySelector("#delete-confirmation");return d.open&&d.matches(":modal")&&d.querySelector("[data-delete-name]").textContent==="Sinh viên kiểm thử 1"&&!!d.querySelector("[data-delete-code]").textContent})()') 'delete opens modal with student name and code'
        Check (Eval 'document.querySelector("#delete-student-form button").disabled&&document.querySelector("[data-delete-explanation]").textContent.includes("Ngừng theo dõi")') 'protected student cannot confirm delete in UI'
        Check (Eval 'document.activeElement.matches("[data-close-delete]")') 'confirmation initially focuses safe cancel action'
        Screenshot 'protected-delete-dialog.png'
        Eval 'document.querySelector("[data-close-delete]").click();true' | Out-Null
        Check (Eval '!document.querySelector("#delete-confirmation").open') 'cancel closes dialog without mutation'
        Eval 'document.querySelector("[data-delete-student]").click();true' | Out-Null
        Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Escape';code='Escape';windowsVirtualKeyCode=27} | Out-Null
        Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Escape';code='Escape';windowsVirtualKeyCode=27} | Out-Null
        Check (Eval '!document.querySelector("#delete-confirmation").open') 'Escape cancels native dialog'
        Eval 'document.querySelector(".student-actions form button[value=archive]").click();true' | Out-Null
        Start-Sleep -Milliseconds 500
        Check (Eval '!!document.querySelector(".student-actions button[value=restore]")&&document.body.textContent.includes("Lịch sử và thống kê được giữ nguyên.")') 'archive action changes to restore with explicit success'
        Eval 'document.querySelector(".student-actions button[value=restore]").click();true' | Out-Null
        Start-Sleep -Milliseconds 500
        Check (Eval '!document.querySelector(".student-actions button[value=restore]")') 'restore action returns to archive'
        Eval ('(()=>{const f=document.querySelector("input[name=action][value=create]").form;f.elements.code.value="'+$prefix+'D";f.elements.name.value="Hồ sơ <b>Xóa</b>";f.elements.class_id.value="'+$fixture.class+'";f.requestSubmit();return true})()') | Out-Null
        Start-Sleep -Milliseconds 500
        Check (Eval 'document.querySelectorAll("[data-student-row]").length===4') 'browser creates unreferenced test profile'
        Eval ('document.querySelector(''[data-student-code="'+$prefix+'D"]'').click();true') | Out-Null
        Check (Eval '(()=>{const d=document.querySelector("#delete-confirmation");return d.open&&d.querySelector("[data-delete-name]").textContent==="Hồ sơ <b>Xóa</b>"&&!d.querySelector("[data-delete-name] b")&&!d.querySelector("button[type=submit]").disabled})()') 'unreferenced identity rendered as text and delete enabled'
        Screenshot 'unreferenced-delete-dialog.png'
        Eval 'document.querySelector("#delete-student-form button").click();true' | Out-Null
        Start-Sleep -Milliseconds 500
        Check (Eval 'document.querySelectorAll("[data-student-row]").length===3&&document.body.textContent.includes("Đã xóa sinh viên chưa có dữ liệu điểm danh.")') 'explicit modal confirmation deletes only new test profile'
    }
    Navigate "/attendance.php?session_id=$($fixture.session)"
    Check (Eval '(()=>{const s=document.querySelector("select[name^=status]");s.value="late";s.dispatchEvent(new Event("change"));return s.dataset.status==="late" && s.classList.contains("attendance-select")})()') 'attendance status color follows selection'
    Navigate "/attendance.php?session_id=$($fixture.session)"
    Check (Eval 'document.querySelector("select[name^=status]").value==="unmarked"') 'changing selection does not auto-save'
    Check (Eval '!!document.querySelector("input[name=csrf]") && !!document.querySelector("input[name=version]") && !!document.querySelector("input[name=complete]")') 'CSRF version and completion fields preserved'
    foreach ($asset in @('ui.js','attendance.js')) {
        $jsSource=Eval ('fetch("/assets/'+$asset+'").then(r=>r.text())')
        $compiled=Cdp 'Runtime.compileScript' @{expression=$jsSource;sourceURL="/assets/$asset";persistScript=$false}
        Check (!$compiled.exceptionDetails) "JavaScript compiles: $asset (without weakening CSP)"
    }
    Check (Eval '(()=>{const sheets=[...document.styleSheets];return sheets.length===1&&sheets[0].cssRules.length>=1&&!!document.querySelector(".table-scroll").getAttribute("aria-label")})()') 'CSS loaded and scroll area labeled'
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
    Check ($script:browserErrors.Count -eq 0) 'no unhandled JavaScript exceptions'
    $report=@{passed=$script:passed;failed=0;viewports=$ViewportWidths;environment='isolated test stack';screenshots='automated QA only, not user acceptance evidence';checks=$script:checks}
    [IO.File]::WriteAllText((Join-Path $root $ResultPath),($report | ConvertTo-Json -Depth 8),(New-Object Text.UTF8Encoding($false)))
    Write-Output "RESULT: $script:passed browser assertions passed"
} finally {
    if ($socket -and $socket.State -eq 'Open') { try { Cdp 'Browser.close' | Out-Null } catch {} }
    if ($socket) { $socket.Dispose() }
    if ($process -and !$process.HasExited) { Stop-Process -Id $process.Id -ErrorAction SilentlyContinue }
    if ($fixture) { & docker @composeArgs exec -T web php /tmp/attendance-browser-fixture.php cleanup $prefix }
}
