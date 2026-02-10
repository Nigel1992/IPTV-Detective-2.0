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

// Now find similar (non-exact) candidates based on available metrics (return only high-confidence matches)
if ($has_live_categories || $has_live_streams || $has_series || $has_series_categories || $has_vod_categories) {
    // Minimum similarity required for a candidate to appear (percent)
    $minSimilarity = 85.0;

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

    // Define metric weights (same as submit logic)
    $metricCols = ['live_streams'=>0.30,'live_categories'=>0.20,'series'=>0.20,'series_categories'=>0.15,'vod_categories'=>0.05];
    $availableMetrics = [];
    foreach ($metricCols as $col => $w) {
        if (in_array($col, $candCols, true)) $availableMetrics[$col] = $w;
    }
    $useLegacy = empty($availableMetrics);

    foreach ($candidates as $r) {
        $match_details = [];
        $similarity = 0.0;

        if ($useLegacy) {
            // legacy using channels/groups if provided in DB
            $other_count_raw = isset($r['channels']) ? intval($r['channels']) : 0;
            $other_groups_raw = isset($r['groups']) ? intval($r['groups']) : 0;
            $chan_count_sim = ($target['channels'] || $other_count_raw) ? (1.0 - abs(($target['channels'] ?? 0) - $other_count_raw) / max(1, max(($target['channels'] ?? 0), $other_count_raw))) * 100.0 : 0.0;
            $group_count_sim = ($target['groups'] || $other_groups_raw) ? (1.0 - abs(($target['groups'] ?? 0) - $other_groups_raw) / max(1, max(($target['groups'] ?? 0), $other_groups_raw))) * 100.0 : 0.0;
            $similarity = ($chan_count_sim * 0.45) + ($group_count_sim * 0.45);
            $match_details[] = ['field'=>'Channels','percentage'=>round($chan_count_sim,2)];
            $match_details[] = ['field'=>'Groups','percentage'=>round($group_count_sim,2)];
        } else {
            $metricScore = 0.0;
            $weightSum = array_sum($availableMetrics);
            foreach ($availableMetrics as $col => $baseW) {
                $valA = isset($target[$col]) ? intval($target[$col]) : 0;
                $valB = isset($r[$col]) ? intval($r[$col]) : 0;
                $sim = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
                $normW = ($weightSum > 0) ? ($baseW / $weightSum) : 0;
                $metricScore += $sim * $normW;
                $match_details[] = ['field'=>ucwords(str_replace('_',' ',$col)),'percentage'=>round($sim,2)];
            }
            $similarity = $metricScore;
        }

        // Only include candidates with similarity >= minimum threshold
        if ($similarity < $minSimilarity) continue;

        $price_diff = floatval($r['price']) - floatval($target['price']);

        $matches[] = [
            'id' => $r['id'],
            'name' => $r['name'],
            'link' => sanitize_url($r['link'] ?? ''),
            'price' => floatval($r['price']),
            'seller_source' => $r['seller_source'] ?? null,
            'seller_info' => $r['seller_info'] ?? null,
            'similarity' => round($similarity,2),
            'grouped' => ($similarity >= 100.0) ? 1 : 0,
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
