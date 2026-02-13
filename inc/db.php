<?php
function get_db() {
    // Read credentials from inc/config.php
    $cfgFile = __DIR__ . '/config.php';
    $altCfg = __DIR__ . '/config.php.local';
    // Allow a local config fallback for hosts that keep credentials in config.php.local
    if (!file_exists($cfgFile) && file_exists($altCfg)) {
        $cfgFile = $altCfg;
    }
    if (!file_exists($cfgFile)) {
        // Throw an exception so higher-level request handlers can return a proper JSON error
        throw new RuntimeException('DB config missing: ' . $cfgFile);
    }
    $cfg = include $cfgFile;
    $host = $cfg['host'] ?? 'localhost';
    $port = $cfg['port'] ?? 3306;
    $db   = $cfg['dbname'] ?? '';
    $user = $cfg['user'] ?? '';
    $pass = $cfg['pass'] ?? '';
    $charset = $cfg['charset'] ?? 'utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $tried = [];
    // Try host as given (with port if provided)
    $hostsToTry = [];
    if ($host === 'localhost') {
        // try both localhost (socket) and 127.0.0.1 (TCP)
        $hostsToTry = ['localhost', '127.0.0.1'];
    } else {
        $hostsToTry = [$host];
    }

    foreach ($hostsToTry as $h) {
        $tried[] = $h;
        $dsn = "mysql:host={$h};port={$port};dbname={$db};charset={$charset}";
        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // try next
            $lastErr = $e;
        }
    }

    // Last attempt: if a socket path is provided in cfg (optional)
    if (!empty($cfg['socket'])) {
        $dsn = "mysql:unix_socket={$cfg['socket']};dbname={$db};charset={$charset}";
        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $lastErr = $e;
        }
    }

    $msg = 'DB connection failed: ' . ($lastErr->getMessage() ?? 'unknown');
    $msg .= "\nTried hosts: " . implode(', ', $tried) . ". Check credentials in inc/config.php and consider using 127.0.0.1 instead of localhost if your environment requires TCP. Also verify MySQL server is running and accessible (host, port).";
    // Throw so callers can catch and log appropriately instead of abruptly exiting
    throw new RuntimeException($msg);

}