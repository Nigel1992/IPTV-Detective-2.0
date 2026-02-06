<?php
// get_counts.php - Public API returning counts useful for homepage UI
header('Content-Type: application/json');
require_once __DIR__ . '/inc/db.php';
try {
    $pdo = get_db();
    $stmt = $pdo->query('SELECT COUNT(*) AS cnt_total FROM providers');
    $total = intval($stmt->fetchColumn());
    $stmt2 = $pdo->query('SELECT COUNT(*) AS cnt_public FROM providers WHERE is_public = 1');
    $public = intval($stmt2->fetchColumn());
    echo json_encode(['success'=>true,'providers_public'=>$public,'providers_total'=>$total]);
} catch (Throwable $e) {
    @file_put_contents(__DIR__ . '/get_counts_error.log', date('c') . " DB error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success'=>false,'providers_public'=>0,'providers_total'=>0,'error'=>'DB error']);
}
