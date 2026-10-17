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
        $callback();
        $_SESSION['flash'] = ['success', 'Đã lưu thay đổi.'];
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
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . ' · Điểm danh</title><link rel="stylesheet" href="/assets/style.css"></head><body><aside><a class="brand" href="/">A<span>Attendance</span></a><p class="muted">QUẢN LÝ HỌC TẬP</p><nav>';
    foreach ($links as $url=>$label) echo '<a ' . ($url === $active ? 'class="active" aria-current="page"' : '') . ' href="/' . $url . '">' . e($label) . '</a>';
    echo '</nav><p class="sidebar-note">Hệ thống điểm danh<br>Giai đoạn 1 · PHP & MySQL</p></aside><main><header><div><p class="eyebrow">KHÔNG GIAN GIẢNG VIÊN</p><h1>' . e($title) . '</h1></div><span class="date">' . date('d/m/Y') . '</span></header>';
    if (isset($_SESSION['flash'])) { [$type,$message] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div role="status" class="alert ' . e($type) . '">' . e($message) . '</div>'; }
}
function page_end(): void { echo '<footer>Attendance System · Dữ liệu được lưu theo giờ Việt Nam (UTC+7)</footer></main></body></html>'; }
function require_record(string $table, int $recordId): void {
    if (!in_array($table, ['classes', 'students'], true)) throw new LogicException('Invalid table');
    if (!query('SELECT id FROM ' . $table . ' WHERE id=?', [$recordId])->fetch()) throw new InvalidArgumentException('Không tìm thấy dữ liệu cần thay đổi.');
}
function class_options(?int $selected = null): void {
    foreach (query('SELECT id, code, name FROM classes ORDER BY code') as $c) echo '<option value="' . e($c['id']) . '" ' . ($selected === (int)$c['id'] ? 'selected' : '') . '>' . e($c['code'] . ' — ' . $c['name']) . '</option>';
}
function status_label(string $s): string { return ['present'=>'Có mặt','absent'=>'Vắng mặt','late'=>'Đi muộn','unmarked'=>'Chưa điểm danh'][$s] ?? ''; }
