<?php
// submit_provider.php - Accepts provider submission, parses M3U, fingerprints, checks similarity, stores in DB
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
$cfg = include __DIR__ . '/inc/config.php';
// Disable PHP error display to avoid corrupting JSON responses and start output buffering
ini_set('display_startup_errors', '0');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
header('Content-Type: application/json');

// Verify CAPTCHA
$captcha_token = $_POST['cf-turnstile-response'] ?? '';
$captcha_valid = false;
if (!empty($captcha_token)) {
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $cfg['turnstile_secret'],
        'response' => $captcha_token
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($response, true);
    $captcha_valid = $result['success'] ?? false;
}
if (!$captcha_valid) {
    http_response_code(400);
    echo json_encode(['error' => 'CAPTCHA verification failed']);
    exit;
}
// Convert uncaught exceptions to JSON error response
set_exception_handler(function($e){
    http_response_code(500);
    // log exception for diagnostics (no sensitive payloads)
    @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " exception: " . preg_replace('/\s+/',' ', substr($e->getMessage(),0,1000)) . "\n", FILE_APPEND);
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


if (!function_exists('parse_m3u')) {
function parse_m3u($text) {
    $lines = preg_split('/\r?\n/', $text);
    $channels = [];
    $groups = [];
    $extra = [];
    $vod_count = 0;
    foreach ($lines as $i => $line) {
        if (strpos($line, '#EXTINF') === 0) {
            $group = '';
            $logo = '';
            $tvgid = '';
            if (preg_match('/group-title="([^"]+)"/', $line, $m)) $group = $m[1];
            if (preg_match('/tvg-logo="([^"]+)"/', $line, $m)) $logo = $m[1];
            if (preg_match('/tvg-id="([^"]+)"/', $line, $m)) $tvgid = $m[1];
            $groups[$group] = true;
            $extra[] = compact('group','logo','tvgid');
        } elseif ($line && $line[0] !== '#') {
            $channels[] = trim($line);
            if (stripos($line, 'vod') !== false) $vod_count++;
        }
    }
    return [
        'channels' => $channels,
        'groups' => array_keys($groups),
        'extra' => $extra,
        'vod_count' => $vod_count
    ];
}
}

function fingerprint($arr) {
    // legacy helper updated to use MD5 (kept for compatibility with older callers)
    sort($arr);
    return md5(implode('|', $arr));
}

$pdo = get_db();
$name = trim($_POST['name'] ?? '');
$link = trim($_POST['link'] ?? '');
$price = floatval($_POST['price'] ?? 0);

$m3u = $_POST['m3u'] ?? '';
$channel_count = isset($_POST['channel_count']) ? intval($_POST['channel_count']) : null;
$group_count = isset($_POST['group_count']) ? intval($_POST['group_count']) : null;
// Optional MD5 of the full file provided by client (32 hex chars)
$md5 = isset($_POST['md5']) ? preg_replace('/[^0-9a-fA-F]/','', $_POST['md5']) : null;
if ($md5 !== null && strlen($md5) !== 32) $md5 = null; // sanitize/accept only full md5 hex
// Log request summary for diagnostics
@file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " request: ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " counts_only=" . (isset($_POST['counts_only']) ? '1' : '0') . " channel_count=" . intval($channel_count) . " group_count=" . intval($group_count) . " m3u_len=" . strlen($m3u) . "\n", FILE_APPEND);
// Require either the full M3U text OR at minimum a channel count supplied by the client
if (!$name || !$link || !$price || (!$m3u && !$channel_count)) {
    http_response_code(400);
    echo json_encode(['error'=>'Missing required fields']);
    exit;
}
// Validate URL and price server-side
if (!filter_var($link, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid provider URL']);
    exit;
}
if (!($price > 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid price']);
    exit;
}
// Anti-bot: require InfinityFree JS verification cookie to be present
if (empty($_COOKIE['__test'])) {
    @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " blocked: missing __test cookie from ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n", FILE_APPEND);
    http_response_code(403);
    echo json_encode(['error' => 'Site verification required. Please reload the page in your browser to pass verification and try again.']);
    exit;
}
// Parse M3U server-side only if provided
$channels = [];
$groups = [];
$extra = [];
$vod_count = 0;
if ($m3u) {
    $parsed = parse_m3u($m3u);
    $channels = $parsed['channels'];
    $groups = $parsed['groups'];
    $extra = $parsed['extra'];
    $vod_count = $parsed['vod_count'];
}
// Use browser-provided counts when present (preferred)
$channel_count = isset($_POST['channel_count']) ? intval($_POST['channel_count']) : count($channels);
$group_count = isset($_POST['group_count']) ? intval($_POST['group_count']) : count($groups);
// Client-side fingerprints are LOCAL only; we accept counts only
$vod_count = isset($_POST['vod_count']) ? intval($_POST['vod_count']) : $vod_count;

// Debug: log request summary (no content) to help diagnose 500s
@file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " debug: counts_only=" . (isset($_POST['counts_only']) ? '1' : '0') . " channel_count=" . intval($channel_count) . " group_count=" . intval($group_count) . " m3u_len=" . strlen($m3u) . " vod_count=" . intval($vod_count) . " mem=" . memory_get_usage() . "\n", FILE_APPEND);
// Sanitize link and channel URLs to avoid storing credentials/tokens
$safe_link = sanitize_url($link);
$sanitized_channels = array_map(function($u){ return sanitize_url($u); }, $channels);
// Prepare channel and group fingerprints (canonical joined lists for comparison)
$norm_channels = array_map(function($u){ return trim(strtolower($u)); }, $sanitized_channels);
sort($norm_channels);
$channel_list = implode('|', $norm_channels);
$norm_groups = array_map(function($g){ return trim(strtolower($g)); }, $groups);
sort($norm_groups);
$group_list = implode('|', $norm_groups);
$channel_fp = $channel_list; // store list for fuzzy matching
$group_fp = $group_list;
// Use MD5 as the single canonical fingerprint when available. Prefer client-provided md5, else compute from provided M3U text (normalize line endings).
if ($md5) {
    $hash = strtolower($md5);
} elseif (!empty($m3u)) {
    // normalize CRLF -> LF for stable hashes
    $normalized_m3u = preg_replace('/\r\n?/', "\n", $m3u);
    $md5calc = strtolower(md5($normalized_m3u));
    $md5 = $md5calc; // store/send back
    $hash = $md5calc;
} else {
    // no file/MD5 available; leave $hash null so only counts-based matching may be used
    $hash = null;
}
$extra_attrs = json_encode($extra);

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
    if (!empty($hash)) {
        // Prefer exact MD5 match when we have file MD5
        $stmt = $pdo->prepare('SELECT id,name,price FROM providers WHERE md5 = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $similarity = 100.0;
            $matched = 1;
            $match_name = $row['name'];
            $match_price = $row['price'];
        }
    }

    // If not an exact MD5/counts match, compute a best-match similarity (counts-based + optional md5 boost)
    if ($similarity === 0) {
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
            if (in_array('md5', $available, true)) $candCols[] = 'md5';
            $stmt = $pdo->prepare('SELECT ' . implode(',', $candCols) . ' FROM providers WHERE is_public = 1 LIMIT 1000');
            $stmt->execute();
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bestSim = 0.0;
            $bestRow = null;
            foreach ($candidates as $r) {
                // skip self if present
                if (!empty($r['id']) && isset($id) && intval($r['id']) === intval($id)) continue;
                $other_count_raw = isset($r['channels']) ? intval($r['channels']) : 0;
                $other_groups_raw = isset($r['groups']) ? intval($r['groups']) : 0;
                $chan_count_sim = ($channel_count || $other_count_raw) ? (1.0 - abs($channel_count - $other_count_raw) / max(1, max($channel_count, $other_count_raw))) * 100.0 : 0.0;
                $group_count_sim = ($group_count || $other_groups_raw) ? (1.0 - abs($group_count - $other_groups_raw) / max(1, max($group_count, $other_groups_raw))) * 100.0 : 0.0;
                $wChan = 0.45; $wGroup = 0.45; $wMd5 = 0.10;
                $md5_score = (isset($hash) && !empty($hash) && isset($r['md5']) && $hash === $r['md5']) ? 100.0 : 0.0;
                $overall = ($chan_count_sim * $wChan) + ($group_count_sim * $wGroup) + ($md5_score * $wMd5);
                if ($overall > $bestSim) {
                    $bestSim = $overall;
                    $bestRow = $r;
                }
            }
            $cheapestSimilar = null;
            if ($bestSim > 0) {
                $similarity = round($bestSim, 2);
                // consider a strong similarity as a match (threshold 85%)
                if ($similarity >= 85.0 && $bestRow) {
                    $matched = 1;
                    $match_name = $bestRow['name'] ?? null;
                    $match_price = isset($bestRow['price']) ? floatval($bestRow['price']) : null;
                    $match_id = isset($bestRow['id']) ? intval($bestRow['id']) : null;
                    $match_link = isset($bestRow['link']) ? sanitize_url($bestRow['link']) : null;
                    // compute channel/group percent texts
                    $other_count_raw = isset($bestRow['channels']) ? intval($bestRow['channels']) : 0;
                    $other_groups_raw = isset($bestRow['groups']) ? intval($bestRow['groups']) : 0;
                    $chan_count_pct = ($channel_count > 0 || $other_count_raw > 0) ? floor((1.0 - abs($channel_count - $other_count_raw) / max(1, max($channel_count, $other_count_raw))) * 100 * 100) / 100 : 0;
                    $group_count_pct = ($group_count > 0 || $other_groups_raw > 0) ? floor((1.0 - abs($group_count - $other_groups_raw) / max(1, max($group_count, $other_groups_raw))) * 100 * 100) / 100 : 0;
                    $match_channels_text = ($channel_count > 0 && $other_count_raw == $channel_count) ? 'Same amount of channels' : ($chan_count_pct . '% amount of channels match');
                    $match_groups_text = ($group_count > 0 && $other_groups_raw == $group_count) ? 'Same amount of groups' : ($group_count_pct . '% amount of groups match');
                    // price diff (match price - submitted price)
                    if ($match_price !== null) {
                        $match_price_diff = round(floatval($match_price) - floatval($price), 2);
                        $match_cheaper = ($match_price_diff < 0);
                    } else {
                        $match_price_diff = null;
                        $match_cheaper = null;
                    }
                }
                // find the cheapest similar provider (similarity >= 85)
                foreach ($candidates as $r2) {
                    $other_count_raw = isset($r2['channels']) ? intval($r2['channels']) : 0;
                    $other_groups_raw = isset($r2['groups']) ? intval($r2['groups']) : 0;
                    $chan_count_sim = ($channel_count || $other_count_raw) ? (1.0 - abs($channel_count - $other_count_raw) / max(1, max($channel_count, $other_count_raw))) * 100.0 : 0.0;
                    $group_count_sim = ($group_count || $other_groups_raw) ? (1.0 - abs($group_count - $other_groups_raw) / max(1, max($group_count, $other_groups_raw))) * 100.0 : 0.0;
                    $md5_score = (isset($hash) && !empty($hash) && isset($r2['md5']) && $hash === $r2['md5']) ? 100.0 : 0.0;
                    $overall2 = ($chan_count_sim * 0.45) + ($group_count_sim * 0.45) + ($md5_score * 0.10);
                    if ($overall2 >= 85.0) {
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
    }
} catch (Exception $e) {
    @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " similarity error: " . $e->getMessage() . "\n", FILE_APPEND);
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
        $hash_db = $hash ?? '';
        $md5_db = $md5 ?? null;
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
            'hash' => $hash_db,
            'md5' => $md5_db,
            'channel_fingerprint' => $channel_fp,
            'group_fingerprint' => $group_fp,
            'extra_attrs' => $extra_attrs,
            'channels' => intval($channel_count),
            'groups' => intval($group_count),
            'created_at' => $created,
            'matched' => intval($matched),
            'match_name' => $match_name,
            'match_price' => $match_price,
            'similarity_score' => $sim_store,
            'is_public' => intval($is_public),
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
            echo json_encode(['error' => 'DB insert failed: packet too large (max_allowed_packet). Use counts-only or reduce playlist size']);
        } else {
            echo json_encode(['error' => 'DB insert failed']);
        }
        exit;
    }
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " migration error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
$id = $pdo->lastInsertId();

// Submission history: insert only if the table exists (some installs don't have provider_submissions)
$user_id = $_POST['user_id'] ?? null;
try {
    $cfg = include __DIR__ . '/inc/config.php';
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='provider_submissions'");
    $stmtCheck->execute([$cfg['dbname']]);
    $hasTable = (bool) $stmtCheck->fetchColumn();
    if ($hasTable) {
        $pdo->prepare('INSERT INTO provider_submissions (provider_id, user_id) VALUES (?,?)')->execute([$id, $user_id]);
    } else {
        @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " note: provider_submissions table missing, skipping history insert\n", FILE_APPEND);
    }
} catch (Exception $e) {
    @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " provider_submissions insert error: " . $e->getMessage() . "\n", FILE_APPEND);
}

if (ob_get_length()) ob_clean();
echo json_encode([
    'id'=>$id,
    'hash'=>$hash,
    'md5'=>$md5,
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
]);
