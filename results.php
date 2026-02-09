
<?php
// Prevent all caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
$pdo = get_db();

$id = intval($_GET['snapshot_id'] ?? 0);
$snapshot = $pdo->prepare('SELECT * FROM snapshots WHERE id = ?');
$snapshot->execute([$id]);
$s = $snapshot->fetch(PDO::FETCH_ASSOC);
if (!$s) { header('Location: /?msg=' . urlencode('Snapshot not found')); exit; }

$channels = $pdo->prepare('SELECT * FROM channels WHERE snapshot_id = ? ORDER BY group_name, name');
$channels->execute([$id]);
$channels = $channels->fetchAll(PDO::FETCH_ASSOC);

// comparisons (show partial overlaps >= 20%)
$new_set = [];
foreach ($channels as $c) { $url = isset($c['url']) ? sanitize_url($c['url']) : ''; $new_set[] = strtolower(trim($c['name'])) . '|' . strtolower(trim($url)); }
$new_set = array_unique($new_set);
$comparisons = [];
$stmtOthers = $pdo->prepare('SELECT * FROM snapshots WHERE id != ?');
$stmtOthers->execute([$id]);
$others = $stmtOthers->fetchAll(PDO::FETCH_ASSOC);
foreach ($others as $other) {
    $other_channels = $pdo->prepare('SELECT name, url FROM channels WHERE snapshot_id = ?');
    $other_channels->execute([$other['id']]);
    $other_set = [];
    while ($row = $other_channels->fetch(PDO::FETCH_ASSOC)) {
        $other_set[] = strtolower(trim($row['name'])) . '|' . strtolower(trim($row['url']));
    }
    $shared = count(array_intersect($new_set, $other_set));
    $similarity = count($new_set) ? $shared / count($new_set) : 0;
    if ($similarity >= 0.20) {
        $prov = $pdo->prepare('SELECT * FROM providers WHERE id = ?');
        $prov->execute([$other['provider_id']]);
        $p = $prov->fetch(PDO::FETCH_ASSOC);
        $comparisons[] = ['provider' => $p, 'snapshot' => $other, 'similarity' => $similarity, 'shared' => $shared];
    }
}

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Results - IPTV Detective</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="/static/style.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
      <div class="container-fluid">
        <a class="navbar-brand" href="/">IPTV Detective 🔎</a>
      </div>
    </nav>
    <div class="container">
      <?php $safe_id = (int)$id; ?>
      <h3>Results for Snapshot #<?php echo htmlspecialchars((string)$safe_id, ENT_QUOTES, 'UTF-8'); ?></h3>
      <p>Channels: <strong><?php echo (int)$s['channel_count']; ?></strong> — Groups: <strong><?php echo (int)$s['group_count']; ?></strong></p>
      <hr>
      <h5>Channels</h5>
      <table class="table table-sm table-striped">
        <thead><tr><th>Group</th><th>Name</th><th>Stream URL</th></tr></thead>
        <tbody>
          <?php foreach ($channels as $c): ?>
            <tr>
              <td><?php echo htmlspecialchars($c['group_name']); ?></td>
              <td><?php echo htmlspecialchars($c['name']); ?></td>
              <td><?php $safe = isset($c['url']) ? sanitize_url($c['url']) : ''; ?><a href="<?php echo htmlspecialchars($safe); ?>" target="_blank"><?php echo htmlspecialchars($safe); ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <hr>
      <h5>Comparisons</h5>
      <?php if ($comparisons): ?>
        <ul class="list-group">
          <?php foreach ($comparisons as $cmp): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <div>
                <strong><?php echo htmlspecialchars($cmp['provider']['name']); ?></strong> — $<?php echo number_format($cmp['provider']['price'],2); ?> — shared channels: <?php echo (int)$cmp['shared']; ?>
                <div class="text-muted">similarity: <?php echo number_format($cmp['similarity'],2); ?></div>
              </div>
              <div><small><?php echo htmlspecialchars($cmp['provider']['website']); ?></small></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="alert alert-secondary">No relevant matches found.</div>
      <?php endif; ?>
    </div>
  </body>
</html>