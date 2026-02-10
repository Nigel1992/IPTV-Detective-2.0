<?php
// admin_9f4b1a.php - Admin Dashboard (renamed for obscurity)
session_start();

// Ensure a per-session CSRF token exists for form protection
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        // fallback to openssl if random_bytes is unavailable for any reason
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// Handle logout before any output
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['iptv_admin'])) setcookie('iptv_admin', '', time()-3600, '/');
    session_destroy();
    header('Location: admin_9f4b1a.php');
    exit;
}

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
$cfg = null;
// Load configuration from inc/config.php or inc/config.php.local
$cfgCandidates = [
    __DIR__ . '/inc/config.php',
    __DIR__ . '/inc/config.php.local',
    __DIR__ . '/inc/config.example.php'
];
foreach ($cfgCandidates as $c) {
    if (is_file($c)) { 
        $cfg = include $c; 
        break;
    }
}
if (!is_array($cfg)) {
    // Last resort: fallback to built-in defaults
    $cfg = [ 'turnstile_site_key' => 'PLACEHOLDER_TURNSTILE_SITE_KEY', 'turnstile_secret' => 'PLACEHOLDER_TURNSTILE_SECRET' ];
}
$pdo = get_db();

function admin_table_exists($pdo, $dbname) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='admins'");
        $stmt->execute([$dbname]);
        return $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Validate CSRF token first
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid request (security token mismatch)';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Verify Cloudflare Turnstile response (if configured)
        if (empty($error)) {
            $turnstile_response = $_POST['cf-turnstile-response'] ?? '';
            $turnstile_secret = $cfg['turnstile_secret'] ?? '';
            if (empty($turnstile_secret)) {
                $error = 'Captcha not configured on server';
            } elseif (empty($turnstile_response)) {
                $error = 'Captcha verification missing';
            } else {
                $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
                $postData = http_build_query([
                    'secret' => $turnstile_secret,
                    'response' => $turnstile_response,
                    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $resp = false;
                if (function_exists('curl_init')) {
                    $ch = curl_init($verifyUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    $resp = curl_exec($ch);
                    curl_close($ch);
                } else {
                    $opts = ['http' => ['method' => 'POST', 'header' => "Content-type: application/x-www-form-urlencoded\r\n", 'content' => $postData, 'timeout' => 5]];
                    $context = stream_context_create($opts);
                    $resp = @file_get_contents($verifyUrl, false, $context);
                }
                if ($resp === false) {
                    $error = 'Captcha verification network error';
                } else {
                    $j = json_decode($resp, true);
                    if (empty($j) || empty($j['success'])) {
                        $error = 'Captcha verification failed';
                    }
                }
            }
        }
        $logged_in = false;
        if (admin_table_exists($pdo, $cfg['dbname'])) {
            $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && password_verify($password, $row['password_hash'])) {
                $logged_in = true;
            }
        }
        if (!$logged_in) {
            // REMOVED: Hardcoded fallback credentials for security
            // This was a temporary measure and should never be used in production
        }
        if ($logged_in) {
            // Regenerate CSRF token after successful login to avoid reuse
            try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } catch (Throwable $_) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
            $_SESSION['admin_user'] = $username;
            setcookie('iptv_admin', $username, time() + 3600 * 8, '/');
            header('Location: admin_9f4b1a.php');
            exit;
        } else {
            $error = 'Invalid credentials';
        }
    }
}

