<?php
require __DIR__ . '/includes/bootstrap.php';
post_action(function () {
    $action = field('action',20);
    if (in_array($action, ['archive','restore'], true)) require_record('students', id($_POST['id'] ?? ''));
    if ($action === 'archive' || $action === 'restore') { query('UPDATE students SET active=? WHERE id=?', [$action === 'restore' ? 1 : 0, id($_POST['id'] ?? '')]); return; }
    $code=code_field(); $name=field('name',120); $email=field('email',190,false); $class=id($_POST['class_id'] ?? '');
    if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Địa chỉ email không hợp lệ.');
    if ($action === 'create') query('INSERT INTO students(class_id,code,name,email) VALUES (?,?,?,?)',[$class,$code,$name,$email ?: null]);
    elseif ($action === 'update') {
        db()->beginTransaction();
        $old=query('SELECT * FROM students WHERE id=? FOR UPDATE',[id($_POST['id'] ?? '')])->fetch();
        if (!$old) { db()->rollBack(); throw new InvalidArgumentException('Không tìm thấy sinh viên.'); }
        if ((int)$old['class_id'] !== $class && query('SELECT 1 FROM attendance WHERE student_id=? LIMIT 1',[$old['id']])->fetch()) { db()->rollBack(); throw new InvalidArgumentException('Sinh viên đã có lịch sử điểm danh, không thể chuyển lớp.'); }
        query('UPDATE students SET class_id=?,code=?,name=?,email=? WHERE id=?',[$class,$code,$name,$email ?: null,$old['id']]); db()->commit();
    } else throw new InvalidArgumentException('Thao tác không hợp lệ.');
});
page_start('Quản lý sinh viên','students.php');
?>
<section class="panel"><h2>Thêm sinh viên</h2><form method="post" class="form-grid"><?= csrf() ?><input type="hidden" name="action" value="create"><label>Mã sinh viên<input name="code" required maxlength="30" placeholder="SV001"></label><label>Họ và tên<input name="name" required maxlength="120" placeholder="Nguyễn Minh Anh"></label><label>Lớp học<select name="class_id" required><option value="">Chọn lớp</option><?php class_options(); ?></select></label><label>Email (tùy chọn)<input type="email" name="email" maxlength="190"></label><button>Thêm sinh viên</button></form></section>
<section class="panel"><h2>Danh sách sinh viên</h2><p class="muted">Ngừng theo dõi để giữ lịch sử. Sinh viên mới được đưa vào các buổi tạo sau thời điểm thêm.</p>
<?php $rows=query('SELECT s.*,c.code AS class_code FROM students s JOIN classes c ON c.id=s.class_id ORDER BY s.active DESC,c.code,s.code')->fetchAll(); if (!$rows) echo '<p class="empty">Chưa có sinh viên. Hãy tạo lớp rồi thêm sinh viên.</p>'; foreach ($rows as $r): ?>
<details class="student"><summary><strong><?= e($r['code']) ?></strong> · <?= e($r['name']) ?> <span class="badge"><?= e($r['class_code']) ?> · <?= $r['active'] ? 'Đang học' : 'Ngừng theo dõi' ?></span></summary><form method="post" class="form-grid"><?= csrf() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><label>Mã sinh viên<input name="code" value="<?= e($r['code']) ?>" required maxlength="30"></label><label>Họ và tên<input name="name" value="<?= e($r['name']) ?>" required maxlength="120"></label><label>Lớp<select name="class_id"><?php class_options((int)$r['class_id']); ?></select></label><label>Email<input type="email" name="email" value="<?= e($r['email']) ?>" maxlength="190"></label><button name="action" value="update">Lưu thông tin</button></form><form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="secondary" name="action" value="<?= $r['active'] ? 'archive' : 'restore' ?>"><?= $r['active'] ? 'Ngừng theo dõi' : 'Khôi phục' ?></button></form></details>
<?php endforeach; ?></section><?php page_end(); ?>
