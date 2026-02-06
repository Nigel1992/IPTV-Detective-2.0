

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
    $fingerprint_hash = trim($_POST['fingerprint_hash'] ?? '');
    $fingerprint_string = trim($_POST['fingerprint_string'] ?? '');
    $channel_count = intval($_POST['channel_count'] ?? 0);
    // Ensure fingerprint string includes channels:count
    if ($channel_count > 0 && strpos($fingerprint_string, 'channels:') === false) {
        $fingerprint_string .= '|channels:' . $channel_count;
    }
    $host = trim($_POST['host'] ?? '');
    $path_type = trim($_POST['path_type'] ?? '');
    $ext = trim($_POST['ext'] ?? '');
    $epg = isset($_POST['epg']) ? (bool)$_POST['epg'] : false;
    $group_count = intval($_POST['group_count'] ?? 0);
    $id_pattern = trim($_POST['id_pattern'] ?? '');

    if (!$name || !$fingerprint_hash || !$fingerprint_string) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, fingerprint_hash (MD5), and fingerprint_string are required']);
        exit;
    }

    // Treat fingerprint_hash as MD5; check if MD5 already exists
    $stmt = $pdo->prepare('SELECT id FROM providers WHERE `md5` = ?');
    $stmt->execute([$fingerprint_hash]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        // Update submission count
        $stmt = $pdo->prepare('UPDATE providers SET submission_count = submission_count + 1 WHERE id = ?');
        $stmt->execute([$existing['id']]);
        echo json_encode(['success' => true, 'provider_id' => $existing['id'], 'message' => 'Provider already exists, submission count updated']);
        exit;
    }

    // Insert provider (store md5 and mirror into hash for compatibility)
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

    $stmt = $pdo->prepare('INSERT INTO providers (name, link, price, `hash`, md5, channel_fingerprint, groups, channels, created_at, extra_attrs, submission_count) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1)');
    $stmt->execute([$name, $website, $price, $fingerprint_hash, $fingerprint_hash, $fingerprint_string, $group_count, $created, $extra_attrs]);
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
