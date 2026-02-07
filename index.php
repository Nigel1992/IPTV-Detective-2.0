<?php
// Load configuration from one of several possible locations (inc/config.php, inc/config.php.local, root config.php.local)
$cfg = null;
$cfgCandidates = [
    __DIR__ . '/inc/config.php',
    __DIR__ . '/inc/config.php.local',
    __DIR__ . '/config.php.local',
    __DIR__ . '/inc/config.example.php',
    __DIR__ . '/config.example.php'
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
$siteKey = isset($cfg['turnstile_site_key']) && $cfg['turnstile_site_key'] ? $cfg['turnstile_site_key'] : 'PLACEHOLDER_TURNSTILE_SITE_KEY';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>IPTV Detective 2.0</title>
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" href="favicon.png">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootswatch dark theme -->
  <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/darkly/bootstrap.min.css" rel="stylesheet" integrity="sha384-qkU2zAXgyuetMWO55YBTK4SZzn3b91PYt/YIaQDoJWr0wpkJdglBZxwVjfN5KyR1" crossorigin="anonymous">
  <!-- Tech fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" integrity="sha384-iGsTKEx3NiYk6dKiX+cGcaB5DsXuYKCR+gdr3PvXmlHvA080iJEASttpi5VUsIn5" crossorigin="anonymous" async defer></script>
  <style>
    :root{
      --bg-1: #061023;
      --bg-2: #081428;
      --accent: #00c2ff;
      --accent-2: #7cf6ff;
      --glass: rgba(15,23,36,0.6);
    }
    html,body{height:100%;}
    body { font-family: 'Inter',system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif; background: radial-gradient(1200px 600px at 10% 10%, rgba(10,20,40,0.12), transparent), linear-gradient(120deg,var(--bg-1),var(--bg-2)); color:#e6eef8; overflow-y:auto; }
    /* subtle animated grid */
    body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px),linear-gradient(0deg, rgba(255,255,255,0.015) 1px, transparent 1px);background-size:200px 200px,200px 200px;opacity:0.6;mix-blend-mode:overlay;pointer-events:none;transform:translateZ(0);animation:pan 30s linear infinite}
    @keyframes pan{from{transform:translateY(0)}to{transform:translateY(-60px)}}

    .hero h1{font-family:'Orbitron',sans-serif;letter-spacing:0.6px;color:var(--accent);text-shadow:0 0 18px rgba(0,194,255,0.12),0 0 6px rgba(124,246,255,0.06)}
    .hero .lead{color:#bcdff0;opacity:0.9;margin-top:6px}

    .card { background:var(--glass); border:1px solid rgba(124,246,255,0.06); box-shadow: 0 10px 30px rgba(0,0,0,0.6); backdrop-filter: blur(8px); }
    .card .card-title{color:var(--accent-2)}
    .hash-box { font-family: 'Space Mono', monospace; word-break: break-all; color:#9be7ff; }

    .btn-primary{background:linear-gradient(90deg,var(--accent),#3ce0ff);border:none;box-shadow:0 6px 18px rgba(0,194,255,0.08)}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 18px 32px rgba(0,194,255,0.12)}

    .badge-tech{background:rgba(0,194,255,0.12);color:var(--accent);border:1px solid rgba(0,194,255,0.14)}

    .table-dark tbody tr:hover { background: rgba(255,255,255,0.02); }

    /* Force consistent vertical alignment and clear row dividers */
    .table tbody tr td { vertical-align: middle !important; border-bottom: 1px solid rgba(255,255,255,0.06); padding-top:0.5rem; padding-bottom:0.5rem; }
    .table tbody tr:last-child td { border-bottom: none; }
    .table .badge, .table .btn { vertical-align: middle; }
    .table tbody tr td .btn { display:inline-flex; align-items:center; gap:0.35rem; }
    .table tbody tr td.text-end { text-align: right; }

    .logo { font-size:24px; color:var(--accent); text-shadow: 0 0 8px rgba(0,194,255,0.06); }
    .provider-name { font-weight:700; }

    /* small responsive tweaks */
    @media (max-width:576px){ .hero h1{font-size:28px} }
    /* Help modal styling */
    .help-modal .modal-content{background:rgba(12,20,30,0.92);color:#e6eef8;border:1px solid rgba(124,246,255,0.06)}
    .help-modal .modal-title{color:var(--accent-2);font-weight:700}
    .help-modal h6{color:var(--accent-2);margin-top:0.8rem}
    .help-term{font-weight:700;color:#fff}
    .help-highlight{font-weight:700;text-decoration:underline;color:var(--accent-2)}
    .help-note{font-size:0.95rem;color:#cfeefb}
  </style>
</head>
<body class="bg-dark text-light">
  <div id="adminStatus" class="position-absolute top-0 end-0 p-3" style="z-index:1050;display:none;"></div>
  <div class="container py-5">
    <div class="text-center mb-4 hero">
      <h1 class="display-5 fw-bold mb-1"><span class="logo"><i class="bi bi-broadcast"></i> IPTV Detective</span></h1>
      <div class="lead">Find the cheapest provider offering the exact same IPTV package — detect resells and compare prices.</div>
      <div class="mt-3">
        <button class="btn btn-outline-light btn-lg" id="howItWorksBtn" title="How this site works" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="bi bi-info-circle"></i> How it works</button>
      </div>
    </div>
    <div id="verifyAlert" class="alert alert-warning" style="display:none">
      <strong>Site verification required:</strong> The host may require a quick verification step. Please click <a href="#" id="verifyReload">Reload</a> and allow the page to finish loading before submitting.
    </div>
    <ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="check-tab" data-bs-toggle="tab" data-bs-target="#check" type="button" role="tab">Check Provider</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="subs-tab" data-bs-toggle="tab" data-bs-target="#subs" type="button" role="tab">Submissions</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="groups-tab" data-bs-toggle="tab" data-bs-target="#groups" type="button" role="tab">Grouped Matches</button>
      </li>
    </ul>
    <div class="tab-content" id="mainTabsContent">
      <div class="tab-pane fade show active" id="check" role="tabpanel">
        <form id="iptv-form" class="card shadow-sm p-4 needs-validation" novalidate>
                    <input type="hidden" name="channel_count" id="channel_count">
                    <input type="hidden" name="group_count" id="group_count">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">IPTV Provider Name</label>
              <input class="form-control" name="name" required>
              <div class="invalid-feedback">Please provide the provider name.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Provider Link to Provider Website [not m3u]</label>
              <input class="form-control" name="link" type="url" required placeholder="https://">
              <div class="invalid-feedback">Please provide a valid URL (starting with http:// or https://).</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Price per Year (USD/EUR/GBP/CAD/AUD)</label>
              <input class="form-control" name="price" type="number" step="0.01" min="0.01" required>
              <div class="invalid-feedback">Please enter a price (minimum $0.01).</div>
            </div>
            <!-- submissions card moved below dialog -->
            <div class="col-md-6">
              <label class="form-label">Select .m3u File</label>
              <input class="form-control" type="file" name="m3u_file" accept=".m3u,.txt" required>
              <div class="mt-1">
                <div class="alert alert-warning small p-2" role="alert" style="background:rgba(255,193,7,0.04);border:1px solid rgba(255,193,7,0.12);color:#ffd27a;">
                  <strong>Don't have a file?</strong>
                  &nbsp;<a href="#" id="xtremeLink" class="fw-bold" style="color:#ffd27a;text-decoration:underline;">Generate a valid M3U from Xtream credentials</a>
                </div>
              </div>
              <div class="mt-2">
                <div class="alert alert-secondary small p-2 mt-2" role="alert" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.03);color:#d6eefc;">
                  <strong>Important:</strong> Always export/upload M3U files with <u>all channels and groups enabled</u>. If you're unsure how to enable them in your panel, contact your IPTV provider and ask them to enable all channels/groups before exporting the M3U.
                </div>
              </div>
              <div class="invalid-feedback">Please select an M3U file to upload.</div>
              <div class="mt-2">
                <span id="m3u-info" class="small text-info"></span>
                <div id="m3u-summary" class="small text-info mt-1" style="display:none"></div>
              </div>
            </div>
          </div>
          <div class="mt-4 d-flex justify-content-end">
            <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars((string)$siteKey, ENT_QUOTES, 'UTF-8'); ?>"></div>
          </div>
          <div class="mt-3 text-end">
            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-search"></i> Check & Compare</button>
          </div>
        </form>
        <div id="results" class="mt-4" style="display:none">
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title mb-3">Result</h5>
              <div class="row g-2">
                <div class="col-md-6"><strong>Provider:</strong> <span id="r_name"></span></div>
                <div class="col-md-6"><strong>Link:</strong> <span id="r_link"></span></div>
                <div class="col-md-6"><strong>Price per year (USD/EUR/GBP/CAD/AUD):</strong> <span id="r_price"></span></div>
                <div class="col-md-6"><strong>Channels:</strong> <span id="r_channels"></span></div>
                <div class="col-md-6"><strong>Groups:</strong> <span id="r_groups"></span></div>
                <div class="col-md-12 mt-2"><strong>Hash (based on M3U only):</strong> <span class="hash-box" id="r_hash"></span></div>
                <div class="col-md-12 mt-2"><strong>Comparison:</strong> <span id="r_compare"></span></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="subs" role="tabpanel">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title mb-3 d-flex align-items-center justify-content-between">
              <span>Public Submissions</span>
              <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#scoreModal" title="How scores are calculated"><i class="bi bi-info-circle"></i> How scores are calculated</button>
            </h5>
            <div id="subs-list"></div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="groups" role="tabpanel">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title mb-3 d-flex align-items-center justify-content-between">
              <span>Grouped Matches</span>
              <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#scoreModal" title="How scores are calculated"><i class="bi bi-info-circle"></i> How scores are calculated</button>
            </h5>
            <p class="small text-muted">Providers grouped by identical group lists. Click a provider to view details.</p>
            <div id="groups-list"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <!-- Help modal (inline) -->
  <div class="modal fade help-modal" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="helpModalLabel">How IPTV Detective works — simple explanation</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="lead help-note">This site helps you check an IPTV provider's channel list and compare it with other public submissions to find identical or very similar packages. It's useful for <span class="help-highlight">spotting resellers</span> (different shops selling the same package) and comparing prices.</p>

          <h6>How to use it</h6>
          <ol class="help-list">
            <li><span class="help-term">Open the <strong>Check Provider</strong> tab</span> and enter the provider name, provider website URL, and the yearly price.</li>
... (file continues identical to original index.html)
</html>