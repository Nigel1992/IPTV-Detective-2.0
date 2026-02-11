<?php
// admin_groups.php - show grouped matches
session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

// simple auth reuse
$users = [ 'Nebula NL' => 'nebula123', 'Carl' => 'carl123' ];
if (!isset($_SESSION['admin_user'])) {
    header('Location: admin_9f4b1a.php');
    exit;
}
// Load configuration from inc/config.php or inc/config.php.local
$cfg = null;
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
    $cfg = [ 'turnstile_site_key' => 'PLACEHOLDER_TURNSTILE_SITE_KEY', 'turnstile_secret' => 'PLACEHOLDER_TURNSTILE_SECRET' ];
}
$pdo = get_db();

// check for grouped_matches table
$stmtTbl = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
$stmtTbl->execute([$cfg['dbname'], 'grouped_matches']);
$has = $stmtTbl->fetchColumn() > 0;

$groups = [];
if ($has) {
    // assume grouped_matches has columns: group_id, provider_id
    $sql = 'SELECT group_id, GROUP_CONCAT(provider_id) AS providers, COUNT(*) AS cnt FROM grouped_matches GROUP BY group_id ORDER BY cnt DESC';
    try {
        $stmt = $pdo->query($sql);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $provIds = array_filter(array_map('trim', explode(',', $r['providers'])));
            $names = [];
            if (count($provIds) > 0) {
                $in = implode(',', array_fill(0, count($provIds), '?'));
                $stmt2 = $pdo->prepare('SELECT id, name FROM providers WHERE id IN (' . $in . ')');
                $stmt2->execute($provIds);
                $map = [];
                while ($p = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                    $map[$p['id']] = $p['name'];
                }
                foreach ($provIds as $pid) {
                    $names[] = (isset($map[$pid]) ? $map[$pid] : $pid);
                }
            }
            $groups[] = [ 'group_id' => $r['group_id'], 'providers' => $names, 'count' => intval($r['cnt']) ];
        }
    } catch (Exception $e) {
        $has = false;
    }
}

// If no table, compute groups on the fly like get_submissions.php
if (!$has) {
    // Fetch all providers
    $stmt = $pdo->query('SELECT id, name, live_streams, live_categories, series, series_categories, vod_categories FROM providers WHERE is_public = 1 ORDER BY id');
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $n = count($providers);
    $adj = array_fill(0, $n, []);
    $live_streams_counts = [];
    $live_categories_counts = [];
    $series_counts = [];
    $series_categories_counts = [];
    $vod_categories_counts = [];
    for ($i = 0; $i < $n; $i++) {
        $p = $providers[$i];
        $live_streams_counts[$i] = intval($p['live_streams'] ?? 0);
        $live_categories_counts[$i] = intval($p['live_categories'] ?? 0);
        if (function_exists('count_field_items')) {
            $series_counts[$i] = count_field_items($p['series'] ?? '');
            $series_categories_counts[$i] = count_field_items($p['series_categories'] ?? '');
            $vod_categories_counts[$i] = count_field_items($p['vod_categories'] ?? '');
        } else {
            $series_counts[$i] = intval($p['series'] ?? 0);
            $series_categories_counts[$i] = intval($p['series_categories'] ?? 0);
            $vod_categories_counts[$i] = intval($p['vod_categories'] ?? 0);
        }
    }
    $threshold = 80.0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i+1; $j < $n; $j++) {
            $metric_sim = 0.0;
            $weights = [
                'live_streams' => 0.30,
                'live_categories' => 0.20,
                'series' => 0.20,
                'series_categories' => 0.15,
                'vod_categories' => 0.05
            ];
            foreach ($weights as $col => $w) {
                $valA = ${$col . '_counts'}[$i];
                $valB = ${$col . '_counts'}[$j];
                $sim = ($valA || $valB) ? (1.0 - abs($valA - $valB) / max(1, max($valA, $valB))) * 100.0 : 0.0;
                $metric_sim += $sim * $w;
            }
            $overall = $metric_sim;
            if ($overall >= $threshold) {
                $adj[$i][] = $j;
                $adj[$j][] = $i;
            }
        }
    }
    // Find connected components (groups)
    $visited = array_fill(0, $n, false);
    $groupId = 0;
    for ($i = 0; $i < $n; $i++) {
        if (!$visited[$i]) {
            $group = [];
            $stack = [$i];
            while (!empty($stack)) {
                $node = array_pop($stack);
                if (!$visited[$node]) {
                    $visited[$node] = true;
                    $group[] = $node;
                    foreach ($adj[$node] as $neighbor) {
                        if (!$visited[$neighbor]) {
                            $stack[] = $neighbor;
                        }
                    }
                }
            }
            if (count($group) > 1) { // Only groups with more than 1
                $names = [];
                foreach ($group as $idx) {
                    $names[] = $providers[$idx]['name'];
                }
                $groups[] = [ 'group_id' => 'computed_' . $groupId, 'providers' => $names, 'count' => count($group) ];
                $groupId++;
            }
        }
    }
    $has = true; // Pretend has to show the groups
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Matched Groups - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="admin_9f4b1a.php">IPTV Detective Admin</a>
        <div class="d-flex">
            <span class="navbar-text me-3">Welcome, <?php echo htmlspecialchars($_SESSION['admin_user']); ?></span>
            <a href="admin_9f4b1a.php?logout=1" class="btn btn-outline-light btn-sm">Back</a>
        </div>
    </div>
</nav>
<div class="container mt-4">
    <h1>Matched Groups</h1>
    <?php if (!$has): ?>
        <div class="alert alert-info">No grouped matches table found or an error occurred.</div>
    <?php else: ?>
        <?php if (empty($groups)): ?>
            <div class="alert alert-secondary">No groups found.</div>
        <?php else: ?>
            <div class="card">
                <div class="card-body table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr><th>Group ID</th><th>Providers</th><th>Count</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groups as $g): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($g['group_id']); ?></td>
                                <td><?php echo htmlspecialchars(implode(', ', $g['providers'])); ?></td>
                                <td><?php echo (int)$g['count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/inc/discord_fab.php'; ?>
</body>
</html>
