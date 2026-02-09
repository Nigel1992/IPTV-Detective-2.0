<?php
// is_admin.php - returns whether current session is an admin
header('Content-Type: application/json');
// start session if available
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// prefer session, but fall back to readable cookie (set by admin login) to help detection from index page
$admin = false;
$user = null;
if (isset($_SESSION['admin_user']) && $_SESSION['admin_user']) {
	$admin = true;
	$user = $_SESSION['admin_user'];
} elseif (!empty($_COOKIE['iptv_admin'])) {
	// best-effort: cookie is set only when admin logs in via admin.php
	$admin = true;
	$user = $_COOKIE['iptv_admin'];
}
echo json_encode(['admin' => (bool)$admin, 'user' => $user]);
