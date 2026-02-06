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
$pdo = get_db();
$cfg = include __DIR__ . '/inc/config.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Matched Groups - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
</body>
</html>
