<?php
// get_submissions.php - Returns all provider submissions as JSON
ini_set('display_errors', 0);
require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json');
// Restrict to admin sessions only
session_start();
if (!isset($_SESSION['admin_user'])) {
    http_response_code(403);
    echo json_encode(['error'=>'Admin access required']);
    exit;
}
try {
$pdo = get_db();
// Check available columns so we only query what exists (some installs have trimmed schemas)
$cfg = include __DIR__ . '/inc/config.php';
$stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
$stmtCols->execute([$cfg['dbname']]);
$available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
// Build main select: include commonly useful columns (avoid selecting missing ones)
$selectCols = ['id','name','link','price','channels','groups','md5','created_at','is_public','hash','md5','link_raw'];
$selectCols = array_values(array_unique($selectCols));
$selectCols = array_filter($selectCols, function($c) use ($available){ return in_array($c, $available, true); });
$selectSql = 'SELECT ' . implode(',', $selectCols) . ' FROM providers WHERE is_public=1 ORDER BY created_at DESC LIMIT 200';
$q = $pdo->prepare($selectSql);
$q->execute();
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
// Compute grouped status using pairwise similarities
$n = count($rows);
$adj = array_fill(0, $n, []);
$counts = [];
$groups_counts = [];
$max_sim = array_fill(0, $n, 0.0);
for ($i = 0; $i < $n; $i++) {
    $p = $rows[$i];
    $counts[$i] = intval($p['channels'] ?? 0);
    $groups_counts[$i] = intval($p['groups'] ?? 0);
}
$threshold = 80.0;
for ($i = 0; $i < $n; $i++) {
    for ($j = $i+1; $j < $n; $j++) {
        $chan_sim = 0.0; $group_sim = 0.0;
        $countA = $counts[$i]; $countB = $counts[$j];
        $gAcount = $groups_counts[$i]; $gBcount = $groups_counts[$j];
        // count-based similarity
        if ($countA > 0 || $countB > 0) {
            $chan_sim = (1.0 - abs($countA - $countB) / max(1, max($countA, $countB))) * 100.0;
        }
        if ($gAcount > 0 || $gBcount > 0) {
            $group_sim = (1.0 - abs($gAcount - $gBcount) / max(1, max($gAcount, $gBcount))) * 100.0;
        }
        $md5_sim = (!empty($rows[$i]['md5']) && !empty($rows[$j]['md5']) && $rows[$i]['md5'] === $rows[$j]['md5']) ? 100.0 : 0.0;
        $overall = ($chan_sim * 0.45) + ($group_sim * 0.45) + ($md5_sim * 0.1);
        if ($overall >= $threshold) {
            $adj[$i][] = $j;
            $adj[$j][] = $i;
        }
        // update max sim for both
        if ($overall > $max_sim[$i]) $max_sim[$i] = $overall;
        if ($overall > $max_sim[$j]) $max_sim[$j] = $overall;
    }
}
// enrich each row with best match shared counts (exact-hash matches only)
foreach ($rows as $idx => &$r) {
    // sanitize public-facing link to avoid leaking credentials
    require_once __DIR__ . '/inc/functions.php';
    if (isset($r['link'])) { $r['link'] = sanitize_url($r['link']); }
    // Ensure a group_fingerprint exists for grouping UI: prefer existing group_fingerprint, else use md5 as a fallback grouping key
    if (!isset($r['group_fingerprint']) || $r['group_fingerprint'] === null || $r['group_fingerprint'] === '') {
        if (!empty($r['md5'])) {
            $r['group_fingerprint'] = 'md5:' . $r['md5'];
        } else {
            $r['group_fingerprint'] = '';
        }
    }
    // numeric counts deprecated for exact-hash matches — use boolean flags instead
    $r['best_shared'] = null;
    $r['best_shared_groups'] = null;
    $r['best_price'] = null;
    $r['best_price_diff'] = 0;
    $r['best_cheaper'] = false;
    $r['best_channels_match'] = false;
    $r['best_groups_match'] = false;
    // mark grouped based on adjacency
    $r['grouped_match'] = (count($adj[$idx]) > 0) ? 1 : 0;
    // set matched if grouped
    if ($r['grouped_match']) {
        $r['matched'] = 1;
    }
    // set similarity score
    if ($max_sim[$idx] > 0) {
        $r['similarity_score'] = round($max_sim[$idx], 1);
    }
}
unset($r);
echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Internal server error','detail'=>substr($e->getMessage(),0,200)]);
}
