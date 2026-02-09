<?php
// Database setup script
$cfg = include __DIR__ . '/inc/config.php';
try {
    $pdo = new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';charset='.$cfg['charset'], $cfg['user'], $cfg['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = file_get_contents(__DIR__ . '/database_schema.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach($statements as $statement) {
        if(!empty($statement) && !preg_match('/^--/', $statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }
    echo "Database schema created successfully!\n";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>