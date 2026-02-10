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
    // matches: number of providers involved in exact field matches (groups of 2+)
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
    // matches: number of providers involved in exact field matches (groups of 2+)
    try {
    // detect available columns
        $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
        $stmtCols->execute([$cfg['dbname']]);
        $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

        // select providers with available fields
        $cols = ['id','live_categories','live_streams','series','series_categories','vod_categories'];
        $cols = array_filter($cols, function($c) use ($available){ return in_array($c, $available, true); });
        $sql = 'SELECT ' . implode(',', array_unique($cols)) . ' FROM providers WHERE is_public = 1';
        $stmt4 = $pdo->prepare($sql);
        $stmt4->execute();
        $providers = $stmt4->fetchAll(PDO::FETCH_ASSOC);

        // build helper arrays for field values
        $n = count($providers);
        $adj = array_fill(0, $n, []);
        $live_categories_values = [];
        $live_streams_values = [];
        $series_values_raw = [];
        $series_categories_values_raw = [];
        $vod_categories_values_raw = [];
        $series_counts = [];
        $series_categories_counts = [];
        $vod_counts = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $providers[$i];
            $live_categories_values[$i] = $p['live_categories'] ?? '';
            $live_streams_values[$i] = $p['live_streams'] ?? '';
            $series_values_raw[$i] = $p['series'] ?? '';
            $series_categories_values_raw[$i] = $p['series_categories'] ?? '';
            $vod_categories_values_raw[$i] = $p['vod_categories'] ?? '';
            // robust counts for fallback/matching
            if (function_exists('count_field_items')) {
                $series_counts[$i] = count_field_items($series_values_raw[$i]);
                $series_categories_counts[$i] = count_field_items($series_categories_values_raw[$i]);
                $vod_counts[$i] = count_field_items($vod_categories_values_raw[$i]);
            } else {
                $series_counts[$i] = is_numeric($p['series'] ?? '') ? intval($p['series']) : 0;
                $series_categories_counts[$i] = is_numeric($p['series_categories'] ?? '') ? intval($p['series_categories']) : 0;
                $vod_counts[$i] = is_numeric($p['vod_categories'] ?? '') ? intval($p['vod_categories']) : 0;
            }
        }

        // exact matching function
        $matched_providers = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i+1; $j < $n; $j++) {
                // Check exact matches on all available fields
                // For each field prefer exact raw equality; if raw is not reliable, fall back to counts when >1
                $field_matches = [];
                // live_categories exact match
                $field_matches[] = ($live_categories_values[$i] === $live_categories_values[$j] && !empty($live_categories_values[$i]));
                // live_streams exact match
                $field_matches[] = ($live_streams_values[$i] === $live_streams_values[$j] && !empty($live_streams_values[$i]));
                // series: prefer raw equality, else if counts equal consider match
                $series_match = false;
                if (!empty($series_values_raw[$i]) && $series_values_raw[$i] === $series_values_raw[$j]) $series_match = true;
                else if ($series_counts[$i] === $series_counts[$j]) $series_match = true;
                $field_matches[] = $series_match;
                // series_categories: same tactic
                $sc_match = false;
                if (!empty($series_categories_values_raw[$i]) && $series_categories_values_raw[$i] === $series_categories_values_raw[$j]) $sc_match = true;
                else if ($series_categories_counts[$i] === $series_categories_counts[$j]) $sc_match = true;
                $field_matches[] = $sc_match;
                // vod categories
                $vod_match = false;
                if (!empty($vod_categories_values_raw[$i]) && $vod_categories_values_raw[$i] === $vod_categories_values_raw[$j]) $vod_match = true;
                else if ($vod_counts[$i] === $vod_counts[$j]) $vod_match = true;
                $field_matches[] = $vod_match;
                
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
                
                // If all available fields match, mark both providers as matched
                if ($available_fields > 0 && $matching_fields == $available_fields) {
                    $matched_providers[$providers[$i]['id']] = true;
                    $matched_providers[$providers[$j]['id']] = true;
                }
            }
        }
        
        $matches = count($matched_providers);
    } catch (Throwable $e) {
        // DB error or missing columns - fall back to 0
        $matches = 0;
    }

    echo json_encode(['success'=>true,'providers_public'=>$public,'providers_total'=>$total,'providers_recent_7'=>$recent7,'providers_matches'=>$matches]);
} catch (Throwable $e) {
    // @file_put_contents(__DIR__ . '/get_counts_error.log', date('c') . " DB error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success'=>false,'providers_public'=>0,'providers_total'=>0,'error'=>'DB error']);
}
