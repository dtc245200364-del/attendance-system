#!/bin/sh
set -eu
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot < /tmp/attendance-monitoring-user.sql
mysql -uroot -e "SHOW GRANTS FOR 'attendance_monitor'@'%';"
