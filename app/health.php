<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/includes/db.php';
try {
    query('SELECT 1');
    echo '{"status":"ok"}';
} catch (Throwable $ex) {
    error_log('Readiness database connection failed');
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
