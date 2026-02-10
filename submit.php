

<?php
// Prevent all caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$debug_log = __DIR__ . '/submit_debug.log';

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/maintenance.php';
$pdo = get_db();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $channel_count = intval($_POST['channel_count'] ?? 0);
    $host = trim($_POST['host'] ?? '');
    $path_type = trim($_POST['path_type'] ?? '');
    $ext = trim($_POST['ext'] ?? '');
    $epg = isset($_POST['epg']) ? (bool)$_POST['epg'] : false;
    $group_count = intval($_POST['group_count'] ?? 0);
    $id_pattern = trim($_POST['id_pattern'] ?? '');

    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Name is required']);
        exit;
    }

    // Insert provider
    $extra_attrs = json_encode([
        'host' => $host,
        'path_type' => $path_type,
        'ext' => $ext,
        'epg' => $epg,
        'id_pattern' => $id_pattern,
        'notes' => $notes
    ]);
    // Use Europe/Amsterdam timezone for created_at
    $created = (new DateTime('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('INSERT INTO providers (name, link, price, groups, channels, created_at, extra_attrs, submission_count) VALUES (?, ?, ?, ?, 0, ?, ?, 1)');
    $stmt->execute([$name, $website, $price, $group_count, $created, $extra_attrs]);
    $provider_id = $pdo->lastInsertId();

    // Insert submission
    $stmt = $pdo->prepare('INSERT INTO provider_submissions (provider_id, user_id) VALUES (?, ?)');
    $stmt->execute([$provider_id, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

    echo json_encode(['success' => true, 'provider_id' => $provider_id, 'message' => 'Provider submitted successfully']);

} catch (Exception $e) {
    $msg = date('c') . ' Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
    @file_put_contents($debug_log, $msg, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
