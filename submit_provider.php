<?php
// submit_provider.php - Accepts provider submission, parses M3U, checks similarity, stores in DB
// Load configuration from inc/config.php or inc/config.php.local
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
    // Last resort: fallback to built-in defaults
    $cfg = [ 'turnstile_site_key' => 'PLACEHOLDER_TURNSTILE_SITE_KEY', 'turnstile_secret' => 'PLACEHOLDER_TURNSTILE_SECRET' ];
}
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
// Disable PHP error display to avoid corrupting JSON responses and start output buffering
ini_set('display_startup_errors', '0');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
header('Content-Type: application/json');

// Re-enable CAPTCHA verification
$turnstile_response = $_POST['cf-turnstile-response'] ?? '';
$turnstile_secret = $cfg['turnstile_secret'] ?? '';
if (empty($turnstile_secret)) {
    http_response_code(400);
    echo json_encode(['error' => 'Captcha not configured on server']);
    exit;
} elseif (empty($turnstile_response)) {
    http_response_code(400);
    echo json_encode(['error' => 'Captcha verification missing']);
    exit;
} else {
    $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $postData = http_build_query([
        'secret' => $turnstile_secret,
        'response' => $turnstile_response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($verifyUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        curl_close($ch);
    } else {
        $opts = ['http' => ['method' => 'POST', 'header' => "Content-type: application/x-www-form-urlencoded\r\n", 'content' => $postData, 'timeout' => 5]];
        $context = stream_context_create($opts);
        $resp = @file_get_contents($verifyUrl, false, $context);
    }
    if ($resp === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Captcha verification network error']);
        exit;
    } else {
        $j = json_decode($resp, true);
        if (empty($j) || empty($j['success'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Captcha verification failed']);
            exit;
        }
    }
}

// Convert uncaught exceptions to JSON error response
set_exception_handler(function($e){
    http_response_code(500);
    // log exception for diagnostics (no sensitive payloads)
    // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " exception: " . preg_replace('/\s+/',' ', substr($e->getMessage(),0,1000)) . "\n", FILE_APPEND);
    if (ob_get_length()) ob_clean();
    // avoid leaking sensitive data in the exception detail
    echo json_encode(['error' => 'Exception', 'detail' => substr($e->getMessage(),0,200)]);
    exit;
});

// Capture fatal errors on shutdown and return JSON instead of HTML
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " FATAL: " . preg_replace('/\s+/',' ', $err['message']) . " in " . ($err['file'] ?? '') . " on line " . ($err['line'] ?? '') . "\n", FILE_APPEND);
        // log request context to help debugging (avoid long payloads)
        $r = [
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'get' => array_keys($_GET),
            'post' => array_keys($_POST),
            'files' => array_keys($_FILES),
        ];
        // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " request: " . json_encode($r) . "\n", FILE_APPEND);
        if (ob_get_length()) ob_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Server error (fatal)']);
        exit;
    }
});

// Log incoming request (short form) for troubleshooting when failures occur
// @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " request-start: method=" . ($_SERVER['REQUEST_METHOD'] ?? '') . " uri=" . ($_SERVER['REQUEST_URI'] ?? '') . " POST_keys=" . implode(',', array_keys($_POST)) . " FILES=" . implode(',', array_keys($_FILES)) . "\n", FILE_APPEND);


// M3U parsing removed — server expects counts provided by the client only.

$pdo = null; // DB will be attempted later; allows fallback to no-DB mode
$name = trim($_POST['name'] ?? '');
$link = trim($_POST['link'] ?? '');
$price = floatval($_POST['price'] ?? 0);

// Xtream credentials (optional to store with submission)
$xt_host = trim($_POST['xt_host'] ?? '');
$xt_port = trim($_POST['xt_port'] ?? '');
$xt_user = trim($_POST['xt_user'] ?? '');
$xt_pass = trim($_POST['xt_pass'] ?? '');
// Seller/source info
$seller_source = trim($_POST['seller_source'] ?? '');
$seller_info = trim($_POST['seller_info'] ?? '');
// Provider link
$link = trim($_POST['link'] ?? '');

