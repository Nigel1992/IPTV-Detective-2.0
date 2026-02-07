<?php
// progress_status.php?snapshot_id=123
// Prevent all caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');
$sid = isset($_GET['snapshot_id']) ? preg_replace('/[^0-9]/','',$_GET['snapshot_id']) : '';
if (!preg_match('/^[0-9]+$/', $sid)) { http_response_code(400); echo json_encode(['error'=>'Invalid snapshot_id']); exit; }
$file = __DIR__ . '/progress_' . $sid . '.log';
if (!is_readable($file)) { echo json_encode(['done'=>true]); exit; }
$txt = @file_get_contents($file);
$txt = is_string($txt) ? substr($txt, 0, 2000) : '';
echo json_encode(['done'=>false, 'msg'=>$txt]);
