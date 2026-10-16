#!/usr/bin/env bash
set -euo pipefail
exec 3<>/dev/tcp/127.0.0.1/9080
printf 'GET /ready HTTP/1.0\r\nHost: localhost\r\n\r\n' >&3
IFS= read -r -t 3 status <&3
[[ "$status" == *' 200 '* ]]
