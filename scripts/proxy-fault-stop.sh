#!/bin/sh
set -eu
if [ -f /tmp/attendance-fault-php.pid ]; then
    kill "$(cat /tmp/attendance-fault-php.pid)"
fi
