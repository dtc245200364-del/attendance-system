<?php
declare(strict_types=1);
// CLI only, copied to /tmp; never install a diagnostic HTTP endpoint.
$checks = [];
function check_security(bool $ok, string $name): void {
    global $checks;
    $checks[] = ['name' => $name, 'pass' => $ok];
}
function permission_denied(PDO $pdo, string $sql): bool {
    try { $pdo->query($sql); return false; }
    catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0) === 1142; }
}
if (($argv[1] ?? '') === 'monitor') {
    $credentials = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $pdo = new PDO('mysql:host=db;charset=utf8mb4', $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($credentials);
    $grants = implode("\n", $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
    check_security(str_contains($grants, 'GRANT USAGE ON *.*') && !preg_match('/GRANT (?:SELECT|ALL|PROCESS|SUPER|REPLICATION)/', $grants), 'monitor user USAGE only');
    check_security((bool)$pdo->query("SHOW GLOBAL STATUS LIKE 'Threads_connected'")->fetch(), 'monitor can read global status');
    check_security(permission_denied($pdo, 'SELECT COUNT(*) FROM attendance_phase1.students'), 'monitor cannot read student data (1142)');
    check_security(permission_denied($pdo, 'SELECT COUNT(*) FROM mysql.user'), 'monitor cannot read mysql.user (1142)');
} else {
    require '/var/www/html/includes/db.php';
    $status = file_get_contents('/proc/self/status');
    check_security((bool)preg_match('/^Uid:\s+33\s+33\s+33\s+33$/m', $status), 'PHP CLI real/effective UID 33');
    foreach (['/var/www/html/includes/bootstrap.php', '/etc/passwd'] as $path) {
        $handle = @fopen($path, 'r+');
        check_security($handle === false, 'write-open denied: ' . $path);
        if (is_resource($handle)) fclose($handle); // Never modify contents even if protection fails.
    }
    $handle = tmpfile();
    check_security(is_resource($handle) && fwrite($handle, 'hardening-probe') === 15, 'temporary writes work');
    if (is_resource($handle)) fclose($handle);
    check_security(!filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN), 'PHP display_errors disabled');
    check_security(!filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), 'PHP expose_php disabled');
    check_security(ini_get('session.cookie_httponly') === '1' && ini_get('session.cookie_samesite') === 'Strict' && ini_get('session.use_strict_mode') === '1', 'PHP session security settings');
    $grants = db()->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $normalized = str_replace('\\_', '_', implode("\n", $grants));
    check_security(count($grants) === 2 && str_contains($normalized, 'GRANT SELECT, INSERT, UPDATE, DELETE ON `attendance_phase1`.*') && !str_contains($normalized, 'WITH GRANT OPTION'), 'app has schema-scoped DML grants only');
    check_security(permission_denied(db(), 'SELECT COUNT(*) FROM mysql.user'), 'app cannot read mysql.user (1142)');
    check_security((int)db()->query('SELECT 1')->fetchColumn() === 1, 'app database connection works');
}
echo json_encode(['checks' => $checks], JSON_THROW_ON_ERROR), "\n";
exit(count(array_filter($checks, static fn(array $c): bool => !$c['pass'])) ? 1 : 0);
