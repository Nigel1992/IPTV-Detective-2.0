<?php
// progress_status.php?snapshot_id=123
// Prevent all caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$sid = isset($_GET['snapshot_id']) ? preg_replace('/[^0-9]/','',$_GET['snapshot_id']) : '';
if (!$sid) { http_response_code(400); exit('Missing snapshot_id'); }
$file = __DIR__ . '/progress_' . $sid . '.log';
if (!file_exists($file)) { echo json_encode(['done'=>true]); exit; }
$txt = @file_get_contents($file);
echo json_encode(['done'=>false, 'msg'=>$txt]);
