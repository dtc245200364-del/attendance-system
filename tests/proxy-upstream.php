<?php
// Temporary internal-only fault server. Never copied into the document root.
if ($_SERVER['REQUEST_URI']==='/slow') sleep(3);
if ($_SERVER['REQUEST_URI']==='/unavailable') { http_response_code(503); echo 'internal details not for clients'; exit; }
header('Content-Type: application/json');
echo json_encode(['host'=>$_SERVER['HTTP_HOST'] ?? '', 'xff'=>$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', 'proto'=>$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '', 'forwarded'=>$_SERVER['HTTP_FORWARDED'] ?? '']);
