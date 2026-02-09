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
    // detect available columns
    $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
    $stmtCols->execute([$cfg['dbname']]);
    $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

    // select providers with available fields
    $cols = ['id','name','link','price','live_categories','live_streams','series','series_categories','vod_categories','seller_source','seller_info'];
    $cols = array_filter($cols, function($c) use ($available){ return in_array($c, $available, true); });
    $sql = 'SELECT ' . implode(',', array_unique($cols)) . ' FROM providers WHERE is_public = 1 ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // build helper arrays for field values
    $n = count($providers);
    $adj = array_fill(0, $n, []);
    $live_categories_values = [];
    $live_streams_values = [];
    $series_values = [];
    $series_categories_values = [];
    $vod_categories_values = [];
    for ($i = 0; $i < $n; $i++) {
        $p = $providers[$i];
        $live_categories_values[$i] = $p['live_categories'] ?? '';
        $live_streams_values[$i] = $p['live_streams'] ?? '';
        $series_values[$i] = $p['series'] ?? '';
        $series_categories_values[$i] = $p['series_categories'] ?? '';
        $vod_categories_values[$i] = $p['vod_categories'] ?? '';
    }

    // exact matching function
    $threshold = 100.0; // Exact matches only
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i+1; $j < $n; $j++) {
            // Check exact matches on all available fields
            $exact_match = true;
            $field_matches = [
                ($live_categories_values[$i] === $live_categories_values[$j] && !empty($live_categories_values[$i])),
                ($live_streams_values[$i] === $live_streams_values[$j] && !empty($live_streams_values[$i])),
                ($series_values[$i] === $series_values[$j] && !empty($series_values[$i])),
                ($series_categories_values[$i] === $series_categories_values[$j] && !empty($series_categories_values[$i])),
                ($vod_categories_values[$i] === $vod_categories_values[$j] && !empty($vod_categories_values[$i]))
            ];
            
            // All available fields must match exactly
            $available_fields = 0;
            $matching_fields = 0;
            foreach ($field_matches as $matches) {
                if ($matches !== false) { // field is available (not empty)
                    $available_fields++;
                    if ($matches) {
                        $matching_fields++;
                    }
                }
            }
            
            // If all available fields match, it's 100%, otherwise 0%
            $overall = ($available_fields > 0 && $matching_fields == $available_fields) ? 100.0 : 0.0;

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
            $members = array_map(function($idx) use ($providers){ $p = $providers[$idx]; return ['id'=>$p['id'],'name'=>$p['name'],'price'=>floatval($p['price']),'link'=>$p['link'],'seller_source'=>($p['seller_source'] ?? null),'seller_info'=>($p['seller_info'] ?? null)]; }, $comp);
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
