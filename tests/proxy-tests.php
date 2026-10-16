<?php
declare(strict_types=1);
$passed=0;
function check(bool $ok,string $label): void { global $passed; if (!$ok) throw new RuntimeException('FAIL: '.$label); echo "PASS: $label\n"; $passed++; }
function request(string $base,string $path,string $method='GET',string $body='',array $headers=[]): array {
    $header=implode("\r\n",array_merge(['Connection: close'],$headers));
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>$header,'content'=>$body,'follow_location'=>0,'ignore_errors'=>true,'timeout'=>8]]);
    $result=file_get_contents($base.$path,false,$context); $raw=$http_response_header ?? [];
    preg_match('/\s(\d{3})\s/',$raw[0] ?? '',$m); $parsed=[];
    foreach ($raw as $h) if (str_contains($h,':')) { [$k,$v]=explode(':',$h,2); $parsed[strtolower($k)][]=trim($v); }
    return [(int)($m[1] ?? 0),(string)$result,$parsed];
}
function secure(array $h,string $case): void {
    foreach (['x-content-type-options'=>'nosniff','x-frame-options'=>'DENY','referrer-policy'=>'same-origin','content-security-policy'=>"default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"] as $name=>$value) check(isset($h[$name]) && $h[$name]===[$value],"single $name on $case");
}
$base='http://nginx:8080';
foreach (['/'=>200,'/assets/style.css'=>200,'/missing.php'=>404,'/.env'=>403] as $path=>$status) {
    [$code,$body,$headers]=request($base,$path); check($code===$status,"status $status $path"); secure($headers,$path);
}
[$code,$body,$headers]=request($base,'/healthz'); check($code===200 && json_decode($body,true)===['status'=>'ok'],'readiness traverses proxy PHP and database');
[$code,$body,$headers]=request($base,'/classes.php','POST','csrf=wrong&action=create',['Content-Type: application/x-www-form-urlencoded']); check($code===403,'CSRF 403 preserved through Nginx'); secure($headers,'CSRF');
[$code,$body,$headers]=request($base,'/classes.php','POST',str_repeat('x',1048577),['Content-Type: application/x-www-form-urlencoded']); check($code===413 && str_contains($body,'1 MiB'),'request body over 1 MiB rejected'); secure($headers,'413');
[$code,$body,$headers]=request('http://nginx:18088','/headers','GET','',['Host: localhost:18087','X-Forwarded-For: 203.0.113.66','X-Forwarded-Proto: https','Forwarded: for=203.0.113.66;proto=https']);
$echo=json_decode($body,true);
check($code===200 && $echo['host']==='localhost:18087','Host including external port forwarded');
check($echo['xff']==='172.30.87.3','client-supplied XFF replaced by actual peer');
check($echo['proto']==='http','client-supplied HTTPS replaced by actual scheme');
check($echo['forwarded']==='','untrusted Forwarded removed');
foreach (['http://nginx:18089'=>'502','http://nginx:18088/unavailable'=>'503','http://nginx:18088/slow'=>'504'] as $url=>$expected) {
    $parts=parse_url($url); $origin='http://'.$parts['host'].':'.$parts['port']; $path=$parts['path'] ?? '/';
    $start=microtime(true); [$code,$body,$headers]=request($origin,$path);
    check($code===(int)$expected && str_contains($body,'Dịch vụ tạm thời gián đoạn') && !str_contains($body,'internal details'),"upstream $expected uses generic page and preserves status");
    secure($headers,$expected);
    if ($expected==='504') check(microtime(true)-$start<3,'read timeout enforced in shortened test configuration');
}
echo "RESULT: $passed proxy assertions passed\n";
