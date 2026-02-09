<?php
// Always return JSON, never HTML errors
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Catch fatal errors and always output JSON
$jsonErrorSent = false;
register_shutdown_function(function() {
    global $jsonErrorSent;
    if (!$jsonErrorSent) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unexpected server error or empty response']);
    }
});
// Always return JSON, never HTML errors
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
// compare.php?id1=PROVIDER_ID1&id2=PROVIDER_ID2
require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json');
$debugLog = __DIR__ . '/compare_debug.log';

try {
    $id1 = isset($_GET['id1']) ? intval($_GET['id1']) : 0;
    $id2 = isset($_GET['id2']) ? intval($_GET['id2']) : 0;
    if (!$id1 || !$id2) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id1 or id2']);
        exit;
    }

    $pdo = get_db();

    // Fetch providers
    $stmt = $pdo->prepare('SELECT id, name, link, price, extra_attrs, channels, groups, md5 FROM providers WHERE id IN (?, ?)');
    $stmt->execute([$id1, $id2]);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($providers) !== 2) {
        http_response_code(404);
        echo json_encode(['error' => 'One or both providers not found']);
        exit;
    }

    // Use count-based similarity
    $p1 = $providers[0];
    $p2 = $providers[1];
    $chan_count_sim = (($p1['channels'] || $p2['channels']) ? (1.0 - abs($p1['channels'] - $p2['channels']) / max(1, max($p1['channels'], $p2['channels']))) * 100.0 : 0);
    $group_count_sim = (($p1['groups'] || $p2['groups']) ? (1.0 - abs($p1['groups'] - $p2['groups']) / max(1, max($p1['groups'], $p2['groups']))) * 100.0 : 0);
    $similarity = round(($chan_count_sim + $group_count_sim) / 2, 2);
    $matches = [
        'channels_match' => $p1['channels'] == $p2['channels'],
        'groups_match' => $p1['groups'] == $p2['groups']
    ];
    $verdict = $similarity >= 85 ? 'Similar' : 'Different';
    $jsonErrorSent = true;
    echo json_encode([
        'provider1' => ['id' => $p1['id'], 'name' => $p1['name'], 'price' => $p1['price']],
        'provider2' => ['id' => $p2['id'], 'name' => $p2['name'], 'price' => $p2['price']],
        'similarity' => $similarity,
        'matches' => $matches,
        'verdict' => $verdict
    ]);

} catch (Exception $e) {
    @file_put_contents($debugLog, date('c') . ' Exception: ' . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
