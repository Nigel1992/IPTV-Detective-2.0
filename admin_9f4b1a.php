<?php
// admin_9f4b1a.php - Admin Dashboard (renamed for obscurity)
session_start();

// Handle logout before any output
if (isset($_GET['logout'])) {
    if (isset($_COOKIE['iptv_admin'])) setcookie('iptv_admin', '', time()-3600, '/');
    session_destroy();
    header('Location: admin_9f4b1a.php');
    exit;
}

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
$cfg = include __DIR__ . '/inc/config.php';
$pdo = get_db();

function admin_table_exists($pdo, $dbname) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='admin_users'");
        $stmt->execute([$dbname]);
        return $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $captcha_token = $_POST['cf-turnstile-response'] ?? '';
    $captcha_valid = false;
    if (!empty($captcha_token)) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => $cfg['turnstile_secret'],
            'response' => $captcha_token
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response, true);
        $captcha_valid = $result['success'] ?? false;
    }
    if (!$captcha_valid) {
        $error = 'CAPTCHA verification failed. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $logged_in = false;
        if (admin_table_exists($pdo, $cfg['dbname'])) {
            $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && password_verify($password, $row['password_hash'])) {
                $logged_in = true;
            }
        }
        if (!$logged_in) {
            $old_users = [
                'Nebula NL' => 'nebula123',
                'Carl' => 'carl123'
            ];
            if (isset($old_users[$username]) && $old_users[$username] === $password) {
                $logged_in = true;
            }
        }
        if ($logged_in) {
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
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                                <div class="mb-3">
                                    <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($cfg['turnstile_site_key']); ?>"></div>
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
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $proto . '://' . $host . '/get_grouped_matches.php';
        $opts = ['http' => ['timeout' => 2, 'ignore_errors' => true]];
        $json = @file_get_contents($url, false, stream_context_create($opts));
        if ($json) {
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
    } catch (Exception $e) {}
    if (!is_numeric($matched_providers) || $matched_providers === 0) {
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
}

// Handle edit action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit' && !empty($_POST['id'])) {
    $editId = intval($_POST['id']);
    $fields = [
        'name' => $_POST['name'] ?? '',
        'link' => $_POST['link'] ?? '',
        'price' => $_POST['price'] ?? '',
        'channels' => $_POST['channels'] ?? '',
        'groups' => $_POST['groups'] ?? ''
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
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <!-- Nice system font stack -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root{--accent:#00d9ff;--muted:#9fb9c9}
        body{font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; background:#0b1220; color:#d6eef5}
        .navbar-brand{display:flex;align-items:center;gap:.6rem;font-weight:700}
        .navbar-brand img{height:30px;border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,0.6)}
        .card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.06));border:1px solid rgba(255,255,255,0.03)}
        .card-header{background:transparent;border-bottom:1px solid rgba(255,255,255,0.02);font-weight:600}
        .table thead th{color:#bfefff}
        .table td, .table th{vertical-align:middle}
        .list-group-item{background:transparent;border:1px solid rgba(255,255,255,0.02)}
        .btn-primary{background:linear-gradient(90deg,var(--accent),#7cf6ff);border:none;color:#032935}
        .navbar{box-shadow:0 8px 40px rgba(0,0,0,0.6)}
        .container{max-width:1180px}
        .logo-mark{width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,#00c2ff,#7cf6ff);display:inline-block}
        a{color:var(--accent)}
        a:hover{text-decoration:underline}
        /* make chart bg transparent */
        #statusChart{background:transparent}
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin_9f4b1a.php">
                <span class="logo-mark" aria-hidden="true"></span>
                <span>IPTV Detective</span>
                <small class="text-muted ms-2" style="font-weight:500;color:var(--muted);">Admin</small>
            </a>
            <div class="d-flex align-items-center">
                <div class="me-3 text-end">
                    <div style="font-size:0.95rem;font-weight:600">Welcome, <?php echo htmlspecialchars($user); ?></div>
                    <div style="font-size:0.78rem;color:var(--muted)">Secure Admin Panel</div>
                </div>
                <a href="?logout=1" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <h1>Dashboard</h1>
        <ul class="nav nav-pills mb-3" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="overview" aria-selected="true">Overview</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="providers-tab" data-bs-toggle="tab" type="button" role="tab" aria-controls="providers" aria-selected="false">Providers</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="groups-tab-btn" type="button">Matched Groups</button>
            </li>
        </ul>
        <div id="mainDashboard">
        <div id="overviewTab">
        <div class="row">
            <div class="col-md-6">
                <div class="card text-white bg-primary mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Total Providers</h5>
                        <p class="card-text"><?php echo $total_providers; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card text-white bg-warning mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Recent Submissions (7 days)</h5>
                        <p class="card-text"><?php echo $recent_submissions; ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">Provider Status</div>
                    <div class="card-body">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
                <!-- Providers table moved to Providers tab pane -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">Recent Providers</div>
                    <div class="card-body">
                        <ul class="list-group">
                            <?php
                            $stmt = $pdo->prepare('SELECT name, created_at FROM providers ORDER BY created_at DESC LIMIT 5');
                            $stmt->execute();
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                echo '<li class="list-group-item">' . htmlspecialchars($row['name']) . ' - ' . $row['created_at'] . '</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>

    <!-- Matched Groups pane (hidden until loaded) -->
    <div id="matchedGroupsPane" class="container mt-4" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Matched Groups</h1>
            <div>
                <button id="groups-back" class="btn btn-secondary btn-sm">Back</button>
            </div>
        </div>
        <div id="groupsContent">
            <div class="text-center py-5">Loading groups&hellip;</div>
        </div>
    </div>

    <!-- Providers pane (hidden until requested) -->
    <div id="providersPane" class="container mt-4" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Providers</h1>
            <div>
                <button id="providers-back" class="btn btn-secondary btn-sm">Back</button>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <?php if (isset($action_err)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($action_err); ?></div>
                <?php endif; ?>
                <?php if (isset($action_msg)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($action_msg); ?></div>
                <?php endif; ?>
                <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Channels</th>
                            <th>Groups</th>
                            <th>Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $stmtCols = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='providers'");
                    $cfg = include __DIR__ . '/inc/config.php';
                    $stmtCols->execute([$cfg['dbname']]);
                    $available = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
                    $selectCols = ['id','name','link','price','channels','groups','created_at'];
                    $selectCols = array_values(array_unique($selectCols));
                    $selectCols = array_filter($selectCols, function($c) use ($available){ return in_array($c, $available, true); });
                    $sql = 'SELECT ' . implode(',', $selectCols) . ' FROM providers ORDER BY created_at DESC';
                    $stmt = $pdo->query($sql);
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $rowId = 'prov-' . htmlspecialchars($r['id']);
                        echo '<tr id="' . $rowId . '">';
                        echo '<td>' . htmlspecialchars($r['id']) . '</td>';
                        $name = htmlspecialchars($r['name'] ?? '');
                        echo '<td>' . $name . '</td>';
                        echo '<td>' . htmlspecialchars(isset($r['price']) ? number_format($r['price'],2) : '') . '</td>';
                        echo '<td>' . htmlspecialchars($r['channels'] ?? '') . '</td>';
                        echo '<td>' . htmlspecialchars($r['groups'] ?? '') . '</td>';
                        echo '<td>' . htmlspecialchars($r['created_at'] ?? '') . '</td>';
                        echo '<td>';
                        echo '<button class="btn btn-sm btn-info me-1" onclick="openEditModal(' . htmlspecialchars($r['id']) . ', \'' . addslashes($name) . '\', \'' . addslashes($r['link'] ?? '') . '\', \'' . addslashes($r['price'] ?? '') . '\', \'' . addslashes($r['channels'] ?? '') . '\', \'' . addslashes($r['groups'] ?? '') . '\')">Edit</button>';
                        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this provider?\');">'
                             . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '"'
                             . '<input type="hidden" name="action" value="delete"'
                             . '<input type="hidden" name="id" value="' . htmlspecialchars($r['id']) . '"'
                             . '<button class="btn btn-sm btn-danger">Delete</button>'
                             . '</form>';
                        echo '</td>';
                        echo '</tr>';
                        // Link row
                        if (!empty($r['link'])) {
                            echo '<tr><td></td><td colspan="6"><strong>Link:</strong> <a href="' . htmlspecialchars($r['link']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($r['link']) . '</a></td></tr>';
                        }
                    }
                    ?>
                    </tbody>
                </table>
                                                <!-- Edit Modal -->
                                                <div class="modal fade" id="editProviderModal" tabindex="-1" aria-labelledby="editProviderModalLabel" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <form method="post" id="editProviderForm">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title" id="editProviderModalLabel">Edit Provider</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="action" value="edit">
                                                                    <input type="hidden" name="id" id="editProviderId">
                                                                    <div class="mb-3">
                                                                        <label for="editProviderName" class="form-label">Name</label>
                                                                        <input type="text" class="form-control" name="name" id="editProviderName" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="editProviderLink" class="form-label">Link</label>
                                                                        <input type="text" class="form-control" name="link" id="editProviderLink">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="editProviderPrice" class="form-label">Price</label>
                                                                        <input type="text" class="form-control" name="price" id="editProviderPrice">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="editProviderChannels" class="form-label">Channels</label>
                                                                        <input type="text" class="form-control" name="channels" id="editProviderChannels">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="editProviderGroups" class="form-label">Groups</label>
                                                                        <input type="text" class="form-control" name="groups" id="editProviderGroups">
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                </div>
            </div>
        </div>
    </div>
    <script>
                // Modal edit logic (manual modal handling to avoid bootstrap dependency)
                function openEditModal(id, name, link, price, channels, groups) {
                    document.getElementById('editProviderId').value = id;
                    document.getElementById('editProviderName').value = name;
                    document.getElementById('editProviderLink').value = link;
                    document.getElementById('editProviderPrice').value = price;
                    document.getElementById('editProviderChannels').value = channels;
                    document.getElementById('editProviderGroups').value = groups;
                    // Manual modal display
                    var el = document.getElementById('editProviderModal');
                    if (!el) return;
                    el.classList.add('show');
                    el.style.display = 'block';
                    el.setAttribute('aria-modal', 'true');
                    el.removeAttribute('aria-hidden');
                    var backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                    // Add close event listeners
                    function closeModal() {
                        el.classList.remove('show');
                        el.style.display = 'none';
                        el.setAttribute('aria-hidden', 'true');
                        el.removeAttribute('aria-modal');
                        if (backdrop.parentNode) {
                            backdrop.parentNode.removeChild(backdrop);
                        }
                    }
                    el.querySelectorAll('[data-bs-dismiss="modal"]').forEach(btn => {
                        btn.addEventListener('click', closeModal);
                    });
                    // Close on backdrop click
                    backdrop.addEventListener('click', closeModal);
                }
                document.getElementById('editProviderForm').addEventListener('submit', function(e){
                    // Optionally add client-side validation here
                });
        const ctx = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Matched', 'Unmatched'],
                datasets: [{
                    data: [<?php echo $matched_providers; ?>, <?php echo $unmatched_providers; ?>],
                    backgroundColor: ['#28a745', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });

        // Tab behaviour: show/hide sections and load matched groups
        function setActiveTab(id){
            document.querySelectorAll('#adminTabs .nav-link').forEach(n => { n.classList.remove('active'); n.setAttribute('aria-selected','false'); });
            const el = document.getElementById(id);
            if (el) { el.classList.add('active'); el.setAttribute('aria-selected','true'); }
        }

        document.getElementById('providers-tab').addEventListener('click', function(){
            setActiveTab('providers-tab');
            // show providers pane
            document.getElementById('mainDashboard').style.display = 'none';
            document.getElementById('matchedGroupsPane').style.display = 'none';
            document.getElementById('providersPane').style.display = '';
        });

        document.getElementById('overview-tab').addEventListener('click', function(){
            setActiveTab('overview-tab');
            document.getElementById('mainDashboard').style.display = '';
            document.getElementById('matchedGroupsPane').style.display = 'none';
            document.getElementById('providersPane').style.display = 'none';
        });

        // Matched Groups loads inline via AJAX from `get_grouped_matches.php`.
        document.getElementById('groups-tab-btn').addEventListener('click', function(){
            setActiveTab('groups-tab-btn');
            const main = document.getElementById('mainDashboard');
            const pane = document.getElementById('matchedGroupsPane');
            const content = document.getElementById('groupsContent');
            main.style.display = 'none';
            pane.style.display = '';
            content.innerHTML = '<div class="text-center py-5">Loading groups&hellip;</div>';
            fetch('get_grouped_matches.php')
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.groups || data.groups.length === 0) {
                        content.innerHTML = '<div class="alert alert-secondary">No grouped matches found.</div>';
                        return;
                    }
                    let html = '<div class="card"><div class="card-body table-responsive"><table class="table table-sm table-striped"><thead><tr><th>IDs</th><th>Group</th><th>Providers</th><th>Count</th><th>Cheapest</th></tr></thead><tbody>';
                    function fmtPrice(p){
                        if (p === null || p === undefined || p === '') return '';
                        const num = Number(p);
                        return isNaN(num) ? '' : num.toFixed(2);
                    }
                    data.groups.forEach((g, idx) => {
                        const members = (g.members || []);
                        const providers = members.map(m => {
                            const name = m.name || m.id || '';
                            const price = fmtPrice(m.price);
                            const href = m.link ? (' href="' + (m.link.replace(/"/g,'') ) + '" target="_blank"') : '';
                            const pricePart = price ? (' <small class="text-muted">(' + price + ')</small>') : '';
                            if (href) return ('<a' + href + '>' + name + '</a>' + pricePart);
                            return name + pricePart;
                        }).join(', ');
                        let cheapest = '';
                        if (g.cheapest) {
                            const c = g.cheapest;
                            const cname = c.name || c.id || '';
                            const cprice = fmtPrice(c.price);
                            const chref = c.link ? (' href="' + (c.link.replace(/"/g,'')) + '" target="_blank"') : '';
                            const cpricePart = cprice ? (' <small class="text-muted">(' + cprice + ')</small>') : '';
                            const cheapHtml = chref ? ('<a' + chref + '>' + cname + '</a>' + cpricePart) : (cname + cpricePart);
                            cheapest = '<strong>' + cheapHtml + '</strong>';
                        }
                        const gidIds = members.map(m => m.id || '').filter(Boolean);
                        const gid = gidIds.length ? gidIds.map(id => '<a href="#" class="group-id-link" data-id="'+id+'">'+id+'</a>').join(', ') : (g.group_id || ('g' + (idx+1)));
                        html += '<tr><td>' + gid + '</td><td>' + (g.label || ('Group ' + (idx+1))) + '</td><td>' + providers + '</td><td>' + (g.count || (members?members.length:0)) + '</td><td>' + cheapest + '</td></tr>';
                    });
                    html += '</tbody></table></div></div>';
                    content.innerHTML = html;
                }).catch(err => {
                    console.error('Failed to load grouped matches', err);
                    content.innerHTML = '<div class="alert alert-warning">Could not load matched groups.</div>';
                });
        });

        document.getElementById('groups-back').addEventListener('click', function(){
            document.getElementById('matchedGroupsPane').style.display = 'none';
            document.getElementById('mainDashboard').style.display = '';
            setActiveTab('overview-tab');
        });

        document.getElementById('providers-back').addEventListener('click', function(){
            document.getElementById('providersPane').style.display = 'none';
            document.getElementById('mainDashboard').style.display = '';
            setActiveTab('overview-tab');
        });

        document.addEventListener('click', function(e){
            const t = e.target;
            if (t && t.classList && t.classList.contains('group-id-link')) {
                e.preventDefault();
                const pid = t.dataset.id;
                if (!pid) return;
                document.getElementById('mainDashboard').style.display = 'none';
                document.getElementById('matchedGroupsPane').style.display = 'none';
                document.getElementById('providersPane').style.display = '';
                setActiveTab('providers-tab');
                setTimeout(() => {
                    const row = document.getElementById('prov-' + pid);
                    if (row) row.scrollIntoView({behavior:'smooth', block:'center'});
                }, 250);
            }
        });
        <?php if (isset($action_msg) && (strpos($action_msg, 'updated') !== false || strpos($action_msg, 'deleted') !== false)): ?>
        setActiveTab('providers-tab');
        // show providers pane
        document.getElementById('mainDashboard').style.display = 'none';
        document.getElementById('matchedGroupsPane').style.display = 'none';
        document.getElementById('providersPane').style.display = '';
        <?php endif; ?>
    </script>
</body>
</html>
