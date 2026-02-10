<?php
// inc/proxy.php - moved from project root to `inc/` for better isolation
// Same functionality as previous proxy.php; see project root for original version (disabled)

ini_set('display_errors', 0);
error_reporting(0);

function is_private_ip($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long >= ip2long('10.0.0.0') && $long <= ip2long('10.255.255.255')) return true;
        if ($long >= ip2long('172.16.0.0') && $long <= ip2long('172.31.255.255')) return true;
        if ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255')) return true;
        if ($long >= ip2long('127.0.0.0') && $long <= ip2long('127.255.255.255')) return true;
        if ($long >= ip2long('169.254.0.0') && $long <= ip2long('169.254.255.255')) return true;
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if ($ip === '::1') return true;
        if (stripos($ip, 'fc') === 0 || stripos($ip, 'fd') === 0) return true;
    }
    return false;
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if (!$url) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing url parameter']);
    exit;
}

$parts = parse_url($url);
if (!$parts || !isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http','https'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid URL scheme']);
    exit;
}
if (!isset($parts['host'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid URL host']);
    exit;
}

$ips = @dns_get_record($parts['host'], DNS_A + DNS_AAAA);
if ($ips === false || count($ips) === 0) {
    $hosts = @gethostbynamel($parts['host']);
    $ips = [];
    if (is_array($hosts)) {
        foreach ($hosts as $h) $ips[] = ['ip' => $h];
    }
}
if (empty($ips)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unable to resolve host']);
    exit;
}
foreach ($ips as $rec) {
    $ip = $rec['ip'] ?? $rec['ipv6'] ?? null;
    if (!$ip) continue;
    if (is_private_ip($ip)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Access to private/internal IPs is forbidden']);
        exit;
    }
}

$countOnly = isset($_GET['count_only']) && $_GET['count_only'] == '1';
$debugSnippet = isset($_GET['debug_snippet']) && $_GET['debug_snippet'] == '1';
// If caller requests full response, allow streaming up to hard caps (be cautious)
$streamFull = isset($_GET['full']) && $_GET['full'] == '1';

// Configurable and safe limits (hard caps)
$HARD_TIMEOUT_CAP = 120; // seconds
$HARD_MAX_BYTES_CAP = 100 * 1024 * 1024; // 100 MB hard cap
$DEFAULT_TIMEOUT = 30; // seconds
$DEFAULT_CONNECT_TIMEOUT = 10; // seconds
$DEFAULT_COUNT_MAX_BYTES = 5 * 1024 * 1024; // 5 MB for count mode
$DEFAULT_FULL_MAX_BYTES = 20 * 1024 * 1024; // 20 MB for non-count mode

// Allow client to request overrides via query params (validated and capped)
$reqTimeout = isset($_GET['timeout']) ? intval($_GET['timeout']) : null; // seconds
$reqMaxMb = isset($_GET['max_mb']) ? intval($_GET['max_mb']) : null; // megabytes
$reqMaxBytes = isset($_GET['max_bytes']) ? intval($_GET['max_bytes']) : null; // bytes

$timeout = ($reqTimeout && $reqTimeout > 0) ? min($reqTimeout, $HARD_TIMEOUT_CAP) : $DEFAULT_TIMEOUT;
$connectTimeout = $DEFAULT_CONNECT_TIMEOUT;

$requestedBytes = null;
if ($reqMaxBytes && $reqMaxBytes > 0) $requestedBytes = $reqMaxBytes;
elseif ($reqMaxMb && $reqMaxMb > 0) $requestedBytes = $reqMaxMb * 1024 * 1024;

// Compute default maxBytes by mode and apply any requested override (capped)
$defaultMaxBytes = $countOnly ? $DEFAULT_COUNT_MAX_BYTES : $DEFAULT_FULL_MAX_BYTES;
if ($requestedBytes !== null) {
    $defaultMaxBytes = min($requestedBytes, $HARD_MAX_BYTES_CAP);
}
$maxBytes = min($defaultMaxBytes, $HARD_MAX_BYTES_CAP);

$ch = curl_init();
// Reconstruct a safe URL from parsed components to avoid injecting raw user input
$safe_url = $parts['scheme'] . '://' . $parts['host'];
if (isset($parts['port'])) $safe_url .= ':' . intval($parts['port']);
if (!empty($parts['path'])) $safe_url .= $parts['path'];
if (!empty($parts['query'])) $safe_url .= '?' . $parts['query'];
// Reject control characters and userinfo
// Ensure host only contains safe characters (letters, digits, hyphen, dot)
if (!preg_match('/^[A-Za-z0-9.\-]+$/', $parts['host'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid host'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    exit;
}
if (preg_match('/[\x00\x0A\x0D]/', $safe_url) || strpos($safe_url, '@') !== false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid characters in URL'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    exit;
}
if (!filter_var($safe_url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid URL'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    exit;
}
// Restrict curl to safe protocols where supported
if (defined('CURLOPT_SAFE_PROTOCOLS')) {
    curl_setopt($ch, CURLOPT_SAFE_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
}
    // nosemgrep: tainted-curl-injection
    curl_setopt($ch, CURLOPT_URL, $safe_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // we handle streaming in write func
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
curl_setopt($ch, CURLOPT_USERAGENT, 'IPTV-Detective-Proxy/1.0');

$data = '';
$received = 0;

// State for count-only streaming
$truncated = false;
$items_count = 0;
$items_sample = [];
$sampleLimit = 10;
$in_array = false;
$collecting = false;
$buf = '';
$in_string = false;
$escape = false;
$depth = 0;
$found_array = false;
$finished = false;
// buffer for debug snippet capture when requested (small, capped)
$debug_buf = '';

if ($countOnly) {
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$received, &$maxBytes, &$truncated, &$items_count, &$items_sample, $sampleLimit, &$in_array, &$collecting, &$buf, &$in_string, &$escape, &$depth, &$finished, &$debug_buf, &$debugSnippet) {
        $len = strlen($chunk);
        // capture a small raw snippet for debugging when requested (cap 4096 bytes)
        if (!empty($debugSnippet) && strlen($debug_buf) < 4096) {
            $need = 4096 - strlen($debug_buf);
            $debug_buf .= substr($chunk, 0, $need);
        }
        $received += $len;
        if ($received > $maxBytes) {
            $truncated = true;
            return 0; // abort transfer, we'll return partial counts
        }
        for ($i = 0; $i < $len; $i++) {
            $c = $chunk[$i];
            // if array not started, skip until we find '['
            if (!$in_array) {
                if (trim($c) === '') continue;
                if ($c === '[') { $in_array = true; $collecting = false; continue; }
                // if object starts, we still want to find an inner array; continue scanning
                if ($c === '{') { /* continue */; }
                continue;
            }
            if ($finished) continue;
            // Inside array parsing
            if (!$collecting) {
                if ($c === ' ' || $c === "\n" || $c === '\r' || $c === '\t' || $c === ',') {
                    // skip leading commas/whitespace
                    if ($c === ']') { $finished = true; break; }
                    continue;
                }
                // start new item
                $collecting = true;
                $buf = '';
                $in_string = false; $escape = false; $depth = 0;
            }
            // Append char to buffer and manage state
            $buf .= $c;
            if ($in_string) {
                if ($escape) { $escape = false; }
                elseif ($c === "\\") { $escape = true; }
                elseif ($c === '"') { $in_string = false; }
            } else {
                if ($c === '"') { $in_string = true; }
                elseif ($c === '{' || $c === '[') { $depth++; }
                elseif ($c === '}' || $c === ']') { if ($depth > 0) $depth--; }
                // Primitive items (strings/numbers) end when we hit a comma or closing bracket and depth==0
                if (($c === ',' || $c === ']') && $depth === 0 && !$in_string) {
                    // trim trailing comma/closing bracket from buf
                    if ($c === ',' || $c === ']') {
                        $bufTrim = rtrim($buf, ",\n\r \t]");
                    } else $bufTrim = rtrim($buf);
                    if (strlen(trim($bufTrim)) > 0) {
                        $items_count++;
                        if (count($items_sample) < $sampleLimit) {
                            $s = trim($bufTrim);
                            // try to parse JSON fragment
                            $parsed = @json_decode($s, true);
                            if ($parsed !== null) $items_sample[] = $parsed;
                            else $items_sample[] = $s;
                        }
                    }
                    $collecting = false;
                    $buf = '';
                    if ($c === ']') { $finished = true; break; }
                }
            }
        }
        return $len;
    });
} else {
    if ($streamFull) {
        // Stream directly to client while parsing headers
        $sentHeaders = false;
        $headerBuf = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$sentHeaders, &$headerBuf, &$remoteCode, &$remoteContentType) {
            $headerBuf .= $headerLine;
            $h = trim($headerLine);
            if ($h === '') return strlen($headerLine);
            if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#i', $h, $m)) {
                $remoteCode = intval($m[1]);
            }
            if (stripos($h, 'Content-Type:') === 0) {
                $remoteContentType = trim(substr($h, strlen('Content-Type:')));
                // set headers to client
                http_response_code($remoteCode);
                header('Content-Type: ' . $remoteContentType);
                $sentHeaders = true;
            }
            // continue collecting (ignore other headers)
            return strlen($headerLine);
        });
        $buffered = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$received, &$maxBytes, &$truncated, &$buffered, &$sentHeaders) {
            $len = strlen($chunk);
            $received += $len;
            if ($received > $maxBytes) {
                $truncated = true;
                return 0; // abort
            }
            if (!$sentHeaders) {
                $buffered .= $chunk;
            } else {
                // flush any buffered data first
                if ($buffered !== '') {
                    echo $buffered; flush();
                    $buffered = '';
                }
                echo $chunk; flush();
            }
            return $len;
        });
    } else {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$data, &$received, $maxBytes, &$truncated) {
            $len = strlen($chunk);
            $received += $len;
            if ($received > $maxBytes) {
                $truncated = true;
                return 0;
            }
            $data .= $chunk;
            return $len;
        });
    }
}

curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 200;
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';

curl_close($ch);

// If transfer was intentionally truncated due to limits, prefer returning a structured truncated response
if ($truncated) {
    // Include a short snippet (base64) for debugging while avoiding arbitrary binary in JSON
    $snippet = '';
    if (isset($data) && strlen($data) > 0) {
        $snippet = base64_encode(substr($data, 0, 4096));
    }
    // @file_put_contents(__DIR__ . '/proxy_debug.log', date('c') . " truncated fetch: url={$url} bytes={$received} max={$maxBytes} code={$code}\n", FILE_APPEND);
    header('Content-Type: application/json');
    if ($countOnly) {
        echo json_encode(['count' => $items_count, 'sample' => $items_sample, 'truncated' => true, 'snippet_b64' => $snippet]);
    } else {
        echo json_encode(['error' => 'Upstream response too large', 'truncated' => true, 'snippet_b64' => $snippet, 'content_type' => $content_type]);
    }
    exit;
}

// If an error occurred and it wasn't due to intentional truncation, return an error
if ($err) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Upstream fetch failed', 'detail' => $err]);
    exit;
}

if ($countOnly) {
    // completed fully
    header('Content-Type: application/json');
    $out = ['count' => $items_count, 'sample' => $items_sample, 'truncated' => false];
    if ($debugSnippet && isset($debug_buf) && strlen($debug_buf) > 0) {
        $out['snippet_b64'] = base64_encode(substr($debug_buf, 0, 4096));
        // also write a short log for quick server-side inspection
        @file_put_contents(__DIR__ . '/proxy_debug.log', date('c') . " debug_snippet url=" . preg_replace('/[\r\n]+/', '', (isset($url)?$url:'')) . " bytes=" . strlen($debug_buf) . "\n", FILE_APPEND);
    }
    echo json_encode($out);
    exit;
}

http_response_code($code);
header('Content-Type: ' . $content_type);
echo $data;
exit;
?>