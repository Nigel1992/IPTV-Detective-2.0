<?php
function fetch_url($url, $timeout = 60) {
    $logfile = __DIR__ . '/../fetch_debug.log';
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 IPTV-Detective/1.0';
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: $ua\r\n"
            ]
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            @file_put_contents($logfile, date('c') . " file_get_contents fail for $url\n", FILE_APPEND);
        }
        return $res;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_HEADER, true); // fetch headers too

    // Optional: progress callback for future use
    /*
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $dl_total, $dl_now) {
        if ($dl_total > 0) {
            echo "<script>if(window.parent)window.parent.postMessage({progress:" . ($dl_now/$dl_total*100) . "},'*');</script>\n";
            flush();
        }
    });
    */
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    $http_code = isset($info['http_code']) ? $info['http_code'] : 0;
    $header_size = isset($info['header_size']) ? $info['header_size'] : 0;
    $body = $header_size ? substr($response, $header_size) : $response;
    if ($response === false || $http_code >= 400) {
        $msg = date('c') . " cURL fail for $url\nHTTP code: $http_code\nError: $err\n";
        if ($response !== false) {
            $msg .= "Headers: " . substr($response, 0, $header_size) . "\n";
        }
        @file_put_contents($logfile, $msg, FILE_APPEND);
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $body;
}

// Sanitize URLs by removing userinfo and redacting sensitive query params
function sanitize_url($u) {
    $sensitive_pattern = '/(user|username|pass|password|token|auth|session|sig|key|pwd)/i';
    // default to empty string so we don't expose '[redacted]' literals in UI
    $clean = '';
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
                // blank-out sensitive query values instead of inserting a visible placeholder
                if (preg_match($sensitive_pattern, $k) || preg_match('/[:@]/', (string)$v)) {
                    $qarr[$k] = '';
                }
            }
            // remove empty query params to keep URL tidy
            $qarr = array_filter($qarr, function($v){ return $v !== '' && $v !== null; });
            if (!empty($qarr)) {
                $qs = '?' . http_build_query($qarr);
            }
        }
        $clean = $scheme . $host . $port . $path . $qs;
    }
    return $clean;
}

// M3U parsing removed; uploads/files are no longer supported by the UI.

// Count items in fields that may be numeric, JSON arrays, or compact object lists
function count_field_items($v) {
    if ($v === null || $v === '') return 0;
    if (is_int($v) || is_float($v)) return intval($v);
    if (is_numeric($v)) return intval($v);
    $s = trim((string)$v);
    // try JSON decode first
    $d = @json_decode($s, true);
    if (is_array($d)) return count($d);
    // common compact JSON fragments: '}{' or '},{' indicate multiple objects
    if (strpos($s, '},{') !== false) return substr_count($s, '},{') + 1;
    if (strpos($s, '}{') !== false) return substr_count($s, '}{') + 1;
    // comma-separated simple lists fallback
    if (strpos($s, ',') !== false) {
        $parts = array_filter(array_map('trim', explode(',', $s)), function($x){ return $x !== ''; });
        return count($parts);
    }
    // otherwise assume single item
    return 1;
}
?>