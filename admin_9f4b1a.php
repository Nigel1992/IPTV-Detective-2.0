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
    <?php require_once __DIR__ . '/inc/discord_fab.php'; ?>
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
                $payload = ['embeds' => [$embed]];
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
                $payload = ['embeds' => [$embed]];
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
    // Ensure tagging columns exist
    try {
        $pdo->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS is_baseline TINYINT(1) DEFAULT 0, ADD COLUMN IF NOT EXISTS is_confirmed_source TINYINT(1) DEFAULT 0, ADD COLUMN IF NOT EXISTS notes TEXT");
    } catch (Exception $e) {
        // ignore - ALTER may not support IF NOT EXISTS on all MySQL/MariaDB versions
        try {
            $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='providers' AND COLUMN_NAME IN ('is_baseline','is_confirmed_source','notes')");
            $stmtCols->execute();
            $cols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
            $need = [];
            if (!in_array('is_baseline', $cols, true)) $need[] = "ADD COLUMN is_baseline TINYINT(1) DEFAULT 0";
            if (!in_array('is_confirmed_source', $cols, true)) $need[] = "ADD COLUMN is_confirmed_source TINYINT(1) DEFAULT 0";
            if (!in_array('notes', $cols, true)) $need[] = "ADD COLUMN notes TEXT";
            if (!empty($need)) $pdo->exec('ALTER TABLE providers ' . implode(', ', $need));
        } catch (Exception $e2) {
            // ignore
        }
    }
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
        'vod_categories' => $_POST['vod_categories'] ?? '',
        'is_baseline' => isset($_POST['is_baseline']) ? 1 : 0,
        'is_confirmed_source' => isset($_POST['is_confirmed_source']) ? 1 : 0,
        'notes' => $_POST['notes'] ?? ''
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

// Handle add action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    // Ensure tagging columns exist
    try {
        $pdo->exec("ALTER TABLE providers ADD COLUMN IF NOT EXISTS is_baseline TINYINT(1) DEFAULT 0, ADD COLUMN IF NOT EXISTS is_confirmed_source TINYINT(1) DEFAULT 0, ADD COLUMN IF NOT EXISTS notes TEXT");
    } catch (Exception $e) {
        // ignore - ALTER may not support IF NOT EXISTS on all MySQL/MariaDB versions
        try {
            $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='providers' AND COLUMN_NAME IN ('is_baseline','is_confirmed_source','notes')");
            $stmtCols->execute();
            $cols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
            $need = [];
            if (!in_array('is_baseline', $cols, true)) $need[] = "ADD COLUMN is_baseline TINYINT(1) DEFAULT 0";
            if (!in_array('is_confirmed_source', $cols, true)) $need[] = "ADD COLUMN is_confirmed_source TINYINT(1) DEFAULT 0";
            if (!in_array('notes', $cols, true)) $need[] = "ADD COLUMN notes TEXT";
            if (!empty($need)) $pdo->exec('ALTER TABLE providers ' . implode(', ', $need));
        } catch (Exception $e2) {
            // ignore
        }
    }
    $fields = [
        'name' => trim($_POST['name'] ?? ''),
        'link' => trim($_POST['link'] ?? ''),
        'price' => !empty($_POST['price']) ? $_POST['price'] : null,
        'seller_source' => $_POST['seller_source'] ?? '',
        'seller_info' => trim($_POST['seller_info'] ?? ''),
        'live_categories' => !empty($_POST['live_categories']) ? $_POST['live_categories'] : null,
        'live_streams' => !empty($_POST['live_streams']) ? $_POST['live_streams'] : null,
        'series' => !empty($_POST['series']) ? $_POST['series'] : null,
        'series_categories' => !empty($_POST['series_categories']) ? $_POST['series_categories'] : null,
        'vod_categories' => !empty($_POST['vod_categories']) ? $_POST['vod_categories'] : null,
        'is_baseline' => isset($_POST['is_baseline']) ? 1 : 0,
        'is_confirmed_source' => isset($_POST['is_confirmed_source']) ? 1 : 0,
        'notes' => trim($_POST['notes'] ?? ''),
        'is_public' => 1, // New providers added by admin are public by default
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    if (empty($fields['name'])) {
        $action_err = 'Provider name is required.';
    } else {
        $columns = implode(',', array_keys($fields));
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';
        $sql = "INSERT INTO providers ($columns) VALUES ($placeholders)";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($fields));
            $newId = $pdo->lastInsertId();
            $action_msg = 'Provider "' . htmlspecialchars($fields['name']) . '" added successfully with ID ' . $newId . '.';
            
            // Update counts
            $total_providers = $pdo->query('SELECT COUNT(*) FROM providers')->fetchColumn();
            $recent_submissions = $pdo->query('SELECT COUNT(*) FROM providers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
        } catch (Exception $e) {
            $action_err = 'Add failed: ' . $e->getMessage();
        }
    }
}

