#!/bin/sh
set -eu
echo $$ > /tmp/attendance-fault-php.pid
exec php -S 0.0.0.0:18090 /tmp/attendance-proxy-upstream.php
