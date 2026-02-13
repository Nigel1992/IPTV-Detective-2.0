<?php
// stats-badge.php
// Dynamic SVG badge that shows live stats (scanned providers, matched < threshold, percentage)
// Query params:
//  - threshold (float) default 50
//  - scope (public|total)  default public
//  - format (svg|json)     default svg

header_remove();
require_once __DIR__ . '/inc/db.php';

$threshold = isset($_GET['threshold']) ? floatval($_GET['threshold']) : 50.0;
$scope = (isset($_GET['scope']) && $_GET['scope'] === 'total') ? 'total' : 'public';
$format = (isset($_GET['format']) && $_GET['format'] === 'json') ? 'json' : 'svg';

try {
    $pdo = get_db();
    if ($scope === 'public') {
        $stmt = $pdo->query('SELECT COUNT(*) FROM providers WHERE is_public = 1');
    } else {
        $stmt = $pdo->query('SELECT COUNT(*) FROM providers');
    }
    $scanned = intval($stmt->fetchColumn());

    $sql = 'SELECT COUNT(*) FROM providers WHERE matched = 1 AND match_price IS NOT NULL AND match_price < ?' . ($scope === 'public' ? ' AND is_public = 1' : '');
    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute([$threshold]);
    $matched_under = intval($stmt2->fetchColumn());

    $percent = $scanned > 0 ? round(($matched_under / $scanned) * 100) : 0;
    $asOf = date('n/j/y'); // short date like 2/13/26

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'as_of' => $asOf, 'scope' => $scope, 'threshold' => $threshold, 'scanned' => $scanned, 'matched_under' => $matched_under, 'percent' => $percent]);
        exit;
    }

    // Render SVG badge
    header('Content-Type: image/svg+xml; charset=utf-8');
    // cache short-lived but allow CDN cache
    header('Cache-Control: public, max-age=600');

    // sanitize for embedding in attributes/text (very small risk, values are numeric)
    $scanned_text = number_format($scanned);
    $matched_text = number_format($matched_under);
    $pct_text = $percent . '%';
    $threshold_text = '$' . number_format($threshold, 0);
    $cta = 'Click here to scan your own service and see if you\'ve been overpaying';

    // Simple, responsive SVG card
    echo '<?xml version="1.0" encoding="UTF-8"?>\n';
    ?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 760 140" width="760" height="140" role="img" aria-label="IPTV Detective stats as of <?php echo $asOf?>">
  <style>
    .bg{fill:#071428}
    .card{fill:linear-gradient(#0b1b2a,#071428);stroke:rgba(255,255,255,0.03);}
    .muted{fill:#9fbfd3;font-family:Inter,Arial,Helvetica,sans-serif;font-size:13px}
    .large{fill:#dff8ff;font-family:Orbitron,Inter,Arial,sans-serif;font-weight:700;font-size:28px}
    .bignum{fill:#00c2ff;font-family:Orbitron,Inter,Arial,sans-serif;font-weight:800;font-size:36px}
    .pct{fill:#7cf67f;font-family:Orbitron,Inter,Arial,sans-serif;font-weight:800;font-size:30px}
    .cta-rect{fill:#00c2ff;fill-opacity:0.08;stroke:rgba(0,194,255,0.14);rx:8}
    .cta-text{fill:#bfefff;font-family:Inter,Arial,Helvetica,sans-serif;font-size:13px}
    a { cursor: pointer; }
  </style>

  <rect x="4" y="4" rx="12" width="752" height="132" fill="#0b1622" stroke="rgba(255,255,255,0.02)"/>

  <!-- left: date + scanned -->
  <g transform="translate(28,28)">
    <text class="muted" x="0" y="0">As of <?php echo htmlspecialchars($asOf, ENT_QUOTES, 'UTF-8'); ?></text>
    <text class="bignum" x="0" y="34"><?php echo $scanned_text; ?></text>
    <text class="muted" x="0" y="58">IPTV services have been scanned in our matching tool</text>
  </g>

  <!-- middle: matched stats -->
  <g transform="translate(360,26)">
    <text class="muted" x="0" y="0">Matched to providers under</text>
    <text class="pct" x="0" y="28"><?php echo $matched_text; ?> <tspan class="muted" x="<?php echo strlen($matched_text) * 20 + 8 ?>" dy="0">of them</tspan></text>
    <text class="large" x="0" y="60"><?php echo $pct_text; ?> &nbsp;<?php echo htmlspecialchars($threshold_text, ENT_QUOTES, 'UTF-8'); ?>/yr</text>
  </g>

  <!-- right: CTA -->
  <g transform="translate(28,96)">
    <!-- helpful note: CTA is also provided as anchor around the IMG where embedded -->
    <rect x="0" y="0" width="704" height="34" rx="8" class="cta-rect" />
    <a xlink:href="/index.php#check">
      <text x="14" y="22" class="cta-text"><?php echo htmlspecialchars($cta, ENT_QUOTES, 'UTF-8'); ?></text>
    </a>
  </g>
</svg>
<?php
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'error'=>'DB error','message'=>substr($e->getMessage(),0,200)]);
    exit;
}
