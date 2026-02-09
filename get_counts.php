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
        $matched_providers = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i+1; $j < $n; $j++) {
                // Check exact matches on all available fields
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
