<?php
// inc/maintenance.php - simple maintenance mode check
// If MAINTENANCE file exists in project root and the visitor is not an admin, show maintenance page and exit.
$flagFile = __DIR__ . '/../MAINTENANCE';
// Don't block admin panel or admin users. Admin panel sets cookie 'iptv_admin' after login.
$isAdminCookie = !empty($_COOKIE['iptv_admin']);
$uri = $_SERVER['REQUEST_URI'] ?? '';
// Allow admin pages (URI contains 'admin_') and AJAX/admin API endpoints if necessary
if (file_exists($flagFile) && !$isAdminCookie && stripos($uri, 'admin_') === false) {
    http_response_code(503);
    // Serve maintenance page if available, else output simple message
    $maintPath = __DIR__ . '/../maintenance.html';
    if (is_file($maintPath)) {
        echo file_get_contents($maintPath);
    } else {
        echo "<html><head><title>Maintenance</title></head><body><h1>Site under maintenance</h1><p>Please check back later.</p></body></html>";
    }
    exit;
}
