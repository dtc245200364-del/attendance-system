<?php
declare(strict_types=1);
// Run inside the new web container only. Fixtures have a random unique prefix.
require '/var/www/html/includes/db.php';
$base=getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8080'; $cookie=''; $token=''; $passed=0;
$prefix='T'.bin2hex(random_bytes(5)); $classIds=[]; $studentIds=[];
function check(bool $ok,string $label): void { global $passed; if (!$ok) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label."\n"; $passed++; }
function http(string $path,?array $data=null): array {
    global $base,$cookie;
    $headers="Connection: close\r\n".($cookie ? "Cookie: $cookie\r\n" : '');
    if ($data!==null) $headers.="Content-Type: application/x-www-form-urlencoded\r\n";
    $ctx=stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>$headers,'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
    $body=file_get_contents($base.$path,false,$ctx); $response=$http_response_header ?? [];
    foreach ($response as $h) if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$h,$m)) $cookie=$m[1];
    preg_match('/\s(\d{3})\s/',$response[0] ?? '',$m);
    return [(int)($m[1]??0),(string)$body,implode("\n",$response)];
}
function post(string $path,array $data): array { global $token; return http($path,['csrf'=>$token]+$data); }
function expect_constraint(callable $f,string $label): void { try {$f();} catch (PDOException $e) { check($e->getCode()==='23000',$label); return; } throw new RuntimeException('Constraint missing: '.$label); }
function expect_consistency(callable $f,string $label): void { try {$f();} catch (PDOException $e) { check($e->getCode()==='45000',$label); return; } throw new RuntimeException('Consistency missing: '.$label); }
try {
    foreach (['/','/classes.php','/students.php','/attendance.php','/history.php','/statistics.php','/assets/style.css','/assets/base.css'] as $url) check(http($url)[0]===200,'HTTP '.$url);
    [$status,$html,$headers]=http('/classes.php'); preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m); $token=$m[1]??'';
    check(strlen($token)===64,'CSRF token');
    check(str_contains($headers,'Content-Security-Policy:') && str_contains($headers,'X-Content-Type-Options: nosniff'),'security headers');
    check(http('/classes.php',['action'=>'create','code'=>$prefix,'name'=>'bad'])[0]===403,'reject missing CSRF');
    check(http('/classes.php',['csrf'=>'wrong','action'=>'create','code'=>$prefix,'name'=>'bad'])[0]===403,'reject wrong CSRF');
    check(http('/students.php',['csrf'=>['bad'],'action'=>'archive','id'=>1])[0]===403,'reject array CSRF');
    foreach (['classes.php'=>['action'=>'update','code'=>'none','name'=>'Missing'], 'students.php'=>['action'=>'archive']] as $path=>$data) check(post('/'.$path,$data+['id'=>4294967295])[0]===422,'reject nonexistent record '.$path);
    check(post('/classes.php',['action'=>'create','code'=>$prefix,'name'=>'   '])[0]===422,'reject blank name');
    check(post('/classes.php',['action'=>'create','code'=>$prefix,'name'=>str_repeat('a',121)])[0]===422,'reject long name');
    check(post('/classes.php',['action'=>'create','code'=>"x'); DROP TABLE classes;--",'name'=>'bad'])[0]===422,'reject invalid class code');
    $xss='<script>alert(1)</script>';
    check(post('/classes.php',['action'=>'create','code'=>$prefix,'name'=>$xss])[0]===302,'create class');
    $class=(int)query('SELECT id FROM classes WHERE code=?',[$prefix])->fetchColumn(); $classIds[]=$class;
    $body=http('/classes.php')[1]; check(str_contains($body,htmlspecialchars($xss,ENT_QUOTES)) && !str_contains($body,$xss),'escape stored XSS');
    check(post('/classes.php',['action'=>'create','code'=>$prefix,'name'=>'duplicate'])[0]===409,'unique class code');
    check(post('/classes.php',['action'=>'update','id'=>$class,'code'=>$prefix,'name'=>'Lớp kiểm thử'])[0]===302,'edit class');
    check(post('/students.php',['action'=>'create','code'=>$prefix.'0','name'=>'Test','class_id'=>$class,'email'=>'bad'])[0]===422,'invalid email');
    for ($i=1;$i<=3;$i++) {
        check(post('/students.php',['action'=>'create','code'=>$prefix.$i,'name'=>$i===1 ? "O'Neil <img src=x onerror=alert(1)>" : 'Sinh viên '.$i,'class_id'=>$class,'email'=>''])[0]===302,'create student '.$i);
        $studentIds[]=(int)query('SELECT id FROM students WHERE code=?',[$prefix.$i])->fetchColumn();
    }
    check(post('/students.php',['action'=>'create','code'=>$prefix.'1','name'=>'Duplicate','class_id'=>$class,'email'=>''])[0]===409,'unique student code');
    check(post('/classes.php',['action'=>'delete','id'=>$class])[0]===409,'cannot delete class with students');
    check(post('/attendance.php',['action'=>'create','class_id'=>$class,'title'=>'Bad date','starts_at'=>'2026-02-30T10:00'])[0]===422,'reject invalid date');
    $when='2099-01-01T10:00';
    check(post('/attendance.php',['action'=>'create','class_id'=>$class,'title'=>'Kiểm thử','starts_at'=>$when])[0]===302,'create session');
    $session=(int)query('SELECT id FROM sessions WHERE class_id=?',[$class])->fetchColumn();
    check((int)query('SELECT COUNT(*) FROM attendance WHERE session_id=? AND status=?',[$session,'unmarked'])->fetchColumn()===3,'snapshot roster unmarked');
    check(post('/attendance.php',['action'=>'create','class_id'=>$class,'title'=>'Duplicate','starts_at'=>$when])[0]===409,'unique class and start');
    expect_constraint(fn()=>query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$session,$studentIds[0]]),'DB duplicate attendance constraint');
    expect_constraint(fn()=>query('INSERT INTO students(class_id,code,name) VALUES (?,?,?)',[4294967295,$prefix.'FK','Bad']),'DB foreign key');
    $statuses=array_combine($studentIds,['present','absent','late']);
    check(post('/attendance.php',['action'=>'mark','session_id'=>$session,'status'=>$statuses])[0]===302,'save three attendance states');
    check(post('/attendance.php',['action'=>'mark','session_id'=>$session,'status'=>$statuses])[0]===302,'resave attendance idempotently');
    check((int)query('SELECT COUNT(*) FROM attendance WHERE session_id=?',[$session])->fetchColumn()===3,'resave creates no duplicates');
    $invalid=$statuses; $invalid[$studentIds[1]]='invalid';
    check(post('/attendance.php',['action'=>'mark','session_id'=>$session,'status'=>$invalid])[0]===422,'reject invalid status atomically');
    check(query('SELECT status FROM attendance WHERE session_id=? AND student_id=?',[$session,$studentIds[1]])->fetchColumn()==='absent','rollback keeps previous status');
    check(post('/attendance.php',['action'=>'mark','session_id'=>$session,'status'=>[$studentIds[0]=>'present']])[0]===422,'reject incomplete roster');
    check(post('/students.php',['action'=>'archive','id'=>$studentIds[0]])[0]===302,'archive student');
    check((int)query('SELECT COUNT(*) FROM attendance WHERE student_id=?',[$studentIds[0]])->fetchColumn()===1,'archive preserves history');
    check(post('/attendance.php',['action'=>'create','class_id'=>$class,'title'=>'Buổi 2','starts_at'=>'2099-01-02T10:00'])[0]===302,'create second session');
    check((int)query('SELECT COUNT(*) FROM attendance a JOIN sessions se ON se.id=a.session_id WHERE se.class_id=?',[$class])->fetchColumn()===5,'archived student excluded from new roster');
    check(post('/students.php',['action'=>'restore','id'=>$studentIds[0]])[0]===302,'restore student');
    check(post('/classes.php',['action'=>'create','code'=>$prefix.'B','name'=>'Lớp B'])[0]===302,'create second class');
    $second=(int)query('SELECT id FROM classes WHERE code=?',[$prefix.'B'])->fetchColumn(); $classIds[]=$second;
    query('INSERT INTO students(class_id,code,name) VALUES (?,?,?)',[$second,$prefix.'OTHER','Other class']);
    $other=(int)db()->lastInsertId(); $studentIds[]=$other;
    expect_consistency(fn()=>query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$session,$other]),'DB rejects cross-class insert');
    expect_consistency(fn()=>query('UPDATE attendance SET student_id=? WHERE session_id=? AND student_id=?',[$other,$session,$studentIds[0]]),'DB rejects cross-class attendance update');
    expect_consistency(fn()=>query('UPDATE students SET class_id=? WHERE id=?',[$second,$studentIds[0]]),'DB rejects transferring student with history');
    expect_consistency(fn()=>query('UPDATE sessions SET class_id=? WHERE id=?',[$second,$session]),'DB rejects transferring populated session');
    $forged=$statuses; unset($forged[$studentIds[0]]); $forged[$other]='present';
    check(post('/attendance.php',['action'=>'mark','session_id'=>$session,'status'=>$forged])[0]===422,'HTTP rejects foreign student roster');
    query('DELETE FROM students WHERE id=?',[$other]);
    check(post('/students.php',['action'=>'update','id'=>$studentIds[0],'code'=>$prefix.'1','name'=>'Edited','class_id'=>$second,'email'=>''])[0]===422,'block class transfer with history');
    check(post('/students.php',['action'=>'update','id'=>$studentIds[0],'code'=>$prefix.'1','name'=>'Tên đã sửa','class_id'=>$class,'email'=>'test@example.com'])[0]===302,'edit student');
    check(post('/attendance.php',['action'=>'create','class_id'=>$second,'title'=>'Empty','starts_at'=>$when])[0]===422,'reject session with empty roster');
    [$code,$body]=http('/history.php?class_id='.$class.'&student_id='.$studentIds[0].'&from=2099-01-01&to=2099-01-01');
    check($code===200 && str_contains($body,'1 lượt ghi nhận'),'history filters');
    check(http('/history.php?class_id=1%20OR%201=1')[0]===422,'reject SQL injection filter');
    check(http('/history.php?from=2099-02-30')[0]===422,'reject invalid date filter');
    check(http('/history.php?class_id[]=1')[0]===422,'reject array history filter');
    check(http('/history.php?from=2099-02-01&to=2099-01-01')[0]===422,'reject reversed date range');
    check(http('/attendance.php?session_id[]=1')[0]===422,'reject array identifier');
    check(str_contains(http('/statistics.php')[1],'100%'),'statistics computed from marked records');
    $stats=http('/statistics.php')[1];
    foreach ([$prefix.'1'=>'100%', $prefix.'2'=>'0%', $prefix.'3'=>'100%'] as $studentCode=>$rate) {
        preg_match('/<tr>(?:(?!<\/tr>).)*'.preg_quote($studentCode,'/').'(?:(?!<\/tr>).)*<\/tr>/s',$stats,$match);
        check(isset($match[0]) && str_contains($match[0],'>'.$rate.'<'),'exact student rate '.$rate);
    }
    $history=http('/history.php?class_id='.$class)[1]; check(str_contains($history,'5 lượt ghi nhận'),'history total includes pending');
    $empty=http('/history.php?class_id='.$second)[1]; check(str_contains($empty,'Không có dữ liệu phù hợp.'),'empty history state');
    $dbClasses=(int)query('SELECT COUNT(*) FROM classes')->fetchColumn();
    post('/classes.php',['action'=>'create','code'=>$prefix.'SQL','name'=>"x'); DROP TABLE classes; --"]);
    $sqlClass=(int)query('SELECT id FROM classes WHERE code=?',[$prefix.'SQL'])->fetchColumn(); $classIds[]=$sqlClass;
    check((int)query('SELECT COUNT(*) FROM classes')->fetchColumn()===$dbClasses+1,'SQL payload stored as data');
    check(str_contains(http('/students.php')[1],'&lt;img')===false,'edited student no stale unsafe HTML');
    // More than 50 rows: exercise actual history pagination without affecting other data.
    for ($i=0;$i<17;$i++) {
        query('INSERT INTO sessions(class_id,title,starts_at) VALUES (?,?,?)',[$class,'Pagination '.$i,'2098-01-01 '.sprintf('%02d:00:00',$i)]);
        $extra=(int)db()->lastInsertId();
        foreach (array_slice($studentIds,0,3) as $s) query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$extra,$s]);
    }
    $history=http('/history.php?class_id='.$class)[1]; $page2=http('/history.php?class_id='.$class.'&page=2')[1];
    check(str_contains($history,'56 lượt ghi nhận') && str_contains($history,'Trang 1 / 2'),'history pagination first page');
    check(str_contains($page2,'Trang 2 / 2') && substr_count($page2,'<tr>')===7,'history pagination second page six records');
    try { query('CREATE TABLE forbidden_test(id INT)'); throw new RuntimeException('App can create tables'); } catch (PDOException $e) { check($e->getCode()==='42000','DB app cannot create tables'); }
    check(post('/classes.php',['action'=>'delete','id'=>$second])[0]===302,'delete empty class');
    echo "RESULT: $passed assertions passed\n";
} finally {
    // Delete only this run's fixtures, never truncate or touch existing rows.
    foreach ($classIds as $c) query('DELETE FROM sessions WHERE class_id=?',[$c]);
    foreach ($studentIds as $s) query('DELETE FROM students WHERE id=?',[$s]);
    foreach ($classIds as $c) query('DELETE FROM classes WHERE id=?',[$c]);
    echo "Test fixtures cleaned.\n";
}
