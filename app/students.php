<?php
require __DIR__ . '/includes/bootstrap.php';
function student_delete_confirmation(array $student): string {
    return hash_hmac('sha256', json_encode([$student['id'],$student['code'],$student['name'],$student['active']],JSON_THROW_ON_ERROR), $_SESSION['csrf']);
}
post_action(function () {
    $action = field('action',20);
    if ($action === 'delete') {
        $studentId=id($_POST['id'] ?? '');
        db()->beginTransaction();
        $student=query('SELECT * FROM students WHERE id=? FOR UPDATE',[$studentId])->fetch();
        if (!$student) throw new InvalidArgumentException('Không tìm thấy sinh viên. Hồ sơ có thể đã được xóa; vui lòng tải lại danh sách.');
        // Every roster entry counts, including unmarked. Never delete attendance.
        if (query('SELECT 1 FROM attendance WHERE student_id=? LIMIT 1',[$studentId])->fetch()) throw new FormConflict('Không thể xóa sinh viên đã có dữ liệu điểm danh, kể cả Chưa điểm danh. Hãy dùng Ngừng theo dõi để giữ nguyên lịch sử và thống kê.');
        // Fail closed if the schema gains an additional relationship we do not handle.
        $relations=query("SELECT k.TABLE_SCHEMA,k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='students'")->fetchAll();
        if (!$relations) throw new FormConflict('Không xác minh được ràng buộc bảo toàn dữ liệu. Chưa xóa sinh viên; hãy dùng Ngừng theo dõi.');
        foreach ($relations as $relation) {
            if ($relation['TABLE_SCHEMA'] !== getenv('DB_NAME') || $relation['TABLE_NAME'] !== 'attendance' || $relation['COLUMN_NAME'] !== 'student_id' || $relation['REFERENCED_COLUMN_NAME'] !== 'id' || !in_array($relation['DELETE_RULE'],['RESTRICT','NO ACTION'],true)) throw new FormConflict('Cấu trúc dữ liệu có quan hệ nghiệp vụ cần kiểm tra thêm. Không thể xóa vĩnh viễn; hãy dùng Ngừng theo dõi.');
        }
        if (($_POST['confirm_delete'] ?? '') !== '1' || !is_string($_POST['confirmation'] ?? null) || !hash_equals(student_delete_confirmation($student),$_POST['confirmation'])) throw new FormConflict('Xác nhận xóa không hợp lệ hoặc hồ sơ đã thay đổi. Vui lòng tải lại danh sách và kiểm tra lại tên, mã sinh viên trước khi xác nhận.');
        try { query('DELETE FROM students WHERE id=?',[$studentId]); }
        catch (PDOException $ex) {
            if ((int)($ex->errorInfo[1] ?? 0) === 1451) {
                attendance_log('database_error','warn',409,$ex);
                throw new FormConflict('Sinh viên có dữ liệu nghiệp vụ liên quan nên không thể xóa. Hãy dùng Ngừng theo dõi để bảo toàn dữ liệu.');
            }
            throw $ex;
        }
        db()->commit();
        return 'Đã xóa sinh viên chưa có dữ liệu điểm danh. Không thay đổi lịch sử hoặc thống kê điểm danh.';
    }
    if (in_array($action, ['archive','restore'], true)) require_record('students', id($_POST['id'] ?? ''));
    if ($action === 'archive' || $action === 'restore') { query('UPDATE students SET active=? WHERE id=?', [$action === 'restore' ? 1 : 0, id($_POST['id'] ?? '')]); return $action === 'restore' ? 'Đã khôi phục sinh viên. Lịch sử và thống kê được giữ nguyên.' : 'Đã ngừng theo dõi sinh viên. Lịch sử và thống kê được giữ nguyên.'; }
    $code=code_field(); $name=field('name',120); $email=field('email',190,false); $class=id($_POST['class_id'] ?? '');
    if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Địa chỉ email không hợp lệ.');
    if ($action === 'create') { query('INSERT INTO students(class_id,code,name,email) VALUES (?,?,?,?)',[$class,$code,$name,$email ?: null]); return 'Đã thêm sinh viên.'; }
    elseif ($action === 'update') {
        db()->beginTransaction();
        $old=query('SELECT * FROM students WHERE id=? FOR UPDATE',[id($_POST['id'] ?? '')])->fetch();
        if (!$old) { db()->rollBack(); throw new InvalidArgumentException('Không tìm thấy sinh viên.'); }
        if ((int)$old['class_id'] !== $class && query('SELECT 1 FROM attendance WHERE student_id=? LIMIT 1',[$old['id']])->fetch()) { db()->rollBack(); throw new InvalidArgumentException('Sinh viên đã có lịch sử điểm danh, không thể chuyển lớp.'); }
        query('UPDATE students SET class_id=?,code=?,name=?,email=? WHERE id=?',[$class,$code,$name,$email ?: null,$old['id']]); db()->commit(); return 'Đã cập nhật hồ sơ sinh viên.';
    } else throw new InvalidArgumentException('Thao tác không hợp lệ.');
});
$editId=null; $deleteCandidate=null; $viewError='';
try {
    if (isset($_GET['edit'])) { $editId=id($_GET['edit']); require_record('students',$editId); }
    if (isset($_GET['delete'])) {
        $deleteId=id($_GET['delete']);
        $deleteCandidate=query('SELECT s.*,EXISTS(SELECT 1 FROM attendance a WHERE a.student_id=s.id) AS has_attendance FROM students s WHERE s.id=?',[$deleteId])->fetch();
        if (!$deleteCandidate) throw new InvalidArgumentException('Không tìm thấy sinh viên để xác nhận xóa.');
    }
} catch (InvalidArgumentException $ex) { http_response_code(422); $viewError=$ex->getMessage(); }
page_start('Quản lý sinh viên','students.php');
?>
<?php if ($viewError): ?><p class="alert error" role="alert"><?= e($viewError) ?></p><?php endif; ?>
<section class="panel student-create-panel"><div class="panel-heading"><div><h2>Thêm sinh viên</h2><p>Bổ sung hồ sơ và chọn lớp học tương ứng.</p></div><span class="record-id"><?= ui_icon('users') ?></span></div><form method="post" class="form-grid"><?= csrf() ?><input type="hidden" name="action" value="create"><label>Mã sinh viên<input name="code" required maxlength="30" placeholder="Ví dụ: SV001"></label><label>Họ và tên<input name="name" required maxlength="120" placeholder="Họ và tên đầy đủ"></label><label>Lớp học<select name="class_id" required><option value="">Chọn lớp</option><?php class_options(); ?></select></label><label>Email (tùy chọn)<input type="email" name="email" maxlength="190" placeholder="Địa chỉ email"></label><button><?= ui_icon('plus') ?> Thêm sinh viên</button></form></section>
<section class="panel student-directory-panel"><div class="panel-heading"><div><h2>Danh sách sinh viên</h2><p>Sửa hồ sơ, quản lý theo dõi và xóa an toàn từng sinh viên.</p></div><label class="search-box"><span>Tìm sinh viên theo tên, mã hoặc lớp</span><?= ui_icon('search') ?><input type="search" id="student-search" placeholder="Tìm tên, mã hoặc lớp…" autocomplete="off"></label></div>
<div class="notice"><?= ui_icon('info') ?><p>Ngừng theo dõi giữ nguyên lịch sử. Chỉ xóa vĩnh viễn hồ sơ chưa có dữ liệu điểm danh hoặc quan hệ nghiệp vụ cần bảo toàn.</p></div>
<?php $rows=query('SELECT s.*,c.code AS class_code,EXISTS(SELECT 1 FROM attendance a WHERE a.student_id=s.id) AS has_attendance FROM students s JOIN classes c ON c.id=s.class_id ORDER BY s.active DESC,c.code,s.code')->fetchAll(); if (!$rows) echo '<p class="empty">Chưa có sinh viên. Hãy tạo lớp rồi thêm sinh viên.</p>'; ?>
<div class="student-list student-directory" aria-label="Danh sách sinh viên"><div class="student-head" aria-hidden="true"><span>HỌ VÀ TÊN</span><span>MÃ SINH VIÊN</span><span>LỚP</span><span>TRẠNG THÁI</span><span>THAO TÁC</span></div>
<?php foreach ($rows as $r): ?>
<div class="student-record" data-student-row>
    <div class="student-row" data-student-search>
        <span class="student-identity"><?= ui_avatar($r['name']) ?><strong><?= e($r['name']) ?></strong></span>
        <span class="student-code"><span class="student-cell-label">Mã sinh viên</span><?= e($r['code']) ?></span>
        <span class="student-class"><span class="student-cell-label">Lớp học</span><span class="badge" title="<?= e($r['class_code']) ?>"><?= e($r['class_code']) ?></span></span>
        <span><span class="student-cell-label">Trạng thái</span><span class="badge <?= $r['active'] ? 'present' : 'unmarked' ?>"><?= $r['active'] ? 'Đang học' : 'Ngừng theo dõi' ?></span></span>
        <div class="student-actions" aria-label="Thao tác với <?= e($r['name']) ?>">
            <a class="button secondary action-button" href="/students.php?edit=<?= e($r['id']) ?>#student-<?= e($r['id']) ?>" data-edit-student="student-<?= e($r['id']) ?>" title="Sửa hồ sơ <?= e($r['name']) ?>" aria-label="Sửa hồ sơ <?= e($r['name']) ?>"><?= ui_icon('edit') ?><span>Sửa</span></a>
            <form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="secondary action-button" name="action" value="<?= $r['active'] ? 'archive' : 'restore' ?>" title="<?= $r['active'] ? 'Ngừng theo dõi, giữ nguyên lịch sử' : 'Khôi phục theo dõi sinh viên' ?>" aria-label="<?= e(($r['active'] ? 'Ngừng theo dõi ' : 'Khôi phục ').$r['name']) ?>"><?= ui_icon($r['active'] ? 'archive' : 'restore') ?><span><?= $r['active'] ? 'Ngừng theo dõi' : 'Khôi phục' ?></span></button></form>
            <a class="button danger action-button" href="/students.php?delete=<?= e($r['id']) ?>#delete-confirmation" data-delete-student="<?= e($r['id']) ?>" data-student-name="<?= e($r['name']) ?>" data-student-code="<?= e($r['code']) ?>" data-has-attendance="<?= $r['has_attendance'] ? '1' : '0' ?>" data-confirmation="<?= e(student_delete_confirmation($r)) ?>" title="<?= $r['has_attendance'] ? 'Xem lý do không thể xóa hồ sơ có điểm danh' : 'Xóa hồ sơ chưa có dữ liệu điểm danh' ?>" aria-label="Xóa sinh viên <?= e($r['name']) ?>"><?= ui_icon('trash') ?><span>Xóa</span></a>
        </div>
    </div>
    <details class="student" id="student-<?= e($r['id']) ?>" <?= $editId === (int)$r['id'] ? 'open' : '' ?>>
        <summary>Chỉnh sửa hồ sơ · <?= e($r['name'].' — '.$r['code']) ?> <span class="muted">(bấm để thu gọn)</span></summary>
        <div class="student-body"><form method="post" class="form-grid"><?= csrf() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><label>Mã sinh viên<input name="code" value="<?= e($r['code']) ?>" required maxlength="30"></label><label>Họ và tên<input name="name" value="<?= e($r['name']) ?>" required maxlength="120"></label><label>Lớp<select name="class_id"><?php class_options((int)$r['class_id']); ?></select></label><label>Email<input type="email" name="email" value="<?= e($r['email']) ?>" maxlength="190"></label><button name="action" value="update">Lưu thông tin</button></form></div>
    </details>
