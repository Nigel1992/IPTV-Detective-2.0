<?php
// Temporary timezone diagnostic — remove after use
header('Content-Type: text/plain; charset=utf-8');
echo "PHP timezone: " . date_default_timezone_get() . "\n";
echo "php.ini date.timezone: " . (ini_get('date.timezone') ?: '(not set)') . "\n";
echo "Server local time (date()): " . date('c') . "\n";
echo "Server UTC time (gmdate()): " . gmdate('c') . "\n\n";

// Attempt to query MySQL timezone info if DB is configured
$cfgFile = __DIR__ . '/inc/config.php';
$cfgAlt = __DIR__ . '/inc/config.php.local';
if (!file_exists($cfgFile) && file_exists($cfgAlt)) {
    // try local config
    $cfgFile = $cfgAlt;
}

if (!file_exists($cfgFile)) {
    echo "No inc/config.php found on server; cannot query MySQL timezone.\n";
    exit;
}

require_once __DIR__ . '/inc/db.php';
try {
    $pdo = get_db();
    $res = $pdo->query("SELECT @@global.time_zone AS global_tz, @@session.time_zone AS session_tz, NOW() AS now_local, UTC_TIMESTAMP() AS now_utc");
    $row = $res->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "MySQL global.time_zone: " . ($row['global_tz'] ?? '(unknown)') . "\n";
        echo "MySQL session.time_zone: " . ($row['session_tz'] ?? '(unknown)') . "\n";
        echo "MySQL NOW(): " . ($row['now_local'] ?? '(unknown)') . "\n";
        echo "MySQL UTC_TIMESTAMP(): " . ($row['now_utc'] ?? '(unknown)') . "\n";
    } else {
        echo "Failed to fetch MySQL timezone info.\n";
    }
} catch (Throwable $e) {
    echo "DB query error: " . $e->getMessage() . "\n";
}

echo "\n-- End of diagnostic --\n";
?>