// Handle bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_ids'])) {
    $selectedIds = $_POST['selected_ids'];
    if (!is_array($selectedIds)) {
        $selectedIds = [$selectedIds];
    }
    $selectedIds = array_map('intval', array_filter($selectedIds));
    
    if (empty($selectedIds)) {
        $action_err = 'No providers selected for bulk operation.';
    } else {
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $action = $_POST['bulk_action'];
        
        try {
            switch ($action) {
                case 'verify':
                    $sql = "UPDATE providers SET is_confirmed_source = 1 WHERE id IN ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($selectedIds);
                    $action_msg = count($selectedIds) . ' provider(s) marked as verified.';
                    break;
                    
                case 'unverify':
                    $sql = "UPDATE providers SET is_confirmed_source = 0 WHERE id IN ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($selectedIds);
                    $action_msg = count($selectedIds) . ' provider(s) marked as unverified.';
                    break;
                    
                case 'baseline':
                    $sql = "UPDATE providers SET is_baseline = 1 WHERE id IN ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($selectedIds);
                    $action_msg = count($selectedIds) . ' provider(s) marked as baseline.';
                    break;
                    
                case 'unbaseline':
                    $sql = "UPDATE providers SET is_baseline = 0 WHERE id IN ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($selectedIds);
                    $action_msg = count($selectedIds) . ' provider(s) removed from baseline.';
                    break;
                    
                case 'delete':
                    if (!isset($_POST['confirm_delete']) || $_POST['confirm_delete'] !== 'yes') {
                        $action_err = 'Delete operation requires confirmation.';
                        break;
                    }
                    $pdo->beginTransaction();
                    // Delete related data first
                    try {
                        $stmt2 = $pdo->prepare("DELETE c FROM channels c JOIN snapshots s ON c.snapshot_id = s.id WHERE s.provider_id IN ($placeholders)");
                        $stmt2->execute($selectedIds);
                        $stmt3 = $pdo->prepare("DELETE FROM snapshots WHERE provider_id IN ($placeholders)");
                        $stmt3->execute($selectedIds);
                    } catch (Exception $e) {}
                    // Delete providers
                    $stmt = $pdo->prepare("DELETE FROM providers WHERE id IN ($placeholders)");
                    $stmt->execute($selectedIds);
                    $pdo->commit();
                    $action_msg = count($selectedIds) . ' provider(s) deleted successfully.';
                    break;
                    
                case 'export':
                    // This will be handled by JavaScript to download CSV
                    break;
                    
                default:
                    $action_err = 'Unknown bulk action.';
            }
            
            // Update counts after operations
            if (in_array($action, ['delete'])) {
                $total_providers = $pdo->query('SELECT COUNT(*) FROM providers')->fetchColumn();
                $recent_submissions = $pdo->query('SELECT COUNT(*) FROM providers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $action_err = 'Bulk operation failed: ' . $e->getMessage();
        }
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
                <button id="add-provider-btn" class="btn btn-primary">
                    <i class="bi bi-plus me-1"></i>Add Provider
                </button>
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
                            <option value="seller_source ASC">Seller A-Z</option>
                            <option value="seller_source DESC">Seller Z-A</option>
                            <option value="live_categories ASC">Live Cats Low-High</option>
                            <option value="live_categories DESC">Live Cats High-Low</option>
                            <option value="live_streams ASC">Live Streams Low-High</option>
                            <option value="live_streams DESC">Live Streams High-Low</option>
                            <option value="series ASC">Series Low-High</option>
                            <option value="series DESC">Series High-Low</option>
                            <option value="series_categories ASC">Series Cats Low-High</option>
                            <option value="series_categories DESC">Series Cats High-Low</option>
                            <option value="vod_categories ASC">VOD Cats Low-High</option>
                            <option value="vod_categories DESC">VOD Cats High-Low</option>
                            <option value="similarity ASC">Similarity Low-High</option>
                            <option value="similarity DESC">Similarity High-Low</option>
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
                <div class="d-flex align-items-center" style="gap:8px;">
                    <button id="compareSelectedBtn" class="btn btn-outline-primary btn-sm" disabled title="Select exactly two providers to compare"><i class="bi bi-bar-chart me-1"></i>Compare selected</button>
                    <span class="badge bg-primary" id="providerCount"><?php echo $total_providers; ?> total</span>
                </div>
            </div>

            <!-- Bulk Operations Bar -->
            <div class="card-header bg-light border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center" style="gap:8px;">
                        <button id="selectAllBtn" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-check2-all me-1"></i>Select All
                        </button>
                        <button id="clearSelectionBtn" class="btn btn-outline-secondary btn-sm" disabled>
                            <i class="bi bi-x me-1"></i>Clear Selection
                        </button>
                        <span id="selectionCount" class="text-muted small ms-2">0 selected</span>
                    </div>
                    <div class="d-flex align-items-center" style="gap:6px;">
                        <div class="dropdown">
                            <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" id="bulkActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false" disabled>
                                <i class="bi bi-gear me-1"></i>Bulk Actions
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="bulkActionsDropdown">
                                <li><a class="dropdown-item" href="#" id="bulkVerify"><i class="bi bi-check-circle me-2"></i>Mark as Verified</a></li>
                                <li><a class="dropdown-item" href="#" id="bulkUnverify"><i class="bi bi-x-circle me-2"></i>Mark as Unverified</a></li>
                                <li><a class="dropdown-item" href="#" id="bulkBaseline"><i class="bi bi-star me-2"></i>Mark as Baseline</a></li>
                                <li><a class="dropdown-item" href="#" id="bulkUnbaseline"><i class="bi bi-star-fill me-2"></i>Remove Baseline</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" id="bulkDelete"><i class="bi bi-trash me-2"></i>Delete Selected</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" id="bulkExport"><i class="bi bi-download me-2"></i>Export Selected</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
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
                                <th style="width:48px;text-align:center"><i class="bi bi-check2-all"></i></th>
                                <th><i class="bi bi-hash me-1"></i>ID</th>
                                <th><i class="bi bi-tag me-1"></i>Name</th>
                                <th><i class="bi bi-people me-1"></i>Seller</th>
                                <th><i class="bi bi-cash me-1"></i>Price</th>
                                <th><i class="bi bi-play-circle me-1"></i>Live Cats</th>
                                <th><i class="bi bi-play me-1"></i>Live Streams</th>
                                <th><i class="bi bi-film me-1"></i>Series</th>
                                <th><i class="bi bi-folder me-1"></i>Series Cats</th>
                                <th><i class="bi bi-collection-play me-1"></i>VOD Cats</th>
                                <th><i class="bi bi-percent me-1"></i>Similarity</th>
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
                            $selectCols = ['id','name','link','price','channels','groups','seller_source','seller_info','live_categories','live_streams','series','series_categories','vod_categories','created_at','is_public','is_baseline','is_confirmed_source','notes'];
                            $selectCols = array_values(array_unique($selectCols));
                            $selectCols = array_filter($selectCols, function($c) use ($available){ return in_array($c, $available, true); });
                            $sql = 'SELECT ' . implode(',', $selectCols) . ' FROM providers ORDER BY created_at DESC';
                            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                            // Pre-compute best similarity for each provider using the same logic as get_comparisons.php
                            $bestSim = [];
                            $nRows = count($rows);
                            $metricCols = ['live_streams'=>0.30,'live_categories'=>0.20,'series'=>0.20,'series_categories'=>0.15,'vod_categories'=>0.05];
                            // determine which structured metrics are available in this install
                            $availableMetrics = [];
                            foreach ($metricCols as $col => $w) {
                                if (in_array($col, $selectCols, true)) $availableMetrics[$col] = $w;
                            }
                            $useLegacy = empty($availableMetrics);
                            for ($i = 0; $i < $nRows; $i++) {
                                for ($j = $i+1; $j < $nRows; $j++) {
                                    $a = $rows[$i]; $b = $rows[$j];
                                    $similarity = 0.0;
                                    if ($useLegacy) {
                                        $countA = intval($a['channels'] ?? 0);
                                        $countB = intval($b['channels'] ?? 0);
                                        $gA = intval($a['groups'] ?? 0);
                                        $gB = intval($b['groups'] ?? 0);
                                        $chan_sim = ($countA || $countB) ? (1.0 - abs($countA - $countB) / max(1, max($countA, $countB))) * 100.0 : 0.0;
                                        $group_sim = ($gA || $gB) ? (1.0 - abs($gA - $gB) / max(1, max($gA, $gB))) * 100.0 : 0.0;
                                        $similarity = ($chan_sim * 0.45) + ($group_sim * 0.45);
                                    } else {
                                        $weightSum = array_sum($availableMetrics);
                                        $metricScore = 0.0;
                                        foreach ($availableMetrics as $col => $baseW) {
                                            $valA = isset($a[$col]) ? intval($a[$col]) : 0;
                                            $valB = isset($b[$col]) ? intval($b[$col]) : 0;
                                            $sim = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
                                            $normW = ($weightSum > 0) ? ($baseW / $weightSum) : 0;
                                            $metricScore += $sim * $normW;
                                        }
                                        $similarity = $metricScore;
                                    }
                                    // update best for a
                                    if (!isset($bestSim[$a['id']]) || $similarity > $bestSim[$a['id']]['score']) {
                                        $bestSim[$a['id']] = ['score'=>$similarity, 'name'=>$b['name'], 'id'=>$b['id']];
                                    }
                                    // update best for b
                                    if (!isset($bestSim[$b['id']]) || $similarity > $bestSim[$b['id']]['score']) {
                                        $bestSim[$b['id']] = ['score'=>$similarity, 'name'=>$a['name'], 'id'=>$a['id']];
                                    }
                                }
                            }
                            foreach ($rows as $r) {
                                $simScore = isset($bestSim[$r['id']]) ? round($bestSim[$r['id']]['score'],1) : 0;
                                                                $rowId = 'prov-' . htmlspecialchars($r['id']);
                                                                    echo '<tr id="' . $rowId . '" data-row-id="' . htmlspecialchars($r['id']) . '" data-name="' . htmlspecialchars(strtolower($r['name'] ?? '')) . '" data-status="' . (isset($r['is_public']) && $r['is_public'] ? 'public' : 'private') . '" data-price="' . htmlspecialchars($r['price'] ?? '') . '" data-created="' . htmlspecialchars($r['created_at'] ?? '') . '" data-seller-source="' . htmlspecialchars(strtolower($r['seller_source'] ?? '')) . '" data-live-categories="' . htmlspecialchars(strtolower($r['live_categories'] ?? '')) . '" data-live-streams="' . htmlspecialchars($r['live_streams'] ?? '') . '" data-series="' . htmlspecialchars($r['series'] ?? '') . '" data-series-categories="' . htmlspecialchars(strtolower($r['series_categories'] ?? '')) . '" data-vod-categories="' . htmlspecialchars(strtolower($r['vod_categories'] ?? '')) . '" data-similarity="' . $simScore . '">';
                                                                    echo '<td style="vertical-align:middle;text-align:center"><input type="checkbox" class="prov-compare-checkbox" data-id="' . htmlspecialchars($r['id']) . '" data-name="' . htmlspecialchars($r['name'] ?? '') . '"></td>';
                                                                    echo '<td><code>' . htmlspecialchars($r['id']) . '</code></td>';
                                                                    $tagBadges = '';
                                                                            if (!empty($r['is_baseline'])) $tagBadges .= ' <span class="badge bg-info">Baseline</span>';
                                                                            if (!empty($r['is_confirmed_source'])) $tagBadges .= ' <span class="badge bg-success">Verified</span>';
                                                                            else $tagBadges .= ' <span class="badge bg-warning">Unverified</span>';
                                                                            echo '<td><strong>' . htmlspecialchars($r['name'] ?? '') . '</strong>' . $tagBadges . '</td>';
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
                                  // Similarity cell
                                  $simScore = isset($bestSim[$r['id']]) ? round($bestSim[$r['id']]['score'],1) : 0;
                                  $simLabel = ($simScore > 0) ? ($simScore . '%') : '-';
                                  $simTitle = ($simScore > 0 && !empty($bestSim[$r['id']]['name'])) ? 'Most similar: ' . htmlspecialchars($bestSim[$r['id']]['name']) . ' (' . $simScore . '%)' : 'No close matches';
                                  // Put tooltip on the whole table cell for easier hovering and enable Bootstrap tooltip
                                  echo '<td title="' . $simTitle . '" data-bs-toggle="tooltip" aria-label="' . $simTitle . '">';
                                  echo '<div class="small mb-1">' . $simLabel . '</div>';
                                  echo '<div class="progress" style="height:8px"><div class="progress-bar ' . ($simScore>=80?'bg-success':($simScore>=50?'bg-warning':'bg-secondary')) . '" role="progressbar" style="width:' . intval(min(100,$simScore)) . '%"></div></div>';
                                  echo '</td>';
                                  echo '<td><small class="text-muted">' . htmlspecialchars($r['created_at'] ?? '') . '</small></td>';
                                                                    echo '<td>';
                                                                    echo '<div class="btn-group btn-group-sm" role="group" style="gap:6px;">';
                                                                    // Add data attributes to avoid inline JSON in onclick (safer)
                                                                    $data_attrs = '';
                                                                    $data_attrs .= ' data-id="' . htmlspecialchars($r['id']) . '"';
                                                                    $data_attrs .= ' data-name="' . htmlspecialchars($r['name'] ?? '') . '"';
                                                                    $data_attrs .= ' data-link="' . htmlspecialchars($r['link'] ?? '') . '"';
                                                                    $data_attrs .= ' data-price="' . htmlspecialchars($r['price'] ?? '') . '"';
                                                                    $data_attrs .= ' data-live-categories="' . htmlspecialchars($r['live_categories'] ?? '') . '"';
                                                                    $data_attrs .= ' data-live-streams="' . htmlspecialchars($r['live_streams'] ?? '') . '"';
                                                                    $data_attrs .= ' data-series="' . htmlspecialchars($r['series'] ?? '') . '"';
                                                                    $data_attrs .= ' data-series-categories="' . htmlspecialchars($r['series_categories'] ?? '') . '"';
                                                                    $data_attrs .= ' data-vod-categories="' . htmlspecialchars($r['vod_categories'] ?? '') . '"';
                                                                    $data_attrs .= ' data-seller-source="' . htmlspecialchars($r['seller_source'] ?? '') . '"';
                                                                    $data_attrs .= ' data-seller-info="' . htmlspecialchars($r['seller_info'] ?? '') . '"';
                                                                    $data_attrs .= ' data-is-baseline="' . intval($r['is_baseline'] ?? 0) . '"';
                                                                    $data_attrs .= ' data-is-confirmed-source="' . intval($r['is_confirmed_source'] ?? 0) . '"';
                                                                    $data_attrs .= ' data-notes="' . htmlspecialchars($r['notes'] ?? '') . '"';
                                                                    echo '<button class="btn btn-outline-info btn-edit-provider d-flex align-items-center justify-content-center" style="width:38px;height:38px;padding:0;" onclick="openEditModalFromButton(this)"' . $data_attrs . ' title="Edit"><i class="bi bi-pencil"></i></button>';
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
                            <!-- Website Link removed from admin edit modal per request -->
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
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="editIsBaseline" name="is_baseline">
                                        <label class="form-check-label" for="editIsBaseline">Tag as <strong>Baseline</strong></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="editIsConfirmedSource" name="is_confirmed_source">
                                        <label class="form-check-label" for="editIsConfirmedSource">Tag as <strong>Verified Source</strong></label>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label for="editNotes" class="form-label"><i class="bi bi-sticky me-1"></i>Admin Notes</label>
                                        <textarea class="form-control" name="notes" id="editNotes" rows="3" placeholder="Add any internal notes about this provider..."></textarea>
                                    </div>
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

        <!-- Add Provider Modal -->
        <div class="modal fade" id="addProviderModal" tabindex="-1" aria-labelledby="addProviderModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" id="addProviderForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addProviderModalLabel">
                                <i class="bi bi-plus me-2"></i>Add New Provider
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addProviderName" class="form-label"><i class="bi bi-tag me-1"></i>Provider Name *</label>
                                        <input type="text" class="form-control" name="name" id="addProviderName" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addProviderLink" class="form-label"><i class="bi bi-link me-1"></i>Website/Link</label>
                                        <input type="url" class="form-control" name="link" id="addProviderLink" placeholder="https://...">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addSellerSource" class="form-label"><i class="bi bi-person me-1"></i>Seller Source</label>
                                        <select class="form-select" name="seller_source" id="addSellerSource">
                                            <option value="">Select source...</option>
                                            <option value="telegram_seller">Telegram Seller</option>
                                            <option value="discord_seller">Discord Seller</option>
                                            <option value="reddit_seller">Reddit Seller</option>
                                            <option value="forum_seller">Forum Seller</option>
                                            <option value="website">Website</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addSellerInfo" class="form-label"><i class="bi bi-info-circle me-1"></i>Seller Info</label>
                                        <input type="text" class="form-control" name="seller_info" id="addSellerInfo" placeholder="username, profile URL, order id">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addProviderPrice" class="form-label"><i class="bi bi-cash me-1"></i>Price</label>
                                        <input type="number" class="form-control" name="price" id="addProviderPrice" step="0.01" min="0" placeholder="0.00">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addLiveCategories" class="form-label"><i class="bi bi-play-circle me-1"></i>Live Categories</label>
                                        <input type="number" class="form-control" name="live_categories" id="addLiveCategories" min="0" placeholder="Number of live categories">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addLiveStreams" class="form-label"><i class="bi bi-play me-1"></i>Live Streams</label>
                                        <input type="number" class="form-control" name="live_streams" id="addLiveStreams" min="0" placeholder="Number of live streams">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addSeries" class="form-label"><i class="bi bi-film me-1"></i>Series</label>
                                        <input type="number" class="form-control" name="series" id="addSeries" min="0" placeholder="Number of series">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addSeriesCategories" class="form-label"><i class="bi bi-folder me-1"></i>Series Categories</label>
                                        <input type="number" class="form-control" name="series_categories" id="addSeriesCategories" min="0" placeholder="Number of series categories">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="addVodCategories" class="form-label"><i class="bi bi-collection-play me-1"></i>VOD Categories</label>
                                        <input type="number" class="form-control" name="vod_categories" id="addVodCategories" min="0" placeholder="Number of VOD categories">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="addIsBaseline" name="is_baseline">
                                        <label class="form-check-label" for="addIsBaseline">Tag as <strong>Baseline</strong></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="addIsConfirmedSource" name="is_confirmed_source">
                                        <label class="form-check-label" for="addIsConfirmedSource">Tag as <strong>Verified Source</strong></label>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label for="addNotes" class="form-label"><i class="bi bi-sticky me-1"></i>Admin Notes</label>
                                        <textarea class="form-control" name="notes" id="addNotes" rows="3" placeholder="Add any internal notes about this provider..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x me-1"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus me-1"></i>Add Provider
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
        <!-- Compare Modal -->
        <div class="modal fade" id="compareModal" tabindex="-1" aria-labelledby="compareModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="compareModalLabel"><i class="bi bi-bar-chart me-2"></i>Provider Comparison</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="compareModalBody">
                        <div class="text-center py-4"><div class="loading-spinner mx-auto mb-3"></div><div>Preparing comparison&hellip;</div></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
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

                const getPrice = (p) => {
                    try {
                        const v = (p.row && p.row.dataset && p.row.dataset.price) ? p.row.dataset.price : '';
                        const n = parseFloat(String(v).replace(/,/g, ''));
                        return isNaN(n) ? 0 : n;
                    } catch(e){ return 0; }
                };
                const getDate = (p) => {
                    try {
                        const v = (p.row && p.row.dataset && p.row.dataset.created) ? p.row.dataset.created : '';
                        const t = Date.parse(v);
                        return isNaN(t) ? 0 : t;
                    } catch(e){ return 0; }
                };

                pairs.sort((a, b) => {
                    if (field === 'name') {
                        const A = a.row.dataset.name || (a.row.cells[1] && a.row.cells[1].textContent.toLowerCase()) || '';
                        const B = b.row.dataset.name || (b.row.cells[1] && b.row.cells[1].textContent.toLowerCase()) || '';
                        return dir === 'ASC' ? A.localeCompare(B) : B.localeCompare(A);
                    }
                    if (field === 'price') {
                            const A = getPrice({row: a.row});
                            const B = getPrice({row: b.row});
                            return dir === 'ASC' ? (A - B) : (B - A);
                        }
                    if (field === 'seller_source') {
                        const A = a.row.dataset.sellerSource || '';
                        const B = b.row.dataset.sellerSource || '';
                        return dir === 'ASC' ? A.localeCompare(B) : B.localeCompare(A);
                    }
                    if (field === 'live_categories') {
                        const A = parseInt(a.row.dataset.liveCategories) || 0;
                        const B = parseInt(b.row.dataset.liveCategories) || 0;
                        return dir === 'ASC' ? (A - B) : (B - A);
                    }
                    if (field === 'live_streams') {
                        const A = parseInt(a.row.dataset.liveStreams) || 0;
                        const B = parseInt(b.row.dataset.liveStreams) || 0;
                        return dir === 'ASC' ? (A - B) : (B - A);
                    }
                    if (field === 'series') {
                        const A = parseInt(a.row.dataset.series) || 0;
                        const B = parseInt(b.row.dataset.series) || 0;
                        return dir === 'ASC' ? (A - B) : (B - A);
                    }
                    if (field === 'series_categories') {
                        const A = parseInt(a.row.dataset.seriesCategories) || 0;
                        const B = parseInt(b.row.dataset.seriesCategories) || 0;
                        return dir === 'ASC' ? (A - B) : (B - A);
                    }
                    if (field === 'vod_categories') {
                        const A = parseInt(a.row.dataset.vodCategories) || 0;
                        const B = parseInt(b.row.dataset.vodCategories) || 0;
                        return dir === 'ASC' ? (A - B) : (B - A);
                    }
                    if (field === 'similarity') {
                        const A = parseFloat(a.row.dataset.similarity) || 0;
                        const B = parseFloat(b.row.dataset.similarity) || 0;
                        return dir === 'ASC' ? (A - B) : (B - A);
                    }
                        const A = getDate({row: a.row});
                        const B = getDate({row: b.row});
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
        function openEditModal(id, name, link, price, live_categories, live_streams, series, series_categories, vod_categories, seller_source, seller_info, is_baseline, is_confirmed_source, notes) {
            // Ensure elements exist before attempting to set values
            const setVal = (sel, val) => {
                const el = document.getElementById(sel);
                if (!el) return;
                // Handle checkboxes
                if (el.type === 'checkbox') {
                    el.checked = (val === 1 || val === '1' || val === true || String(val).toLowerCase() === 'true');
                    return;
                }
                // If it's a select, try to match by option value first, then by option text
                if (el.tagName === 'SELECT') {
                    if (val === null || val === undefined || val === '') { el.selectedIndex = -1; return; }
                    const sval = String(val).trim();
                    const norm = sval.replace(/[^a-z0-9]/gi,'').toLowerCase();
                    // Debug: log incoming value and normalized form
                    try { console.log('openEditModal: setting select', sel, 'value=', sval, 'norm=', norm); } catch(e){}
                    // First try exact value match
                    for (let i = 0; i < el.options.length; i++) {
                        if (el.options[i].value === sval) { el.selectedIndex = i; return; }
                    }
                    // Then try exact text match (case-insensitive)
                    for (let i = 0; i < el.options.length; i++) {
                        if (el.options[i].text.trim().toLowerCase() === sval.toLowerCase()) { el.selectedIndex = i; return; }
                    }
                    // Then try normalized match (strip non-alnum, lowercase)
                    for (let i = 0; i < el.options.length; i++) {
                        const otext = (el.options[i].text || '').replace(/[^a-z0-9]/gi,'').toLowerCase();
                        const oval = (el.options[i].value || '').replace(/[^a-z0-9]/gi,'').toLowerCase();
                        if (otext === norm || oval === norm) { el.selectedIndex = i; return; }
                    }
                    // Nothing matched — add a temporary option so the passed value is visible
                    const tmpVal = '__tmp_passed_value__';
                    // Remove existing tmp if present
                    for (let i = el.options.length - 1; i >= 0; i--) {
                        if (el.options[i].value === tmpVal) el.remove(i);
                    }
                    try {
                        const opt = document.createElement('option');
                        opt.value = tmpVal;
                        opt.text = 'Passed: ' + sval;
                        el.insertBefore(opt, el.firstChild);
                        el.selectedIndex = 0;
                    } catch(e) {
                        el.selectedIndex = -1;
                    }
                    return;
                }
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
            setVal('editIsBaseline', is_baseline);
            setVal('editIsConfirmedSource', is_confirmed_source);
            setVal('editNotes', notes);

            const modalEl = document.getElementById('editProviderModal');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }

        // Helper to open modal from a button's data-* attributes (safer than inline JSON args)
        function openEditModalFromButton(btn) {
            if (!btn) return;
            const d = btn.dataset || {};
            const id = d.id || '';
            const name = d.name || '';
            const link = d.link || '';
            const price = d.price || '';
            const live_categories = d.liveCategories || d['live-categories'] || d['liveCategories'] || d['liveCategories'] || '';
            const live_streams = d.liveStreams || d['live-streams'] || d['liveStreams'] || '';
            const series = d.series || '';
            const series_categories = d.seriesCategories || d['series-categories'] || d['seriesCategories'] || '';
            const vod_categories = d.vodCategories || d['vod-categories'] || d['vodCategories'] || '';
            const seller_source = d.sellerSource || d['seller-source'] || '';
            const seller_info = d.sellerInfo || d['seller-info'] || '';
            const is_baseline = (d.isBaseline || d['is-baseline'] || '0');
            const is_confirmed_source = (d.isConfirmedSource || d['is-confirmed-source'] || '0');
            const notes = d.notes || '';
            openEditModal(id, name, link, price, live_categories, live_streams, series, series_categories, vod_categories, seller_source, seller_info, is_baseline, is_confirmed_source, notes);
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

            // Add provider button
            const addProviderBtn = document.getElementById('add-provider-btn');
            if (addProviderBtn) {
                addProviderBtn.addEventListener('click', function() {
                    const modalEl = document.getElementById('addProviderModal');
                    if (modalEl) {
                        const modal = new bootstrap.Modal(modalEl);
                        modal.show();
                    }
                });
            }

            // Update recent activity labels on load
            if (typeof updateRecentActivityTimes === 'function') updateRecentActivityTimes();

            // Initialize Bootstrap tooltips for elements with data-bs-toggle="tooltip"
            try {
                const ttList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                ttList.forEach(function (el) { if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) new bootstrap.Tooltip(el); });
            } catch (e) { console.warn('Tooltip init failed', e); }
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
    <script>
        // Compare selection handling
        (function(){
            const selected = new Map();
            const btn = document.getElementById('compareSelectedBtn');
            function updateButton() {
                const n = selected.size;
                if (!btn) return;
                if (n === 2) {
                    const names = Array.from(selected.values()).map(s => s.name || s.id);
                    btn.disabled = false;
                    btn.textContent = 'Compare: ' + names.join(' vs ');
                } else {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="bi bi-bar-chart me-1"></i>Compare selected';
                }
            }

            document.addEventListener('change', function(e){
                const t = e.target;
                if (!t || !t.classList) return;
                if (t.classList.contains('prov-compare-checkbox')) {
                    const id = t.dataset.id;
                    const name = t.dataset.name || id;
                    if (t.checked) {
                        selected.set(id, {id, name});
                        // limit to 2: uncheck oldest if user checks a third
                        if (selected.size > 2) {
                            const firstKey = selected.keys().next().value;
                            if (firstKey) {
                                selected.delete(firstKey);
                                const firstCb = document.querySelector('.prov-compare-checkbox[data-id="' + firstKey + '"]');
                                if (firstCb) firstCb.checked = false;
                            }
                        }
                    } else {
                        selected.delete(id);
                    }
                    updateButton();
                }
            });

            if (btn) {
                btn.addEventListener('click', function(){
                    if (selected.size !== 2) return;
                    const ids = Array.from(selected.keys());
                    const a = encodeURIComponent(ids[0]);
                    const b = encodeURIComponent(ids[1]);
                    const modal = new bootstrap.Modal(document.getElementById('compareModal'));
                    const body = document.getElementById('compareModalBody');
                    if (body) body.innerHTML = '<div class="text-center py-4"><div class="loading-spinner mx-auto mb-3"></div><div>Loading comparison&hellip;</div></div>';
                    modal.show();
                    fetch('get_pair_comparison.php?id_a=' + a + '&id_b=' + b)
                        .then(r => r.json())
                        .then(data => {
                            if (!data || data.error) {
                                body.innerHTML = '<div class="alert alert-danger">Comparison failed.</div>';
                                return;
                            }
                            renderComparison(body, data);
                        })
                        .catch(err => {
                            console.error('Compare failed', err);
                            if (body) body.innerHTML = '<div class="alert alert-danger">Comparison failed.</div>';
                        });
                });
            }

            function renderComparison(container, data) {
                try {
                    const a = data.a || {};
                    const b = data.b || {};
                    const metrics = data.metrics || [];
                    const overall = (typeof data.overall === 'number') ? data.overall : null;
                    let html = '';
                    html += '<div class="mb-3 d-flex justify-content-between align-items-center">';
                    html += '<div><strong>' + escapeHtml(a.name || a.id) + '</strong> <small class="text-muted">ID ' + escapeHtml(String(a.id)) + '</small></div>';
                    html += '<div class="text-center"><span class="badge bg-secondary">VS</span></div>';
                    html += '<div class="text-end"><strong>' + escapeHtml(b.name || b.id) + '</strong> <small class="text-muted">ID ' + escapeHtml(String(b.id)) + '</small></div>';
                    html += '</div>';
                    if (overall !== null) {
                        html += '<div class="mb-3"><h4>Overall similarity: <span class="text-primary">' + Number(overall).toFixed(2) + '%</span></h4></div>';
                    }
                    html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>Metric</th><th class="text-end">' + escapeHtml(a.name || 'A') + '</th><th class="text-end">' + escapeHtml(b.name || 'B') + '</th><th class="text-end">Similarity</th></tr></thead><tbody>';
                    metrics.forEach(m => {
                        html += '<tr>';
                        html += '<td>' + escapeHtml(m.field) + '</td>';
                        html += '<td class="text-end"><code>' + escapeHtml(String(m.a_val ?? '-')) + '</code></td>';
                        html += '<td class="text-end"><code>' + escapeHtml(String(m.b_val ?? '-')) + '</code></td>';
                        html += '<td class="text-end"><strong>' + (typeof m.percentage === 'number' ? (m.percentage.toFixed(2) + '%') : '-') + '</strong></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                    container.innerHTML = html;
                } catch (e) {
                    container.innerHTML = '<div class="alert alert-danger">Failed to render comparison.</div>';
                }
            }

            function escapeHtml(s){
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
        })();
    </script>
    <script>
        // Ensure Save Changes is clickable when modal opens (defensive fix)
        (function(){
            const modalEl = document.getElementById('editProviderModal');
            if (!modalEl) return;
            modalEl.addEventListener('shown.bs.modal', function(){
                try {
                    const submit = modalEl.querySelector('button[type="submit"]');
                    if (submit) {
                        submit.disabled = false;
                        submit.style.pointerEvents = 'auto';
                        submit.style.zIndex = 2000;
                    }
                    // Remove any temporary 'Passed:' option placeholders that might block clicks
                    const sel = document.getElementById('editSellerSource');
                    if (sel) {
                        for (let i = sel.options.length - 1; i >= 0; i--) {
                            if (sel.options[i].value === '__tmp_passed_value__') sel.remove(i);
                        }
                    }
                } catch (e) { console.warn(e); }
            });
            modalEl.addEventListener('hidden.bs.modal', function(){
                try {
                    const sel = document.getElementById('editSellerSource');
                    if (sel) {
                        for (let i = sel.options.length - 1; i >= 0; i--) {
                            if (sel.options[i].value === '__tmp_passed_value__') sel.remove(i);
                        }
                    }
                } catch(e){}
            });
        })();

        // Add provider modal event handlers
        (function(){
            const modalEl = document.getElementById('addProviderModal');
            if (modalEl) {
                modalEl.addEventListener('hidden.bs.modal', function(){
                    // Reset form when modal is closed
                    const form = document.getElementById('addProviderForm');
                    if (form) {
                        form.reset();
                    }
                });
            }
        })();

        // Bulk Operations Functionality
        (function(){
            const selectAllBtn = document.getElementById('selectAllBtn');
            const clearSelectionBtn = document.getElementById('clearSelectionBtn');
            const selectionCount = document.getElementById('selectionCount');
            const bulkActionsDropdown = document.getElementById('bulkActionsDropdown');
            const bulkActionItems = document.querySelectorAll('#bulkActionsDropdown + .dropdown-menu .dropdown-item');
            
            function updateBulkUI() {
                const checkedBoxes = document.querySelectorAll('.prov-compare-checkbox:checked');
                const totalChecked = checkedBoxes.length;
                
                // Update selection count
                selectionCount.textContent = totalChecked + ' selected';
                
                // Enable/disable buttons
                clearSelectionBtn.disabled = totalChecked === 0;
                bulkActionsDropdown.disabled = totalChecked === 0;
                
                // Update select all button text
                const totalBoxes = document.querySelectorAll('.prov-compare-checkbox').length;
                if (totalChecked === totalBoxes && totalBoxes > 0) {
                    selectAllBtn.innerHTML = '<i class="bi bi-x me-1"></i>Deselect All';
                } else {
                    selectAllBtn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Select All';
                }
            }
            
            // Select All / Deselect All
            selectAllBtn.addEventListener('click', function(){
                const checkboxes = document.querySelectorAll('.prov-compare-checkbox');
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                
                checkboxes.forEach(cb => {
                    cb.checked = !allChecked;
                    // Trigger change event to update comparison logic
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                });
                
                updateBulkUI();
            });
            
            // Clear Selection
            clearSelectionBtn.addEventListener('click', function(){
                document.querySelectorAll('.prov-compare-checkbox:checked').forEach(cb => {
                    cb.checked = false;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                });
                updateBulkUI();
            });
            
            // Bulk Actions
            bulkActionItems.forEach(item => {
                item.addEventListener('click', function(e){
                    e.preventDefault();
                    const action = this.id.replace('bulk', '').toLowerCase();
                    const checkedBoxes = document.querySelectorAll('.prov-compare-checkbox:checked');
                    
                    if (checkedBoxes.length === 0) {
                        alert('Please select at least one provider.');
                        return;
                    }
                    
                    const selectedIds = Array.from(checkedBoxes).map(cb => cb.dataset.id);
                    
                    // Confirm delete action
                    if (action === 'delete') {
                        if (!confirm(`Are you sure you want to delete ${selectedIds.length} provider(s)? This action cannot be undone.`)) {
                            return;
                        }
                    }
                    
                    // Handle export separately (client-side)
                    if (action === 'export') {
                        exportSelectedProviders(selectedIds);
                        return;
                    }
                    
                    // Submit bulk action
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    // Add CSRF token
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = 'csrf_token';
                    csrfInput.value = '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>';
                    form.appendChild(csrfInput);
                    
                    // Add bulk action
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'bulk_action';
                    actionInput.value = action;
                    form.appendChild(actionInput);
                    
                    // Add selected IDs
                    selectedIds.forEach(id => {
                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'selected_ids[]';
                        idInput.value = id;
                        form.appendChild(idInput);
                    });
                    
                    // Add delete confirmation
                    if (action === 'delete') {
                        const confirmInput = document.createElement('input');
                        confirmInput.type = 'hidden';
                        confirmInput.name = 'confirm_delete';
                        confirmInput.value = 'yes';
                        form.appendChild(confirmInput);
                    }
                    
                    document.body.appendChild(form);
                    form.submit();
                });
            });
            
            // Export function
            function exportSelectedProviders(selectedIds) {
                // Get provider data from table rows
                const rows = [];
                selectedIds.forEach(id => {
                    const row = document.querySelector(`tr[data-row-id="${id}"]`) || document.querySelector(`#prov-${id}`);
                    if (row) {
                        const cells = row.querySelectorAll('td');
                        if (cells.length >= 12) { // Make sure we have enough cells
                            const providerData = {
                                id: id,
                                name: cells[1].textContent.trim(),
                                seller: cells[2].textContent.replace(/<[^>]*>/g, '').trim(),
                                price: cells[3].textContent.replace(/[^\d.]/g, ''),
                                live_categories: cells[4].textContent.trim(),
                                live_streams: cells[5].textContent.trim(),
                                series: cells[6].textContent.trim(),
                                series_categories: cells[7].textContent.trim(),
                                vod_categories: cells[8].textContent.trim(),
                                similarity: cells[9].textContent.trim(),
                                created: cells[10].textContent.trim()
                            };
                            rows.push(providerData);
                        }
                    }
                });
                
                if (rows.length === 0) {
                    alert('No provider data found to export.');
                    return;
                }
                
                // Create CSV
                const headers = ['ID', 'Name', 'Seller', 'Price', 'Live Categories', 'Live Streams', 'Series', 'Series Categories', 'VOD Categories', 'Similarity', 'Created'];
                let csv = headers.join(',') + '\n';
                
                rows.forEach(row => {
                    const values = [
                        row.id,
                        '"' + row.name.replace(/"/g, '""') + '"',
                        '"' + row.seller.replace(/"/g, '""') + '"',
                        row.price,
                        row.live_categories,
                        row.live_streams,
                        row.series,
                        row.series_categories,
                        row.vod_categories,
                        row.similarity,
                        row.created
                    ];
                    csv += values.join(',') + '\n';
                });
                
                // Download CSV
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `providers_export_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
            
            // Update UI on checkbox changes
            document.addEventListener('change', function(e){
                if (e.target.classList.contains('prov-compare-checkbox')) {
                    updateBulkUI();
                }
            });
            
            // Initial UI update
            updateBulkUI();
        })();
    </script>
</body>
</html>
