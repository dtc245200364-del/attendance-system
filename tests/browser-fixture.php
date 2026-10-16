<?php
require '/var/www/html/includes/db.php';
$mode=$argv[1] ?? ''; $prefix=$argv[2] ?? '';
if (!preg_match('/^B[a-f0-9]{24}$/D',$prefix)) throw new RuntimeException('Invalid fixture prefix');
if ($mode==='create') {
    db()->beginTransaction();
    query('INSERT INTO classes(code,name) VALUES (?,?)',[$prefix,'Lớp kiểm thử nghiệm thu']); $class=(int)db()->lastInsertId();
    $students=[];
    for ($i=1;$i<=3;$i++) { query('INSERT INTO students(class_id,code,name) VALUES (?,?,?)',[$class,$prefix.$i,'Sinh viên kiểm thử '.$i]); $students[]=(int)db()->lastInsertId(); }
    query('INSERT INTO sessions(class_id,title,starts_at) VALUES (?,?,?)',[$class,'Buổi kiểm thử giao diện','2097-10-16 08:00:00']); $session=(int)db()->lastInsertId();
    foreach ($students as $s) query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$session,$s]);
    db()->commit(); echo json_encode(compact('prefix','class','students','session'));
} elseif ($mode==='cleanup') {
    db()->beginTransaction();
    $class=query('SELECT id FROM classes WHERE code=?',[$prefix])->fetchColumn();
    if ($class) {
        query('DELETE FROM sessions WHERE class_id=?',[$class]);
        query('DELETE FROM students WHERE class_id=?',[$class]);
        query('DELETE FROM classes WHERE id=?',[$class]);
    }
    db()->commit(); echo 'Browser fixtures cleaned.';
} else throw new RuntimeException('Invalid fixture mode');
