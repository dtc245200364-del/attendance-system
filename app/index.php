<?php
require __DIR__ . '/includes/bootstrap.php';
$counts=query('SELECT (SELECT COUNT(*) FROM classes) AS classes, (SELECT COUNT(*) FROM students WHERE active=1) AS students, (SELECT COUNT(*) FROM sessions) AS sessions, (SELECT COUNT(*) FROM attendance WHERE status="unmarked") AS pending')->fetch();
page_start('Tổng quan','index.php');
?>
<section class="hero">
    <div><p class="eyebrow">MỖI BUỔI HỌC ĐỀU QUAN TRỌNG</p><h2>Sẵn sàng cho buổi học tiếp theo?</h2><p>Ghi nhận chuyên cần, quản lý lớp học và theo dõi sinh viên trong một không gian.</p></div>
    <div class="hero-actions"><a class="button" href="/attendance.php"><?= ui_icon('plus') ?> Bắt đầu điểm danh</a></div>
</section>
<section class="metrics" aria-label="Số liệu tổng quan">
<?php
ui_metric('Lớp học',(int)$counts['classes'],'book');
ui_metric('Sinh viên đang học',(int)$counts['students'],'users');
ui_metric('Buổi điểm danh',(int)$counts['sessions'],'calendar');
ui_metric('Chưa điểm danh',(int)$counts['pending'],'clock','tone-amber');
$distribution=['present'=>0,'absent'=>0,'late'=>0,'unmarked'=>0];
foreach (query('SELECT status,COUNT(*) AS total FROM attendance GROUP BY status') as $d) $distribution[$d['status']]=(int)$d['total'];
$attendanceTotal=array_sum($distribution);
?>
</section>
<div class="dashboard-grid">
<section class="panel">
    <div class="panel-heading"><div><h2>Buổi học gần đây</h2><p>Các buổi được sắp xếp theo thời gian bắt đầu.</p></div><a class="panel-link" href="/attendance.php">Xem tất cả <?= ui_icon('arrow') ?></a></div>
    <div class="table-scroll"><table><thead><tr><th>Lớp</th><th>Buổi học</th><th>Bắt đầu</th><th>Thao tác</th></tr></thead><tbody>
    <?php $rows=query('SELECT s.*,c.code FROM sessions s JOIN classes c ON c.id=s.class_id ORDER BY s.starts_at DESC,s.id DESC LIMIT 8')->fetchAll(); if (!$rows) echo '<tr><td colspan="4" class="empty">Chưa có buổi học. Bắt đầu bằng việc tạo lớp và thêm sinh viên.</td></tr>'; foreach ($rows as $s): ?>
    <tr><td><span class="badge"><?= e($s['code']) ?></span></td><td><strong><?= e($s['title']) ?></strong><br><small>ID #<?= e($s['id']) ?></small></td><td><?= e(date('d/m/Y',strtotime($s['starts_at']))) ?><br><small><?= e(date('H:i',strtotime($s['starts_at']))) ?></small></td><td><a class="panel-link" href="/attendance.php?session_id=<?= e($s['id']) ?>">Điểm danh <?= ui_icon('arrow') ?></a></td></tr>
    <?php endforeach; ?></tbody></table></div><p class="table-hint">Cuộn ngang để xem đầy đủ thông tin và thao tác.</p>
</section>
<section class="panel"><div class="panel-heading"><div><h2>Tình hình điểm danh</h2><p>Phân bố trạng thái trên tất cả các buổi.</p></div><span class="count-pill"><?= e($attendanceTotal) ?> lượt</span></div>
    <div class="distribution-grid"><?php foreach ($distribution as $status=>$value): ?><div class="distribution-row <?= e($status) ?>"><div class="distribution-label"><span><?= status_label($status) ?></span><strong><?= e($value) ?></strong></div><meter min="0" max="<?= max(1,$attendanceTotal) ?>" value="<?= e($value) ?>" aria-label="<?= e(status_label($status)) ?>: <?= e($value) ?> trên <?= e($attendanceTotal) ?> lượt"><?= e($value) ?></meter></div><?php endforeach; ?></div>
    <p class="distribution-note">Lượt chưa điểm danh được theo dõi riêng, không tự động tính là vắng mặt.</p>
</section>
</div>
<?php page_end(); ?>
