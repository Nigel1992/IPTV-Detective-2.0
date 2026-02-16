<?php
// get_matches_for_provider.php - Return similarity list for a given provider id (admin-only)
ini_set('display_errors', 0);
require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['admin_user'])) {
    http_response_code(403);
    echo json_encode(['error'=>'Admin access required']);
    exit;
}
try {
    $pdo = get_db();
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['error'=>'Invalid id']);
        exit;
    }

    // Determine available metric columns
    $desired = ['id','name','link','price','channels','groups','live_streams','live_categories','series','series_categories','vod_categories','seller_source','seller_info','is_baseline'];
    $desc = $pdo->query("DESCRIBE providers")->fetchAll(PDO::FETCH_ASSOC);
    $existingCols = array_column($desc, 'Field');
    $selectCols = array_values(array_intersect($desired, $existingCols));
    if (empty($selectCols)) $selectCols = ['id','name'];
    $select = implode(',', $selectCols);

    $stmt = $pdo->prepare("SELECT $select FROM providers ORDER BY id ASC");
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $target = null;
    foreach ($providers as $p) {
        if (intval($p['id']) === $id) { $target = $p; break; }
    }
    if ($target === null) {
        echo json_encode(['error'=>'Provider not found']);
        exit;
    }

    // Metric weights (match admin page logic)
    $metricCols = ['live_streams'=>0.30,'live_categories'=>0.20,'series'=>0.20,'series_categories'=>0.15,'vod_categories'=>0.05];
    $availableMetrics = [];
    foreach ($metricCols as $col => $w) {
        if (in_array($col, $selectCols, true)) $availableMetrics[$col] = $w;
    }
    $useLegacy = empty($availableMetrics);

    $matches = [];
    foreach ($providers as $p) {
        $pid = intval($p['id']);
        if ($pid === $id) continue;
        $similarity = 0.0;
        if ($useLegacy) {
            $countA = intval($target['channels'] ?? 0);
            $countB = intval($p['channels'] ?? 0);
            $gA = intval($target['groups'] ?? 0);
            $gB = intval($p['groups'] ?? 0);
            $chan_sim = ($countA || $countB) ? (1.0 - abs($countA - $countB) / max(1, max($countA, $countB))) * 100.0 : 0.0;
            $group_sim = ($gA || $gB) ? (1.0 - abs($gA - $gB) / max(1, max($gA, $gB))) * 100.0 : 0.0;
            $similarity = ($chan_sim * 0.45) + ($group_sim * 0.45);
        } else {
            $weightSum = array_sum($availableMetrics);
            $metricScore = 0.0;
            foreach ($availableMetrics as $col => $baseW) {
                $valA = isset($target[$col]) ? intval($target[$col]) : 0;
                $valB = isset($p[$col]) ? intval($p[$col]) : 0;
                $sim = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
                $normW = ($weightSum > 0) ? ($baseW / $weightSum) : 0;
                $metricScore += $sim * $normW;
            }
            $similarity = $metricScore;
        }

        $matches[] = [
            'id'=>$pid,
            'name'=>$p['name'] ?? '',
            'score'=>round($similarity,1),
            'is_baseline'=>!empty($p['is_baseline']) ? 1 : 0,
            'price'=>isset($p['price']) ? floatval($p['price']) : null,
            'seller_source'=>$p['seller_source'] ?? null,
            'seller_info'=>$p['seller_info'] ?? null,
            'metrics'=>array_intersect_key($p, array_flip(array_keys($availableMetrics)))
        ];
    }

    usort($matches, function($a,$b){ return ($b['score'] <=> $a['score']); });

    echo json_encode(['matches'=>$matches,'target'=>['id'=>intval($target['id']),'name'=>$target['name'] ?? '']]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Internal server error','detail'=>substr($e->getMessage(),0,200)]);
    exit;
}

