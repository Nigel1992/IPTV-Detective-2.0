<?php
// admin_update_series_count.php
// Simple admin-only endpoint to update a provider's series count and optionally recompute matches.
// Upload this to your site root and call via POST with 'provider_id' and 'series_count'.

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

// basic admin check: prefer session-admin flag
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['admin_user']) && empty($_COOKIE['iptv_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin login required']);
    exit;
}

header('Content-Type: application/json');

$provider_id = isset($_POST['provider_id']) ? intval($_POST['provider_id']) : 0;
$series_count = isset($_POST['series_count']) ? intval($_POST['series_count']) : null;
$recompute = isset($_POST['recompute']) ? (bool)$_POST['recompute'] : false;

if (!$provider_id || $series_count === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing provider_id or series_count']);
    exit;
}

try {
    $pdo = get_db();
    $stmt = $pdo->prepare('UPDATE providers SET series = ? WHERE id = ?');
    $stmt->execute([$series_count, $provider_id]);
    $ok = $stmt->rowCount() > 0;

    $resp = ['ok' => (bool)$ok, 'provider_id' => $provider_id, 'series' => $series_count];

    if ($recompute) {
        // Optionally run update_matches.php to refresh similarity/matches
        // This will execute server-side and may take time on large DBs
        $updateScript = __DIR__ . '/update_matches.php';
        if (is_readable($updateScript)) {
            ob_start();
            include $updateScript;
            $out = ob_get_clean();
            $resp['recompute_output'] = $out;
        } else {
            $resp['recompute_output'] = 'update_matches.php not found';
        }
    }

    echo json_encode($resp);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>