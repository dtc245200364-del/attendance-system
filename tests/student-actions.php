<?php
declare(strict_types=1);
if (getenv('PHASE6_TEST') !== '1' || getenv('DB_HOST') !== 'db') throw new RuntimeException('Isolated test project required');
require '/var/www/html/includes/db.php';
$checks=[];$cookie='';$class=0;
function check(bool $ok,string $name): void {global $checks;$checks[]=['name'=>$name,'pass'=>$ok];if(!$ok)throw new RuntimeException('FAIL: '.$name);}
function request(string $path,?array $data=null): array {
    global $cookie;
    $ctx=stream_context_create(['http'=>['method'=>$data===null?'GET':'POST','header'=>"Connection: close\r\nCookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>$data===null?'':http_build_query($data),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
    $html=file_get_contents('http://nginx:8080'.$path,false,$ctx);
    foreach($http_response_header as $h)if(preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$h,$m))$cookie=$m[1];
    preg_match('/\s(\d{3})\s/',$http_response_header[0],$m);return [(int)$m[1],$html];
}
function hidden(string $html,string $name): string {preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$html,$m);return html_entity_decode($m[1]??'',ENT_QUOTES,'UTF-8');}
function delete_form(int $id): array {global $csrf; $r=request('/students.php?delete='.$id);return ['csrf'=>$csrf,'action'=>'delete','id'=>$id,'confirm_delete'=>'1','confirmation'=>hidden($r[1],'confirmation')];}
function stats_row(string $code): string {$html=request('/statistics.php')[1];preg_match('/<tr>(?:(?!<\/tr>).)*'.preg_quote($code,'/').'(?:(?!<\/tr>).)*<\/tr>/s',$html,$m);return $m[0]??'';}
try {
    $prefix='SA'.bin2hex(random_bytes(5));query('INSERT INTO classes(code,name) VALUES (?,?)',[$prefix,'Student actions isolated']);$class=(int)db()->lastInsertId();
    $csrf=hidden(request('/students.php')[1],'csrf');
    $student=['csrf'=>$csrf,'action'=>'create','code'=>$prefix.'A','name'=>'Sinh viên "An" <img src=x onerror=alert(1)>','class_id'=>$class,'email'=>''];
    check(request('/students.php',$student)[0]===302,'create student through HTTP');
    $id=(int)query('SELECT id FROM students WHERE code=?',[$student['code']])->fetchColumn();
    check(str_contains(request('/students.php')[1],'Đã thêm sinh viên.'),'specific create success message');
    $edit=$student;$edit['action']='update';$edit['id']=$id;$edit['email']='an@example.com';
    check(request('/students.php',$edit)[0]===302,'edit student through HTTP');
    check(query('SELECT email FROM students WHERE id=?',[$id])->fetchColumn()==='an@example.com','edited data persisted');
    $view=request('/students.php?edit='.$id)[1];check(str_contains($view,'value="an@example.com"') && str_contains($view,'id="student-'.$id.'" open'),'edit link opens current populated form without JavaScript');
    check(!str_contains($view,'<img src=x onerror=alert(1)>') && str_contains($view,'&lt;img'),'identity and attributes escape stored XSS');
    $before=query('SELECT * FROM students WHERE id=?',[$id])->fetch();$confirm=request('/students.php?delete='.$id);
    check($confirm[0]===200 && str_contains($confirm[1],'Xác nhận xóa sinh viên') && str_contains($confirm[1],$student['code']),'GET confirmation shows identity');
    check(query('SELECT * FROM students WHERE id=?',[$id])->fetch()===$before,'GET confirmation never deletes or updates');
    $form=delete_form($id);$bad=$form;$bad['csrf']='wrong';check(request('/students.php',$bad)[0]===403,'delete rejects wrong CSRF');
    $bad=$form;unset($bad['confirm_delete']);check(request('/students.php',$bad)[0]===409,'delete requires explicit confirmation');
    $bad=$form;$bad['confirmation']='forged';check(request('/students.php',$bad)[0]===409,'delete rejects forged identity confirmation');
    $renamed=$edit;$renamed['name']='Tên hồ sơ đã thay đổi';check(request('/students.php',$renamed)[0]===302,'rename after opening confirmation');
    check(request('/students.php',$form)[0]===409,'stale identity confirmation cannot delete renamed student');
    $form=delete_form($id);check(request('/students.php',$form)[0]===302,'delete student without attendance');
    check(!query('SELECT 1 FROM students WHERE id=?',[$id])->fetch(),'unreferenced student permanently removed');
    check(str_contains(request('/students.php')[1],'Đã xóa sinh viên chưa có dữ liệu điểm danh.'),'specific delete success message');
    check(request('/students.php',$form)[0]===422,'repeated delete handles nonexistent student');
    check(request('/students.php?delete[]=1')[0]===422 && request('/students.php?edit=1%20OR%201=1')[0]===422,'invalid GET identifiers rejected');
    $student['code']=$prefix.'B';$student['name']='Sinh viên có lịch sử';check(request('/students.php',$student)[0]===302,'create protected test student');
    $protected=(int)query('SELECT id FROM students WHERE code=?',[$student['code']])->fetchColumn();
    query('INSERT INTO sessions(class_id,title,starts_at) VALUES (?,?,?)',[$class,'Isolated student actions','2095-01-01 08:00:00']);$session=(int)db()->lastInsertId();
    query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$session,$protected]);
    foreach(['unmarked','present','absent','late'] as $state) {
        query('UPDATE attendance SET status=? WHERE session_id=? AND student_id=?',[$state,$session,$protected]);
        $snapshot=query('SELECT * FROM attendance WHERE student_id=?',[$protected])->fetchAll();
        $blocked=request('/students.php',delete_form($protected));
        check($blocked[0]===409 && str_contains($blocked[1],'Ngừng theo dõi'),'reject delete with attendance: '.$state);
        check(query('SELECT * FROM attendance WHERE student_id=?',[$protected])->fetchAll()===$snapshot && (bool)query('SELECT 1 FROM students WHERE id=?',[$protected])->fetch(),'preserve student/history: '.$state);
    }
    $snapshot=query('SELECT * FROM attendance WHERE student_id=?',[$protected])->fetchAll();$stats=stats_row($student['code']);
    foreach(['archive'=>0,'restore'=>1] as $action=>$active) {
        check(request('/students.php',['csrf'=>$csrf,'action'=>$action,'id'=>$protected])[0]===302,'HTTP '.$action);
        check((int)query('SELECT active FROM students WHERE id=?',[$protected])->fetchColumn()===$active,'active flag '.$action);
        check(query('SELECT * FROM attendance WHERE student_id=?',[$protected])->fetchAll()===$snapshot && stats_row($student['code'])===$stats,'attendance and statistics unchanged after '.$action);
        check(str_contains(request('/history.php?class_id='.$class)[1],'1 lượt ghi nhận'),'history still visible after '.$action);
    }
    try {query('DELETE FROM students WHERE id=?',[$protected]);throw new RuntimeException('Missing restrict FK');}
    catch(PDOException $ex){check((int)($ex->errorInfo[1]??0)===1451,'MySQL FK RESTRICT independently protects history');}
    $student['code']=$prefix.'C';$student['name']='Archived empty profile';check(request('/students.php',$student)[0]===302,'create empty profile for archive/delete');
    $empty=(int)query('SELECT id FROM students WHERE code=?',[$student['code']])->fetchColumn();request('/students.php',['csrf'=>$csrf,'action'=>'archive','id'=>$empty]);
    check(request('/students.php',delete_form($empty))[0]===302,'archived profile without attendance can be deleted');
    $protectedView=request('/students.php?delete='.$protected)[1];check(str_contains($protectedView,'Hãy dùng Ngừng theo dõi') && preg_match('/<button class="danger" type="submit" disabled>/',$protectedView)===1,'protected confirmation disables permanent delete');
    echo json_encode(['passed'=>count($checks),'failed'=>0,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
} finally {
    if(db()->inTransaction())db()->rollBack();
    if($class){query('DELETE FROM sessions WHERE class_id=?',[$class]);query('DELETE FROM students WHERE class_id=?',[$class]);query('DELETE FROM classes WHERE id=?',[$class]);}
}
