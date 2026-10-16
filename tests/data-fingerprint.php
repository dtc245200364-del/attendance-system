<?php
require '/var/www/html/includes/db.php';
// No row contents or credentials are emitted. Auto-increment counters are not data.
db()->beginTransaction();
$result=[];
foreach (['classes'=>'id','students'=>'id','sessions'=>'id','attendance'=>'session_id,student_id'] as $table=>$order) {
    $rows=query("SELECT * FROM $table ORDER BY $order")->fetchAll();
    $result[$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
}
db()->commit();
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
