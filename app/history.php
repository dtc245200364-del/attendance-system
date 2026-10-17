<?php
require __DIR__ . '/includes/bootstrap.php';
$where=[]; $params=[]; $error='';
$selectedClass=is_string($_GET['class_id'] ?? null) && ctype_digit($_GET['class_id']) ? (int)$_GET['class_id'] : null;
try {
    foreach (['class_id'=>'se.class_id','student_id'=>'s.id'] as $key=>$column) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') { $where[]="$column=?"; $params[]=id($_GET[$key]); }
    }
    foreach (['from'=>'>=','to'=>'<='] as $key=>$op) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $v=$_GET[$key]; $dt=is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d',$v) : false;
            if (!$dt || $dt->format('Y-m-d')!==$v) throw new InvalidArgumentException('Ngày lọc không hợp lệ.');
            $where[]="se.starts_at $op ?"; $params[]=$v . ($key==='from' ? ' 00:00:00' : ' 23:59:59');
        }
    }
    if (!empty($_GET['from']) && !empty($_GET['to']) && $_GET['from']>$_GET['to']) throw new InvalidArgumentException('Ngày bắt đầu phải trước ngày kết thúc.');
} catch (InvalidArgumentException $ex) { $error=$ex->getMessage(); http_response_code(422); }
$page=filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]) ?: 1;
$base=' FROM attendance a JOIN students s ON s.id=a.student_id JOIN sessions se ON se.id=a.session_id JOIN classes c ON c.id=se.class_id' . ($where ? ' WHERE '.implode(' AND ',$where) : '');
$total=$error ? 0 : (int)query('SELECT COUNT(*)'.$base,$params)->fetchColumn();
$pages=max(1,(int)ceil($total/50)); $page=min($page,$pages); $offset=($page-1)*50;
$rows=$error ? [] : query('SELECT a.*,s.code,s.name,se.title,se.starts_at,c.code AS class_code'.$base." ORDER BY se.starts_at DESC,se.id DESC,s.code LIMIT 50 OFFSET $offset",$params)->fetchAll();
page_start('Lịch sử điểm danh','history.php');
?>
<section class="panel"><div class="panel-heading"><div><h2>Tra cứu trạng thái điểm danh theo buổi</h2><p>Thu hẹp kết quả theo lớp, sinh viên hoặc khoảng ngày.</p></div><span class="record-id"><?= ui_icon('search') ?></span></div><p class="notice">Hiển thị trạng thái hiện tại của từng sinh viên trong từng buổi, không phải nhật ký các lần chỉnh sửa. Thời gian bên dưới là thời gian bắt đầu buổi học.</p><form method="get" class="form-grid"><label>Lớp<select name="class_id"><option value="">Tất cả lớp</option><?php class_options($selectedClass); ?></select></label><label>Sinh viên<select name="student_id"><option value="">Tất cả sinh viên</option><?php foreach (query('SELECT id,code,name FROM students ORDER BY code') as $s): ?><option value="<?= e($s['id']) ?>" <?= (string)$s['id']===($_GET['student_id'] ?? '') ? 'selected' : '' ?>><?= e($s['code'].' — '.$s['name']) ?></option><?php endforeach; ?></select></label><label>Từ ngày<input type="date" name="from" value="<?= e(is_string($_GET['from'] ?? '') ? ($_GET['from'] ?? '') : '') ?>"></label><label>Đến ngày<input type="date" name="to" value="<?= e(is_string($_GET['to'] ?? '') ? ($_GET['to'] ?? '') : '') ?>"></label><button><?= ui_icon('search') ?> Lọc lịch sử</button><a class="button secondary" href="/history.php">Bỏ lọc</a></form></section>
<?php if ($error) echo '<p class="alert error">'.e($error).'</p>'; ?>
<section class="panel"><div class="panel-heading"><div><h2><?= e($total) ?> lượt ghi nhận</h2><p>Trạng thái hiện tại của sinh viên trong từng buổi học.</p></div><span class="count-pill">50 lượt / trang</span></div><div class="table-scroll"><table><thead><tr><th>Sinh viên</th><th>Lớp / Buổi</th><th>Bắt đầu buổi học</th><th>Trạng thái</th></tr></thead><tbody><?php if (!$rows) echo '<tr><td colspan="4" class="empty">Không có dữ liệu phù hợp.</td></tr>'; foreach ($rows as $r): ?><tr><td><span class="student-identity"><?= ui_avatar($r['name']) ?><strong><?= e($r['name']) ?></strong></span><br><small><?= e($r['code']) ?></small></td><td><?= e($r['class_code']) ?> · <a href="/attendance.php?session_id=<?= e($r['session_id']) ?>">ID #<?= e($r['session_id']) ?></a><br><small><?= e($r['title']) ?></small></td><td><?= e(date('d/m/Y H:i',strtotime($r['starts_at']))) ?></td><td><span class="badge <?= e($r['status']) ?>"><?= status_label($r['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><p class="table-hint">Cuộn ngang để xem đầy đủ thời gian và trạng thái.</p><div class="pagination"><?php if ($page>1): ?><a href="?<?= e(http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>">← Trước</a><?php endif; ?><span>Trang <?= $page ?> / <?= $pages ?></span><?php if ($page<$pages): ?><a href="?<?= e(http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>">Sau →</a><?php endif; ?></div></section><?php page_end(); ?>
