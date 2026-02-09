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

$ch = curl_init();
// Reconstruct a safe URL from parsed components to avoid injecting raw user input
$safe_url = $parts['scheme'] . '://' . $parts['host'];
if (isset($parts['port'])) $safe_url .= ':' . intval($parts['port']);
if (!empty($parts['path'])) $safe_url .= $parts['path'];
if (!empty($parts['query'])) $safe_url .= '?' . $parts['query'];
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
// Load optional proxy allowlist from local config (inc/config.php)
$cfg = null;
if (is_file(__DIR__ . '/config.php')) {
    $cfg = include __DIR__ . '/config.php';
}
$allowed_hosts = $cfg['proxy_allowlist'] ?? [];
if (!empty($allowed_hosts)) {
    $host_ok = false;
    foreach ($allowed_hosts as $pattern) {
        if (strpos($pattern, '*') !== false) {
            $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
            if (preg_match($regex, $parts['host'])) { $host_ok = true; break; }
        } else {
            if (strcasecmp($pattern, $parts['host']) === 0) { $host_ok = true; break; }
        }
    }
    if (!$host_ok) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Host not allowed'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        exit;
    }
}
// Restrict curl to safe protocols where supported
if (defined('CURLOPT_SAFE_PROTOCOLS')) {
    curl_setopt($ch, CURLOPT_SAFE_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
}
curl_setopt($ch, CURLOPT_URL, $safe_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'IPTV-Detective-Proxy/1.0');
$maxBytes = 5 * 1024 * 1024; // 5MB
$data = '';
$received = 0;

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$data, &$received, $maxBytes) {
    $len = strlen($chunk);
    $received += $len;
    if ($received > $maxBytes) {
        return 0;
    }
    $data .= $chunk;
    return $len;
});

curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 200;
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';

curl_close($ch);

if ($err) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Upstream fetch failed', 'detail' => $err]);
    exit;
}

if ($received >= $maxBytes) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Upstream response too large']);
    exit;
}

http_response_code($code);
header('Content-Type: ' . $content_type);
echo $data;
exit;
?>