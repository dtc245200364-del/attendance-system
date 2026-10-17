<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Ho_Chi_Minh');
// Fixed fields only: never emit SQL, form data, session IDs or user supplied text.
function attendance_log(string $event, string $level, int $status, ?Throwable $exception = null): void {
    $routes = ['index.php', 'classes.php', 'students.php', 'attendance.php', 'history.php', 'statistics.php'];
    $route = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $diagnostic = [];
    if ($exception !== null) {
        $known = [PDOException::class, InvalidArgumentException::class, RuntimeException::class, LogicException::class, Error::class, TypeError::class];
        $diagnostic = ['exception_type' => in_array(get_class($exception), $known, true) ? get_class($exception) : 'Throwable',
            'error_file' => basename($exception->getFile()), 'error_line' => $exception->getLine()];
        if ($exception instanceof PDOException) {
            $state = (string)($exception->errorInfo[0] ?? $exception->getCode());
            $diagnostic['sqlstate'] = preg_match('/^[A-Z0-9]{5}$/D', $state) ? $state : 'other';
            $diagnostic['driver_code'] = (int)($exception->errorInfo[1] ?? 0);
        }
    }
    error_log('ATTENDANCE_EVENT ' . json_encode([
        'source' => 'php', 'event' => $event, 'level' => $level, 'status' => $status,
        'route' => in_array($route, $routes, true) ? $route : 'other',
        'method' => in_array($method, ['GET', 'POST', 'HEAD', 'OPTIONS'], true) ? $method : 'OTHER',
        'request_id' => is_string($requestId) && preg_match('/^[a-f0-9]{32}$/D', $requestId) ? $requestId : 'none',
    ] + $diagnostic, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
register_shutdown_function(static function (): void {
    $status = http_response_code() ?: 200;
    $last = error_get_last();
    if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) $status = 500;
    attendance_log('request_completed', $status >= 500 ? 'error' : ($status >= 400 ? 'warn' : 'info'), $status);
});
session_start();
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
require_once __DIR__ . '/db.php';
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function id(mixed $value): int {
    if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1 || (int)$value > 4294967295) throw new InvalidArgumentException('Mã định danh không hợp lệ.');
    return (int)$value;
}
function field(string $key, int $max, bool $required = true): string {
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) throw new InvalidArgumentException('Dữ liệu không hợp lệ.');
    $v = trim($raw);
    if (($required && $v === '') || preg_match('//u', $v) !== 1 || preg_match_all('/./us', $v) > $max) throw new InvalidArgumentException('Vui lòng điền đúng thông tin, tối đa ' . $max . ' ký tự.');
    return $v;
}
function code_field(): string {
    $v = field('code', 30);
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $v)) throw new InvalidArgumentException('Mã chỉ gồm chữ không dấu, số, dấu gạch ngang hoặc gạch dưới.');
    return $v;
}
class FormConflict extends RuntimeException {}
function post_action(callable $callback): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        page_start('Phiên không hợp lệ', basename($_SERVER['SCRIPT_NAME']));
        echo '<div class="alert error" role="alert">Phiên không hợp lệ. Vui lòng tải lại trang.</div><a class="button" href="/' . e(basename($_SERVER['SCRIPT_NAME'])) . '">Tải lại trang</a>';
        page_end(); exit;
    }
    try {
        $message = $callback();
        $_SESSION['flash'] = ['success', is_string($message) ? $message : 'Đã lưu thay đổi.'];
        header('Location: ' . basename($_SERVER['SCRIPT_NAME'])); exit;
    } catch (FormConflict $ex) {
        if (db()->inTransaction()) db()->rollBack();
        http_response_code(409); $_SESSION['flash'] = ['error', $ex->getMessage()];
    } catch (InvalidArgumentException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        http_response_code(422); $_SESSION['flash'] = ['error', $ex->getMessage()];
    } catch (PDOException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        if (in_array($ex->getCode(), ['23000', '45000'], true)) {
            attendance_log('database_error', 'warn', 409, $ex);
            http_response_code(409); $_SESSION['flash'] = ['error', 'Mã hoặc thời gian bị trùng, hoặc dữ liệu đang được sử dụng.'];
        } else { throw $ex; }
    }
}
set_exception_handler(function (Throwable $ex): void {
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
    attendance_log('unhandled_exception', 'error', 500, $ex); http_response_code(500);
    echo '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Lỗi hệ thống</title><p>Không thể xử lý yêu cầu. Vui lòng thử lại sau.</p></html>';
});
function page_start(string $title, string $active): void {
    $links = ['index.php'=>'Tổng quan', 'classes.php'=>'Lớp học', 'students.php'=>'Sinh viên', 'attendance.php'=>'Điểm danh', 'history.php'=>'Lịch sử', 'statistics.php'=>'Thống kê'];
    $icons = ['index.php'=>'grid','classes.php'=>'book','students.php'=>'users','attendance.php'=>'check','history.php'=>'clock','statistics.php'=>'chart'];
    $descriptions = ['index.php'=>'Mọi thông tin cần thiết, trong một không gian.', 'classes.php'=>'Tổ chức lớp học, kết nối việc giảng dạy.', 'students.php'=>'Quản lý hồ sơ và hành trình học tập.', 'attendance.php'=>'Ghi nhận sự hiện diện, theo từng buổi học.', 'history.php'=>'Tra cứu rõ ràng. Theo dõi liền mạch.', 'statistics.php'=>'Nhìn lại chuyên cần qua những con số.'];
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . ' · Attendance</title><link rel="stylesheet" href="/assets/style.css"><script src="/assets/ui.js" defer></script></head><body><a class="skip-link" href="#main-content">Đến nội dung chính</a><aside class="sidebar"><a class="brand" href="/">' . ui_icon('cap') . '<span>Attendance<small>Không gian học tập</small></span></a><p class="nav-label">QUẢN LÝ ĐÀO TẠO</p><nav aria-label="Điều hướng chính">';
    foreach ($links as $url=>$label) echo '<a ' . ($url === $active ? 'class="active" aria-current="page"' : '') . ' href="/' . $url . '">' . ui_icon($icons[$url]) . '<span>' . e($label) . '</span>' . ($url === $active ? '<span class="nav-dot" aria-hidden="true"></span>' : '') . '</a>';
    echo '</nav><div class="sidebar-note">' . ui_icon('cap') . '<strong>Mỗi buổi học đều có ý nghĩa.</strong><p>Đồng hành cùng việc dạy và học mỗi ngày.</p></div><div class="sidebar-bottom"><span class="connection-dot" aria-hidden="true"></span> Quản lý điểm danh sinh viên</div></aside><main id="main-content" tabindex="-1"><div class="topbar"><span class="breadcrumb">Quản lý đào tạo <span aria-hidden="true">/</span> <strong>' . e($links[$active] ?? $title) . '</strong></span><span class="date">' . ui_icon('calendar') . '<time datetime="' . date('Y-m-d') . '">' . date('d/m/Y') . '</time></span></div><header class="page-header"><div><p class="eyebrow">KHÔNG GIAN GIẢNG VIÊN</p><h1>' . e($title) . '</h1><p class="page-description">' . e($descriptions[$active] ?? 'Quản lý điểm danh sinh viên') . '</p></div><span class="workspace-mark">' . ui_icon($icons[$active] ?? 'cap') . '</span></header>';
    if (isset($_SESSION['flash'])) { [$type,$message] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div role="status" class="alert ' . e($type) . '">' . e($message) . '</div>'; }
}
function page_end(): void { echo '<footer><span><strong>Attendance</strong> · Quản lý học tập</span><span>Giờ Việt Nam · UTC+7</span></footer></main></body></html>'; }

// Static, local SVG icons. No external font, library or user-provided markup.
function ui_icon(string $name): string {
    $paths = [
        'cap'=>'<path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M6 11v6c4 3 8 3 12 0v-6M21 9v7"/>',
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'book'=>'<path d="M12 5c-3-2-6-2-9-1v15c3-1 6-1 9 1 3-2 6-2 9-1V4c-3-1-6-1-9 1v15Z"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M18 15c2 0 3 2 3 4v2"/>',
        'check'=>'<rect x="4" y="4" width="16" height="17" rx="3"/><path d="M9 3h6v4H9zM8 14l3 3 5-6"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chart'=>'<path d="M4 3v17h17M8 16v-4M13 16V8M18 16V5"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4M17 3v4M3 11h18M8 15h2M14 15h2"/>',
        'arrow'=>'<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'search'=>'<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'edit'=>'<path d="m16 3 5 5-12 12-6 1 1-6L16 3ZM13 6l5 5"/>',
        'archive'=>'<rect x="3" y="3" width="18" height="5" rx="1"/><path d="M5 8v12h14V8M10 12h4"/>',
        'restore'=>'<path d="M3 10a9 9 0 1 1 2 8M3 4v6h6M12 8v5l3 2"/>',
        'trash'=>'<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
    ];
    return '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
}
function ui_metric(string $label, int $value, string $icon, string $tone = ''): void {
    echo '<article class="metric ' . e($tone) . '"><div class="metric-top"><span>' . e($label) . '</span><span class="metric-icon">' . ui_icon($icon) . '</span></div><strong>' . e($value) . '</strong><small>' . ($value === 0 ? 'Chưa có lượt ghi nhận' : 'Dữ liệu hiện tại') . '</small></article>';
}
function ui_avatar(string $name): string {
    preg_match('/\S/u', $name, $initial);
    return '<span class="avatar" aria-hidden="true">' . e($initial[0] ?? '?') . '</span>';
}
function require_record(string $table, int $recordId): void {
    if (!in_array($table, ['classes', 'students'], true)) throw new LogicException('Invalid table');
    if (!query('SELECT id FROM ' . $table . ' WHERE id=?', [$recordId])->fetch()) throw new InvalidArgumentException('Không tìm thấy dữ liệu cần thay đổi.');
}
function class_options(?int $selected = null): void {
    foreach (query('SELECT id, code, name FROM classes ORDER BY code') as $c) echo '<option value="' . e($c['id']) . '" ' . ($selected === (int)$c['id'] ? 'selected' : '') . '>' . e($c['code'] . ' — ' . $c['name']) . '</option>';
}
function status_label(string $s): string { return ['present'=>'Có mặt','absent'=>'Vắng mặt','late'=>'Đi muộn','unmarked'=>'Chưa điểm danh'][$s] ?? ''; }
