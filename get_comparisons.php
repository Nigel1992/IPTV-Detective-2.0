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
$has_md5 = in_array('md5', $available, true);
$has_channels_col = in_array('channels', $available, true);
$has_groups_col = in_array('groups', $available, true);
$selectCols = ['id','name','link','price','hash'];
if ($has_md5) $selectCols[] = 'md5';
if ($has_channels_col) $selectCols[] = 'channels';
if ($has_groups_col) $selectCols[] = 'groups';
$stmt = $pdo->prepare('SELECT ' . implode(',', array_unique($selectCols)) . ' FROM providers WHERE id = ?');
$stmt->execute([$pid]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) { http_response_code(404); echo json_encode(['error'=>'Provider not found']); exit; }
// fallback to numeric channels column
if (isset($target['channels'])) {
    $target_count = intval($target['channels']);
} else {
    $target_count = 0;
}
// also collect target groups
if (isset($target['groups'])) {
    $target_groups_count = intval($target['groups']);
} else {
    $target_groups_count = 0;
}
// fetch exact MD5 matches first
$matches = [];
if (!empty($target['md5'])) {
    // build inner select depending on available columns
    $innerCols = ['id','name','link','price'];
    if ($has_channel) $innerCols[] = 'channel_fingerprint';
    if ($has_group) $innerCols[] = 'group_fingerprint';
    $stmt = $pdo->prepare('SELECT ' . implode(',', $innerCols) . ' FROM providers WHERE id != ? AND is_public = 1 AND `md5` = ?');
    $stmt->execute([$pid, $target['md5']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        // cap shared channels list to 50 for response (if channel_fingerprint available)
        $other_list = (isset($r['channel_fingerprint']) && $r['channel_fingerprint']) ? explode('|', $r['channel_fingerprint']) : [];
        $raw_shared = array_slice(array_values(array_intersect($target_list, $other_list)), 0, 50);
        // sanitize URLs: remove userinfo and redact sensitive query params
        $sensitive_pattern = '/(user|username|pass|password|token|auth|session|sig|key|pwd)/i';
        $shared_channels = [];
        foreach ($raw_shared as $u) {
            $clean = '[redacted]';
            $parts = @parse_url($u);
            if ($parts !== false && isset($parts['host'])) {
                $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
                $host = $parts['host'];
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $path = $parts['path'] ?? '';
                $qs = '';
                if (isset($parts['query'])) {
                    parse_str($parts['query'], $qarr);
                    foreach ($qarr as $k => $v) {
                        if (preg_match($sensitive_pattern, $k) || preg_match('/[:@]/', (string)$v)) {
                            $qarr[$k] = '[REDACTED]';
                        }
                    }
                    $qs = '?' . http_build_query($qarr);
                }
                $clean = $scheme . $host . $port . $path . $qs;
            }
            $shared_channels[] = $clean;
        }
        $shared = count($shared_channels);

        // groups: compute overlap counts (cap to 50 for reporting)
        $other_groups = (isset($r['group_fingerprint']) && $r['group_fingerprint']) ? explode('|', $r['group_fingerprint']) : [];
        $raw_shared_groups = array_slice(array_values(array_intersect($target_groups_list, $other_groups)), 0, 50);
        $shared_groups_count = count($raw_shared_groups);

        $price_diff = floatval($r['price']) - floatval($target['price']);
        $matches[] = [
            'id' => $r['id'],
            'name' => $r['name'] ?? null,
            'link' => sanitize_url($r['link'] ?? ''),
            'price' => floatval($r['price']),
            'similarity' => 100.0,
            'shared' => $shared,
            'shared_groups' => $shared_groups_count,
            // indicate everything matches (don't list totals)
            'channels_match' => true,
            'groups_match' => true,
            'channels_match_text' => 'Same amount of channels',
            'groups_match_text' => 'Same amount of groups',
            'price_diff' => round($price_diff, 2),
            'cheaper' => $price_diff < 0,
            'shared_channels' => $shared_channels
        ];
    }
}

// Now find similar (non-exact) candidates based on count closeness
if ($target_count > 0 || $target_groups_count > 0) {
    // fetch a reasonable candidate set (exclude exact md5 matches) — limit to recent 1000 for performance
    // build candidate select dynamically (avoid selecting missing columns)
    $candCols = ['id','name','link','price'];
    if ($has_channels_col) $candCols[] = 'channels';
    if ($has_groups_col) $candCols[] = 'groups';
    $stmt = $pdo->prepare('SELECT ' . implode(',', $candCols) . ' FROM providers WHERE id != ? AND is_public = 1 LIMIT 1000');
    $stmt->execute([$pid]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($candidates as $r) {
        // skip exact md5 matches already included
        if (!empty($target['md5']) && isset($r['md5']) && $r['md5'] === $target['md5']) continue;
        $other_count_raw = isset($r['channels']) ? intval($r['channels']) : 0;
        $other_groups_raw = isset($r['groups']) ? intval($r['groups']) : 0;
        // count-based similarity
        $chan_count_sim = ($target_count || $other_count_raw) ? (1.0 - abs($target_count - $other_count_raw) / max(1, max($target_count, $other_count_raw))) * 100.0 : 0;
        $group_count_sim = ($target_groups_count || $other_groups_raw) ? (1.0 - abs($target_groups_count - $other_groups_raw) / max(1, max($target_groups_count, $other_groups_raw))) * 100.0 : 0;
        // weights: channels and groups dominate, md5 is a soft factor
        $wChan = 0.45; $wGroup = 0.45; $wMd5 = 0.10;
        $md5_score = (isset($target['md5']) && isset($r['md5']) && $target['md5'] && $r['md5'] && $target['md5'] === $r['md5']) ? 100.0 : 0.0;
        $overall = ($chan_count_sim * $wChan) + ($group_count_sim * $wGroup) + ($md5_score * $wMd5);
        // ignore trivial similarities (threshold 10%) and exact matches already handled
        if ($overall < 10.0) continue;
        $price_diff = floatval($r['price']) - floatval($target['price']);
        $simRounded = round($overall,2);
        $isGrouped = $simRounded >= 80.0;
        // determine whether counts are exactly equal
        $channels_all_equal = ($target_count > 0 && $other_count_raw == $target_count);
        $groups_all_equal = ($target_groups_count > 0 && $other_groups_raw == $target_groups_count);
        $chan_count_pct = ($target_count > 0 || $other_count_raw > 0) ? floor((1.0 - abs($target_count - $other_count_raw) / max(1, max($target_count, $other_count_raw))) * 100 * 100) / 100 : 0;
        $group_count_pct = ($target_groups_count > 0 || $other_groups_raw > 0) ? floor((1.0 - abs($target_groups_count - $other_groups_raw) / max(1, max($target_groups_count, $other_groups_raw))) * 100 * 100) / 100 : 0;
        $matches[] = [
            'id' => $r['id'],
            'name' => $r['name'],
            'link' => sanitize_url($r['link'] ?? ''),
            'price' => floatval($r['price']),
            'similarity' => $simRounded,
            'grouped' => $isGrouped ? 1 : 0,
            'shared' => 0,
            'shared_groups' => 0,
            'channels_match' => $channels_all_equal,
            'groups_match' => $groups_all_equal,
            'channels_match_text' => $channels_all_equal ? 'Same amount of channels' : ($chan_count_pct . '% amount of channels match'),
            'groups_match_text' => $groups_all_equal ? 'Same amount of groups' : ($group_count_pct . '% amount of groups match'),
            'price_diff' => round($price_diff, 2),
            'cheaper' => $price_diff < 0,
            'shared_channels' => []
        ];
    }
}

// sort by similarity desc then by shared desc
usort($matches, function($a,$b){ if ($b['similarity'] <=> $a['similarity']) return $b['similarity'] <=> $a['similarity']; return $b['shared'] <=> $a['shared']; });
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
    'target' => ['id'=>$target['id'],'name'=>$target['name'],'price'=>floatval($target['price']),'link'=>sanitize_url($target['link'] ?? ''),'md5'=>($target['md5'] ?? null)],
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