if (!isset($_SESSION['admin_user'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login - IPTV Detective</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
        <!-- Cloudflare Turnstile widget -->
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header text-center">
                            <h4>Admin Login</h4>
                        </div>
                        <div class="card-body">
                            <?php if (isset($error)): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>

                                <!-- Turnstile widget -->
                                <div class="mb-3">
                                    <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($cfg['turnstile_site_key'] ?? ''); ?>"></div>
                                </div>

                                <button type="submit" name="login" class="btn btn-primary w-100">Login</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = get_db();
$user = $_SESSION['admin_user'];

$total_providers = $pdo->query('SELECT COUNT(*) FROM providers')->fetchColumn();
$recent_submissions = $pdo->query('SELECT COUNT(*) FROM providers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
$matched_providers = 0;
// Handle maintenance toggle from admin (create/remove MAINTENANCE flag file)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_action'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $action_err = 'Invalid request (security token mismatch)';
    } else {
        $action = ($_POST['maintenance_action'] === 'enable') ? 'enable' : 'disable';
        $flagFile = __DIR__ . '/MAINTENANCE';
        if ($action === 'enable') {
            $written = @file_put_contents($flagFile, 'Maintenance enabled at ' . date('c') . " by " . ($_SESSION['admin_user'] ?? 'admin') . "\n");
            if ($written === false) {
                $action_err = 'Failed to enable maintenance mode (could not write flag file)';
            } else {
                $action_msg = 'Maintenance mode enabled';
                // Notify Discord webhook if configured
                if (!empty($cfg['discord_webhook'])) {
                    // Build a nicer Discord embed with @everyone mention
                $adminUser = ($_SESSION['admin_user'] ?? 'admin');
                $details = trim(@file_get_contents($flagFile) ?: 'No additional details provided.');
                $embed = [
                    'title' => 'IPTV Detective — Maintenance Enabled',
                    'description' => "The website is temporarily offline while we apply fixes and address issues. We will notify when the site is back online.",
                    'color' => 15158332, // red
                    'fields' => [
                        ['name' => 'Enabled by', 'value' => $adminUser, 'inline' => true],
                        ['name' => 'Time', 'value' => date('c'), 'inline' => true],
                        ['name' => 'Details', 'value' => substr($details, 0, 1024), 'inline' => false]
                    ]
                ];
                $payload = ['content' => '@everyone', 'embeds' => [$embed]];
                try {
                    $ch = curl_init($cfg['discord_webhook']);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    $resp = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                } catch (Throwable $_e) {
                    // ignore notification errors
                }
                }
            }
        } else {
            if (file_exists($flagFile) && !@unlink($flagFile)) {
                $action_err = 'Failed to disable maintenance mode (could not remove flag file)';
            } else {
                $action_msg = 'Maintenance mode disabled';
                if (!empty($cfg['discord_webhook'])) {
                    $adminUser = ($_SESSION['admin_user'] ?? 'admin');
                $embed = [
                    'title' => 'IPTV Detective — Maintenance Disabled',
                    'description' => "The website is back online. Maintenance has been completed or paused. If issues persist, please follow up.",
                    'color' => 3066993, // green
                    'fields' => [
                        ['name' => 'Disabled by', 'value' => $adminUser, 'inline' => true],
                        ['name' => 'Time', 'value' => date('c'), 'inline' => true]
                    ]
                ];
                $payload = ['content' => '@everyone', 'embeds' => [$embed]];
                try {
                    $ch = curl_init($cfg['discord_webhook']);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    $resp = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                } catch (Throwable $_e) {}
                }
            }
        }
    }
}

try {
    $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
    $cfg = include __DIR__ . '/inc/config.php';
    $stmtCols->execute([$cfg['dbname']]);
    $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
    $cols = ['id','name','link','price','channels','groups','md5'];
    $cols = array_filter($cols, function($c) use ($available){ return in_array($c, $available, true); });
    $sql = 'SELECT ' . implode(',', array_unique($cols)) . ' FROM providers WHERE is_public = 1 ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $n = count($providers);
    if ($n > 0) {
        $adj = array_fill(0, $n, []);
        $counts = [];
        $groups_counts = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $providers[$i];
            $counts[$i] = intval($p['channels'] ?? 0);
            $groups_counts[$i] = intval($p['groups'] ?? 0);
        }
        $threshold = 80.0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i+1; $j < $n; $j++) {
                $chan_sim = 0.0; $group_sim = 0.0;
                $countA = $counts[$i]; $countB = $counts[$j];
                $gAcount = $groups_counts[$i]; $gBcount = $groups_counts[$j];
                if ($countA > 0 || $countB > 0) {
                    $chan_sim = (1.0 - abs($countA - $countB) / max(1, max($countA, $countB))) * 100.0;
                }
                if ($gAcount > 0 || $gBcount > 0) {
                    $group_sim = (1.0 - abs($gAcount - $gBcount) / max(1, max($gAcount, $gBcount))) * 100.0;
                }
                $md5_sim = (!empty($providers[$i]['md5']) && !empty($providers[$j]['md5']) && $providers[$i]['md5'] === $providers[$j]['md5']) ? 100.0 : 0.0;
                $overall = ($chan_sim * 0.45) + ($group_sim * 0.45) + ($md5_sim * 0.1);
                if ($overall >= $threshold) {
                    $adj[$i][] = $j;
                    $adj[$j][] = $i;
                }
            }
        }
        $visited = array_fill(0, $n, false);
        $ids_in_groups = [];
        for ($i = 0; $i < $n; $i++) {
            if ($visited[$i]) continue;
            $stack = [$i]; $comp = [];
            while (!empty($stack)) {
                $u = array_pop($stack);
                if ($visited[$u]) continue;
                $visited[$u] = true;
                $comp[] = $u;
                foreach ($adj[$u] as $v) {
                    if (!$visited[$v]) $stack[] = $v;
                }
            }
            if (count($comp) >= 2) {
                foreach ($comp as $idx) {
                    $ids_in_groups[ $providers[$idx]['id'] ] = true;
                }
            }
        }
        $matched_providers = count($ids_in_groups);
    }
} catch (Throwable $e) {
    $stmtTbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $stmtTbl->execute([$cfg['dbname'], 'grouped_matches']);
    if ($stmtTbl->fetchColumn() > 0) {
        $matched_providers = $pdo->query('SELECT COUNT(DISTINCT provider_id) FROM grouped_matches')->fetchColumn();
    } else {
        $stmtTbl->execute([$cfg['dbname'], 'matches']);
        if ($stmtTbl->fetchColumn() > 0) {
            $matched_providers = $pdo->query('SELECT COUNT(DISTINCT provider_id) FROM matches')->fetchColumn();
        } else {
            $matched_providers = 0;
        }
    }
}
$unmatched_providers = max(0, $total_providers - $matched_providers);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['id'])) {
    $delId = intval($_POST['id']);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM providers WHERE id = ?');
        $stmt->execute([$delId]);
        try {
            $stmt2 = $pdo->prepare("DELETE c FROM channels c JOIN snapshots s ON c.snapshot_id = s.id WHERE s.provider_id = ?");
            $stmt2->execute([$delId]);
            $stmt3 = $pdo->prepare('DELETE FROM snapshots WHERE provider_id = ?');
            $stmt3->execute([$delId]);
        } catch (Exception $e) {}
        $pdo->commit();
        $action_msg = 'Provider ' . $delId . ' deleted.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $action_err = 'Delete failed: ' . $e->getMessage();
    }
    $total_providers = $pdo->query('SELECT COUNT(*) FROM providers')->fetchColumn();
    $recent_submissions = $pdo->query('SELECT COUNT(*) FROM providers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
    try {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        // Avoid using attacker-controlled host (HTTP_HOST). Use loopback and set a trusted Host header.
        $local_host = '127.0.0.1';
        $host_header = 'Host: ' . ($cfg['host'] ?? 'localhost');
        $ch = curl_init($proto . '://' . $local_host . '/get_grouped_matches.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [$host_header]);
        // forward session cookie so the admin-only endpoint can authenticate
        if (session_id()) {
            curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
        }
        $json = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($json && $http_code >= 200 && $http_code < 300) {
            $data = json_decode($json, true);
            if (isset($data['groups']) && is_array($data['groups'])) {
                $ids = [];
                foreach ($data['groups'] as $g) {
                    if (!empty($g['members']) && is_array($g['members'])) {
                        foreach ($g['members'] as $m) {
                            if (!empty($m['id'])) $ids[$m['id']] = true;
                        }
                    }
                }
                $matched_providers = count($ids);
            }
        }
    } catch (Exception $e) {
        // Ignore errors during delete operation to prevent 500 errors
        $matched_providers = 0;
    }
    $unmatched_providers = max(0, $total_providers - $matched_providers);
}

// Handle edit action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit' && !empty($_POST['id'])) {
    $editId = intval($_POST['id']);
    $fields = [
        'name' => $_POST['name'] ?? '',
        'link' => $_POST['link'] ?? '',
        'price' => $_POST['price'] ?? '',
        'seller_source' => $_POST['seller_source'] ?? '',
        'seller_info' => $_POST['seller_info'] ?? '',
        'live_categories' => $_POST['live_categories'] ?? '',
        'live_streams' => $_POST['live_streams'] ?? '',
        'series' => $_POST['series'] ?? '',
        'series_categories' => $_POST['series_categories'] ?? '',
        'vod_categories' => $_POST['vod_categories'] ?? ''
    ];
    $set = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $set[] = "$k = ?";
        $params[] = $v;
    }
    $params[] = $editId;
    $sql = "UPDATE providers SET " . implode(',', $set) . " WHERE id = ?";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $action_msg = 'Provider ' . $editId . ' updated.';
    } catch (Exception $e) {
        $action_err = 'Edit failed: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - IPTV Detective</title>
    <!-- Dark Bootswatch theme for a professional dark UI -->
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5/dist/darkly/bootstrap.min.css" rel="stylesheet" integrity="sha384-t2UKecXY6tDoQIsEiNhYTaTFWmoHgQT7MV80h9huTejPYLkdgaOHv8ssDrS3Cdcw" crossorigin="anonymous">
    <!-- Nice system font stack -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    <link href="static/style.css" rel="stylesheet">
    <style>
        :root{
            --accent:#00d9ff;
            --accent-hover:#7cf6ff;
            --muted:#9fb9c9;
            --success:#28a745;
            --warning:#ffc107;
            --danger:#dc3545;
            --info:#17a2b8;
            --dark-bg:#0b1220;
            --card-bg:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.06));
            --border-color:rgba(255,255,255,0.03);
        }
        body{
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
            background: var(--dark-bg);
            color:#d6eef5;
            min-height: 100vh;
        }
        .navbar-brand{
            display:flex;
            align-items:center;
            gap:.6rem;
            font-weight:700;
            font-size:1.25rem;
        }
        .navbar-brand img{
            height:30px;
            border-radius:6px;
            box-shadow:0 6px 18px rgba(0,0,0,0.6);
        }
        .card{
            background: var(--card-bg);
            border:1px solid var(--border-color);
            border-radius:12px;
            box-shadow:0 8px 32px rgba(0,0,0,0.12);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card:hover{
            transform: translateY(-2px);
            box-shadow:0 12px 40px rgba(0,0,0,0.18);
        }
        .card-header{
            background:transparent;
            border-bottom:1px solid var(--border-color);
            font-weight:600;
            padding:1rem 1.25rem;
            border-radius:12px 12px 0 0 !important;
        }
        .card-body{
            padding:1.25rem;
        }
        .table thead th{
            color:#bfefff;
            font-weight:600;
            border-bottom:2px solid var(--border-color);
            padding:0.75rem;
        }
        .table td, .table th{
            vertical-align:middle;
            padding:0.75rem;
        }
        .table tbody tr{
            transition: background-color 0.2s ease;
        }
        .table tbody tr:hover{
            background-color: rgba(255,255,255,0.02);
        }
        .list-group-item{
            background:transparent;
            border:1px solid var(--border-color);
            color:#d6eef5;
        }
        .btn-primary{
            background:linear-gradient(90deg,var(--accent),var(--accent-hover));
            border:none;
            color:#032935;
            font-weight:600;
            padding:0.5rem 1rem;
            border-radius:8px;
            transition: all 0.2s ease;
        }
        .btn-primary:hover{
            transform: translateY(-1px);
            box-shadow:0 6px 20px rgba(0,217,255,0.3);
        }
        .btn-outline-light{
            border-color: var(--border-color);
            color: #d6eef5;
            transition: all 0.2s ease;
        }
        .btn-outline-light:hover{
            background-color: rgba(255,255,255,0.1);
            border-color: var(--accent);
            color: var(--accent);
        }
        .navbar{
            box-shadow:0 8px 40px rgba(0,0,0,0.6);
            backdrop-filter: blur(10px);
            background: rgba(33,37,41,0.95);
        }
        /* Navbar link separators */
        .navbar .navbar-nav .nav-item + .nav-item::before {
            content: "";
            display: inline-block;
            width: 1px;
            height: 22px;
            background: rgba(255,255,255,0.06);
            margin: 0 10px;
            vertical-align: middle;
            /* small nudge to vertically center inside nav items */
            transform: translateY(0.2rem);
        }
        /* Ensure nav items and links align on a single baseline */
        .navbar .navbar-nav {
            display: flex;
            align-items: center;
        }
        .navbar .navbar-nav .nav-item{ display:flex; align-items:center; }
        .navbar .navbar-nav .nav-link{
            display:flex;
            align-items:center;
            gap: .5rem;
            padding: .55rem .65rem;
            line-height:1;
            height: 40px; /* consistent tap target */
        }
        .navbar-brand{ align-items:center; height:40px; display:flex; }
        /* Hide separators on small screens */
        @media (max-width: 576px) {
            .navbar .navbar-nav .nav-item + .nav-item::before { display: none; }
            .navbar .navbar-nav .nav-link{ height:auto; padding:.45rem .5rem; }
        }
        .container{
            max-width:1280px;
        }
        .logo-mark{
            width:36px;
            height:36px;
            border-radius:8px;
            background:linear-gradient(135deg,#00c2ff,#7cf6ff);
            display:inline-block;
            position:relative;
        }
        .logo-mark::after{
            content: '';
            position: absolute;
            top: 6px;
            left: 6px;
            width: 24px;
            height: 24px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z'/%3E%3C/svg%3E") no-repeat center;
            background-size: contain;
        }
        a{
            color:var(--accent);
            transition: color 0.2s ease;
        }
        a:hover{
            color:var(--accent-hover);
            text-decoration:underline;
        }
        /* Chart styling */
        #statusChart{
            background:transparent;
        }
        /* Stats cards */
        .stats-card{
            background: var(--card-bg);
            border:1px solid var(--border-color);
            border-radius:12px;
            padding:1.5rem;
            text-align:center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stats-card:hover{
            transform: translateY(-4px);
            box-shadow:0 12px 40px rgba(0,0,0,0.2);
        }
        .stats-number{
            font-size:2.5rem;
            font-weight:700;
            margin:0.5rem 0;
        }
        .stats-label{
            font-size:0.9rem;
            color:var(--muted);
            font-weight:500;
            text-transform:uppercase;
            letter-spacing:0.5px;
        }
        /* Navigation tabs */
        .nav-pills .nav-link{
            border-radius:8px;
            margin-right:0.5rem;
            padding:0.5rem 1rem;
            transition: all 0.2s ease;
        }
        .nav-pills .nav-link.active{
            background: var(--accent);
            color:#032935;
            font-weight:600;
        }
        .nav-pills .nav-link:not(.active){
            color:#d6eef5;
            border:1px solid var(--border-color);
        }
        .nav-pills .nav-link:not(.active):hover{
            background: rgba(255,255,255,0.05);
            border-color: var(--accent);
        }
        /* Loading animation */
        .loading-spinner{
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 50%;
            border-top-color: var(--accent);
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        /* Modal improvements */
        .modal-content{
            background: var(--dark-bg);
            border:1px solid var(--border-color);
            border-radius:12px;
        }
        .modal-header{
            border-bottom:1px solid var(--border-color);
        }
        .modal-footer{
            border-top:1px solid var(--border-color);
        }
        /* Form controls */
        .form-control, .form-select{
            background: rgba(255,255,255,0.05);
            border:1px solid var(--border-color);
            color:#d6eef5;
            border-radius:8px;
        }
        .form-control:focus, .form-select:focus{
            background: rgba(255,255,255,0.08);
            border-color: var(--accent);
            color:#d6eef5;
            box-shadow: 0 0 0 0.2rem rgba(0, 217, 255, 0.25);
        }
        /* Make native select dropdown options and Bootstrap dropdowns match dark theme */
        .form-select option,
        .form-select optgroup {
            background: var(--dark-bg);
            color: #d6eef5;
        }
        /* Boostrap dropdown menus (e.g., .dropdown-menu) */
        .dropdown-menu {
            background: rgba(11,18,32,0.95);
            border: 1px solid var(--border-color);
            color: #d6eef5;
        }
        .dropdown-item {
            color: #d6eef5;
        }
        .dropdown-item:hover, .dropdown-item:focus {
            background: rgba(0,217,255,0.08);
            color: #032935;
        }
        /* Alert improvements */
        .alert{
            border-radius:8px;
            border:1px solid transparent;
        }
        .alert-success{
            background: rgba(40, 167, 69, 0.1);
            border-color: rgba(40, 167, 69, 0.2);
            color: #d4edda;
        }
        .alert-danger{
            background: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.2);
            color: #f5c6cb;
        }
        /* Badge styling */
        .badge{
            font-size:0.75rem;
            padding:0.25rem 0.5rem;
            border-radius:6px;
        }
        /* Responsive improvements */
        @media (max-width: 768px) {
            .navbar-brand{
                font-size:1.1rem;
            }
            .stats-number{
                font-size:2rem;
            }
            .container{
                padding:0 1rem;
            }
        }
        /* Elevated visual polish */
        :root{
            --card-elev: 0 14px 40px rgba(2,6,23,0.55);
            --soft-elev: 0 8px 24px rgba(2,6,23,0.45);
            --glass: rgba(255,255,255,0.03);
        }
        /* Subtle entrance animation for cards */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card, .stats-card {
            will-change: transform, opacity;
            animation: fadeInUp 420ms ease both;
        }
        /* Stronger card shadows and interactive lift */
        .card{
            transition: transform 220ms cubic-bezier(.2,.9,.2,1), box-shadow 220ms ease;
            box-shadow: var(--card-elev);
        }
        .card:hover{ transform: translateY(-6px); box-shadow: 0 28px 80px rgba(0,0,0,0.5); }
        .stats-card{ box-shadow: var(--soft-elev); border-radius:14px; }

        /* Table row elevation on hover with subtle border accent */
        .table tbody tr{ transition: background-color 180ms ease, transform 180ms ease, box-shadow 180ms ease; }
        .table tbody tr:hover{ background: linear-gradient(90deg, rgba(255,255,255,0.01), rgba(0,0,0,0.04)); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.25); }
        .table thead th{ position: sticky; top: 0; z-index: 10; backdrop-filter: blur(6px); }

        /* Buttons: soft gradients, depth and hover states */
        .btn-primary{ box-shadow: 0 8px 20px rgba(0,217,255,0.12); }
        .btn-primary:active{ transform: translateY(1px) scale(0.997); }
        .btn-outline-light{ transition: transform 160ms ease, box-shadow 160ms ease; }
        .btn-outline-light:hover{ transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.35); }

        /* Action buttons inside table */
        .btn-group .btn{ transition: transform 140ms ease, box-shadow 140ms ease; }
        .btn-group .btn:hover{ transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.28); }

        /* Input and selects: larger tap area and inner shadow */
        .form-control, .form-select{ padding:0.6rem 0.75rem; border-radius:10px; box-shadow: inset 0 1px 0 rgba(0,0,0,0.25); }
        .form-control::placeholder{ color: rgba(214,238,245,0.45); }

        /* Badges refinement */
        .badge{ font-weight:700; border-radius:8px; padding:0.35rem 0.6rem; }
        .badge.bg-primary{ background: linear-gradient(90deg,#00c2ff,#7cf6ff); color:#032935; }
        .badge.bg-success{ background: linear-gradient(90deg,#28a745,#5cd67a); }

        /* Scrollbar styling for wide tables */
        .table-responsive::-webkit-scrollbar{ height:8px; }
        .table-responsive::-webkit-scrollbar-thumb{ background: rgba(255,255,255,0.06); border-radius:8px; }

        /* Small utilities */
        .text-muted{ opacity:0.85; }
        .link-muted a{ color:var(--muted); }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin_9f4b1a.php">
                <span class="logo-mark" aria-hidden="true"></span>
                <span>IPTV Detective</span>
                <small class="text-muted ms-2" style="font-weight:500;color:var(--muted);">Admin Panel</small>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="admin_9f4b1a.php">
                            <i class="bi bi-house-door me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="switchToTab('providers-tab')">
                            <i class="bi bi-list-ul me-1"></i>Providers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="switchToTab('groups-tab-btn')">
                            <i class="bi bi-diagram-3 me-1"></i>Groups
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="./" target="_blank">
                            <i class="bi bi-eye me-1"></i>View Site
                        </a>
                    </li>
                </ul>
                <div class="d-flex align-items-center">
                    <div class="me-3 text-end">
                        <div style="font-size:0.95rem;font-weight:600">Welcome, <?php echo htmlspecialchars($user); ?></div>
                        <div style="font-size:0.78rem;color:var(--muted)">Administrator</div>
                    </div>
                    <a href="?logout=1" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="mb-1"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
                <p class="text-muted mb-0">Monitor and manage your IPTV provider database</p>
            </div>
            <div class="text-end">
                <div style="font-size:0.9rem;font-weight:600">Last updated</div>
                <div style="font-size:0.8rem;color:var(--accent)"><?php echo date('M j, Y H:i'); ?></div>
            </div>
        </div>

        <ul class="nav nav-pills mb-4" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="overview" aria-selected="true">
                    <i class="bi bi-graph-up me-1"></i>Overview
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="providers-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="providers" aria-selected="false">
                    <i class="bi bi-list-ul me-1"></i>Providers
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="groups-tab-btn" type="button">
                    <i class="bi bi-diagram-3 me-1"></i>Matched Groups
                </button>
            </li>
        </ul>

        <div id="mainDashboard">
            <div id="overviewTab">
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stats-card h-100">
                            <div class="stats-number text-primary"><?php echo number_format($total_providers); ?></div>
                            <div class="stats-label">Total Providers</div>
                            <i class="bi bi-collection position-absolute top-50 end-0 translate-middle-y me-3 opacity-25" style="font-size:2rem;"></i>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stats-card h-100">
                            <div class="stats-number text-success"><?php echo number_format($matched_providers); ?></div>
                            <div class="stats-label">Matched Providers</div>
                            <i class="bi bi-check-circle position-absolute top-50 end-0 translate-middle-y me-3 opacity-25" style="font-size:2rem;"></i>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stats-card h-100">
                            <div class="stats-number text-warning"><?php echo number_format($recent_submissions); ?></div>
                            <div class="stats-label">Recent (7 days)</div>
                            <i class="bi bi-clock position-absolute top-50 end-0 translate-middle-y me-3 opacity-25" style="font-size:2rem;"></i>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stats-card h-100">
                            <div class="stats-number text-info"><?php echo $unmatched_providers > 0 ? number_format($unmatched_providers) : '0'; ?></div>
                            <div class="stats-label">Unmatched</div>
                            <i class="bi bi-question-circle position-absolute top-50 end-0 translate-middle-y me-3 opacity-25" style="font-size:2rem;"></i>
                        </div>
                    </div>
                </div>

                <!-- Maintenance toggle row -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex align-items-center">
                                <i class="bi bi-shield-exclamation me-2"></i>Site Maintenance Mode
                            </div>
                            <div class="card-body">
                                <?php
                                $flagFile = __DIR__ . '/MAINTENANCE';
                                $isMaint = file_exists($flagFile);
                                if (isset($action_msg)) echo '<div class="alert alert-success">' . htmlspecialchars($action_msg) . '</div>';
                                if (isset($action_err)) echo '<div class="alert alert-danger">' . htmlspecialchars($action_err) . '</div>';
                                ?>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <p class="mb-0">Status: <strong><?php echo $isMaint ? '<span class="text-danger">Enabled</span>' : '<span class="text-success">Disabled</span>'; ?></strong></p>
                                        <p class="small text-muted mb-0">When enabled, non-admin visitors will see a maintenance page. Admins can still access the dashboard.</p>
                                    </div>
                                    <div>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <?php if ($isMaint): ?>
                                                <button type="submit" name="maintenance_action" value="disable" class="btn btn-outline-light">Disable Maintenance</button>
                                            <?php else: ?>
                                                <button type="submit" name="maintenance_action" value="enable" class="btn btn-danger">Enable Maintenance</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center">
                                <i class="bi bi-pie-chart me-2"></i>
                                Provider Status Distribution
                            </div>
                            <div class="card-body">
                                <canvas id="statusChart" style="max-height:300px;"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center">
                                <i class="bi bi-activity me-2"></i>
                                Recent Activity
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php
                                    $stmt = $pdo->prepare('SELECT name, created_at FROM providers ORDER BY created_at DESC LIMIT 8');
                                    $stmt->execute();
                                    $count = 0;
                                    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) && $count < 8) {
                                        // compute server-side fallback, but expose epoch ms for client-side relative rendering
                                        $timeStr = 'N/A';
                                        $tsMs = '';
                                        if (!empty($row['created_at'])) {
                                            try {
                                                // Treat stored datetimes as UTC to avoid server timezone mismatches.
                                                $dt = new DateTime($row['created_at'], new DateTimeZone('UTC'));
                                                $ts = $dt->getTimestamp();
                                                if ($ts !== false && $ts > 0) {
                                                    $timeAgo = time() - $ts;
                                                    $timeStr = $timeAgo < 3600 ? round($timeAgo/60) . 'm ago' : 
                                                              ($timeAgo < 86400 ? round($timeAgo/3600) . 'h ago' : 
                                                              round($timeAgo/86400) . 'd ago');
                                                    $tsMs = intval($ts) * 1000;
                                                }
                                            } catch (Exception $e) {
                                                // fallback to strtotime if DateTime parsing fails
                                                $ts = @strtotime($row['created_at']);
                                                if ($ts !== false && $ts > 0) {
                                                    $timeAgo = time() - $ts;
                                                    $timeStr = $timeAgo < 3600 ? round($timeAgo/60) . 'm ago' : 
                                                              ($timeAgo < 86400 ? round($timeAgo/3600) . 'h ago' : 
                                                              round($timeAgo/86400) . 'd ago');
                                                    $tsMs = intval($ts) * 1000;
                                                }
                                            }
                                        }
                                        echo '<div class="list-group-item px-0 d-flex justify-content-between align-items-center">';
                                        echo '<div><i class="bi bi-plus-circle text-success me-2"></i><strong>' . htmlspecialchars($row['name'] ?? '') . '</strong></div>';
                                        echo '<small class="text-muted" data-ts="' . htmlspecialchars($tsMs) . '">' . htmlspecialchars($timeStr) . '</small>';
                                        echo '</div>';
                                        $count++;
                                    }
                                    if ($count === 0) {
                                        echo '<div class="text-center text-muted py-3">No recent activity</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex align-items-center">
                                <i class="bi bi-lightning me-2"></i>
                                Quick Actions
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center" onclick="switchToTab('providers-tab')">
                                            <i class="bi bi-plus-circle me-2"></i>
                                            Add Provider
                                        </button>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-success w-100 d-flex align-items-center justify-content-center" onclick="switchToTab('groups-tab-btn')">
                                            <i class="bi bi-diagram-3 me-2"></i>
                                            View Groups
                                        </button>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="./" target="_blank" class="btn btn-outline-info w-100 d-flex align-items-center justify-content-center">
                                            <i class="bi bi-eye me-2"></i>
                                            Public Site
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-warning w-100 d-flex align-items-center justify-content-center" onclick="refreshData()">
                                            <i class="bi bi-arrow-clockwise me-2"></i>
                                            Refresh Data
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Matched Groups pane (hidden until loaded) -->
    <div id="matchedGroupsPane" class="container mt-4" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="mb-1"><i class="bi bi-diagram-3 me-2"></i>Matched Groups</h1>
                <p class="text-muted mb-0">View providers grouped by similarity</p>
            </div>
            <div class="d-flex gap-2">
                <button id="groups-back" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                </button>
            </div>
        </div>
        <div id="groupsContent">
            <div class="text-center py-5">
                <div class="loading-spinner mx-auto mb-3"></div>
                <div>Loading groups&hellip;</div>
            </div>
        </div>
    </div>

    <!-- Providers pane (hidden until requested) -->
    <div id="providersPane" class="container mt-4" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="mb-1"><i class="bi bi-list-ul me-2"></i>Providers Management</h1>
                <p class="text-muted mb-0">View, edit, and manage all IPTV providers</p>
            </div>
            <div class="d-flex gap-2">
                <button id="providers-back" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                </button>
            </div>
        </div>

        <!-- Search and Filter Bar -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="searchInput" class="form-label">Search Providers</label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Search by name...">
                    </div>
                    <div class="col-md-3">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="public">Public</option>
                            <option value="private">Private</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="sortBy" class="form-label">Sort By</label>
                        <select class="form-select" id="sortBy">
                            <option value="created_at DESC">Newest First</option>
                            <option value="created_at ASC">Oldest First</option>
                            <option value="name ASC">Name A-Z</option>
                            <option value="name DESC">Name Z-A</option>
                            <option value="price ASC">Price Low-High</option>
                            <option value="price DESC">Price High-Low</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" onclick="applyFilters()">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>Providers List</span>
                <span class="badge bg-primary" id="providerCount"><?php echo $total_providers; ?> total</span>
            </div>
            <div class="card-body p-0">
                <?php if (isset($action_err)): ?>
                    <div class="alert alert-danger mx-3 mt-3"><?php echo htmlspecialchars($action_err); ?></div>
                <?php endif; ?>
                <?php if (isset($action_msg)): ?>
                    <div class="alert alert-success mx-3 mt-3"><?php echo htmlspecialchars($action_msg); ?></div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="providersTable">
                        <thead class="table-dark">
                            <tr>
                                <th><i class="bi bi-hash me-1"></i>ID</th>
                                <th><i class="bi bi-tag me-1"></i>Name</th>
                                <th><i class="bi bi-people me-1"></i>Seller</th>
                                <th><i class="bi bi-cash me-1"></i>Price</th>
                                <th><i class="bi bi-play-circle me-1"></i>Live Cats</th>
                                <th><i class="bi bi-play me-1"></i>Live Streams</th>
                                <th><i class="bi bi-film me-1"></i>Series</th>
                                <th><i class="bi bi-folder me-1"></i>Series Cats</th>
                                <th><i class="bi bi-collection-play me-1"></i>VOD Cats</th>
                                <th><i class="bi bi-calendar me-1"></i>Created</th>
                                <th><i class="bi bi-gear me-1"></i>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="providersTableBody">
                            <?php
                            $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
                            $cfg = include __DIR__ . '/inc/config.php';
                            $stmtCols->execute([$cfg['dbname']]);
                            $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
                            $selectCols = ['id','name','link','price','channels','groups','seller_source','seller_info','live_categories','live_streams','series','series_categories','vod_categories','created_at','is_public'];
                            $selectCols = array_values(array_unique($selectCols));
                            $selectCols = array_filter($selectCols, function($c) use ($available){ return in_array($c, $available, true); });
                            $sql = 'SELECT ' . implode(',', $selectCols) . ' FROM providers ORDER BY created_at DESC';
                            $stmt = $pdo->query($sql);
                            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $rowId = 'prov-' . htmlspecialchars($r['id']);
                                $statusBadge = isset($r['is_public']) && $r['is_public'] ? '<span class="badge bg-success">Public</span>' : '<span class="badge bg-secondary">Private</span>';
                                  echo '<tr id="' . $rowId . '" data-name="' . htmlspecialchars(strtolower($r['name'] ?? '')) . '" data-status="' . (isset($r['is_public']) && $r['is_public'] ? 'public' : 'private') . '">';
                                  echo '<td><code>' . htmlspecialchars($r['id']) . '</code></td>';
                                  echo '<td><strong>' . htmlspecialchars($r['name'] ?? '') . '</strong> ' . $statusBadge . '</td>';
                                  // Seller column
                                  $sellerLabel = '-';
                                  if (!empty($r['seller_source']) || !empty($r['seller_info'])) {
                                      $parts = [];
                                      if (!empty($r['seller_source'])) $parts[] = htmlspecialchars(ucwords(str_replace(['_','-'], ' ', $r['seller_source'])));
                                      if (!empty($r['seller_info'])) {
                                          $infoRaw = $r['seller_info'];
                                          $display = htmlspecialchars((strlen($infoRaw) > 60 ? substr($infoRaw, 0, 60) . '...' : $infoRaw));
                                          if (preg_match('~https?://~i', $infoRaw)) {
                                              $link = htmlspecialchars($infoRaw);
                                              $parts[] = '<a href="' . $link . '" target="_blank" rel="noopener noreferrer">' . $display . '</a>';
                                          } else {
                                              $parts[] = $display;
                                          }
                                      }
                                      $sellerLabel = '<small class="text-muted">' . implode(' — ', $parts) . '</small>';
                                  }
                                  echo '<td>' . $sellerLabel . '</td>';
                                  echo '<td>' . (isset($r['price']) && $r['price'] ? '<span class="text-success fw-bold">$' . htmlspecialchars(number_format($r['price'],2)) . '</span>' : '<span class="text-muted">-</span>') . '</td>';
                                  echo '<td>' . (isset($r['live_categories']) && $r['live_categories'] ? '<small class="text-info">' . htmlspecialchars(substr($r['live_categories'], 0, 20)) . (strlen($r['live_categories']) > 20 ? '...' : '') . '</small>' : '<span class="text-muted">-</span>') . '</td>';
                                  echo '<td>' . (isset($r['live_streams']) && $r['live_streams'] ? '<small class="text-success">' . htmlspecialchars(substr($r['live_streams'], 0, 20)) . (strlen($r['live_streams']) > 20 ? '...' : '') . '</small>' : '<span class="text-muted">-</span>') . '</td>';
                                  echo '<td>' . (isset($r['series']) && $r['series'] ? '<small class="text-warning">' . htmlspecialchars(substr($r['series'], 0, 20)) . (strlen($r['series']) > 20 ? '...' : '') . '</small>' : '<span class="text-muted">-</span>') . '</td>';
                                  echo '<td>' . (isset($r['series_categories']) && $r['series_categories'] ? '<small class="text-primary">' . htmlspecialchars(substr($r['series_categories'], 0, 20)) . (strlen($r['series_categories']) > 20 ? '...' : '') . '</small>' : '<span class="text-muted">-</span>') . '</td>';
                                  echo '<td>' . (isset($r['vod_categories']) && $r['vod_categories'] ? '<small class="text-secondary">' . htmlspecialchars(substr($r['vod_categories'], 0, 20)) . (strlen($r['vod_categories']) > 20 ? '...' : '') . '</small>' : '<span class="text-muted">-</span>') . '</td>';
                                  echo '<td><small class="text-muted">' . htmlspecialchars($r['created_at'] ?? '') . '</small></td>';
                                                                    echo '<td>';
                                                                    echo '<div class="btn-group btn-group-sm" role="group" style="gap:6px;">';
                                                                    // Prepare JS-safe values
                                                                    $js_id = json_encode($r['id']);
                                                                    $js_name = json_encode($r['name'] ?? '');
                                                                    $js_link = json_encode($r['link'] ?? '');
                                                                    $js_price = json_encode($r['price'] ?? '');
                                                                    $js_live_categories = json_encode($r['live_categories'] ?? '');
                                                                    $js_live_streams = json_encode($r['live_streams'] ?? '');
                                                                    $js_series = json_encode($r['series'] ?? '');
                                                                    $js_series_categories = json_encode($r['series_categories'] ?? '');
                                                                    $js_vod_categories = json_encode($r['vod_categories'] ?? '');
                                                                    $js_seller_source = json_encode($r['seller_source'] ?? '');
                                                                    $js_seller_info = json_encode($r['seller_info'] ?? '');
                                                                    $onclick = 'openEditModal(' . $js_id . ', ' . $js_name . ', ' . $js_link . ', ' . $js_price . ', ' . $js_live_categories . ', ' . $js_live_streams . ', ' . $js_series . ', ' . $js_series_categories . ', ' . $js_vod_categories . ', ' . $js_seller_source . ', ' . $js_seller_info . ');';
                                                                    echo '<button class="btn btn-outline-info d-flex align-items-center justify-content-center" style="width:38px;height:38px;padding:0;" onclick="' . htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8') . '" title="Edit"><i class="bi bi-pencil"></i></button>';
                                                                    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this provider?\');">';
                                                                    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">';
                                                                    echo '<input type="hidden" name="action" value="delete">';
                                                                    echo '<input type="hidden" name="id" value="' . htmlspecialchars($r['id']) . '">';
                                                                    echo '<button class="btn btn-outline-danger d-flex align-items-center justify-content-center" style="width:38px;height:38px;padding:0;" title="Delete"><i class="bi bi-trash"></i></button>';
                                                                    echo '</form>';
                                  echo '</div>';
                                  echo '</td>';
                                echo '</tr>';
                                // Link row
                                if (!empty($r['link'])) {
                                    echo '<tr><td></td><td colspan="10"><small><i class="bi bi-link-45deg me-1"></i><a href="' . htmlspecialchars($r['link']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($r['link']) . '</a></small></td></tr>';
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="editProviderModal" tabindex="-1" aria-labelledby="editProviderModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" id="editProviderForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editProviderModalLabel">
                                <i class="bi bi-pencil me-2"></i>Edit Provider
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" id="editProviderId">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editProviderName" class="form-label">
                                            <i class="bi bi-tag me-1"></i>Name
                                        </label>
                                        <input type="text" class="form-control" name="name" id="editProviderName" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editProviderPrice" class="form-label">
                                            <i class="bi bi-cash me-1"></i>Price
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" step="0.01" class="form-control" name="price" id="editProviderPrice">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editLiveCategories" class="form-label">
                                            <i class="bi bi-play-circle me-1"></i>Live Categories
                                        </label>
                                        <input type="number" class="form-control" name="live_categories" id="editLiveCategories">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editLiveStreams" class="form-label">
                                            <i class="bi bi-play me-1"></i>Live Streams
                                        </label>
                                        <input type="number" class="form-control" name="live_streams" id="editLiveStreams">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editSeries" class="form-label">
                                            <i class="bi bi-film me-1"></i>Series
                                        </label>
                                        <input type="number" class="form-control" name="series" id="editSeries">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editSeriesCategories" class="form-label">
                                            <i class="bi bi-folder me-1"></i>Series Categories
                                        </label>
                                        <input type="number" class="form-control" name="series_categories" id="editSeriesCategories">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editVodCategories" class="form-label">
                                            <i class="bi bi-collection-play me-1"></i>VOD Categories
                                        </label>
                                        <input type="number" class="form-control" name="vod_categories" id="editVodCategories">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="editProviderLink" class="form-label">
                                    <i class="bi bi-link-45deg me-1"></i>Website Link
                                </label>
                                <input type="url" class="form-control" name="link" id="editProviderLink" placeholder="https://example.com">
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editSellerSource" class="form-label"><i class="bi bi-people me-1"></i>Seller Source</label>
                                        <select class="form-select" name="seller_source" id="editSellerSource">
                                            <option value="iptv_website">IPTV Website</option>
                                            <option value="z2u">Z2U</option>
                                            <option value="g2g">G2G</option>
                                            <option value="made_in_china">Made-in-China</option>
                                            <option value="alibaba">Alibaba</option>
                                            <option value="independent_reseller">Independent Reseller</option>
                                            <option value="reddit_seller">Reddit Seller</option>
                                            <option value="discord_seller">Discord Seller</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editSellerInfo" class="form-label"><i class="bi bi-info-circle me-1"></i>Seller Info</label>
                                        <input type="text" class="form-control" name="seller_info" id="editSellerInfo" placeholder="username, profile URL, order id">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x me-1"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check me-1"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Initialize CSRF token
        if (!window.csrfToken) {
            window.csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>';
        }

        // Tab switching function
        function switchToTab(tabId) {
            if (tabId === 'providers-tab') {
                setActiveTab('providers-tab');
                document.getElementById('mainDashboard').style.display = 'none';
                document.getElementById('matchedGroupsPane').style.display = 'none';
                document.getElementById('providersPane').style.display = '';
            } else if (tabId === 'groups-tab-btn') {
                setActiveTab('groups-tab-btn');
                const main = document.getElementById('mainDashboard');
                const pane = document.getElementById('matchedGroupsPane');
                const content = document.getElementById('groupsContent');
                main.style.display = 'none';
                pane.style.display = '';
                content.innerHTML = '<div class="text-center py-5"><div class="loading-spinner mx-auto mb-3"></div><div>Loading groups&hellip;</div></div>';
                loadGroups();
            } else if (tabId === 'overview-tab') {
                setActiveTab('overview-tab');
                document.getElementById('mainDashboard').style.display = '';
                document.getElementById('matchedGroupsPane').style.display = 'none';
                document.getElementById('providersPane').style.display = 'none';
                // refresh recent activity times for user's local timezone
                if (typeof updateRecentActivityTimes === 'function') updateRecentActivityTimes();
            }
        }

        // Search and filter functionality
        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;
            const sortBy = document.getElementById('sortBy').value;

            const table = document.getElementById('providersTable');
            const tbody = document.getElementById('providersTableBody');
            // Only consider main provider rows (they have IDs like "prov-<id>") so detail/link rows are excluded
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => row.id && row.id.startsWith('prov-'));

            // Build provider/detail pairs so each provider stays attached to its following link/detail row
            const children = Array.from(tbody.children);
            const pairs = [];
            for (let i = 0; i < children.length; i++) {
                const r = children[i];
                if (r.id && r.id.startsWith('prov-')) {
                    let detail = null;
                    const nxt = children[i+1];
                    if (nxt && (!nxt.id || !nxt.id.startsWith('prov-')) && nxt.querySelector && nxt.querySelector('a')) {
                        detail = nxt;
                        i++; // skip detail in the main loop
                    }
                    pairs.push({ row: r, detail: detail });
                }
            }

            // Sort pair array according to selected sort
            if (sortBy && pairs.length) {
                const parts = sortBy.split(/\s+/);
                const field = parts[0] || 'created_at';
                const dir = (parts[1] || 'DESC').toUpperCase();

                const getPrice = (r) => {
                    const txt = (r.cells[3] && r.cells[3].textContent) ? r.cells[3].textContent : '';
                    const m = txt.match(/[-\d.,]+/);
                    return m ? (parseFloat(m[0].replace(/,/g, '')) || 0) : 0;
                };
                const getDate = (r) => {
                    const txt = (r.cells[9] && r.cells[9].textContent) ? r.cells[9].textContent.trim() : '';
                    const t = Date.parse(txt);
                    return isNaN(t) ? 0 : t;
                };

                pairs.sort((a, b) => {
                    if (field === 'name') {
                        const A = a.row.dataset.name || (a.row.cells[1] && a.row.cells[1].textContent.toLowerCase()) || '';
                        const B = b.row.dataset.name || (b.row.cells[1] && b.row.cells[1].textContent.toLowerCase()) || '';
                        return dir === 'ASC' ? A.localeCompare(B) : B.localeCompare(A);
                    }
                    if (field === 'price') {
                        const A = getPrice(a.row);
                        const B = getPrice(b.row);
                        return dir === 'ASC' ? (A - B) : (B - A);
                    }
                    const A = getDate(a.row);
                    const B = getDate(b.row);
                    return dir === 'ASC' ? (A - B) : (B - A);
                });
            }

            // Rebuild tbody with pairs in the new order (this moves nodes in the DOM)
            const frag = document.createDocumentFragment();
            pairs.forEach(p => {
                frag.appendChild(p.row);
                if (p.detail) frag.appendChild(p.detail);
            });
            // Clear and append
            while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
            tbody.appendChild(frag);

            // Apply filtering (show/hide) and count visible
            let shown = 0;
            pairs.forEach(p => {
                const row = p.row;
                const detail = p.detail;
                const name = row.dataset.name || '';
                const status = row.dataset.status || '';
                const matchesSearch = name.includes(searchTerm);
                const matchesStatus = !statusFilter || status === statusFilter;
                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                    if (detail) detail.style.display = '';
                    shown++;
                } else {
                    row.style.display = 'none';
                    if (detail) detail.style.display = 'none';
                }
            });

            // Update count
            document.getElementById('providerCount').textContent = shown + ' shown';
        }

        // Refresh data function
        function refreshData() {
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="loading-spinner me-2"></span>Refreshing...';
            btn.disabled = true;

            // Reload the page to refresh data
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }

        // Modal edit logic
        function openEditModal(id, name, link, price, live_categories, live_streams, series, series_categories, vod_categories, seller_source, seller_info) {
            // Ensure elements exist before attempting to set values
            const setVal = (sel, val) => {
                const el = document.getElementById(sel);
                if (!el) return;
                el.value = (val === null || val === undefined) ? '' : val;
            };

            setVal('editProviderId', id);
            setVal('editProviderName', name);
            setVal('editProviderLink', link);
            setVal('editSellerSource', seller_source);
            setVal('editSellerInfo', seller_info);
            setVal('editProviderPrice', price);
            setVal('editLiveCategories', live_categories);
            setVal('editLiveStreams', live_streams);
            setVal('editSeries', series);
            setVal('editSeriesCategories', series_categories);
            setVal('editVodCategories', vod_categories);

            const modalEl = document.getElementById('editProviderModal');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }

        // Chart initialization
        const ctx = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Matched', 'Unmatched'],
                datasets: [{
                    data: [<?php echo $matched_providers; ?>, <?php echo $unmatched_providers; ?>],
                    backgroundColor: ['#28a745', '#6c757d'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Tab behaviour
        function setActiveTab(id){
            document.querySelectorAll('#adminTabs .nav-link').forEach(n => {
                n.classList.remove('active');
                n.setAttribute('aria-selected','false');
            });
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('active');
                el.setAttribute('aria-selected','true');
            }
        }

        // Event listeners
        document.getElementById('providers-tab').addEventListener('click', function(){
            switchToTab('providers-tab');
        });

        document.getElementById('overview-tab').addEventListener('click', function(){
            switchToTab('overview-tab');
        });

        document.getElementById('groups-tab-btn').addEventListener('click', function(){
            switchToTab('groups-tab-btn');
        });

        document.getElementById('groups-back').addEventListener('click', function(){
            switchToTab('overview-tab');
        });

        document.getElementById('providers-back').addEventListener('click', function(){
            switchToTab('overview-tab');
        });

        // Load groups function
        function loadGroups() {
            const content = document.getElementById('groupsContent');
            fetch('get_grouped_matches.php')
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.groups || data.groups.length === 0) {
                        content.innerHTML = '<div class="alert alert-secondary"><i class="bi bi-info-circle me-2"></i>No grouped matches found.</div>';
                        return;
                    }

                    let html = '<div class="row">';
                    data.groups.forEach((g, idx) => {
                        const members = (g.members || []);
                        const providers = members.map(m => {
                            const name = m.name || m.id || '';
                            const price = m.price ? ` ($${Number(m.price).toFixed(2)})` : '';
                            const href = m.link ? (` href="${m.link.replace(/"/g,'')}" target="_blank"`) : '';
                            if (href) return `<a${href} class="text-decoration-none">${name}${price}</a>`;
                            return `${name}${price}`;
                        }).join(', ');

                        let cheapest = '';
                        if (g.cheapest) {
                            const c = g.cheapest;
                            const cname = c.name || c.id || '';
                            const cprice = c.price ? ` ($${Number(c.price).toFixed(2)})` : '';
                            const chref = c.link ? (` href="${c.link.replace(/"/g,'')}" target="_blank"`) : '';
                            const cheapHtml = chref ? `<a${chref} class="fw-bold text-success">${cname}${cprice}</a>` : `<span class="fw-bold text-success">${cname}${cprice}</span>`;
                            cheapest = `<div class="mt-2"><small class="text-muted">Cheapest: ${cheapHtml}</small></div>`;
                        }

                        const gidIds = members.map(m => m.id || '').filter(Boolean);
                        const gid = gidIds.length ? gidIds.map(id => `<a href="#" class="group-id-link badge bg-primary text-decoration-none me-1">${id}</a>`).join('') : (g.group_id || (`g${idx+1}`));

                        html += `
                            <div class="col-lg-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <span class="fw-bold">${g.label || ('Group ' + (idx+1))}</span>
                                        <span class="badge bg-info">${g.count || members.length} providers</span>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-2">
                                            <small class="text-muted">IDs: ${gid}</small>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Providers:</strong>
                                            <div class="mt-1">${providers}</div>
                                        </div>
                                        ${cheapest}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    content.innerHTML = html;
                })
                .catch(err => {
                    console.error('Failed to load matched matches', err);
                    content.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Could not load matched groups.</div>';
                });
        }

        // Group ID link handler
        document.addEventListener('click', function(e){
            const t = e.target;
            if (t && t.classList && t.classList.contains('group-id-link')) {
                e.preventDefault();
                const pid = t.textContent;
                if (!pid) return;
                switchToTab('providers-tab');
                setTimeout(() => {
                    const row = document.getElementById('prov-' + pid);
                    if (row) {
                        row.scrollIntoView({behavior:'smooth', block:'center'});
                        row.style.backgroundColor = 'rgba(0,217,255,0.1)';
                        setTimeout(() => {
                            row.style.backgroundColor = '';
                        }, 2000);
                    }
                }, 250);
            }
        });

        // Auto-switch to providers tab if there's an action message
        <?php if (isset($action_msg) && (strpos($action_msg, 'updated') !== false || strpos($action_msg, 'deleted') !== false)): ?>
        switchToTab('providers-tab');
        <?php endif; ?>

        // Initialize search on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add search input listener
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', applyFilters);
            }

            // Add filter listeners
            const statusFilter = document.getElementById('statusFilter');
            const sortBy = document.getElementById('sortBy');
            if (statusFilter) statusFilter.addEventListener('change', applyFilters);
            if (sortBy) sortBy.addEventListener('change', applyFilters);
            // Update recent activity labels on load
            if (typeof updateRecentActivityTimes === 'function') updateRecentActivityTimes();
        });

        // Update recent activity times to user's local timezone using data-ts (epoch ms)
        function relativeTimeFromEpochMs(epochMs) {
            if (!epochMs || isNaN(epochMs) || epochMs <= 0) return 'N/A';
            const now = Date.now();
            const diff = Math.floor((now - Number(epochMs)) / 1000); // seconds
            if (diff < 60) return Math.max(1, diff) + 's ago';
            if (diff < 3600) return Math.round(diff / 60) + 'm ago';
            if (diff < 86400) return Math.round(diff / 3600) + 'h ago';
            return Math.round(diff / 86400) + 'd ago';
        }

        function updateRecentActivityTimes() {
            const els = document.querySelectorAll('#overviewTab .list-group-item small[data-ts]');
            els.forEach(el => {
                const ts = parseInt(el.getAttribute('data-ts'), 10);
                const txt = relativeTimeFromEpochMs(ts);
                el.textContent = txt;
            });
        }

    </script>
</body>
</html>
