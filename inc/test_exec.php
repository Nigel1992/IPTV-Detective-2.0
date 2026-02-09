<?php
// inc/test_exec.php - basic execution test: logs and prints 'ok'
// @file_put_contents(__DIR__ . '/proxy_debug.log', date('c') . " test_exec called\n", FILE_APPEND);
header('Content-Type: text/plain');
echo "ok";
?>