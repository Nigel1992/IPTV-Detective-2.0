<?php
// progress_status.php?snapshot_id=123
// Prevent all caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');
$sid = filter_input(INPUT_GET, 'snapshot_id', FILTER_SANITIZE_NUMBER_INT);
if (!$sid || !preg_match('/^[0-9]+$/', $sid)) { http_response_code(400); echo json_encode(['error'=>'Invalid snapshot_id']); exit; }
// Construct expected file path and ensure it's inside this directory
$file = __DIR__ . '/progress_' . $sid . '.log';
$expected_dir = realpath(__DIR__);
$real = realpath($file);
if ($real === false || strpos($real, $expected_dir) !== 0) { echo json_encode(['error'=>'File not accessible']); exit; }
if (!is_readable($real)) { echo json_encode(['done'=>true]); exit; }
// Read up to 2000 bytes safely and strip control characters
$txt = '';
$fh = @fopen($real, 'rb');
if ($fh) {
    $txt = stream_get_contents($fh, 2000);
    fclose($fh);
}
$txt = is_string($txt) ? htmlspecialchars(preg_replace('/[\x00-\x1F\x7F]/u', '', $txt), ENT_QUOTES, 'UTF-8') : '';
// Return base64-encoded message to avoid any accidental XSS when consumed
$enc = base64_encode($txt);
$payload = ['done' => false, 'msg' => (string)$enc, 'encoding' => 'base64'];
// Use JSON_HEX_* flags to ensure safe representation when embedded in HTML contexts
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
