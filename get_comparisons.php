<?php
// get_comparisons.php?id=PROVIDER_ID
ini_set('display_errors', 0);
require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json');
$debugLog = __DIR__ . '/get_comparisons_debug.log';
try {
    // Log that this endpoint was invoked (helps diagnose 500s caused by early failures)
    @file_put_contents($debugLog, date('c') . " - Invoked from " . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . " REQUEST: " . json_encode($_REQUEST) . "\n", FILE_APPEND);
    $pid = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$pid) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
    $pdo = get_db();
    // helper: sanitize links before returning them
    require_once __DIR__ . '/inc/functions.php';

// fetch target provider (schema-aware: select only columns that exist)
$stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
$cfg = include __DIR__ . '/inc/config.php';
$stmtCols->execute([$cfg['dbname']]);
$available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
$has_live_categories = in_array('live_categories', $available, true);
$has_live_streams = in_array('live_streams', $available, true);
$has_series = in_array('series', $available, true);
$has_series_categories = in_array('series_categories', $available, true);
$has_vod_categories = in_array('vod_categories', $available, true);
$selectCols = ['id','name','link','price','seller_source','seller_info'];
if ($has_live_categories) $selectCols[] = 'live_categories';
if ($has_live_streams) $selectCols[] = 'live_streams';
if ($has_series) $selectCols[] = 'series';
if ($has_series_categories) $selectCols[] = 'series_categories';
if ($has_vod_categories) $selectCols[] = 'vod_categories';
$stmt = $pdo->prepare('SELECT ' . implode(',', array_unique($selectCols)) . ' FROM providers WHERE id = ?');
$stmt->execute([$pid]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) { http_response_code(404); echo json_encode(['error'=>'Provider not found']); exit; }
// fetch exact MD5 matches first
$matches = [];
// MD5 matching removed - not used anymore

// Now find similar (non-exact) candidates based on exact match of specific fields
if ($has_live_categories || $has_live_streams || $has_series || $has_series_categories || $has_vod_categories) {
    // fetch a reasonable candidate set — limit to recent 1000 for performance
    // build candidate select dynamically (avoid selecting missing columns)
    $candCols = ['id','name','link','price','seller_source','seller_info'];
    if ($has_live_categories) $candCols[] = 'live_categories';
    if ($has_live_streams) $candCols[] = 'live_streams';
    if ($has_series) $candCols[] = 'series';
    if ($has_series_categories) $candCols[] = 'series_categories';
    if ($has_vod_categories) $candCols[] = 'vod_categories';
    $stmt = $pdo->prepare('SELECT ' . implode(',', $candCols) . ' FROM providers WHERE id != ? AND is_public = 1 LIMIT 1000');
    $stmt->execute([$pid]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($candidates as $r) {
        // Check exact matches for each available field
        $matches_live_categories = !$has_live_categories || (isset($target['live_categories']) && isset($r['live_categories']) && $target['live_categories'] == $r['live_categories']);
        $matches_live_streams = !$has_live_streams || (isset($target['live_streams']) && isset($r['live_streams']) && $target['live_streams'] == $r['live_streams']);
        $matches_series = !$has_series || (isset($target['series']) && isset($r['series']) && $target['series'] == $r['series']);
        $matches_series_categories = !$has_series_categories || (isset($target['series_categories']) && isset($r['series_categories']) && $target['series_categories'] == $r['series_categories']);
        $matches_vod_categories = !$has_vod_categories || (isset($target['vod_categories']) && isset($r['vod_categories']) && $target['vod_categories'] == $r['vod_categories']);
        
        // All available fields must match exactly
        $all_match = $matches_live_categories && $matches_live_streams && $matches_series && $matches_series_categories && $matches_vod_categories;
        
        if (!$all_match) continue;
        
        $price_diff = floatval($r['price']) - floatval($target['price']);
        $simRounded = 100.0; // Exact match
        $isGrouped = true; // Since it's an exact match
        
        // Build detailed match information
        $match_details = [];
        if ($has_live_categories) {
            $match_details[] = [
                'field' => 'Live Categories',
                'matches' => $matches_live_categories,
                'percentage' => $matches_live_categories ? 100 : 0
            ];
        }
        if ($has_live_streams) {
            $match_details[] = [
                'field' => 'Live Streams',
                'matches' => $matches_live_streams,
                'percentage' => $matches_live_streams ? 100 : 0
            ];
        }
        if ($has_series) {
            $match_details[] = [
                'field' => 'Series',
                'matches' => $matches_series,
                'percentage' => $matches_series ? 100 : 0
            ];
        }
        if ($has_series_categories) {
            $match_details[] = [
                'field' => 'Series Categories',
                'matches' => $matches_series_categories,
                'percentage' => $matches_series_categories ? 100 : 0
            ];
        }
        if ($has_vod_categories) {
            $match_details[] = [
                'field' => 'VOD Categories',
                'matches' => $matches_vod_categories,
                'percentage' => $matches_vod_categories ? 100 : 0
            ];
        }
        
        $matches[] = [
            'id' => $r['id'],
            'name' => $r['name'],
            'link' => sanitize_url($r['link'] ?? ''),
            'price' => floatval($r['price']),
            'seller_source' => $r['seller_source'] ?? null,
            'seller_info' => $r['seller_info'] ?? null,
            'similarity' => $simRounded,
            'grouped' => 1,
            'shared' => 0,
            'price_diff' => round($price_diff, 2),
            'cheaper' => $price_diff < 0,
            'match_details' => $match_details
        ];
    }
}

// sort by similarity desc then by shared desc
usort($matches, function($a,$b){ if ($b['similarity'] <=> $a['similarity']) return $b['similarity'] <=> $a['similarity']; return $b['shared'] <=> $a['shared']; });
// return top 10
$matches = array_slice($matches, 0, 10);
// find best cheaper match (max savings)
$best_cheaper = null;
foreach ($matches as $m) {
    if ($m['cheaper']) {
        $savings = floatval($target['price']) - floatval($m['price']);
        if ($best_cheaper === null || $savings > $best_cheaper['savings']) {
            $best_cheaper = $m;
            $best_cheaper['savings'] = round($savings,2);
        }
    }
}

$out = [
    'target' => ['id'=>$target['id'],'name'=>$target['name'],'price'=>floatval($target['price']),'link'=>sanitize_url($target['link'] ?? '')],
    'matches'=>$matches,
    'best_cheaper'=>$best_cheaper,
    'target_is_cheapest' => $best_cheaper === null
];
@file_put_contents($debugLog, date('c') . " - Success: returning " . count($matches) . " matches for provider {$target['id']}\n", FILE_APPEND);
echo json_encode($out);
} catch (Throwable $e) {
    // log to disk for postmortem and return a limited error detail for debugging
    $msg = date('c') . " - Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\nREQUEST: " . json_encode($_GET) . "\n\n";
    @file_put_contents($debugLog, $msg, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error'=>'Internal server error','detail'=>substr($e->getMessage(),0,200)]);
    exit;
}
