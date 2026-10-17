<?php
declare(strict_types=1);

// Shared by all workers of the single web instance. Lost/recreated files invalidate
// old forms. Keep the lock until the database transaction finishes; fail closed.
function attendance_lock(int $session) {
    $directory = sys_get_temp_dir() . '/attendance-versions';
    if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('Version storage unavailable');
    $handle = @fopen($directory . '/' . $session, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Version lock unavailable');
    return $handle;
}
function attendance_revision($handle): string {
    rewind($handle);
    $revision = stream_get_contents($handle);
    if (!preg_match('/^[a-f0-9]{64}$/D', $revision)) $revision = attendance_rotate($handle);
    return $revision;
}
function attendance_rotate($handle): string {
    $revision = bin2hex(random_bytes(32));
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $revision) !== 64 || !fflush($handle)) throw new RuntimeException('Version write failed');
    return $revision;
}
function attendance_version(int $session, array $rows, string $revision): string {
    $snapshot = [];
    foreach ($rows as $row) $snapshot[(int)$row['student_id']] = [$row['status'], $row['updated_at']];
    ksort($snapshot, SORT_NUMERIC);
    return hash_hmac('sha256', $session . ':' . $revision . ':' . json_encode($snapshot, JSON_THROW_ON_ERROR), $_SESSION['csrf']);
}
function similar_session_title(string $left, string $right): bool {
    $normalize = static fn(string $s): string => preg_replace('/[\p{Z}\p{P}\p{S}\s]+/u', '', strtolower($s));
    $a = $normalize($left); $b = $normalize($right);
    similar_text($a, $b, $percent);
    return preg_match('/^' . preg_quote($a, '/') . '$/iuD', $b) === 1 || $percent >= 80;
}
