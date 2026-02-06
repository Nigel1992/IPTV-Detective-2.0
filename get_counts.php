<?php
// get_counts.php - Public API returning counts useful for homepage UI
header('Content-Type: application/json');
require_once __DIR__ . '/inc/db.php';
try {
    $pdo = get_db();
    $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM providers');
    $cnt = intval($stmt->fetchColumn());
    echo json_encode(['success'=>true,'providers'=>$cnt]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'DB error']);
}
