<?php
// Quick diagnostic: connect to DB using inc/config.php.local and show provider counts
$cfgFile = __DIR__ . '/inc/config.php';
if (!file_exists($cfgFile)) {
    $alt = __DIR__ . '/inc/config.php.local';
    if (file_exists($alt)) $cfgFile = $alt;
}
if (!file_exists($cfgFile)) {
    echo "No DB config found (inc/config.php or inc/config.php.local)\n";
    exit(1);
}
$cfg = include $cfgFile;
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'],$cfg['port'],$cfg['dbname'],$cfg['charset'] ?? 'utf8mb4');
try {
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $tot = $pdo->query('SELECT COUNT(*) FROM providers')->fetchColumn();
    $pub = $pdo->query('SELECT COUNT(*) FROM providers WHERE is_public = 1')->fetchColumn();
    echo "Total providers: " . intval($tot) . "\n";
    echo "Public providers (is_public=1): " . intval($pub) . "\n";
} catch (PDOException $e) {
    echo "DB error: " . $e->getMessage() . "\n";
    exit(2);
}