</div>
<?php endforeach; ?></div><p class="empty" id="student-search-empty" role="status" hidden>Không có sinh viên phù hợp với tìm kiếm.</p></section>
<?php $blocked=(bool)($deleteCandidate['has_attendance'] ?? false); ?>
<dialog id="delete-confirmation" class="student-delete-dialog" aria-labelledby="delete-title" aria-describedby="delete-explanation" <?= $deleteCandidate ? 'open' : '' ?>>
    <div class="dialog-icon"><?= ui_icon('trash') ?></div><h2 id="delete-title">Xác nhận xóa sinh viên</h2>
    <p class="delete-identity"><strong data-delete-name><?= e($deleteCandidate['name'] ?? '') ?></strong><br>Mã sinh viên: <strong data-delete-code><?= e($deleteCandidate['code'] ?? '') ?></strong></p>
    <p id="delete-explanation" data-delete-explanation><?= $blocked ? 'Không thể xóa: sinh viên đã có dữ liệu điểm danh, kể cả Chưa điểm danh. Hãy dùng Ngừng theo dõi để giữ nguyên lịch sử và thống kê.' : 'Hồ sơ sẽ bị xóa vĩnh viễn. Hệ thống chỉ cho phép xóa nếu sinh viên chưa có dữ liệu điểm danh hoặc quan hệ nghiệp vụ cần bảo toàn.' ?></p>
    <form method="post" id="delete-student-form"><?= csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($deleteCandidate['id'] ?? '') ?>"><input type="hidden" name="confirm_delete" value="1"><input type="hidden" name="confirmation" value="<?= $deleteCandidate ? e(student_delete_confirmation($deleteCandidate)) : '' ?>">
        <div class="dialog-actions"><a class="button secondary" href="/students.php" data-close-delete>Hủy</a><button class="danger" type="submit" <?= $blocked ? 'disabled' : '' ?>><?= ui_icon('trash') ?> Xóa vĩnh viễn</button></div>
    </form>
</dialog>
<?php page_end(); ?>
