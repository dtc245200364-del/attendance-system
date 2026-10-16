<?php
$ctx=stream_context_create(['http'=>['header'=>"X-Forwarded-For: 172.30.87.2\r\nX-Forwarded-Proto: https\r\n",'ignore_errors'=>true,'timeout'=>5]]);
file_get_contents('http://web:8080/',false,$ctx);
if (!str_contains($http_response_header[0],' 403 ')) throw new RuntimeException('Untrusted peer bypassed proxy-only access');
echo "PASS: untrusted database-network peer cannot bypass proxy using forged headers\n";
