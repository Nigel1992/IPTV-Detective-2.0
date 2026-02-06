<?php
// get_grouped_matches.php - Return provider groups where similarity >= 80%
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
    $cfg = include __DIR__ . '/inc/config.php';
    // detect available columns
    $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
    $stmtCols->execute([$cfg['dbname']]);
    $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

    // select providers
    $cols = ['id','name','link','price','channels','groups','md5'];
    $cols = array_filter($cols, function($c) use ($available){ return in_array($c, $available, true); });
    $sql = 'SELECT ' . implode(',', array_unique($cols)) . ' FROM providers WHERE is_public = 1 ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // build helper arrays
    $n = count($providers);
    $adj = array_fill(0, $n, []);
    // Precompute counts
    $counts = [];
    $groups_counts = [];
    for ($i = 0; $i < $n; $i++) {
        $p = $providers[$i];
        $counts[$i] = intval($p['channels'] ?? 0);
        $groups_counts[$i] = intval($p['groups'] ?? 0);
    }

    // similarity function
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
            $md5_sim = (!empty($providers[$i]['md5']) && !empty($providers[$j]['md5']) && $providers[$i]['md5'] === $providers[$j]['md5']) ? 100.0 : 0.0;
            $overall = ($chan_sim * 0.45) + ($group_sim * 0.45) + ($md5_sim * 0.1);
            // if overall >= threshold, connect
            if ($overall >= $threshold) {
                $adj[$i][] = $j;
                $adj[$j][] = $i;
            }
        }
    }

    // find connected components (groups)
    $visited = array_fill(0, $n, false);
    $groups_out = [];
    for ($i = 0; $i < $n; $i++) {
        if ($visited[$i]) continue;
        // BFS
        $stack = [$i]; $comp = [];
        while (!empty($stack)) {
            $u = array_pop($stack);
            if ($visited[$u]) continue;
            $visited[$u] = true;
            $comp[] = $u;
            foreach ($adj[$u] as $v) {
                if (!$visited[$v]) $stack[] = $v;
            }
        }
        if (count($comp) >= 2) {
            // build group info
            $members = array_map(function($idx) use ($providers){ $p = $providers[$idx]; return ['id'=>$p['id'],'name'=>$p['name'],'price'=>floatval($p['price']),'link'=>$p['link']]; }, $comp);
            // sort members by id to build stable group id
            $ids_for_hash = array_map(function($m){ return (string)$m['id']; }, $members);
            sort($ids_for_hash, SORT_STRING);
            $group_id = substr(md5(implode(',', $ids_for_hash)),0,12);
            // cheapest
            usort($members, function($a,$b){ return $a['price'] <=> $b['price']; });
            $cheapest = $members[0];
            $group_label = implode(', ', array_map(function($m){ return $m['name']; }, array_slice($members,0,6)));
            $groups_out[] = ['group_id'=>$group_id,'label'=>$group_label,'count'=>count($members),'cheapest'=>$cheapest,'members'=>$members];
        }
    }

    echo json_encode(['groups'=>$groups_out]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Internal server error','detail'=>substr($e->getMessage(),0,200)]);
    exit;
}
