<?php
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/html'));
$failed = false;
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    passthru('php -l '.escapeshellarg($file->getPathname()), $code);
    if ($code !== 0) $failed = true;
}
exit($failed ? 1 : 0);
