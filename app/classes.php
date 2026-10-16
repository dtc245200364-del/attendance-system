<?php
require __DIR__ . '/includes/bootstrap.php';
post_action(function () {
    $action = field('action', 20);
    if (in_array($action, ['delete','update'], true)) require_record('classes', id($_POST['id'] ?? ''));
    if ($action === 'delete') { query('DELETE FROM classes WHERE id = ?', [id($_POST['id'] ?? '')]); return; }
    $code = code_field(); $name = field('name', 120);
    if ($action === 'update') query('UPDATE classes SET code = ?, name = ? WHERE id = ?', [$code, $name, id($_POST['id'] ?? '')]);
    elseif ($action === 'create') query('INSERT INTO classes(code,name) VALUES (?,?)', [$code,$name]);
    else throw new InvalidArgumentException('Thao tác không hợp lệ.');
});
page_start('Quản lý lớp học', 'classes.php');
?>
<section class="panel"><h2>Thêm lớp học</h2><p class="muted">Tổ chức danh sách sinh viên theo từng lớp.</p><form method="post" class="form-row"><?= csrf() ?><input type="hidden" name="action" value="create"><label>Mã lớp<input name="code" maxlength="30" required placeholder="CNTT01" pattern="[A-Za-z0-9_-]+"></label><label>Tên lớp<input name="name" maxlength="120" required placeholder="Công nghệ thông tin 01"></label><button>Thêm lớp</button></form></section>
<section class="panel"><h2>Danh sách lớp</h2><p class="muted">Chỉ xóa được lớp chưa có sinh viên và buổi điểm danh.</p>
<?php $rows=query('SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id AND s.active=1) AS total FROM classes c ORDER BY c.code')->fetchAll(); if (!$rows) echo '<p class="empty">Chưa có lớp học. Hãy thêm lớp đầu tiên.</p>'; foreach ($rows as $r): ?>
<div class="record"><form method="post" class="form-row"><?= csrf() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><label>Mã lớp<input aria-label="Mã lớp" name="code" value="<?= e($r['code']) ?>" maxlength="30" required></label><label>Tên lớp<input name="name" value="<?= e($r['name']) ?>" maxlength="120" required></label><span class="badge"><?= e($r['total']) ?> sinh viên</span><button name="action" value="update" class="secondary">Lưu</button></form><form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button name="action" value="delete" class="danger">Xóa lớp trống</button></form></div>
<?php endforeach; ?></section><?php page_end(); ?>
