<?php
// inc/logging.php
// Centralized safe logger. By default this is a NO-OP to prevent creating .log files
// on hosts that disallow file writes or where logs are sensitive.

$ENABLE_LOGS = false; // set true to allow logging (enable with caution)
$LOG_DIR = __DIR__ . '/../logs';

function safe_log($message, $path = null) {
    global $ENABLE_LOGS, $LOG_DIR;
    if (!$ENABLE_LOGS) return false;
    if ($path === null) {
        if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0755, true);
        $path = $LOG_DIR . '/app.log';
    }
    // append safely
    return @file_put_contents($path, $message, FILE_APPEND | LOCK_EX);
}

// helper to format messages
function safe_logf($path, $fmt /*, args... */) {
    $args = array_slice(func_get_args(), 2);
    $msg = vsprintf($fmt, $args);
    return safe_log($msg, $path);
}
