<?php
// Disabled root proxy - moved to `inc/proxy.php`
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Proxy disabled. Use inc/proxy.php instead.']);
exit;
?>