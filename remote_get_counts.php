<?php
// get_counts.php - Public API returning counts useful for homepage UI
header('Content-Type: application/json');
require_once __DIR__ . '/inc/db.php';
try {
    $pdo = get_db();
    $stmt = $pdo->query('SELECT COUNT(*) AS cnt_total FROM providers');
    $total = intval($stmt->fetchColumn());
    $stmt2 = $pdo->query('SELECT COUNT(*) AS cnt_public FROM providers WHERE is_public = 1');
    $public = intval($stmt2->fetchColumn());
    // recent submissions in the last 7 days
    $stmt3 = $pdo->query("SELECT COUNT(*) AS cnt_recent FROM providers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $recent7 = intval($stmt3->fetchColumn());
    // matches: number of providers involved in duplicate md5 groups (exact matches)
    try {
        $stmt4 = $pdo->query("SELECT SUM(cnt) AS total_dup FROM (SELECT COUNT(*) AS cnt FROM providers WHERE md5 IS NOT NULL AND md5 != '' GROUP BY md5 HAVING COUNT(*) >= 2) t");
        $matches = intval($stmt4->fetchColumn());
    } catch (Throwable $e) {
        // md5 column missing or other DB error - fall back to 0
        $matches = 0;
    }

    // Optional: provide "matched under <threshold>" when requested by the badge (keeps backward compatibility)
    $providers_matched_under = null;
    if (isset($_GET['threshold'])) {
        $thr = floatval($_GET['threshold']);
        $scopeParam = (isset($_GET['scope']) && $_GET['scope'] === 'total') ? 'total' : 'public';
        $sql = 'SELECT COUNT(*) FROM providers WHERE matched = 1 AND match_price IS NOT NULL AND match_price < ?' . ($scopeParam === 'public' ? ' AND is_public = 1' : '');
        $stmt5 = $pdo->prepare($sql);
        $stmt5->execute([$thr]);
        $providers_matched_under = intval($stmt5->fetchColumn());
    }

    $out = ['success'=>true,'providers_public'=>$public,'providers_total'=>$total,'providers_recent_7'=>$recent7,'providers_matches'=>$matches];
    if ($providers_matched_under !== null) {
        $out['providers_matched_under'] = $providers_matched_under;
    }

    echo json_encode($out);
} catch (Throwable $e) {
    // @file_put_contents(__DIR__ . '/get_counts_error.log', date('c') . " DB error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success'=>false,'providers_public'=>0,'providers_total'=>0,'error'=>'DB error']);
}
