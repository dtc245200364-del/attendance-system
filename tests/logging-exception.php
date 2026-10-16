<?php
// CLI-only test; never installed in the public document root.
declare(strict_types=1);
require '/var/www/html/includes/bootstrap.php';
throw new RuntimeException('PHASE4_PRIVATE_EXCEPTION_CANARY');