$channel_count_raw = $_POST['channel_count'] ?? null;
$group_count_raw = $_POST['group_count'] ?? null;
// Require channel_count to be present (may be 0) and numeric
if ($channel_count_raw === null || $channel_count_raw === '' || !is_numeric($channel_count_raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid channel_count']);
    exit;
}
$channel_count = intval($channel_count_raw);
$group_count = ($group_count_raw === null || $group_count_raw === '') ? null : intval($group_count_raw);
// Counts provided by client
$live_categories_count = isset($_POST['live_categories_count']) ? intval($_POST['live_categories_count']) : 0;
$live_streams_count = isset($_POST['live_streams_count']) ? intval($_POST['live_streams_count']) : 0;
$series_count = isset($_POST['series_count']) ? intval($_POST['series_count']) : 0;
$series_categories_count = isset($_POST['series_categories_count']) ? intval($_POST['series_categories_count']) : 0;
$vod_categories_count = isset($_POST['vod_categories_count']) ? intval($_POST['vod_categories_count']) : 0;
// Log request summary for diagnostics
// @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " request: ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " counts_only=" . (isset($_POST['counts_only']) ? '1' : '0') . " channel_count=" . intval($channel_count) . " group_count=" . intval($group_count) . "\n", FILE_APPEND);
// Require either the full M3U text OR at minimum a channel count supplied by the client
// Require all submission fields to be present
// Allow counts-only submissions without Xtream credentials
$counts_only = isset($_POST['counts_only']) && ($_POST['counts_only'] == '1' || $_POST['counts_only'] === 'true');

// xt_port is optional; require other fields depending on counts_only
if ($counts_only) {
    $required = [
        'name' => $name,
        'price' => $price,
        'channel_count' => $channel_count,
        'seller_source' => $seller_source,
        'seller_info' => $seller_info
    ];
} else {
    $required = [
        'name' => $name,
        'price' => $price,
        'channel_count' => $channel_count,
        'xt_host' => $xt_host,
        'xt_user' => $xt_user,
        'xt_pass' => $xt_pass,
        'seller_source' => $seller_source,
        'seller_info' => $seller_info
    ];
}
foreach ($required as $k => $v) {
    if ($k === 'price') { if (!($price > 0)) { http_response_code(400); echo json_encode(['error'=>'Missing or invalid required fields']); exit; } continue; }
    if ($v === null || (is_string($v) && strlen(trim($v)) === 0)) {
        http_response_code(400);
        echo json_encode(['error'=>'Missing or invalid required fields']);
        exit;
    }
}
// If provided, xt_port must be numeric
if ($xt_port !== '' && !ctype_digit($xt_port)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid xt_port']);
    exit;
}
// Validate provider link only if provided, and always validate price server-side
if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid provider URL']);
    exit;
}
if (!($price > 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid price']);
    exit;
}
if (!($price > 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid price']);
    exit;
}
// Anti-bot site verification removed for test/development mode. Submissions no longer require the '__test' cookie.
// Parse M3U server-side only if provided
// No server-side M3U parsing. Use client-provided counts only.
$channels = [];
$groups = [];
$extra = [];
$vod_count = 0;
// Use browser-provided counts when present (preferred)
$channel_count = isset($_POST['channel_count']) ? intval($_POST['channel_count']) : count($channels);
$group_count = isset($_POST['group_count']) ? intval($_POST['group_count']) : count($groups);
// Client-side fingerprints are LOCAL only; we accept counts only
$vod_count = isset($_POST['vod_count']) ? intval($_POST['vod_count']) : $vod_count;

// Debug: log request summary (no content) to help diagnose 500s
// @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " debug: counts_only=" . (isset($_POST['counts_only']) ? '1' : '0') . " channel_count=" . intval($channel_count) . " group_count=" . intval($group_count) . " vod_count=" . intval($vod_count) . " mem=" . memory_get_usage() . "\n", FILE_APPEND);
// Sanitize link and channel URLs to avoid storing credentials/tokens
$safe_link = $link ? sanitize_url($link) : null;
$sanitized_channels = array_map(function($u){ return sanitize_url($u); }, $channels);
// Prepare channel and group lists (for potential future use)
$norm_channels = array_map(function($u){ return trim(strtolower($u)); }, $sanitized_channels);
sort($norm_channels);
$channel_list = implode('|', $norm_channels);
$norm_groups = array_map(function($g){ return trim(strtolower($g)); }, $groups);
sort($norm_groups);
$group_list = implode('|', $norm_groups);
$extra_attrs = json_encode($extra);

// Attempt to connect to DB. If unavailable, return a 'no DB' JSON response so UI can continue.
try {
    $pdo = get_db();
} catch (Throwable $e) {
    // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " DB unavailable: " . substr($e->getMessage(),0,200) . "\n", FILE_APPEND);
    $fallback = [
        'ok' => true,
        'db_inserted' => false,
        'message' => 'Submission accepted (no DB mode)',
        'groups' => count($groups),
        'vod_count' => intval($vod_count),
        'matched' => 0,
        'similarity' => 0
    ];
    echo htmlentities(json_encode($fallback, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

// Similarity / matching
$similarity = 0; // percentage 0..100
$matched = 0;
$match_name = null;
$match_price = null;
$match_id = null;
$match_link = null;
$match_price_diff = null;
$match_cheaper = null;
$match_channels_text = null;
$match_groups_text = null;
$cheapest_match = null;
try {
    // Similarity / matching
        // Only attempt if we have at least counts or groups
        if ($channel_count > 0 || $group_count > 0) {
            // detect which columns are available to avoid SQL errors
            $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
            $cfg = include __DIR__ . '/inc/config.php';
            $stmtCols->execute([$cfg['dbname']]);
            $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
            $candCols = ['id','name','link','price'];
            if (in_array('channels', $available, true)) $candCols[] = 'channels';
            if (in_array('groups', $available, true)) $candCols[] = 'groups';
            if (in_array('live_streams', $available, true)) $candCols[] = 'live_streams';
            if (in_array('live_categories', $available, true)) $candCols[] = 'live_categories';
            if (in_array('series', $available, true)) $candCols[] = 'series';
            if (in_array('series_categories', $available, true)) $candCols[] = 'series_categories';
            if (in_array('vod_categories', $available, true)) $candCols[] = 'vod_categories';
            $stmt = $pdo->prepare('SELECT ' . implode(',', $candCols) . ' FROM providers WHERE is_public = 1 LIMIT 1000');
            $stmt->execute();
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bestSim = 0.0;
            $bestRow = null;
            // Define metrics to consider (in priority). Weights sum to 1.0.
            $metricCols = ['live_streams'=>0.30,'live_categories'=>0.20,'series'=>0.20,'series_categories'=>0.15,'vod_categories'=>0.05];
            // Build list of available metrics for this DB
            $availableMetrics = [];
            foreach ($metricCols as $col => $w) {
                if (in_array($col, $available, true)) $availableMetrics[$col] = $w;
            }
            // If no modern metrics available, fall back to legacy channels/groups method
            $useLegacy = empty($availableMetrics);

            // First: check for any exact-match candidate across all available metrics (fast path)
            foreach ($candidates as $r) {
                if (!empty($r['id']) && isset($id) && intval($r['id']) === intval($id)) continue;
                $exact = true;
                if (!$useLegacy) {
                    // Check exact matches on the actual field values, not counts
                    $fieldMatches = [
                        'live_categories' => isset($live_categories) && isset($r['live_categories']) && $live_categories == $r['live_categories'],
                        'live_streams' => isset($live_streams) && isset($r['live_streams']) && $live_streams == $r['live_streams'],
                        'series' => isset($series) && isset($r['series']) && $series == $r['series'],
                        'series_categories' => isset($series_categories) && isset($r['series_categories']) && $series_categories == $r['series_categories'],
                        'vod_categories' => isset($vod_categories) && isset($r['vod_categories']) && $vod_categories == $r['vod_categories']
                    ];
                    
                    // All available fields must match exactly
                    foreach ($availableMetrics as $col => $w) {
                        if (isset($fieldMatches[$col]) && !$fieldMatches[$col]) {
                            $exact = false;
                            break;
                        }
                    }
                } else {
                    // legacy exact match for channels/groups
                    $other_count_raw = isset($r['channels']) ? intval($r['channels']) : 0;
                    $other_groups_raw = isset($r['groups']) ? intval($r['groups']) : 0;
                    if ($other_count_raw !== intval($channel_count) || $other_groups_raw !== intval($group_count)) $exact = false;
                }
                if ($exact) {
                    // exact match found - treat as 100% similarity
                    $bestSim = 100.0;
                    $bestRow = $r;
                    break;
                }
            }

            // If no exact match found, check for partial matches (but still use exact logic for our fields)
            if ($bestRow === null) {
                foreach ($candidates as $r) {
                    if (!empty($r['id']) && isset($id) && intval($r['id']) === intval($id)) continue;
                    $overall = 0.0;
                    if ($useLegacy) {
                        $other_count_raw = isset($r['channels']) ? intval($r['channels']) : 0;
                        $other_groups_raw = isset($r['groups']) ? intval($r['groups']) : 0;
                        $chan_count_sim = ($channel_count || $other_count_raw) ? (1.0 - abs($channel_count - $other_count_raw) / max(1, max($channel_count, $other_count_raw))) * 100.0 : 0.0;
                        $group_count_sim = ($group_count || $other_groups_raw) ? (1.0 - abs($group_count - $other_groups_raw) / max(1, max($group_count, $other_groups_raw))) * 100.0 : 0.0;
                        $overall = ($chan_count_sim * 0.45) + ($group_count_sim * 0.45);
                    } else {
                        // Check exact matches on the actual field values
                        $fieldMatches = [
                            'live_categories' => isset($live_categories) && isset($r['live_categories']) && $live_categories == $r['live_categories'],
                            'live_streams' => isset($live_streams) && isset($r['live_streams']) && $live_streams == $r['live_streams'],
                            'series' => isset($series) && isset($r['series']) && $series == $r['series'],
                            'series_categories' => isset($series_categories) && isset($r['series_categories']) && $series_categories == $r['series_categories'],
                            'vod_categories' => isset($vod_categories) && isset($r['vod_categories']) && $vod_categories == $r['vod_categories']
                        ];
                        
                        // Count how many available fields match
                        $matchingFields = 0;
                        $totalFields = 0;
                        foreach ($availableMetrics as $col => $w) {
                            $totalFields++;
                            if (isset($fieldMatches[$col]) && $fieldMatches[$col]) {
                                $matchingFields++;
                            }
                        }
                        
                        // If all available fields match, it's 100%, otherwise 0%
                        $overall = ($matchingFields == $totalFields && $totalFields > 0) ? 100.0 : 0.0;
                    }
                    if ($overall > $bestSim) {
                        $bestSim = $overall;
                        $bestRow = $r;
                    }
                }
            }

            $cheapestSimilar = null;
            if ($bestSim > 0) {
                $similarity = round($bestSim, 2);
                // Log per-metric debug info for the top candidate to help diagnose mismatches
                try {
                    $debugLine = sprintf("similarity-debug: best_id=%s best_name=%s similar=%.2f available_metrics=%s", ($bestRow['id'] ?? 'none'), ($bestRow['name'] ?? ''), $similarity, implode(',', array_keys($availableMetrics)) );
                    // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " " . $debugLine . "\n", FILE_APPEND);
                    // If metric columns are used, include per-metric values
                    if (!empty($availableMetrics)) {
                        foreach ($availableMetrics as $col => $w) {
                            $valA = 0; $valB = 0;
                            switch ($col) {
                                case 'live_streams': $valA = intval($live_streams_count); break;
                                case 'live_categories': $valA = intval($live_categories_count); break;
                                case 'series': $valA = intval($series_count); break;
                                case 'series_categories': $valA = intval($series_categories_count); break;
                                case 'vod_categories': $valA = intval($vod_categories_count); break;
                            }
                            $valB = isset($bestRow[$col]) ? intval($bestRow[$col]) : 0;
                            $simMetric = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
                            // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " metric: {$col} submitted={$valA} candidate={$valB} sim={$simMetric}\n", FILE_APPEND);
                        }
                    } else {
                        // legacy metrics channels/groups
                        $other_count_raw = isset($bestRow['channels']) ? intval($bestRow['channels']) : 0;
                        $other_groups_raw = isset($bestRow['groups']) ? intval($bestRow['groups']) : 0;
                        $chan_count_sim = ($channel_count || $other_count_raw) ? (1.0 - abs($channel_count - $other_count_raw) / max(1, max($channel_count, $other_count_raw))) * 100.0 : 0.0;
                        $group_count_sim = ($group_count || $other_groups_raw) ? (1.0 - abs($group_count - $other_groups_raw) / max(1, max($group_count, $other_groups_raw))) * 100.0 : 0.0;
                        // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " metric: channels submitted={$channel_count} candidate={$other_count_raw} sim={$chan_count_sim}\n", FILE_APPEND);
                        // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " metric: groups submitted={$group_count} candidate={$other_groups_raw} sim={$group_count_sim}\n", FILE_APPEND);
                    }
                } catch (Throwable $e) { /* @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " similarity-debug-ex: " . $e->getMessage() . "\n", FILE_APPEND); */ }

                if ($similarity >= 100.0 && $bestRow) {
                    $matched = 1;
                    $match_name = $bestRow['name'] ?? null;
                    $match_price = isset($bestRow['price']) ? floatval($bestRow['price']) : null;
                    $match_id = isset($bestRow['id']) ? intval($bestRow['id']) : null;
                    $match_link = isset($bestRow['link']) ? sanitize_url($bestRow['link']) : null;
                    // Provide a short matching summary
                    $match_channels_text = "Similarity: {$similarity}%";
                    $match_groups_text = '';
                    if ($match_price !== null) {
                        $match_price_diff = round(floatval($match_price) - floatval($price), 2);
                        $match_cheaper = ($match_price_diff < 0);
                    } else {
                        $match_price_diff = null;
                        $match_cheaper = null;
                    }
                }
                // find cheapest similar (>=85%)
                foreach ($candidates as $r2) {
                    // compute overall2 similar to above
                    $overall2 = 0.0;
                    if ($useLegacy) {
                        $other_count_raw = isset($r2['channels']) ? intval($r2['channels']) : 0;
                        $other_groups_raw = isset($r2['groups']) ? intval($r2['groups']) : 0;
                        $chan_count_sim = ($channel_count || $other_count_raw) ? (1.0 - abs($channel_count - $other_count_raw) / max(1, max($channel_count, $other_count_raw))) * 100.0 : 0.0;
                        $group_count_sim = ($group_count || $other_groups_raw) ? (1.0 - abs($group_count - $other_groups_raw) / max(1, max($group_count, $other_groups_raw))) * 100.0 : 0.0;
                        $overall2 = ($chan_count_sim * 0.45) + ($group_count_sim * 0.45);
                    } else {
                        $metricScore2 = 0.0;
                        foreach ($availableMetrics as $col => $baseW) {
                            $valA = 0;
                            switch ($col) {
                                case 'live_streams': $valA = intval($live_streams_count); break;
                                case 'live_categories': $valA = intval($live_categories_count); break;
                                case 'series': $valA = intval($series_count); break;
                                case 'series_categories': $valA = intval($series_categories_count); break;
                                case 'vod_categories': $valA = intval($vod_categories_count); break;
                            }
                            $valB = isset($r2[$col]) ? intval($r2[$col]) : 0;
                            $sim = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
                            $metricScore2 += $sim * $baseW;
                        }
                        $overall2 = $metricScore2;
                    }
                    if ($overall2 >= 100.0) {
                        if ($cheapestSimilar === null || (isset($r2['price']) && floatval($r2['price']) < floatval($cheapestSimilar['price']))) {
                            $cheapestSimilar = $r2;
                        }
                    }
                }
                if ($cheapestSimilar) {
                    $cheapest_match = [
                        'id' => isset($cheapestSimilar['id']) ? intval($cheapestSimilar['id']) : null,
                        'name' => $cheapestSimilar['name'] ?? null,
                        'link' => isset($cheapestSimilar['link']) ? sanitize_url($cheapestSimilar['link']) : null,
                        'price' => isset($cheapestSimilar['price']) ? floatval($cheapestSimilar['price']) : null
                    ];
                }
            }
        }
} catch (Exception $e) {
    // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " similarity error: " . $e->getMessage() . "\n", FILE_APPEND);
}

// Ensure is_public column exists and drop old country/language columns (migration-on-write)
try {
    $cfg = include __DIR__ . '/inc/config.php';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers' AND COLUMN_NAME='is_public'");
    $stmt->execute([$cfg['dbname']]);
    $has = $stmt->fetchColumn();
    if (!$has) {
        $pdo->exec("ALTER TABLE providers ADD COLUMN is_public TINYINT(1) DEFAULT 1");
    }
    // ensure md5 column exists (store full-file md5 when available)
    $stmtMd = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers' AND COLUMN_NAME='md5'");
    $stmtMd->execute([$cfg['dbname']]);
    $hasMd = $stmtMd->fetchColumn();
    if (!$hasMd) {
        $pdo->exec("ALTER TABLE providers ADD COLUMN md5 CHAR(32) DEFAULT NULL");
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " added column md5\n", FILE_APPEND);
    }
    // ensure seller_source and seller_info columns exist
    $stmtSeller = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers' AND COLUMN_NAME IN ('seller_source','seller_info')");
    $stmtSeller->execute([$cfg['dbname']]);
    $sellerCols = $stmtSeller->fetchAll(PDO::FETCH_COLUMN);
    $needAdd = [];
    if (!in_array('seller_source', $sellerCols, true)) $needAdd[] = "ADD COLUMN seller_source VARCHAR(100) DEFAULT NULL";
    if (!in_array('seller_info', $sellerCols, true)) $needAdd[] = "ADD COLUMN seller_info VARCHAR(2000) DEFAULT NULL";
    if (!empty($needAdd)) {
        $pdo->exec("ALTER TABLE providers " . implode(',', $needAdd));
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " added columns: " . implode(',', $needAdd) . "\n", FILE_APPEND);
    }
    // drop country and language if still present
    $stmt2 = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers' AND COLUMN_NAME IN ('country','language')");
    $stmt2->execute([$cfg['dbname']]);
    $cols = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($cols)) {
        $drops = array_map(function($c){ return "DROP COLUMN `$c`"; }, $cols);
        $pdo->exec("ALTER TABLE providers " . implode(',', $drops));
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " dropped columns: " . implode(',', $cols) . "\n", FILE_APPEND);
    }
        // Ensure we have a place to store the raw link separately from the public link
        $stmt3 = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers' AND COLUMN_NAME='link_raw'");
        $stmt3->execute([$cfg['dbname']]);
        $has_raw = $stmt3->fetchColumn();
        if (!$has_raw) {
            $pdo->exec("ALTER TABLE providers ADD COLUMN link_raw VARCHAR(2000) DEFAULT NULL");
            @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " added column link_raw\n", FILE_APPEND);
        }
    // Prepare is_public flag (default to public)
    $is_public = isset($_POST['is_public']) ? intval($_POST['is_public']) : 1;

    // Insert provider: store sanitized public link and keep raw link in admin-only column
    try {
        // Prepare values and prefer non-null where applicable
        // store similarity as a percent value (0..100)
        $sim_store = round($similarity, 2);

        // Candidate columns and their values (only these will be considered for insertion)
        // Always include created_at using Europe/Amsterdam timezone to keep consistent timestamps
        $created = (new DateTime('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d H:i:s');
        $colMap = [
            'name' => $name,
            'link' => $safe_link,
            'link_raw' => $link,
            'price' => $price,
            // Xtream credentials (stored for admin debugging)
            'xt_host' => $xt_host,
            'xt_port' => $xt_port,
            'xt_user' => $xt_user,
            'xt_pass' => $xt_pass,
            'extra_attrs' => $extra_attrs,
            // Counts
            'channels' => intval($channel_count),
            'groups' => intval($group_count),
            'live_categories' => intval($live_categories_count),
            'live_streams' => intval($live_streams_count),
            'series' => intval($series_count),
            'series_categories' => intval($series_categories_count),
            'vod_categories' => intval($vod_categories_count),
            'created_at' => $created,
            'matched' => intval($matched),
            'match_name' => $match_name,
            'match_price' => $match_price,
            'similarity_score' => $sim_store,
            'is_public' => intval($is_public),
            // seller info
            'seller_source' => $seller_source,
            'seller_info' => $seller_info,
        ];

        // Query which columns exist in this DB and keep only those
        $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
        $cfg = include __DIR__ . '/inc/config.php';
        $stmtCols->execute([$cfg['dbname']]);
        $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
        $availableMap = array_flip($available);

        $insertCols = [];
        $insertVals = [];
        foreach ($colMap as $col => $val) {
            if (isset($availableMap[$col])) {
                $insertCols[] = "`$col`";
                $insertVals[] = $val;
            } else {
                @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " skipped missing column: $col\n", FILE_APPEND);
            }
        }

        if (empty($insertCols)) {
            throw new Exception('No valid columns available to insert');
        }

        // Check for existing provider with same name, link, price and delete it to avoid duplicates
        $stmtDup = $pdo->prepare('SELECT id FROM providers WHERE name = ? AND link = ? AND price = ?');
        $stmtDup->execute([$name, $safe_link, $price]);
        $existing = $stmtDup->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->prepare('DELETE FROM providers WHERE id = ?')->execute([$existing['id']]);
            @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " deleted duplicate provider id=" . $existing['id'] . "\n", FILE_APPEND);
        }

        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
        $sql = 'INSERT INTO providers (' . implode(',', $insertCols) . ') VALUES (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($insertVals);

        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " insert OK: name=" . substr($name,0,80) . " columns=" . implode(',', $insertCols) . " id=" . $pdo->lastInsertId() . "\n", FILE_APPEND);
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " insert error: " . $e->getMessage() . "\n", FILE_APPEND);
        // ensure previous buffers are cleared so we return clean JSON
        if (ob_get_length()) ob_clean();
        http_response_code(500);
        $msg = strip_tags($e->getMessage());
        if (stripos($msg,'max_allowed_packet')!==false) {
            echo htmlentities(json_encode(['error' => 'DB insert failed: packet too large (max_allowed_packet). Use counts-only or reduce playlist size'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        } else {
            echo htmlentities(json_encode(['error' => 'DB insert failed'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        }
        exit;
    }
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " migration error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
$id = $pdo->lastInsertId();

// Insert snapshot and channels if full M3U provided
if (!empty($parsed['channels'])) {
    try {
        // Check if snapshots table exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='snapshots'");
        $stmt->execute([$cfg['dbname']]);
        $has_snapshots = $stmt->fetchColumn();
        if ($has_snapshots) {
            // Insert snapshot
            $stmt = $pdo->prepare('INSERT INTO snapshots (provider_id, name, link, price, created_at, channel_count, group_count) VALUES (?, ?, ?, ?, NOW(), ?, ?)');
            $stmt->execute([$id, $name, $safe_link, $price, count($parsed['channels']), count($parsed['groups'])]);
            $snapshot_id = $pdo->lastInsertId();
            // Check if channels table exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='channels'");
            $stmt->execute([$cfg['dbname']]);
            $has_channels = $stmt->fetchColumn();
            if ($has_channels) {
                // Insert channels
                $stmt = $pdo->prepare('INSERT INTO channels (snapshot_id, name, url, group_name, logo) VALUES (?, ?, ?, ?, ?)');
                foreach ($parsed['channels'] as $ch) {
                    $stmt->execute([$snapshot_id, $ch['name'], sanitize_url($ch['url']), $ch['group_name'], $ch['logo']]);
                }
            }
        }
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " snapshot/channels insert error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// Submission history: insert only if the table exists (some installs don't have provider_submissions)
$user_id = $_POST['user_id'] ?? null;
try {
    $cfg = include __DIR__ . '/inc/config.php';
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='provider_submissions'");
    $stmtCheck->execute([$cfg['dbname']]);
    $hasTable = (bool) $stmtCheck->fetchColumn();
    if ($hasTable) {
        // Ensure columns exist in provider_submissions for seller info; add if missing
        $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='provider_submissions'");
        $stmtCols->execute([$cfg['dbname']]);
        $subCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
        $needAdd = [];
        if (!in_array('seller_source', $subCols, true)) $needAdd[] = "ADD COLUMN seller_source VARCHAR(100) DEFAULT NULL";
        if (!in_array('seller_info', $subCols, true)) $needAdd[] = "ADD COLUMN seller_info VARCHAR(2000) DEFAULT NULL";
        if (!empty($needAdd)) {
            $pdo->exec("ALTER TABLE provider_submissions " . implode(',', $needAdd));
            @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " added provider_submissions columns: " . implode(',', $needAdd) . "\n", FILE_APPEND);
            // refresh column list
            $stmtCols->execute([$cfg['dbname']]);
            $subCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
        }
        // Build insert dynamically based on available columns
        $insertCols = ['provider_id'];
        $insertVals = [$id];
        if (in_array('user_id', $subCols, true)) { $insertCols[] = 'user_id'; $insertVals[] = $user_id; }
        if (in_array('seller_source', $subCols, true)) { $insertCols[] = 'seller_source'; $insertVals[] = $seller_source; }
        if (in_array('seller_info', $subCols, true)) { $insertCols[] = 'seller_info'; $insertVals[] = $seller_info; }
        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
        $sql = 'INSERT INTO provider_submissions (' . implode(',', $insertCols) . ') VALUES (' . $placeholders . ')';
        $pdo->prepare($sql)->execute($insertVals);
    } else {
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " note: provider_submissions table missing, skipping history insert\n", FILE_APPEND);
    }
} catch (Exception $e) {
    @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " provider_submissions insert error: " . $e->getMessage() . "\n", FILE_APPEND);
}

if (ob_get_length()) ob_clean();
echo htmlentities(json_encode([
    'id'=>$id,
    'db_inserted' => (!empty($id) ? true : false),
    'channels'=>$channel_count,
    'groups'=>$group_count,
    'vod_count'=>$vod_count,
    'matched'=>$matched,
    'match_id'=> $match_id,
    'match_name'=>$match_name,
    'match_link'=>$match_link,
    'match_price'=>$match_price,
    'match_price_diff'=>$match_price_diff,
    'match_cheaper'=>$match_cheaper,
    'match_channels_text'=>$match_channels_text,
    'match_groups_text'=>$match_groups_text,
    'cheapest_match' => isset($cheapest_match) ? $cheapest_match : null,
    'similarity'=>round($similarity,2)
], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
