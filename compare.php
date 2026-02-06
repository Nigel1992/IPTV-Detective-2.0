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
    $stmt = $pdo->prepare('SELECT id, name, link, price, channel_fingerprint, extra_attrs, channels, groups, md5 FROM providers WHERE id IN (?, ?)');
    $stmt->execute([$id1, $id2]);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($providers) !== 2) {
        http_response_code(404);
        echo json_encode(['error' => 'One or both providers not found']);
        exit;
    }

    // Use MD5 file integrity only for comparisons
    $md1 = $providers[0]['md5'] ?? null;
    $md2 = $providers[1]['md5'] ?? null;
    if (!$md1 || !$md2) {
        http_response_code(400);
        echo json_encode(['error' => 'MD5 missing for one or both providers']);
        exit;
    }
    $comparison = compareFingerprints(['md5'=>$md1], ['md5'=>$md2]);
    $jsonErrorSent = true;
    echo json_encode([
        'provider1' => ['id' => $providers[0]['id'], 'name' => $providers[0]['name'], 'price' => $providers[0]['price']],
        'provider2' => ['id' => $providers[1]['id'], 'name' => $providers[1]['name'], 'price' => $providers[1]['price']],
        'similarity' => $comparison['score'],
        'matches' => $comparison['matches'],
        'verdict' => $comparison['verdict']
    ]);

} catch (Exception $e) {
    @file_put_contents($debugLog, date('c') . ' Exception: ' . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

function parseFingerprint($provider) {
    // parseFingerprint now only provides MD5
    return [ 'md5' => $provider['md5'] ?? null ];
}

function compareFingerprints($fp1, $fp2) {
    // MD5-only comparison
    $md1 = $fp1['md5'] ?? null;
    $md2 = $fp2['md5'] ?? null;
    $score = ($md1 && $md2 && $md1 === $md2) ? 100 : 0;
    $matches = [ 'MD5: ' . ($md1 ?? 'NULL') . ' vs ' . ($md2 ?? 'NULL') ];
    $verdict = $score === 100 ? 'Exact file match (MD5)' : 'Different';
    return ['score' => $score, 'matches' => $matches, 'verdict' => $verdict];
}