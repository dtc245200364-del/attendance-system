<?php
require __DIR__ . '/includes/bootstrap.php';
post_action(function () {
    $action=field('action',20);
    if ($action === 'create') {
        $class=id($_POST['class_id'] ?? ''); $title=field('title',150); $date=field('starts_at',16);
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date);
        if (!$dt || $dt->format('Y-m-d\TH:i') !== $date || (int)$dt->format('Y') < 2000 || (int)$dt->format('Y') > 2100) throw new InvalidArgumentException('Ngày giờ không hợp lệ (năm 2000–2100).');
        db()->beginTransaction();
        $students=query('SELECT id FROM students WHERE class_id=? AND active=1 FOR UPDATE',[$class])->fetchAll();
        if (!$students) { db()->rollBack(); throw new InvalidArgumentException('Lớp phải có sinh viên đang học trước khi tạo buổi.'); }
        query('INSERT INTO sessions(class_id,title,starts_at) VALUES (?,?,?)',[$class,$title,$dt->format('Y-m-d H:i:s')]); $session=(int)db()->lastInsertId();
        foreach ($students as $s) query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$session,$s['id']]);
        db()->commit();
    } elseif ($action === 'mark') {
        $session=id($_POST['session_id'] ?? ''); $statuses=$_POST['status'] ?? null;
        if (!is_array($statuses)) throw new InvalidArgumentException('Thiếu danh sách điểm danh.');
        db()->beginTransaction();
        $roster=query('SELECT student_id FROM attendance WHERE session_id=? FOR UPDATE',[$session])->fetchAll(PDO::FETCH_COLUMN);
        if (!$roster || count($roster)!==count($statuses)) { db()->rollBack(); throw new InvalidArgumentException('Danh sách điểm danh không hợp lệ.'); }
        foreach ($roster as $student) {
            $status=$statuses[$student] ?? null;
            if (!is_string($status) || !in_array($status,['unmarked','present','absent','late'],true)) { db()->rollBack(); throw new InvalidArgumentException('Trạng thái điểm danh không hợp lệ.'); }
            query('UPDATE attendance SET status=? WHERE session_id=? AND student_id=?',[$status,$session,$student]);
        }
        db()->commit();
    } else throw new InvalidArgumentException('Thao tác không hợp lệ.');
});
$session=null; $sessionError='';
if (isset($_GET['session_id'])) {
    try { $session=id($_GET['session_id']); }
    catch (InvalidArgumentException $ex) { http_response_code(422); $sessionError=$ex->getMessage(); }
}
page_start('Điểm danh','attendance.php');
?>
<section class="panel"><h2>Tạo buổi điểm danh</h2><form method="post" class="form-grid"><?= csrf() ?><input type="hidden" name="action" value="create"><label>Lớp học<select name="class_id" required><option value="">Chọn lớp</option><?php class_options(); ?></select></label><label>Nội dung buổi học<input name="title" required maxlength="150" placeholder="Buổi 1 — Triển khai hệ thống"></label><label>Ngày và giờ<input name="starts_at" type="datetime-local" required value="<?= date('Y-m-d\TH:i') ?>"></label><button>Tạo buổi</button></form></section>
<section class="panel"><h2>Chọn buổi để điểm danh</h2><form method="get" class="form-row"><label>Buổi học<select name="session_id" required><option value="">Chọn buổi học</option><?php $sessions=query('SELECT s.*,c.code FROM sessions s JOIN classes c ON c.id=s.class_id ORDER BY s.starts_at DESC,s.id DESC')->fetchAll(); foreach ($sessions as $s): ?><option value="<?= e($s['id']) ?>" <?= (string)($s['id']) === ($_GET['session_id'] ?? '') ? 'selected' : '' ?>><?= e($s['code'].' · '.$s['title'].' · '.date('d/m/Y H:i',strtotime($s['starts_at']))) ?></option><?php endforeach; ?></select></label><button class="secondary">Mở danh sách</button></form></section>
<?php if ($sessionError) echo '<p class="alert error">'.e($sessionError).'</p>'; if ($session !== null):
$rows=query('SELECT a.*,s.code,s.name FROM attendance a JOIN students s ON s.id=a.student_id WHERE a.session_id=? ORDER BY s.code',[$session])->fetchAll(); ?>
<section class="panel"><h2>Danh sách điểm danh</h2><p class="muted">Chưa điểm danh không được tự động tính là vắng mặt.</p><?php if (!$rows): ?><p class="empty">Không tìm thấy buổi học.</p><?php else: ?><form method="post"><?= csrf() ?><input type="hidden" name="action" value="mark"><input type="hidden" name="session_id" value="<?= e($session) ?>"><div class="table-scroll"><table><thead><tr><th>Mã sinh viên</th><th>Họ và tên</th><th>Trạng thái</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><select aria-label="Trạng thái <?= e($r['name']) ?>" name="status[<?= e($r['student_id']) ?>]"><?php foreach (['unmarked','present','absent','late'] as $status): ?><option value="<?= $status ?>" <?= $status===$r['status'] ? 'selected' : '' ?>><?= status_label($status) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div><button>Lưu điểm danh</button></form><?php endif; ?></section><?php endif; page_end(); ?>
