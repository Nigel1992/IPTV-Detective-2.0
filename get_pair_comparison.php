<?php
// get_pair_comparison.php?id_a=ID_A&id_b=ID_B
ini_set('display_errors', 0);
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
header('Content-Type: application/json');
try {
    $ida = isset($_GET['id_a']) ? intval($_GET['id_a']) : 0;
    $idb = isset($_GET['id_b']) ? intval($_GET['id_b']) : 0;
    if (!$ida || !$idb) { http_response_code(400); echo json_encode(['error'=>'Missing ids']); exit; }
    $pdo = get_db();

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
    if (in_array('channels', $available, true)) $selectCols[] = 'channels';
    if (in_array('groups', $available, true)) $selectCols[] = 'groups';
    if ($has_live_categories) $selectCols[] = 'live_categories';
    if ($has_live_streams) $selectCols[] = 'live_streams';
    if ($has_series) $selectCols[] = 'series';
    if ($has_series_categories) $selectCols[] = 'series_categories';
    if ($has_vod_categories) $selectCols[] = 'vod_categories';

    $stmt = $pdo->prepare('SELECT ' . implode(',', array_unique($selectCols)) . ' FROM providers WHERE id IN (?,?)');
    $stmt->execute([$ida, $idb]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 2) { http_response_code(404); echo json_encode(['error'=>'Provider(s) not found']); exit; }
    // order rows by ida, idb
    $a = ($rows[0]['id'] == $ida) ? $rows[0] : $rows[1];
    $b = ($rows[0]['id'] == $idb) ? $rows[0] : $rows[1];

    // Prepare metrics
    $metricCols = ['live_streams'=>0.30,'live_categories'=>0.20,'series'=>0.20,'series_categories'=>0.15,'vod_categories'=>0.05];
    $availableMetrics = [];
    foreach ($metricCols as $col => $w) {
        if (in_array($col, $selectCols, true)) $availableMetrics[$col] = $w;
    }
    $useLegacy = empty($availableMetrics);

    $metrics = [];
    $overall = 0.0;
    if ($useLegacy) {
        $countA = intval($a['channels'] ?? 0);
        $countB = intval($b['channels'] ?? 0);
        $gA = intval($a['groups'] ?? 0);
        $gB = intval($b['groups'] ?? 0);
        $chan_sim = ($countA || $countB) ? (1.0 - abs($countA - $countB) / max(1, max($countA, $countB))) * 100.0 : 0.0;
        $group_sim = ($gA || $gB) ? (1.0 - abs($gA - $gB) / max(1, max($gA, $gB))) * 100.0 : 0.0;
        $overall = ($chan_sim * 0.45) + ($group_sim * 0.45);
        $metrics[] = ['field'=>'Channels','a_val'=>$countA,'b_val'=>$countB,'percentage'=>round($chan_sim,2)];
        $metrics[] = ['field'=>'Groups','a_val'=>$gA,'b_val'=>$gB,'percentage'=>round($group_sim,2)];
    } else {
        $weightSum = array_sum($availableMetrics);
        $metricScore = 0.0;
        foreach ($availableMetrics as $col => $baseW) {
            $valA = intval($a[$col] ?? 0);
            $valB = intval($b[$col] ?? 0);
            $sim = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
            $normW = ($weightSum > 0) ? ($baseW / $weightSum) : 0;
            $metricScore += $sim * $normW;
            $metrics[] = ['field'=>ucwords(str_replace('_',' ',$col)),'a_val'=>$valA,'b_val'=>$valB,'percentage'=>round($sim,2)];
        }
        $overall = $metricScore;
    }

    $out = [
        'a' => ['id'=>intval($a['id']),'name'=>$a['name'] ?? null,'price'=>floatval($a['price'] ?? 0),'link'=>sanitize_url($a['link'] ?? '')],
        'b' => ['id'=>intval($b['id']),'name'=>$b['name'] ?? null,'price'=>floatval($b['price'] ?? 0),'link'=>sanitize_url($b['link'] ?? '')],
        'metrics' => $metrics,
        'overall' => round($overall,2)
    ];
    echo json_encode($out);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Internal server error','detail'=>substr($e->getMessage(),0,200)]);
    exit;
}
