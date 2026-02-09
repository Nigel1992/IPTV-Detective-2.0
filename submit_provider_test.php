<?php
// submit_provider_test.php - Test-only endpoint for `test_local_submit.php`
// Does NOT touch the database. Echoes back received fields and validates the single-use skip token.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', 0);
error_reporting(0);

// Catch uncaught exceptions and return JSON
set_exception_handler(function($e){
    http_response_code(500);
    // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " submit_test exception: " . substr($e->getMessage(),0,500) . "\n", FILE_APPEND);
    echo json_encode(['error' => 'Exception', 'detail' => substr($e->getMessage(), 0, 300)]);
    exit;
});

// Catch fatal errors on shutdown and return JSON
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " submit_test fatal: " . ($err['message'] ?? '') . "\n", FILE_APPEND);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Fatal error', 'detail' => substr($err['message'] ?? '', 0, 300)]);
        exit;
    }
});

session_start();

try {
    $post = $_POST;
    $skip_token = $post['skip_token'] ?? '';
    $skip_ok = false;
    if (!empty($skip_token) && !empty($_SESSION['test_skip_token']) && hash_equals($_SESSION['test_skip_token'], $skip_token)) {
        $skip_ok = true;
        unset($_SESSION['test_skip_token']);
        // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " submit_test: skip_token accepted\n", FILE_APPEND);
    } else {
        if (!empty($skip_token)) {
            // @file_put_contents(__DIR__ . '/submit_debug.log', date('c') . " submit_test: skip_token rejected\n", FILE_APPEND);
        }
    }

    // Basic validation (no DB)
    $name = trim($post['name'] ?? '');
    $link = trim($post['link'] ?? '');
    $price = floatval($post['price'] ?? 0);

    if (!$name || !$link || !($price > 0)) {
        // Return JSON error but with 200 status so client can always parse a JSON response
        echo htmlentities(json_encode(['ok' => false, 'error' => 'Missing required fields or invalid price', 'received' => ['name'=>$name,'link'=>$link,'price'=>$price]], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        exit;
    }

    $response = [
        'ok' => true,
        'message' => 'Test submit accepted (no DB).',
        'skip_token_accepted' => $skip_ok,
        'received' => [
            'name' => $name,
            'link' => $link,
            'price' => $price,
            'counts' => [
                'channel_count' => intval($post['channel_count'] ?? 0),
                'group_count' => intval($post['group_count'] ?? 0)
            ]
        ]
    ];
    // Optionally include first 2000 chars of any provided M3U for debugging (avoid huge payloads)
    if (!empty($post['m3u'])) {
        $m = $post['m3u'];
        $response['received']['m3u_preview'] = substr($m, 0, 2000);
        $response['received']['m3u_len'] = strlen($m);
    }

    echo htmlentities(json_encode($response, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Exception','detail'=>substr($e->getMessage(),0,300)]);
    exit;
}
