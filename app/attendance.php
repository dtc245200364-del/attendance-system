<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/attendance-version.php';
$creationWarning = null;
post_action(function () {
    global $creationWarning;
    $action=field('action',20);
    if ($action === 'create') {
        $class=id($_POST['class_id'] ?? ''); $title=field('title',150); $date=field('starts_at',16);
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date);
        if (!$dt || $dt->format('Y-m-d\TH:i') !== $date || (int)$dt->format('Y') < 2000 || (int)$dt->format('Y') > 2100) throw new InvalidArgumentException('Ngày giờ không hợp lệ (năm 2000–2100).');
        db()->beginTransaction();
        query('SELECT id FROM classes WHERE id=? FOR UPDATE', [$class])->fetch();
        $sameDay = query('SELECT id,title,starts_at FROM sessions WHERE class_id=? AND starts_at>=? AND starts_at<? ORDER BY id', [$class,$dt->format('Y-m-d 00:00:00'),$dt->modify('+1 day')->format('Y-m-d 00:00:00')])->fetchAll();
        $similar = array_values(array_filter($sameDay, fn($s) => similar_session_title($title, $s['title'])));
        if ($similar) {
            $confirmation = hash_hmac('sha256', json_encode([$class,$title,$date,$similar], JSON_THROW_ON_ERROR), $_SESSION['csrf']);
            if (($_POST['confirm_similar'] ?? '') !== '1' || !is_string($_POST['similar_token'] ?? null) || !hash_equals($confirmation, $_POST['similar_token'])) {
                $creationWarning = ['token'=>$confirmation,'sessions'=>$similar];
                throw new FormConflict('Có buổi cùng lớp, cùng ngày và tiêu đề tương tự. Hãy kiểm tra ID buổi bên dưới; chỉ xác nhận nếu muốn tạo một buổi riêng.');
            }
        }
        $students=query('SELECT id FROM students WHERE class_id=? AND active=1 FOR UPDATE',[$class])->fetchAll();
        if (!$students) { db()->rollBack(); throw new InvalidArgumentException('Lớp phải có sinh viên đang học trước khi tạo buổi.'); }
        query('INSERT INTO sessions(class_id,title,starts_at) VALUES (?,?,?)',[$class,$title,$dt->format('Y-m-d H:i:s')]); $session=(int)db()->lastInsertId();
        foreach ($students as $s) query('INSERT INTO attendance(session_id,student_id) VALUES (?,?)',[$session,$s['id']]);
        db()->commit();
    } elseif ($action === 'mark') {
        $session=id($_POST['session_id'] ?? '');
        if (($_POST['complete'] ?? '') !== '1') throw new InvalidArgumentException('Dữ liệu gửi bị thiếu hoặc vượt giới hạn. Vui lòng tải lại danh sách; chưa có thay đổi nào được lưu.');
        $statuses=$_POST['status'] ?? null;
        if (isset($_POST['status_payload'])) {
            if (!is_string($_POST['status_payload'])) throw new InvalidArgumentException('Danh sách điểm danh không hợp lệ.');
            try { $statuses=json_decode($_POST['status_payload'],true,512,JSON_THROW_ON_ERROR); }
            catch (JsonException $ex) { throw new InvalidArgumentException('Danh sách điểm danh không đầy đủ. Vui lòng tải lại.'); }
        }
        if (!is_array($statuses)) throw new InvalidArgumentException('Thiếu danh sách điểm danh.');
        $lock=attendance_lock($session);
        try {
        db()->beginTransaction();
        query('SELECT id FROM sessions WHERE id=? FOR UPDATE',[$session])->fetch();
        $roster=query('SELECT student_id,status,updated_at FROM attendance WHERE session_id=? ORDER BY student_id FOR UPDATE',[$session])->fetchAll();
        if (!is_string($_POST['version'] ?? null) || !hash_equals(attendance_version($session,$roster,attendance_revision($lock)), $_POST['version'])) throw new FormConflict('Danh sách đã thay đổi hoặc phiên bản đã hết hạn. Chưa lưu thay đổi. Vui lòng bấm Mở danh sách để tải lại trước khi điểm danh.');
        if (!$roster || count($roster)!==count($statuses)) throw new InvalidArgumentException('Danh sách điểm danh không hợp lệ.');
        foreach ($roster as $row) {
            $student=$row['student_id'];
            $status=$statuses[$student] ?? null;
            if (!is_string($status) || !in_array($status,['unmarked','present','absent','late'],true)) { db()->rollBack(); throw new InvalidArgumentException('Trạng thái điểm danh không hợp lệ.'); }
            query('UPDATE attendance SET status=? WHERE session_id=? AND student_id=?',[$status,$session,$student]);
        }
        // Rotate before commit: a failed commit only invalidates forms, never lets a
        // stale form through. Also catches A -> B -> A within the same second.
        attendance_rotate($lock);
        db()->commit();
        } finally {
            if (db()->inTransaction()) db()->rollBack();
            flock($lock,LOCK_UN); fclose($lock);
        }
    } else throw new InvalidArgumentException('Thao tác không hợp lệ.');
});
$session=null; $sessionError='';
if (isset($_GET['session_id'])) {
    try { $session=id($_GET['session_id']); }
    catch (InvalidArgumentException $ex) { http_response_code(422); $sessionError=$ex->getMessage(); }
}
page_start('Điểm danh','attendance.php');
?>
<section class="panel"><div class="panel-heading"><div><h2>Tạo buổi điểm danh</h2><p>Chọn lớp, đặt tên và thời gian bắt đầu buổi học.</p></div><span class="record-id"><?= ui_icon('calendar') ?></span></div><form method="post" class="form-grid"><?= csrf() ?><input type="hidden" name="action" value="create"><label>Lớp học<select name="class_id" required><option value="">Chọn lớp</option><?php class_options(is_string($_POST['class_id'] ?? null) && ctype_digit($_POST['class_id']) ? (int)$_POST['class_id'] : null); ?></select></label><label>Nội dung buổi học<input name="title" value="<?= e(is_string($_POST['title'] ?? null) ? $_POST['title'] : '') ?>" required maxlength="150" placeholder="Buổi 1 — Triển khai hệ thống"></label><label>Ngày và giờ<input name="starts_at" type="datetime-local" required value="<?= e(is_string($_POST['starts_at'] ?? null) ? $_POST['starts_at'] : date('Y-m-d\TH:i')) ?>"></label><?php if ($creationWarning): ?><div class="alert error"><p>Các buổi cần đối chiếu:</p><ul><?php foreach ($creationWarning['sessions'] as $candidate): ?><li><a href="/attendance.php?session_id=<?= e($candidate['id']) ?>">ID #<?= e($candidate['id']) ?> · <?= e($candidate['title']) ?> · <?= e($candidate['starts_at']) ?></a></li><?php endforeach; ?></ul><input type="hidden" name="similar_token" value="<?= e($creationWarning['token']) ?>"><label><input type="checkbox" name="confirm_similar" value="1" required> Tôi đã đối chiếu và muốn tạo một buổi riêng.</label></div><?php endif; ?><button><?= ui_icon('plus') ?> Tạo buổi</button></form></section>
<section class="panel"><div class="panel-heading"><div><h2>Chọn buổi để điểm danh</h2><p>Đối chiếu ID để phân biệt các buổi có tên gần giống nhau.</p></div></div><form method="get" class="form-row"><label>Buổi học<select name="session_id" required><option value="">Chọn buổi học</option><?php $sessions=query('SELECT s.*,c.code FROM sessions s JOIN classes c ON c.id=s.class_id ORDER BY s.starts_at DESC,s.id DESC')->fetchAll(); foreach ($sessions as $s): ?><option value="<?= e($s['id']) ?>" <?= (string)($s['id']) === ($_GET['session_id'] ?? '') ? 'selected' : '' ?>><?= e('ID #'.$s['id'].' · '. $s['code'].' · '.$s['title'].' · '.date('d/m/Y H:i',strtotime($s['starts_at']))) ?></option><?php endforeach; ?></select></label><button class="secondary">Mở danh sách</button></form></section>
<?php if ($sessionError) echo '<p class="alert error">'.e($sessionError).'</p>'; if ($session !== null):
$lock=attendance_lock($session);
try {
    $rows=query('SELECT a.*,s.code,s.name FROM attendance a JOIN students s ON s.id=a.student_id WHERE a.session_id=? ORDER BY s.code',[$session])->fetchAll();
    $version=attendance_version($session,$rows,attendance_revision($lock));
} finally { flock($lock,LOCK_UN); fclose($lock); }
$inputLimit=(int)ini_get('max_input_vars'); $needsScript=$inputLimit>0 && count($rows)+5>$inputLimit;
?>
<section class="panel"><div class="panel-heading"><div><h2>Danh sách điểm danh</h2><p>Kiểm tra trạng thái của từng sinh viên trước khi lưu.</p></div><span class="badge">ID #<?= e($session) ?></span></div><?php foreach ($sessions as $info): if ((int)$info['id'] !== $session) continue; ?><div class="session-summary"><span class="badge"><?= e($info['code']) ?></span><strong><?= e($info['title']) ?></strong><span><?= e(date('d/m/Y · H:i',strtotime($info['starts_at']))) ?></span></div><?php endforeach; ?><div class="notice"><?= ui_icon('info') ?><p>Chưa điểm danh không được tự động tính là vắng mặt. Thay đổi chỉ được lưu khi bấm Lưu điểm danh.</p></div><?php if (!$rows): ?><p class="empty">Không tìm thấy buổi học.</p><?php else: ?><?php if ($needsScript): ?><p class="muted">Danh sách đông: cần JavaScript để gửi đầy đủ dữ liệu. Nếu không gửi được, hệ thống sẽ từ chối toàn bộ.</p><?php endif; ?><form method="post" id="attendance-mark"><?= csrf() ?><input type="hidden" name="action" value="mark"><input type="hidden" name="version" value="<?= e($version) ?>"><input type="hidden" name="session_id" value="<?= e($session) ?>"><div class="table-scroll"><table><thead><tr><th>Mã sinh viên</th><th>Họ và tên</th><th>Trạng thái</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><select aria-label="Trạng thái <?= e($r['name']) ?>" name="status[<?= e($r['student_id']) ?>]"><?php foreach (['unmarked','present','absent','late'] as $status): ?><option value="<?= $status ?>" <?= $status===$r['status'] ? 'selected' : '' ?>><?= status_label($status) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div><p class="table-hint">Cuộn ngang để chọn trạng thái cho từng sinh viên.</p><input type="hidden" name="complete" value="1"><div class="form-actions"><p>Kiểm tra lại danh sách trước khi xác nhận.</p><button <?= $needsScript ? 'disabled' : '' ?>><?= ui_icon('check') ?> Lưu điểm danh</button></div></form><?php endif; ?></section><?php endif; ?><script src="/assets/attendance.js" defer></script><?php page_end(); ?>
