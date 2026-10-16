<?php
$context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>5]]);
file_get_contents('http://nginx:9091/stub_status',false,$context);
if (!str_contains($http_response_header[0],' 403 ')) throw new RuntimeException('Web peer can read restricted Nginx status');
echo "PASS: web container cannot read exporter-only Nginx stub_status\n";
