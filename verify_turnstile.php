<?php
// verify_turnstile.php - lightweight server-side verification for Cloudflare Turnstile
$cfg = null;
$candidates = [ __DIR__ . '/inc/config.php', __DIR__ . '/inc/config.php.local', __DIR__ . '/inc/config.example.php' ];
foreach ($candidates as $c) { if (is_file($c)) { $cfg = include $c; break; } }
if (!is_array($cfg)) $cfg = [ 'turnstile_secret' => 'PLACEHOLDER_TURNSTILE_SECRET' ];
header('Content-Type: application/json');
$token = $_POST['cf-turnstile-response'] ?? '';
$secret = $cfg['turnstile_secret'] ?? '';
if (empty($secret)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Captcha not configured']);
    exit;
}
if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing token']);
    exit;
}
$verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
$post = http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null]);
$resp = false;
if (function_exists('curl_init')) {
    $ch = curl_init($verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $resp = curl_exec($ch);
    curl_close($ch);
} else {
    $opts = ['http'=>['method'=>'POST','header'=>'Content-type: application/x-www-form-urlencoded\r\n','content'=>$post,'timeout'=>5]];
    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($verifyUrl, false, $ctx);
}
if ($resp === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Network error']);
    exit;
}
$j = json_decode($resp, true);
if (!is_array($j) || empty($j['success'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Verification failed', 'raw' => $j]);
    exit;
}
echo json_encode(['success' => true, 'raw' => $j]);
exit;
