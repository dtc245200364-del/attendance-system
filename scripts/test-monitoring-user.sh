#!/bin/sh
set -eu
mysql --defaults-extra-file=/tmp/attendance-monitor-client.cnf -N -e 'SELECT 1; SHOW GLOBAL STATUS LIKE "Threads_connected";'
if mysql --defaults-extra-file=/tmp/attendance-monitor-client.cnf -e 'SELECT COUNT(*) FROM attendance_phase1.students' >/tmp/attendance-monitor-denied.log 2>&1; then
    echo 'FAIL: monitoring user can read business data'
    exit 1
fi
if ! grep -q 'ERROR 1142' /tmp/attendance-monitor-denied.log; then
    echo 'FAIL: expected permission denial not observed'
    exit 1
fi
echo 'PASS: monitoring user can read global status and cannot SELECT student data'
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot -N -e "SELECT max_user_connections FROM mysql.user WHERE user='attendance_monitor' AND host='%';"
