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
    // common compact JSON fragments: detect multiple objects separated by commas
    // handle separators with optional whitespace/newlines like '}, {' or '},\n{'
    if (preg_match_all('/}\s*,\s*{/', $s, $matches) && !empty($matches[0])) {
        return count($matches[0]) + 1;
    }
    // fallback to simple literal checks
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

// Inject a floating Discord button into HTML pages (non-invasive)
// Only activate when the client accepts HTML to avoid breaking API/JSON responses
if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false) {
    if (!defined('DISCORD_FAB_BUFFER_STARTED')) {
        define('DISCORD_FAB_BUFFER_STARTED', 1);
        ob_start(function($buf) {
            // Skip injection for admin pages or logged-in admins
            try {
                if (php_sapi_name() !== 'cli') {
                    if (session_status() === PHP_SESSION_NONE) {
                        @session_start();
                    }
                    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
                    if (!empty($_SESSION['admin_user']) || stripos($script, 'admin') !== false) {
                        // do not inject FAB on admin pages
                    } else {
                        $invite = 'https://discord.gg/zxUq3afdn8';
                        $fab = <<<HTML

<!-- Discord Floating Button (injected) -->
<style>
/* Simple, unobtrusive FAB */
.discord-fab{position:fixed;right:18px;bottom:18px;z-index:99999}
.discord-fab button{background:#5865F2;color:#fff;border:0;border-radius:50%;width:56px;height:56px;box-shadow:0 6px 18px rgba(88,101,242,.28);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:24px}
.discord-fab button:active{transform:scale(.98)}
.discord-fab .label{position:absolute;right:72px;bottom:22px;background:rgba(0,0,0,0.75);color:#fff;padding:6px 10px;border-radius:6px;font-size:13px;white-space:nowrap;opacity:0;transition:opacity .18s ease}
.discord-fab:hover .label{opacity:1}
.discord-fab .pulse{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:56px;height:56px;border-radius:50%;box-shadow:0 0 0 0 rgba(88,101,242,0.6);animation:dfab-pulse 2s infinite}
@keyframes dfab-pulse{0%{box-shadow:0 0 0 0 rgba(88,101,242,0.35)}70%{box-shadow:0 0 0 18px rgba(88,101,242,0)}100%{box-shadow:0 0 0 0 rgba(88,101,242,0)}}
</style>
<div class="discord-fab" aria-hidden="false">
  <div class="pulse" aria-hidden="true"></div>
  <a href="$invite" target="_blank" rel="noopener noreferrer" title="Join our Discord to discuss and report issues">
    <button aria-label="Join Discord" type="button">
      <!-- Discord glyph -->
      <svg width="22" height="22" viewBox="0 0 245 240" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#ffffff" d="M104.4 104.4c0 6.5-5.3 11.8-11.8 11.8s-11.8-5.3-11.8-11.8 5.3-11.8 11.8-11.8 11.8 5.3 11.8 11.8zm63 0c0 6.5-5.3 11.8-11.8 11.8s-11.8-5.3-11.8-11.8 5.3-11.8 11.8-11.8 11.8 5.3 11.8 11.8z"/><path fill="#ffffff" d="M189.5 20H55.5C43.4 20 33.8 29.6 33.8 41.7v119.3c0 12.1 9.6 21.7 21.7 21.7h114.3l-5.4-18.7 13.1 11.9 12.3 11.4 21.9 19.9V41.7C211.2 29.6 201.6 20 189.5 20z"/></svg>
    </button>
  </a>
  <div class="label">Join our Discord — report issues & chat</div>
</div>

<script>
// Accessibility: allow keyboard focus and Esc to close (opens new tab by link)
document.addEventListener('keydown', function(e){ if(e.key==="Escape"){ var el=document.querySelector('.discord-fab'); if(el) el.style.display='none'; }});
</script>
<!-- End Discord FAB -->
HTML;

                        // Inject before closing </body> if present and output appears to be HTML
                        if (stripos($buf, '</body>') !== false) {
                            $buf = str_ireplace('</body>', $fab . "</body>", $buf);
                        } else {
                            $buf .= $fab;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // fail silently
            }
            return $buf;
        });
    }
}

?>