<?php
declare(strict_types=1);
if (getenv('PHASE6_TEST') !== '1' || getenv('DB_HOST') !== 'db') throw new RuntimeException('Isolated test stack required');
require '/var/www/html/includes/db.php';
$checks=[];
function check(bool $ok,string $name): void { global $checks; $checks[]=['name'=>$name,'pass'=>$ok]; if (!$ok) throw new RuntimeException('FAIL: '.$name); }
function request(string $path, ?array $body=null, string &$cookie=''): array {
    $ctx=stream_context_create(['http'=>['method'=>$body===null?'GET':'POST','header'=>"Connection: close\r\nCookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>$body===null?'':http_build_query($body),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
    $html=file_get_contents('http://nginx:8080'.$path,false,$ctx);
    foreach($http_response_header as $h) if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$h,$m)) $cookie=$m[1];
    preg_match('/\s(\d{3})\s/',$http_response_header[0],$m);
    return [(int)$m[1],$html,$http_response_header];
}
function hidden(string $html,string $name): string { preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/', $html,$m); return html_entity_decode($m[1]??'',ENT_QUOTES,'UTF-8'); }
function form(int $id,string &$cookie): array { $r=request('/attendance.php?session_id='.$id,null,$cookie);return ['csrf'=>hidden($r[1],'csrf'),'action'=>'mark','session_id'=>$id,'version'=>hidden($r[1],'version'),'complete'=>'1']; }
$class=0;
try {
    $prefix='P6'.bin2hex(random_bytes(5));
    query('INSERT INTO classes(code,name) VALUES (?,?)',[$prefix,'Phase 6 isolated']);$class=(int)db()->lastInsertId();
    query('INSERT INTO students(class_id,code,name) VALUES (?,?,?)',[$class,$prefix.'A','<script>PRIVATE</script>']);$student=(int)db()->lastInsertId();
    $a='';$b='';$page=request('/attendance.php',null,$a);$csrf=hidden($page[1],'csrf');
    $create=['csrf'=>$csrf,'action'=>'create','class_id'=>$class,'title'=>'Buổi 2: Triển khai phần mềm','starts_at'=>'2096-01-01T08:00'];
    check(request('/attendance.php',$create,$a)[0]===302,'create isolated session');
    $session=(int)query('SELECT id FROM sessions WHERE class_id=?',[$class])->fetchColumn();
    $fa=form($session,$a);$fb=form($session,$b);
    check(request('/attendance.php',$fa+['status'=>[$student=>'present']],$a)[0]===302,'first user saves');
    $stale=request('/attendance.php',$fb+['status'=>[$student=>'absent']],$b);
    check($stale[0]===409 && str_contains($stale[1],'Mở danh sách'),'second user stale form HTTP 409 and reload guidance');
    check(query('SELECT status FROM attendance WHERE session_id=? AND student_id=?',[$session,$student])->fetchColumn()==='present','stale form does not overwrite');
    $tab1=form($session,$a);$tab2=form($session,$a);
    check(request('/attendance.php',$tab1+['status'=>[$student=>'present']],$a)[0]===302 && request('/attendance.php',$tab2+['status'=>[$student=>'absent']],$a)[0]===409,'two tabs sharing one browser session reject stale save');
    $fa=form($session,$a);$fb=form($session,$b);$sockets=[];
    foreach([[$a,$fa,'present'],[$b,$fb,'late']] as [$cookie,$data,$state]) {
        $body=http_build_query($data+['status'=>[$student=>$state]]);
        $socket=stream_socket_client('tcp://nginx:8080',$errno,$error,5);
        if(!$socket)throw new RuntimeException('Concurrent HTTP connection failed');
        fwrite($socket,"POST /attendance.php HTTP/1.1\r\nHost: nginx\r\nConnection: close\r\nCookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
        stream_set_timeout($socket,20);$sockets[]=$socket;
    }
    $codes=[];foreach($sockets as $socket){$response=stream_get_contents($socket);fclose($socket);preg_match('/HTTP\/1.1 (\d{3})/',$response,$match);$codes[]=(int)($match[1]??0);}
    $sorted=$codes;sort($sorted);
    check($sorted===[302,409],'simultaneous independent users: exactly one commit and one conflict');
    $winner=$codes[0]===302?'present':'late';
    check(query('SELECT status FROM attendance WHERE session_id=? AND student_id=?',[$session,$student])->fetchColumn()===$winner,'concurrent winner preserved in MySQL');
    $reset=form($session,$a);check(request('/attendance.php',$reset+['status'=>[$student=>'present']],$a)[0]===302,'fresh form saves after concurrent conflict');
    $old=form($session,$a);$new=form($session,$b);
    check(request('/attendance.php',$new+['status'=>[$student=>'late']],$b)[0]===302,'change to late');
    $new=form($session,$b);check(request('/attendance.php',$new+['status'=>[$student=>'present']],$b)[0]===302,'change back to present');
    check(request('/attendance.php',$old+['status'=>[$student=>'absent']],$a)[0]===409,'ABA transition still rejects old form');
    $new=form($session,$a);check(request('/attendance.php',$new+['status'=>[$student=>'present']],$a)[0]===302,'unchanged save accepted');
    check(request('/attendance.php',$new+['status'=>[$student=>'late']],$a)[0]===409,'replayed form rejected even after unchanged save');
    $new=form($session,$a);$invalid=$new;unset($invalid['complete']);
    check(request('/attendance.php',$invalid+['status'=>[$student=>'absent']],$a)[0]===422,'missing end sentinel rejected');
    $invalid=$new;$invalid['version']='forged';check(request('/attendance.php',$invalid+['status'=>[$student=>'absent']],$a)[0]===409,'forged version rejected');
    check(request('/attendance.php',$new+['status_payload'=>'{broken'], $a)[0]===422,'invalid JSON rejected');
    check(request('/attendance.php',$new+['status_payload'=>'{}'], $a)[0]===422,'incomplete JSON rejected');
    check(request('/attendance.php',$new+['status_payload'=>json_encode([$student=>'invalid'])],$a)[0]===422,'invalid state rejected');
    $duplicate=$create;$duplicate['starts_at']='2096-01-01T08:05';$duplicate['title']='Buổi 2 : Triển khai phần mềm';
    $warning=request('/attendance.php',$duplicate,$a);
    check($warning[0]===409 && str_contains($warning[1],'ID #'.$session),'similar title warning with existing ID');
    check((int)query('SELECT COUNT(*) FROM sessions WHERE class_id=?',[$class])->fetchColumn()===1,'warning creates no extra session');
    $confirmed=$duplicate+['confirm_similar'=>'1','similar_token'=>hidden($warning[1],'similar_token')];
    check(request('/attendance.php',$confirmed,$a)[0]===302,'explicit confirmation creates separate session');
    check((int)query('SELECT COUNT(*) FROM sessions WHERE class_id=?',[$class])->fetchColumn()===2,'both sessions retained');
    $history=request('/history.php?class_id='.$class,null,$a)[1];
    check(str_contains($history,'không phải nhật ký') && str_contains($history,'Bắt đầu buổi học') && str_contains($history,'ID #'.$session),'history semantics and session ID');
    check(str_contains($history,'&lt;script&gt;PRIVATE&lt;/script&gt;') && !str_contains($history,'<script>PRIVATE'),'stored XSS escaped');
    check(str_contains(request('/statistics.php',null,$a)[1],'100%'),'unmarked excluded from attendance denominator');
    check(str_contains(request('/attendance.php?session_id='.$session,null,$a)[1],'ID #'.$session),'attendance session ID');
    // More rows than the default PHP max_input_vars. Data stays in the test DB.
    db()->beginTransaction();
    for($i=0;$i<1100;$i++) {query('INSERT INTO students(class_id,code,name) VALUES (?,?,?)',[$class,$prefix.'L'.$i,'Large roster']);$sid=(int)db()->lastInsertId();query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$session,$sid]);}
    db()->commit();
    $ids=query('SELECT student_id FROM attendance WHERE session_id=?',[$session])->fetchAll(PDO::FETCH_COLUMN);
    $statuses=array_fill_keys($ids,'late');$new=form($session,$a);
    $largePage=request('/attendance.php?session_id='.$session,null,$a)[1];
    check(str_contains($largePage,'disabled') && str_contains($largePage,'/assets/attendance.js'),'large roster fallback guarded and script present');
    check(request('/attendance.php',$new+['status'=>$statuses],$a)[0]===422,'PHP-truncated traditional form rejected');
    check((int)query("SELECT COUNT(*) FROM attendance WHERE session_id=? AND status='late'",[$session])->fetchColumn()===0,'truncated form changes no rows');
    check(request('/attendance.php',$new+['status_payload'=>json_encode($statuses)],$a)[0]===302,'1101-student JSON payload saved');
    check((int)query("SELECT COUNT(*) FROM attendance WHERE session_id=? AND status='late'",[$session])->fetchColumn()===1101,'all 1101 rows saved');
    check((int)query('SELECT COUNT(*) FROM attendance WHERE session_id=?',[$session])->fetchColumn()===1101,'save creates no duplicate attendance');
    $new=form($session,$a);$statuses=array_fill_keys($ids,'present');$statuses[$ids[count($ids)-1]]='invalid';
    check(request('/attendance.php',$new+['status_payload'=>json_encode($statuses)],$a)[0]===422,'large invalid payload rolled back');
    check((int)query("SELECT COUNT(*) FROM attendance WHERE session_id=? AND status='late'",[$session])->fetchColumn()===1101,'atomic rollback retains all states');
    // Loss of ephemeral revision state must invalidate forms, not allow replay.
    $new=form($session,$a);unlink(sys_get_temp_dir().'/attendance-versions/'.$session);
    check(request('/attendance.php',$new+['status_payload'=>json_encode(array_fill_keys($ids,'present'))],$a)[0]===409,'lost revision file fails closed');
    $notFound=request('/phase6-missing',null,$a);
    check($notFound[0]===404,'Nginx preserves HTTP 404');
    $headers=implode("\n",$notFound[2]);
    check(str_contains($headers,'X-Frame-Options: DENY') && str_contains($headers,'Content-Security-Policy:') && str_contains($headers,'X-Request-ID:'),'Nginx error response security and correlation headers');
    check(request('/attendance.php',['csrf'=>'wrong','action'=>'mark'],$a)[0]===403,'Nginx preserves HTTP 403');
    check(request('/attendance.php',['csrf'=>'wrong','oversized'=>str_repeat('x',1048577)],$a)[0]===413,'Nginx rejects body over 1 MiB before PHP');
    echo json_encode(['passed'=>count($checks),'failed'=>0,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
} finally {
    if(db()->inTransaction())db()->rollBack();
    if($class){query('DELETE FROM sessions WHERE class_id=?',[$class]);query('DELETE FROM students WHERE class_id=?',[$class]);query('DELETE FROM classes WHERE id=?',[$class]);}
}
