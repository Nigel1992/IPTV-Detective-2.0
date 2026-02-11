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
// Load configuration
$cfg = null;
$cfgCandidates = [
    __DIR__ . '/inc/config.php',
    __DIR__ . '/inc/config.php.local',
    __DIR__ . '/inc/config.example.php'
];
foreach ($cfgCandidates as $c) {
    if (is_file($c)) { 
        $cfg = include $c; 
        break;
    }
}
if (!is_array($cfg)) {
    $cfg = [ 'turnstile_site_key' => 'PLACEHOLDER_TURNSTILE_SITE_KEY', 'turnstile_secret' => 'PLACEHOLDER_TURNSTILE_SECRET' ];
}
$stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
$stmtCols->execute([$cfg['dbname']]);
$available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
// Build main select: include commonly useful columns (avoid selecting missing ones)
$selectCols = ['id','name','link','price','channels','groups','live_streams','live_categories','series','series_categories','vod_categories','created_at','is_public','link_raw','matched','match_name','match_price','similarity_score'];
// include seller fields if present
$selectCols[] = 'seller_source';
$selectCols[] = 'seller_info';
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
$live_streams_counts = [];
$live_categories_counts = [];
$series_counts = [];
$series_categories_counts = [];
$vod_categories_counts = [];
$max_sim = array_fill(0, $n, 0.0);
// track best-match index for each row
$best_idx = array_fill(0, $n, -1);
// Store actual field values for exact matching
$live_categories_values = [];
$live_streams_values = [];
$series_values = [];
$series_categories_values = [];
$vod_categories_values = [];
for ($i = 0; $i < $n; $i++) {
    $p = $rows[$i];
    $counts[$i] = intval($p['channels'] ?? 0);
    $groups_counts[$i] = intval($p['groups'] ?? 0);
    $live_streams_counts[$i] = intval($p['live_streams'] ?? 0);
    $live_categories_counts[$i] = intval($p['live_categories'] ?? 0);
    $series_counts[$i] = intval($p['series'] ?? 0);
    $series_categories_counts[$i] = intval($p['series_categories'] ?? 0);
    $vod_categories_counts[$i] = intval($p['vod_categories'] ?? 0);
    // Store actual field values for exact matching
    $live_categories_values[$i] = $p['live_categories'] ?? '';
    $live_streams_values[$i] = $p['live_streams'] ?? '';
    $series_values[$i] = $p['series'] ?? '';
    $series_categories_values[$i] = $p['series_categories'] ?? '';
    $vod_categories_values[$i] = $p['vod_categories'] ?? '';
}
// Use weighted similarity for grouping (threshold 95%)
$minSimilarity = 95.0;
$metricCols = ['live_streams'=>0.30,'live_categories'=>0.20,'series'=>0.20,'series_categories'=>0.15,'vod_categories'=>0.05];
for ($i = 0; $i < $n; $i++) {
    for ($j = $i+1; $j < $n; $j++) {
        $weightSum = 0.0;
        $metricScore = 0.0;
        foreach ($metricCols as $col => $baseW) {
            $valA = isset($rows[$i][$col]) ? intval($rows[$i][$col]) : 0;
            $valB = isset($rows[$j][$col]) ? intval($rows[$j][$col]) : 0;
            $sim = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
            $weightSum += $baseW;
            $metricScore += $sim * $baseW;
        }
        $similarity = ($weightSum > 0) ? ($metricScore / $weightSum) : 0.0;
        if ($similarity >= $minSimilarity) {
            $adj[$i][] = $j;
            $adj[$j][] = $i;
        }
        // update max sim and best index for both
        if ($similarity > $max_sim[$i]) { $max_sim[$i] = $similarity; $best_idx[$i] = $j; }
        if ($similarity > $max_sim[$j]) { $max_sim[$j] = $similarity; $best_idx[$j] = $i; }
    }
}
// enrich each row with best match shared counts (exact-hash matches only)
foreach ($rows as $idx => &$r) {
    // sanitize public-facing link to avoid leaking credentials
    require_once __DIR__ . '/inc/functions.php';
    if (isset($r['link'])) { $r['link'] = sanitize_url($r['link']); }
    // Ensure channels and groups are numbers, use live counts if available
    $r['channels'] = intval($r['live_streams'] ?? $r['channels'] ?? 0);
    $r['groups'] = intval($r['live_categories'] ?? $r['groups'] ?? 0);
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
        // include best match info if available
        if (isset($best_idx[$idx]) && $best_idx[$idx] >= 0 && isset($rows[$best_idx[$idx]])) {
            $r['best_match_id'] = $rows[$best_idx[$idx]]['id'];
            $r['best_match_name'] = $rows[$best_idx[$idx]]['name'];
            $r['best_match_score'] = round($max_sim[$idx], 1);
        } else {
            $r['best_match_id'] = null;
            $r['best_match_name'] = null;
            $r['best_match_score'] = null;
        }
    } else {
        $r['best_match_id'] = null;
        $r['best_match_name'] = null;
        $r['best_match_score'] = null;
    }
}
unset($r);
echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Internal server error','detail'=>substr($e->getMessage(),0,200)]);
}
