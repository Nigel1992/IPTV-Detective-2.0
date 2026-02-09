<?php
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
    // Last resort: fallback to built-in defaults
    $cfg = [ 'turnstile_site_key' => 'PLACEHOLDER_TURNSTILE_SITE_KEY', 'turnstile_secret' => 'PLACEHOLDER_TURNSTILE_SECRET' ];
}
$siteKey = isset($cfg['turnstile_site_key']) && $cfg['turnstile_site_key'] ? $cfg['turnstile_site_key'] : 'PLACEHOLDER_TURNSTILE_SITE_KEY';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>IPTV Detective 2.1</title>
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" href="favicon.png">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Cloudflare Turnstile -->
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/darkly/bootstrap.min.css" rel="stylesheet" integrity="sha384-qkU2zAXgyuetMWO55YBTK4SZzn3b91PYt/YIaQDoJWr0wpkJdglBZxwVjfN5KyR1" crossorigin="anonymous">
  <!-- Tech fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">

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

    /* Stats grid: square stat cards */
    .stats-grid{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;align-items:center}

    /* heading with glow */
    .stats-heading{margin-bottom:0.35rem;text-align:center}
    .stats-heading strong{display:inline-block;font-family:'Orbitron',sans-serif;font-weight:700;color:var(--accent);font-size:2.4rem;letter-spacing:1.6px;text-shadow:0 0 10px rgba(0,194,255,0.12),0 0 28px rgba(124,246,255,0.03);padding:0.2rem 0}
    @keyframes glowPulse{from{filter:brightness(1);text-shadow:0 0 8px rgba(0,194,255,0.12),0 0 20px rgba(124,246,255,0.03);}to{filter:brightness(1.05);text-shadow:0 0 18px rgba(0,194,255,0.18),0 0 38px rgba(124,246,255,0.06);}}
    .stats-heading strong{animation:glowPulse 2.2s ease-in-out infinite alternate}

    .stat-card{width:160px;height:160px;border-radius:10px;background:var(--glass);border:1px solid rgba(255,255,255,0.03);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.35rem;padding:0.8rem;box-shadow:0 8px 22px rgba(0,0,0,0.35);transition:transform .12s ease, box-shadow .12s ease}
    .stat-card:hover{transform:translateY(-6px);box-shadow:0 18px 40px rgba(0,0,0,0.5)}

    /* per-card accents */
    .stat-card.providers{background:linear-gradient(180deg, rgba(0,194,255,0.06), rgba(12,24,36,0.6));border-color:rgba(0,194,255,0.12)}
    .stat-card.total{background:linear-gradient(180deg, rgba(124,246,255,0.04), rgba(12,24,36,0.6));border-color:rgba(124,246,255,0.08)}
    .stat-card.recent{background:linear-gradient(180deg, rgba(255,193,7,0.06), rgba(12,24,36,0.6));border-color:rgba(255,193,7,0.12)}
    .stat-card.matches{background:linear-gradient(180deg, rgba(124,237,102,0.06), rgba(12,24,36,0.6));border-color:rgba(124,237,102,0.12)}

    .stat-card.providers .stat-count{color:var(--accent)}
    .stat-card.total .stat-count{color:var(--accent-2)}
    .stat-card.recent .stat-count{color:#ffd27a}
    .stat-card.matches .stat-count{color:#7cf67f}

    .stat-card .stat-count{font-size:28px;font-weight:800}
    .stat-card .stat-label{font-size:0.9rem;color:#cfeefb;opacity:0.95;text-align:center}
    .stat-card .btn{margin-top:0.35rem}
    @media (max-width:576px){
      .stats-heading strong{font-size:1.6rem}
      .stat-card{width:120px;height:120px}
      .stat-card .stat-count{font-size:22px}
    }

    /* Result panel styling */
    .result-panel .stat-grid{display:flex;gap:0.75rem;flex-wrap:wrap}
    /* Uniform small stat cards */
    .result-panel .stat{flex:1 1 140px;background:linear-gradient(180deg, rgba(5,12,20,0.6), rgba(10,18,30,0.55));border:1px solid rgba(255,255,255,0.04);padding:0.8rem;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:120px;min-height:84px}
    .result-panel .stat .label{font-size:0.82rem;color:#bcdff0;opacity:0.9}
    .result-panel .stat .value{font-size:1.25rem;font-weight:800;color:var(--accent);margin-top:0.25rem}
    .result-panel .meta{font-size:0.9rem;color:#cfeefb;opacity:0.85}

    /* Provider info card (left column) */
    .result-panel .info-card{background:linear-gradient(180deg, rgba(6,16,28,0.6), rgba(10,18,30,0.55));border:1px solid rgba(255,255,255,0.04);padding:0.8rem;border-radius:8px;min-height:220px}
    .info-card .info-row{display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0;border-bottom:1px dashed rgba(255,255,255,0.02)}
    .info-card .info-row:last-child{border-bottom:none}
    .info-card .info-label{color:#9bd6ea;font-size:0.85rem}
    .info-card .info-value{font-weight:700}

    /* Match / no-match card */
    .match-card{border-radius:8px;padding:0.75rem;margin-top:0.6rem}
    .match-card.success{background:linear-gradient(180deg, rgba(10,30,18,0.6), rgba(12,34,20,0.55));border:1px solid rgba(46, 192, 115, 0.18);color:#bff4cf}
    .match-card.warn{background:linear-gradient(180deg, rgba(34,22,14,0.6), rgba(36,26,16,0.55));border:1px solid rgba(255,193,7,0.12);color:#ffe7a8}
    .match-card.error{background:linear-gradient(180deg, rgba(34,12,12,0.6), rgba(36,16,16,0.55));border:1px solid rgba(255,80,80,0.12);color:#ffd6d6}

    .match-header{display:flex;justify-content:space-between;align-items:center}

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
          <div class="row">
            <div class="col-lg-6">
              <div class="card mb-3 bg-transparent border-light">
                <div class="card-body">
                  <h5 class="card-title">Provider Info</h5>
                  <div class="mb-3 mt-1">
                    <label class="form-label">Seller / Source</label>
                    <select class="form-select" name="seller_source" required>
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
                    <div class="form-text">Choose where you bought the package from.</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Seller details (username / profile / URL)</label>
                    <input class="form-control" name="seller_info" required placeholder="username, profile URL, order id, etc.">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">IPTV Provider Name</label>
                    <input class="form-control" name="name" required placeholder="Provider or brand (e.g. SuperIPTV, BestIPTV)">
                    <div class="invalid-feedback">Please provide the provider name.</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Provider Link</label>
                    <input class="form-control" name="link" type="url" required placeholder="https://provider.example.com">
                    <div class="invalid-feedback">Please provide a valid provider URL.</div>
                  </div>
                  <div class="mb-0">
                    <label class="form-label">Price per Year</label>
                    <div class="input-group">
                      <span class="input-group-text">$</span>
                      <input class="form-control" name="price" type="number" step="0.01" min="0.01" required>
                    </div>
                    <div class="invalid-feedback">Please enter a price (minimum $0.01).</div>
                  </div>
                  
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="card mb-3 bg-transparent border-light">
                <div class="card-body">
                  <h5 class="card-title">Xtream Credentials</h5>
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">Xtream Host</label>
                      <input class="form-control" name="xt_host" required placeholder="panel.example.com">
                      <div class="invalid-feedback">Please enter the Xtream host.</div>
                    </div>
                    <div class="col-6">
                      <label class="form-label">Port <small class="text-muted">(optional)</small></label>
                      <input class="form-control" name="xt_port" placeholder="80 or 8080">
                      <div class="form-text text-muted">Port is optional; leave blank to use default (80/443).</div>
                    </div>
                    <div class="col-6">
                      <label class="form-label">Username</label>
                      <input class="form-control" name="xt_user" required>
                      <div class="invalid-feedback">Please enter the Xtream username.</div>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Password</label>
                      <input class="form-control" name="xt_pass" type="password" required>
                      <div class="invalid-feedback">Please enter the Xtream password.</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">Tip: reload page if site verification prompts appear.</div>
            <div class="text-end">
              <div class="mb-2 d-inline-block" style="transform:translateY(-10px);">
                <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($siteKey); ?>" style="display:inline-block;"></div>
              </div>
              <div>
                <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-search"></i> Check &amp; Compare</button>
              </div>
            </div>
          </div>
        </form>
        <div id="results" class="mt-4" style="display:none">
          <div class="card shadow-sm result-panel">
            <div class="card-body">
              <h5 class="card-title mb-3">Result</h5>

              <div class="row mb-3">
                <div class="col-md-6">
                  <div class="info-card">
                    <div class="info-row"><div class="info-label">Provider</div><div class="info-value" id="r_name">&nbsp;</div></div>
                    <div class="info-row"><div class="info-label">Link</div><div class="info-value"><a id="r_link" href="#" target="_blank" class="text-decoration-none text-info">&nbsp;</a></div></div>
                    <div class="info-row"><div class="info-label">Price / year</div><div class="info-value" id="r_price">&nbsp;</div></div>
                    <div class="info-row"><div class="info-label">Seller</div><div class="info-value" id="r_seller">&nbsp;</div></div>
                    <div class="mt-2 small text-muted">Results are approximate and depend on available public submissions.</div>
                  </div>
                </div>

                <div class="col-md-6">
                  <div class="stat-grid">
                    <div class="stat"><div class="label">Live categories</div><div id="r_live_cats" class="value">N/A</div></div>
                    <div class="stat"><div class="label">Live streams</div><div id="r_live_streams" class="value">N/A</div></div>
                    <div class="stat"><div class="label">Series</div><div id="r_series_count" class="value">N/A</div></div>
                    <div class="stat"><div class="label">Series categories</div><div id="r_series_cats_count" class="value">N/A</div></div>
                    <div class="stat"><div class="label">VOD categories</div><div id="r_vod_cats_count" class="value">N/A</div></div>
                    <div class="stat" id="vod_streams_row" style="display:none"><div class="label">VOD streams</div><div id="r_vod_streams_count" class="value">N/A</div></div>
                  </div>

              <div id="fetchProgressWrap" style="display:none;margin-top:0.75rem">
                <div class="progress">
                  <div id="fetchProgressBar" class="progress-bar bg-info" role="progressbar" style="width:0%">0%</div>
                </div>
                <div id="fetchProgressLabel" class="small text-muted mt-1">Fetching data</div>
              </div>
                </div>
              </div>

              <div id="r_compare" class="mt-2"></div>

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
          <h5 class="mb-3">How IPTV Detective works — simple explanation</h5>
          <p>This site helps you check an IPTV provider's channel list and compare it with other public submissions to find identical or very similar packages. It's useful for spotting resellers (different shops selling the same package) and comparing prices.</p>

          <h6 class="mt-3">How it works (brief)</h6>
          <p>IPTV Detective analyzes a provider's playlist and compares it to public submissions to identify identical or highly similar packages — useful for spotting resellers and finding better prices.</p>

          <h6 class="mt-3">Fetch &amp; analyze in-browser</h6>
          <p>When you enter Xtream/portal credentials the browser fetches the playlist directly from the panel (using player API endpoints) and parses it locally. This avoids uploading full playlists to the server for most checks.</p>

          <h6 class="mt-3">What is sent to the server</h6>
          <p>Only small numeric metrics (counts) are sent to the server for comparison. Your Xtream credentials are not sent during the normal "Check &amp; Compare" flow. If you explicitly submit a provider for listing, optional fields (such as submitted raw links) may be stored for admin review; Xtream credentials are never stored and will not be saved with submissions.</p>

          <h6 class="mt-3">Comparison</h6>
          <p>The server compares submissions using available metrics (live streams, live categories, series, series categories, VOD categories) and/or legacy channel/group counts. The system prefers structured metrics when present and falls back to channel/group counts for older databases.</p>

          <h6 class="mt-3">What we show</h6>
          <ul class="help-list">
            <li><strong>Counts &amp; summary</strong> — numbers of streams, categories and VOD counts.</li>
            <li><strong>Similarity score</strong> — a single percentage combining multiple metrics to indicate how close two packages are.</li>
            <li><strong>Matched providers</strong> — candidates with high similarity and a short summary including price comparison.</li>
          </ul>

          <h6 class="mt-3">Privacy &amp; storage</h6>
          <p>By default the playlist is parsed in your browser and only small summaries are uploaded. The server does not receive your plain-text Xtream password during the check flow. If you choose to "submit" a provider for the public database, that submission may be stored (including optional raw links or credentials if provided) — submissions are visible to admins and may be displayed publicly depending on site settings.</p>

          <h6 class="mt-3">Tips</h6>
          <ul class="help-list">
            <li>Use a provider website URL that starts with <code>https://</code> or <code>http://</code>.</li>
            <li>Large playlists take longer to fetch — use counts-only submission if fetch fails.</li>
            <li>Do not paste credentials into the provider URL; use the dedicated Xtream fields.</li>
            <li>Refreshing the page may be required if site verification (anti-bot) appears.</li>
          </ul>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back to site</button>
        </div>
      </div>
    </div>
  </div>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">

  <!-- Score explanation modal -->
  <div class="modal fade help-modal" id="scoreModal" tabindex="-1" aria-labelledby="scoreModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="scoreModalLabel">How scores and matches are calculated</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="lead help-note">This explains how the project computes similarity between IPTV packages and why results look the way they do.</p>

          <h6>1) Metrics used for comparison</h6>
          <p>We compare numeric metrics reported by the playlist fetch: live streams, live categories, series, series categories and VOD categories. Older installs may instead use legacy channel and group counts.</p>

          <h6>2) Weights and score composition</h6>
          <p class="help-note">Each metric is converted to a percentage similarity and combined using project-specific weights. Current weights (used when structured metrics are present):</p>
          <ul class="help-list">
            <li><strong>live_streams</strong>: 30%</li>
            <li><strong>live_categories</strong>: 20%</li>
            <li><strong>series</strong>: 20%</li>
            <li><strong>series_categories</strong>: 15%</li>
            <li><strong>vod_categories</strong>: 5%</li>
          </ul>
          <p>On older databases where these metrics are not available we fall back to a legacy comparison using channel and group counts (each contributing roughly 45% of the score).</p>

          <h6>3) Thresholds and labels used by the site</h6>
          <ul class="help-list">
            <li><strong>Match (stored as matched)</strong>: similarity &gt;= 85% — very likely the same package.</li>
            <li><strong>Strong partial</strong>: 70%–85% — high overlap, worth manual review.</li>
            <li><strong>Partial</strong>: 50%–70% — some overlap but likely different.</li>
            <li><strong>No match</strong>: &lt; 50% — different package.</li>
          </ul>

          <h6>4) Practical notes</h6>
          <ul class="help-list">
            <li>URLs and group names are normalised (case, trailing slashes, simple params) to reduce false mismatches.</li>
            <li>Similarity is a heuristic — a high score is a strong signal but human review is recommended before taking action.</li>
          </ul>

          <div class="mt-3"><strong>Example:</strong> If live_streams and live_categories both match closely, the weighted score will reflect those priorities (live_streams has the largest influence at 30%).</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- M3U info modal (shown before choosing file) -->
  <!-- Removed - no longer needed -->
  <script>
        // MD5 helper using WebCrypto if available; fallback to a compact, tested JS implementation
        async function md5hex(msg){
          // try WebCrypto (may not be available for 'MD5' on all browsers)
          try{
            if (crypto && crypto.subtle && crypto.subtle.digest) {
              const enc = new TextEncoder();
              const buf = await crypto.subtle.digest('MD5', enc.encode(msg));
              return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2,'0')).join('');
            }
          }catch(e){ /* fall back to JS */ }
          return md5_js(msg);
        }
        function md5_js(s){
          // MD5 on bytes via TextEncoder (robust and deterministic)
          const K = [
            0xd76aa478,0xe8c7b756,0x242070db,0xc1bdceee,0xf57c0faf,0x4787c62a,0xa8304613,0xfd469501,
            0x698098d8,0x8b44f7af,0xffff5bb1,0x895cd7be,0x6b901122,0xfd987193,0xa679438e,0x49b40821,
            0xf61e2562,0xc040b340,0x265e5a51,0xe9b6c7aa,0xd62f105d,0x02441453,0xd8a1e681,0xe7d3fbc8,
            0x21e1cde6,0xc33707d6,0xf4d50d87,0x455a14ed,0xa9e3e905,0xfcefa3f8,0x676f02d9,0x8d2a4c8a,
            0xfffa3942,0x8771f681,0x6d9d6122,0xfde5380c,0xa4beea44,0x4bdecfa9,0xf6bb4b60,0xbebfbc70,
            0x289b7ec6,0xeaa127fa,0xd4ef3085,0x04881d05,0xd9d4d039,0xe6db99e5,0x1fa27cf8,0xc4ac5665,
            0xf4292244,0x432aff97,0xab9423a7,0xfc93a039,0x655b59c3,0x8f0ccc92,0xffeff47d,0x85845dd1,
            0x6fa87e4f,0xfe2ce6e0,0xa3014314,0x4e0811a1,0xf7537e82,0xbd3af235,0x2ad7d2bb,0xeb86d391
          ];
          const S = [
            7,12,17,22, 7,12,17,22, 7,12,17,22, 7,12,17,22,
            5,9,14,20, 5,9,14,20, 5,9,14,20, 5,9,14,20,
            4,11,16,23, 4,11,16,23, 4,11,16,23, 4,11,16,23,
            6,10,15,21, 6,10,15,21, 6,10,15,21, 6,10,15,21
          ];

          const enc = new TextEncoder();
          const msgBytes = enc.encode(s);
          const origBitLen = msgBytes.length * 8;

          // pad: append 0x80 then zeros until length mod 64 == 56
          const withOne = new Uint8Array(msgBytes.length + 1);
          withOne.set(msgBytes); withOne[msgBytes.length] = 0x80;
          let paddedLen = withOne.length;
          while (paddedLen % 64 !== 56) paddedLen++;
          const padded = new Uint8Array(paddedLen + 8);
          padded.set(withOne);
          const dv = new DataView(padded.buffer);
          // append length in bits, little-endian 64-bit
          dv.setUint32(padded.length - 8, origBitLen & 0xffffffff, true);
          dv.setUint32(padded.length - 4, Math.floor(origBitLen / 0x100000000), true);

          let a0 = 0x67452301, b0 = 0xefcdab89, c0 = 0x98badcfe, d0 = 0x10325476;

          for (let i = 0; i < padded.length; i += 64) {
            const M = new Uint32Array(16);
            for (let j = 0; j < 16; j++) M[j] = dv.getUint32(i + j*4, true);
            let A = a0, B = b0, C = c0, D = d0;
            for (let k = 0; k < 64; k++) {
              let F, g;
              if (k < 16) { F = (B & C) | (~B & D); g = k; }
              else if (k < 32) { F = (D & B) | (~D & C); g = (5*k + 1) % 16; }
              else if (k < 48) { F = B ^ C ^ D; g = (3*k + 5) % 16; }
              else { F = C ^ (B | ~D); g = (7*k) % 16; }
              const tmp = D;
              D = C;
              C = B;
              const sum = (A + F + K[k] + (M[g] >>> 0)) >>> 0;
              const rotated = ((sum << S[k]) | (sum >>> (32 - S[k]))) >>> 0;
              B = (B + rotated) >>> 0;
              A = tmp;
            }
            a0 = (a0 + A) >>> 0; b0 = (b0 + B) >>> 0; c0 = (c0 + C) >>> 0; d0 = (d0 + D) >>> 0;
          }

          function toHexLE(n){ let s = ''; for (let i = 0; i < 4; i++) s += ('0' + ((n >>> (i*8)) & 0xff).toString(16)).slice(-2); return s; }
          return toHexLE(a0) + toHexLE(b0) + toHexLE(c0) + toHexLE(d0);
        }

        // Parse M3U file locally and show channel/group counts
        // Removed - now using Xtream credentials

    // Show verification banner if anti-bot cookie not present
    (function(){
      const alertEl = document.getElementById('verifyAlert');
      const reloadLink = document.getElementById('verifyReload');
      if (alertEl) {
        if (!document.cookie.includes('__test=')) {
          alertEl.style.display = '';
          reloadLink.addEventListener('click', function(e){ e.preventDefault(); window.location.reload(true); });
        } else {
          alertEl.style.display = 'none';
        }
        // Re-check on visibility change (user may complete verification in another tab)
        document.addEventListener('visibilitychange', function(){ if (document.visibilityState === 'visible') { if (document.cookie.includes('__test=')) alertEl.style.display='none'; }});
      }
    })();

    // Server-backed submissions

    async function renderSubs() {
      const el = document.getElementById('subs-list');
      if (!document.cookie.includes('__test=')) {
        el.innerHTML = '<div class="alert alert-warning">Site verification required. Please reload the page and allow verification to complete, then open Submissions.</div>';
        return;
      }
      el.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span> Loading...</div>';
      try {
        const res = await fetch('get_submissions.php');
        const data = await res.json();
        if (!data || !data.length) {
          el.innerHTML = '<div class="alert alert-secondary">No submissions yet.</div>';
          return;
        }
        let html = `<div class="table-responsive"><table class="table table-sm table-dark table-hover align-middle"><thead class="table-secondary text-dark"><tr><th>Date</th><th>Provider</th><th>Link</th><th>Price per year (USD/EUR/GBP/CAD/AUD)</th><th>Channels</th><th>Groups</th><th style="width:180px">Similarity</th><th>Status</th></tr></thead><tbody>`;
        for (let s of data) {
          // cache submission data for detail view
          window._submissionCache = window._submissionCache || {};
          window._submissionCache[s.id] = s;
          const created = s.created_at || '';
          const sim = s.similarity_score ? (parseFloat(s.similarity_score).toFixed(2) + '%') : '-';
          const simVal = s.similarity_score ? Math.min(100, Math.round(parseFloat(s.similarity_score))) : 0;
          const status = s.matched ? `<span class='badge bg-success'><i class='bi bi-check-circle'></i> Match</span>` : `<span class='badge bg-secondary text-dark'><i class='bi bi-lock'></i> No match</span>`;
          html += `<tr>
            <td><small>${created}</small></td>
            <td><div class="provider-name">${escapeHtml(s.name)}</div></td>
            <td><a href="${escapeHtml(s.link)}" target="_blank">${escapeHtml(s.link)}</a></td>
            <td>${s.price} / year</td>
            <td>${s.channels}</td>
            <td>${s.groups}</td>
            <td>
              <div class="small mb-1">${sim}</div>
              <div class="progress" style="height:8px"> 
                <div class="progress-bar ${simVal>=80?'bg-success':(simVal>=50?'bg-warning':'bg-secondary')}" role="progressbar" style="width:${simVal}%"></div>
              </div>
            </td>
            <td class="text-end"><div class="d-flex justify-content-end align-items-center gap-2">${status}<button class="btn btn-sm btn-outline-primary view-details" data-id="${s.id}" title="More info" aria-label="More info"><i class="bi bi-eye"></i>&nbsp;More info</button></div></td> 
          </tr>`;
        }
        html += '</tbody></table>';
        el.innerHTML = html;
      } catch (e) {
        el.innerHTML = '<div class="alert alert-danger">Failed to load submissions</div>';
        console.error(e);
      }
    }

    function escapeHtml(s){ if(!s) return ''; return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]; }); }

    // flagging removed
    function flagProvider(){ throw new Error('Flagging removed'); }

    document.getElementById('iptv-form').onsubmit = async function(e){
      e.preventDefault();
      const form = this;
      let btn = null;
      try {
      // Bootstrap validation
      if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return;
      }
      btn = form.querySelector('button[type=submit], button, input[type=submit]');
      if (btn) { try { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Fetching...'; } catch(e) {} }
      const name = form.name.value.trim();
      // Provider link input removed; server accepts optional link if provided elsewhere
      const price = parseFloat(form.price.value);
      // Get Xtream credentials
      const xtHost = form.xt_host.value.trim();
      const xtPort = form.xt_port.value.trim();
      // If port is provided, it must be numeric
      if (xtPort && !/^[0-9]+$/.test(xtPort)) {
        form.classList.add('was-validated');
        alert('Port must be a numeric value');
        if (btn) { try { btn.disabled = false; btn.innerHTML = 'Submit'; } catch(e) {} }
        return;
      }
      const xtUser = form.xt_user.value.trim();
      const xtPass = form.xt_pass.value.trim();
      // Seller/source information
      const sellerSource = (form.seller_source && form.seller_source.selectedIndex>=0) ? form.seller_source.options[form.seller_source.selectedIndex].text : '';
      const sellerInfo = form.seller_info ? form.seller_info.value.trim() : '';
      // Anti-bot check
      if (!document.cookie.includes('__test=')) {
        alert('Please reload the page to complete site verification (anti-bot). After reload, re-enter your credentials and submit.');
        window.location.reload(true);
        return;
      }
      // Captcha check
      const turnstileResponse = form['cf-turnstile-response'].value;
      if (!turnstileResponse) {
        alert('Please complete the captcha.');
        if (btn) { btn.disabled=false; btn.innerHTML='<i class="bi bi-search"></i> Check & Compare'; }
        return;
      }
      // Additional client-side validation
      if (link) {
        try { new URL(link); } catch (e) { alert('Please enter a valid URL for Provider Link.'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="bi bi-search"></i> Check & Compare'; } return; }
      }
      if (!(price > 0)) { alert('Please enter a valid price greater than 0'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="bi bi-search"></i> Check & Compare'; } return; }
      if (!xtHost || !xtUser || !xtPass) { alert('Please fill in all Xtream credentials.'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="bi bi-search"></i> Check & Compare'; } return; }

      const scheme = xtHost.startsWith('http') ? '' : 'http://';
      const hostPort = xtPort ? xtHost + ':' + xtPort : xtHost;

      // Helper: fetch with retries for transient 404/5xx errors
      async function fetchWithRetries(url, opts = {}, retries = 3, backoff = 300) {
        let attempt = 0;
        while (true) {
          attempt++;
          try {
            const res = await fetch(url, opts);
            if (res.ok) return res;
            // Retry for transient server errors and 404 (intermittent) up to retries
            if ([404, 429, 502, 503, 504].includes(res.status) && retries > 0) {
              console.warn(`fetch attempt ${attempt} got ${res.status}, retrying in ${backoff}ms`);
              await new Promise(r => setTimeout(r, backoff));
              retries--; backoff *= 2; continue;
            }
            return res;
          } catch (e) {
            if (retries > 0) {
              console.warn(`fetch attempt ${attempt} failed: ${e}. retrying in ${backoff}ms`);
              await new Promise(r => setTimeout(r, backoff));
              retries--; backoff *= 2; continue;
            }
            throw e;
          }
        }
      }

      // Use player_api.php endpoints to gather counts via the server proxy
      if (btn) { btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Processing...'; }

      // Show provider info immediately so user sees it's processing
      try {
        const resultsEl = document.getElementById('results'); if (resultsEl) resultsEl.style.display = '';
        const nameEl = document.getElementById('r_name'); if (nameEl) nameEl.textContent = name;
        const linkElNow = document.getElementById('r_link'); if (linkElNow) {
          if (link) { linkElNow.textContent = link; linkElNow.href = link; linkElNow.target = '_blank'; }
          else { linkElNow.textContent = '—'; linkElNow.href = '#'; linkElNow.removeAttribute('target'); }
        }
        const priceElNow = document.getElementById('r_price'); if (priceElNow) priceElNow.textContent = price.toFixed(2);
          const sellerElNow = document.getElementById('r_seller'); if (sellerElNow) sellerElNow.textContent = (sellerSource || sellerInfo) ? (sellerSource + (sellerInfo ? ' — ' + sellerInfo : '')) : '—';
        ['r_live_cats','r_live_streams','r_series_count','r_series_cats_count','r_vod_cats_count','r_vod_streams_count'].forEach(id=>{ const el=document.getElementById(id); if(el) el.textContent='—'; });
        const vodRow = document.getElementById('vod_streams_row'); if (vodRow) vodRow.style.display = 'none';
        const rCompare = document.getElementById('r_compare'); if (rCompare) rCompare.innerHTML = '<div class="small text-muted">Fetching comparison...</div>';
      } catch (ex) {}

      let apiCounts = {
        live_categories: null,
        live_streams: null,
        series: null,
        series_categories: null,
        vod_categories: null
      };

      const apiActions = {
        live_categories: 'get_live_categories',
        live_streams: 'get_live_streams',
        series: 'get_series',
        series_categories: 'get_series_categories',
        vod_categories: 'get_vod_categories'
      };

      const makeApiUrl = (action) => {
        const base = `${scheme}${hostPort}/player_api.php?username=${encodeURIComponent(xtUser)}&password=${encodeURIComponent(xtPass)}&action=`;
        return base + encodeURIComponent(action);
      };

      const fetchApiAction = async (action) => {
        const url = makeApiUrl(action);
        // Request full response (may be large) — we ask proxy for full response up to server caps
        const proxyUrl = 'inc/proxy.php?url=' + encodeURIComponent(url) + '&full=1&timeout=60&max_mb=50';
        try {
          const r = await fetchWithRetries(proxyUrl, { signal: (new AbortController()).signal }, 3);
          if (!r.ok) {
            console.warn('API fetch failed for', action, r.status);
            return { ok: false, status: r.status };
          }
          const body = await (async () => {
            try { return await r.json(); } catch (e) { const txt = await r.text(); try { return JSON.parse(txt); } catch(e2) { return txt; } }
          })();
          // If proxy returned count object, honor it
          if (body && typeof body === 'object' && (body.count !== undefined || body.error !== undefined)) {
            return { ok: true, body };
          }
          // Fallback: if body is array or object, try to deduce array inside
          return { ok: true, body };
        } catch (e) {
          console.warn('API fetch exception for', action, e);
          return { ok: false, status: null, error: e };
        }
      };

      // Optional: attempt to fetch API directly from the user's browser (may be blocked by CORS/mixed-content)
      async function fetchApiActionLocal(action) {
        const url = makeApiUrl(action); // direct URL to xtream
        console.groupCollapsed(`[Local Fetch] ${action}`);
        console.log('Request URL:', url);
        // Mixed-content pre-check
        if (location.protocol === 'https:' && url.startsWith('http:')) {
          const msg = 'Mixed content blocked: browser will prevent HTTP requests from an HTTPS page. Use server proxy or allow insecure content in your browser.';
          console.warn(msg);
          console.groupEnd();
          return { ok: false, error: msg };
        }
        const start = performance.now();
        let attempt = 0;
        try {
          const opts = { mode: 'cors', credentials: 'omit' };
          for (attempt = 1; attempt <= 3; attempt++) {
            const t0 = performance.now();
            try {
              console.log(`Attempt ${attempt}: fetch`, { url, opts });
              const r = await fetchWithRetries(url, opts, 2);
              const dur = (performance.now() - t0).toFixed(1);
              console.log(`Response status: ${r.status} (${dur} ms)`);
              const ctype = (r.headers.get('content-type') || '').toLowerCase();
              console.log('Content-Type:', ctype);
              const clen = r.headers.get('content-length') || 'unknown';
              console.log('Content-Length header:', clen);
              // try to parse body safely and log previews
              let body;
              if (ctype.includes('application/json') || ctype.includes('text/json')) {
                try {
                  body = await r.clone().json();
                  console.log('JSON body preview:', (typeof body === 'object') ? JSON.stringify(body).slice(0,1000) : String(body).slice(0,1000));
                } catch (e) {
                  const txt = await r.clone().text();
                  console.warn('JSON parse failed; text preview:', txt.slice(0,1000));
                  body = txt;
                }
              } else {
                const txt = await r.clone().text();
                console.log('Text body preview:', txt.slice(0,1000));
                body = txt;
              }
              const totalDur = (performance.now() - start).toFixed(1);
              console.log(`Fetch succeeded in ${totalDur} ms`);
              console.groupEnd();
              return { ok: true, body };
            } catch (err) {
              console.warn(`Attempt ${attempt} failed:`, err);
              // TypeError often means CORS/mixed-content or network error
              if (err instanceof TypeError) {
                const msg = 'TypeError: likely CORS or network error (browser blocked request). Use server proxy or enable insecure content.';
                console.warn(msg);
                console.groupEnd();
                return { ok: false, error: msg };
              }
              // retry delay
              if (attempt < 3) await new Promise(r => setTimeout(r, 200 * attempt));
              else {
                const em = (err && err.message) ? err.message : String(err);
                console.error('Local fetch failed after retries:', em);
                console.groupEnd();
                return { ok: false, error: em };
              }
            }
          }
        } catch (e) {
          console.error('Unexpected error in local fetch:', e);
          console.groupEnd();
          return { ok: false, error: (e && e.message) ? e.message : String(e) };
        }
      };

      // 'Fetch from my machine' handler removed per request (samples and local fetch disabled for privacy)      })();

      // Fetch all actions in parallel with progress updates
      try {
        const totalActions = Object.keys(apiActions).length;
        let completedActions = 0;
        // show progress UI
        const progressWrap = document.getElementById('fetchProgressWrap');
        const progressBar = document.getElementById('fetchProgressBar');
        const progressLabel = document.getElementById('fetchProgressLabel');
        if (progressWrap && progressBar && progressLabel) {
          progressWrap.style.display = '';
          // Ensure results section is visible so progress bar can be seen
          const resultsEl = document.getElementById('results'); if (resultsEl) resultsEl.style.display = '';
          progressBar.classList.add('progress-bar-striped','progress-bar-animated');
          progressBar.style.width = '0%';
          progressBar.textContent = '0%';
          progressLabel.textContent = `Fetching data (0/${totalActions})`;
        }

        const promises = Object.entries(apiActions).map(async ([k, act]) => {
          const res = await fetchApiAction(act);
          let count = null; let sample = [];
          if (!res.ok) {
            // If upstream returns 404, abort immediately with a clear error
            if (res.status === 404) {
              throw new Error(`Upstream endpoint returned 404 for action '${act}'. This usually indicates an invalid host, port, or credentials.`);
            }
            // otherwise record as unavailable and continue
            apiCounts[k] = null;
          } else {
            const body = res.body;
            // Determine count and sample list
            // If proxy provided a count-only response
            if (body && typeof body === 'object' && body.count !== undefined) {
              count = Number(body.count) || 0;
              sample = Array.isArray(body.sample) ? body.sample.slice(0,10) : [];
            } else if (Array.isArray(body)) { count = body.length; sample = body.slice(0,10); }
            else if (typeof body === 'object' && body !== null) {
              const arr = body.categories || body.playlist || body.streams || body.items || body.data || null;
              if (Array.isArray(arr)) { count = arr.length; sample = arr.slice(0,10); }
              else {
                const findArray = obj => {
                  if (!obj || typeof obj !== 'object') return null;
                  for (const key in obj) {
                    if (Array.isArray(obj[key])) return obj[key];
                    if (typeof obj[key] === 'object') {
                      const r = findArray(obj[key]); if (r) return r;
                    }
                  }
                  return null;
                };
                const arr2 = findArray(body);
                if (Array.isArray(arr2)) { count = arr2.length; sample = arr2.slice(0,10); }
              }
            } else if (typeof body === 'string') {
              const lines = body.split(/\r?\n/).map(s=>s.trim()).filter(Boolean);
              count = lines.length; sample = lines.slice(0,10);
            }
            apiCounts[k] = { count, sample };
          }
          // update progress
          completedActions++;
          if (progressBar && progressLabel) {
            const pct = Math.round((completedActions / totalActions) * 100);
            progressBar.style.width = pct + '%';
            progressBar.textContent = pct + '%';
            progressLabel.textContent = `Fetching data (${completedActions}/${totalActions})`;
          }
        });
        await Promise.all(promises);
        // hide progress UI
        if (progressWrap && progressBar && progressLabel) {
          progressBar.classList.remove('progress-bar-striped','progress-bar-animated');
          setTimeout(()=>{ progressWrap.style.display = 'none'; }, 400);
        }
      } catch (e) {
        console.error('API fetches failed', e);
        // If this error was thrown due to a 404 upstream, surface a clear message to the user and abort
        const msg = (e && e.message) ? e.message : 'API fetches failed';
        // display inline error in results area and as an alert
        try {
          const rCompareEl = document.getElementById('r_compare'); if (rCompareEl) rCompareEl.innerHTML = `<div class="match-card error"><div class="match-header"><div class="d-flex align-items-center gap-2"><i class="bi bi-exclamation-octagon-fill"></i><div class="match-title">Fetch cancelled</div></div></div><div class="mt-2 small">${escapeHtml(msg)} — please check host/port/credentials and try again.</div></div>`;
        } catch (ex) {}
        alert('Fetch cancelled: ' + msg + '\nPlease check your host, port, username and password, then try again.');
        // hide progress UI
        const progressWrap = document.getElementById('fetchProgressWrap');
        if (progressWrap) progressWrap.style.display = 'none';
        // Re-throw so the outer submission logic aborts and re-enables UI via outer catch
        throw e;
      }

      // Populate UI with counts
      const setCountUI = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = (val === null ? 'N/A' : val); };
      setCountUI('r_live_cats', apiCounts.live_categories && apiCounts.live_categories.count !== null ? apiCounts.live_categories.count : 'N/A');
      setCountUI('r_live_streams', apiCounts.live_streams && apiCounts.live_streams.count !== null ? apiCounts.live_streams.count : 'N/A');
      setCountUI('r_series_count', apiCounts.series && apiCounts.series.count !== null ? apiCounts.series.count : 'N/A');
      setCountUI('r_series_cats_count', apiCounts.series_categories && apiCounts.series_categories.count !== null ? apiCounts.series_categories.count : 'N/A');
      setCountUI('r_vod_cats_count', apiCounts.vod_categories && apiCounts.vod_categories.count !== null ? apiCounts.vod_categories.count : 'N/A');
      // VOD streams disabled by request; do not populate r_vod_streams_count (leave as N/A)

      // Do not show item name samples for privacy
      const listsEl = document.getElementById('r_lists');
      if (listsEl) {
        listsEl.innerHTML = '';
      }

      // Prepare form data
      const fd = new FormData();
      fd.append('name', name); fd.append('price', price.toFixed(2));
      const liveCats = apiCounts.live_categories && apiCounts.live_categories.count !== null ? apiCounts.live_categories.count : 0;
      const liveStreams = apiCounts.live_streams && apiCounts.live_streams.count !== null ? apiCounts.live_streams.count : 0;
      const seriesCnt = apiCounts.series && apiCounts.series.count !== null ? apiCounts.series.count : 0;
      const seriesCats = apiCounts.series_categories && apiCounts.series_categories.count !== null ? apiCounts.series_categories.count : 0;
      const vodCats = apiCounts.vod_categories && apiCounts.vod_categories.count !== null ? apiCounts.vod_categories.count : 0;
      // VOD streams disabled — report 0 by default
      const vodStreams = 0;
      fd.append('live_categories_count', liveCats);
      fd.append('live_streams_count', liveStreams);
      fd.append('series_count', seriesCnt);
      fd.append('series_categories_count', seriesCats);
      fd.append('vod_categories_count', vodCats);
      fd.append('vod_streams_count', vodStreams);
      // Provide legacy fields so server accepts the submission
      fd.append('channel_count', liveStreams);
      fd.append('group_count', liveCats);
      const md5Hash = ''; // Placeholder for MD5 hash - not currently used
      fd.append('md5', md5Hash);
      fd.append('counts_only', '1');
      // include seller/source fields
      fd.append('seller_source', sellerSource || '');
      fd.append('seller_info', sellerInfo || '');
      // Add captcha token
      fd.append('cf-turnstile-response', turnstileResponse);

      const res = await fetch('submit_provider.php', { method: 'POST', body: fd });
      let data;
      const ctype = (res.headers.get('content-type') || '').toLowerCase();
      if (ctype.includes('application/json')) {
        try { data = await res.json(); } catch (e) { throw new Error('Invalid JSON from server'); }
      } else {
        const txt = await res.text();
        if (/This site requires Javascript|slowAES|aes\.js/i.test(txt)) {
          throw new Error('Server requires JavaScript/cookies (anti-bot). Please reload this page in your browser and try again.');
        }
        const snippet = txt.replace(/\s+/g,' ').slice(0,1000);
        throw new Error('Invalid response from server: ' + snippet);
      }

      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-search"></i> Check & Compare'; }
      if (!res.ok) { alert('Error: ' + (data && data.error ? data.error : 'Server error')); return; }
      if (data.error) { alert('Error: ' + data.error); return; }
      const setIf = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
      setIf('r_name', name);
      // Link element content and href
      const linkEl = document.getElementById('r_link'); if (linkEl) { linkEl.textContent = link; linkEl.href = link; }
      setIf('r_price', price.toFixed(2));
      setIf('r_channels', data.channels);
      setIf('r_groups', data.groups);
      // ensure VOD streams row is hidden when empty
      try {
        const vodVal = Number(document.getElementById('r_vod_streams_count')?.textContent) || 0;
        const vodRow = document.getElementById('vod_streams_row'); if (vodRow) vodRow.style.display = vodVal > 0 ? '' : 'none';
      } catch (ex) {}
      let compareHtml = '';
      if (data.matched) {
        const diff = (data.match_price_diff !== null && data.match_price_diff !== undefined) ? Number(data.match_price_diff) : null;
        const diffText = diff === null ? '' : (diff < 0 ? `<span class='text-success fw-bold'>Cheaper by $${Math.abs(diff)}</span>` : `<span class='text-danger fw-bold'>More expensive by $${diff}</span>`);
        const visit = data.match_link ? ` <a class="visit-inline" href="${escapeHtml(data.match_link)}" target="_blank">Visit</a>` : '';

        compareHtml = `
          <div class="match-card success">
            <div class="match-header">
              <div class="d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill fs-5"></i><div class="match-title">Match</div></div>
              <div class="text-muted small">Similarity: <strong>${data.similarity}%</strong></div>
            </div>
            <div class="mt-2">
              <div><strong>${escapeHtml(data.match_name || 'Matched provider')}</strong> <span class="text-muted">($${data.match_price !== null && data.match_price !== undefined ? data.match_price : 'N/A'})</span> ${diffText}${visit}</div>
              ${data.match_channels_text ? `<div class="small text-muted mt-2">${escapeHtml(data.match_channels_text)}</div>` : ''}
              ${data.match_groups_text ? `<div class="small text-muted">${escapeHtml(data.match_groups_text)}</div>` : ''}
              ${data.cheapest_match && data.cheapest_match.name ? `<div class="small mt-2">Cheapest similar: <strong>${escapeHtml(data.cheapest_match.name)}</strong> ($${data.cheapest_match.price}) ${data.cheapest_match.link ? ` — <a href="${escapeHtml(data.cheapest_match.link)}" target="_blank">Visit</a>` : ''}</div>` : ''}
            </div>
          </div>`;
      } else if (data.similarity && data.similarity > 0) {
        compareHtml = `<div class="match-card warn"><div class="match-header"><div class="d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><div class="match-title">Similar</div></div><div class="text-muted small">Similarity: <strong>${data.similarity}%</strong></div></div><div class="mt-2 small text-muted">Likely similar package — please review manually.</div></div>`;
      } else {
        compareHtml = `<div class="match-card error"><div class="match-header"><div class="d-flex align-items-center gap-2"><i class="bi bi-lock-fill"></i><div class="match-title">No match found</div></div></div><div class="mt-2 small text-muted">Likely private or not enough data</div></div>`;
      }
      const rCompareEl = document.getElementById('r_compare'); if (rCompareEl) rCompareEl.innerHTML = compareHtml;
      const resultsEl = document.getElementById('results');
      if (resultsEl) {
        resultsEl.style.display = '';
        try { resultsEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(e) { window.scrollTo({ top: resultsEl.offsetTop, behavior: 'smooth' }); }
      }
      renderSubs();
      } catch (e) {
        alert('Submission failed: ' + (e.message || 'Unknown error'));
        if (btn) { btn.disabled=false; btn.innerHTML='<i class="bi bi-search"></i> Check & Compare'; }
        console.error(e);
      }
    };

    // Initial load: check admin access to enable Submissions/Grouped Matches tabs
    (async function(){
      try{
        const r = await fetch('is_admin.php', { credentials: 'same-origin', cache: 'no-store' });
        const j = await r.json();
        const isAdmin = j && j.admin;
        const subsBtn = document.getElementById('subs-tab');
        const groupsBtn = document.getElementById('groups-tab');
        const adminStatus = document.getElementById('adminStatus');
        if (!isAdmin) {
          // hide the nav items for non-admins
          if (subsBtn && subsBtn.parentElement) subsBtn.parentElement.style.display = 'none';
          if (groupsBtn && groupsBtn.parentElement) groupsBtn.parentElement.style.display = 'none';
        } else {
          // register listeners and render submissions
          if (subsBtn) subsBtn.addEventListener('click', renderSubs);
          if (groupsBtn) groupsBtn.addEventListener('click', renderGroupedMatches);
          renderSubs();
          // show admin badge in top-right header
          if (adminStatus) {
            const user = j.user || 'admin';
            adminStatus.style.display = '';
            // Use relative path for admin link
            adminStatus.innerHTML = '<a href="admin_9f4b1a.php" class="badge" style="background:#ff8c00;color:#fff;padding:0.55rem 0.7rem;">Admin signed in: ' + escapeHtml(user) + '</a>';
          }
        }
      } catch(e){
        console.warn('is_admin check failed', e);
        // by default hide admin tabs to be safe
        const subsBtn = document.getElementById('subs-tab');
        const groupsBtn = document.getElementById('groups-tab');
        if (subsBtn && subsBtn.parentElement) subsBtn.parentElement.style.display = 'none';
        if (groupsBtn && groupsBtn.parentElement) groupsBtn.parentElement.style.display = 'none';
      }
    })();

    /* Local compare removed */

    // Copy button removed; no delegated handler needed


    async function compareLocalToOnline(){ console.warn('compareLocalToOnline removed'); return; } // local compare disabled




    // Render grouped matches: groups with more than one provider (grouped matches disabled)
    async function renderGroupedMatches(){
      const el = document.getElementById('groups-list');
      if (!document.cookie.includes('__test=')) {
        el.innerHTML = '<div class="alert alert-warning">Site verification required. Please reload the page and allow verification to complete, then open Grouped Matches.</div>';
        return;
      }
      el.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span> Loading grouped matches...</div>';
      try{
        const res = await fetch('get_grouped_matches.php');
        const data = await res.json();
        if(!data || !data.groups || !data.groups.length){ el.innerHTML = '<div class="alert alert-secondary">No grouped matches found.</div>'; return; }

        let out = '<div class="row g-3">';
        for(const g of data.groups){
          // cheapest provider
          const cheapest = g.cheapest;
          const providersHtml = g.members.map(p => `
            <div class="d-flex justify-content-between align-items-center py-1">
              <div>
                <strong>${escapeHtml(p.name)}</strong>
                <div class="small text-muted">$${p.price}/yr${p.link ? ' — <a href="'+escapeHtml(p.link)+'" target="_blank">Visit</a>' : ''}</div>
              </div>
              <div><button class="btn btn-sm btn-action more-info small-btn view-details" data-id="${p.id}" title="More info">More info</button></div>
            </div>
          `).join('');
          const groupsPreview = g.label ? escapeHtml(g.label) : '';
          out += `<div class="col-md-6">
            <div class="card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between">
                  <div>
                    <div class="fw-bold">${groupsPreview}</div>
                    <div class="small text-muted">${g.count} providers grouped by similarity</div>
                  </div>
                  <div class="text-end">
                    <div class="fw-bold">Cheapest: $${cheapest.price}</div>
                    <a href="${escapeHtml(cheapest.link)}" target="_blank" class="btn btn-sm btn-primary ms-2">Visit</a>
                  </div>
                </div>
                <hr>
                ${providersHtml}
              </div>
            </div>
          </div>`;
        }
        out += '</div>';
        el.innerHTML = out;
      } catch(e){ el.innerHTML = '<div class="alert alert-danger">Failed to load grouped matches</div>'; console.error(e); }
    }

    // Details modal
    function viewDetails(id){
      const s = window._submissionCache && window._submissionCache[id];
      if(!s) return alert('Details not available');
      document.getElementById('modalProvider').textContent = s.name;
      document.getElementById('modalLink').innerHTML = `<a href="${escapeHtml(s.link)}" target="_blank">${escapeHtml(s.link)}</a>`;
      document.getElementById('modalPrice').textContent = s.price;
      document.getElementById('modalChannels').textContent = s.channels;
      document.getElementById('modalGroups').textContent = s.groups;
      document.getElementById('modalSimilarity').textContent = s.similarity_score ? parseFloat(s.similarity_score).toFixed(2) + '%' : 'N/A';
      // Remove File Hash (MD5) line from modal
      // Removed: modalMd5 element handling

      // Show the modal
      detailsModal.show();

      // Load comparisons
      const compEl = document.getElementById('modalComparisons');
      compEl.innerHTML = '<div class="text-center py-2"><span class="spinner-border spinner-border-sm" role="status"></span> Finding matches...</div>';
      fetch('get_comparisons.php?id=' + id)
        .then(r => r.json())
        .then(data => {
          if (data.error) { compEl.innerHTML = '<div class="alert alert-danger">Comparison error</div>'; return; }
          // show best cheaper match if present and sufficiently similar (>=80%)
          if (data.target_is_cheapest) {
            compEl.innerHTML = `<div class="alert alert-success"><strong>This is the cheapest known listing for this playlist.</strong></div>`;
          } else if (data.best_cheaper && parseFloat(data.best_cheaper.similarity || 0) >= 80) {
            const b = data.best_cheaper;
            const bcHtml = `<div class="alert alert-warning"><strong>Cheaper provider found:</strong> ${escapeHtml(b.name)} — $${b.price} / year (save $${b.savings}) <a href="${escapeHtml(b.link)}" target="_blank" class="ms-2 btn btn-sm btn-outline-light">Visit</a></div>`;
            compEl.innerHTML = bcHtml;
          } else {
            compEl.innerHTML = '';
          }
          if (!data.matches || !data.matches.length) { compEl.innerHTML += '<div class="alert alert-secondary">No similar public providers found.</div>'; }
          else {
            // Filter matches to only show those with 80% similarity or higher
            const highSimilarityMatches = data.matches.filter(m => {
              const sim = parseFloat(m.similarity || 0);
              return sim >= 80;
            });
            if (!highSimilarityMatches.length) {
              compEl.innerHTML += '<div class="alert alert-secondary">No highly similar (80%+) public providers found.</div>';
            } else {
            let out = '<div class="row g-3">';
            for (let m of highSimilarityMatches) {
              const cheaperBadge = m.cheaper ? `<div class="text-success">Cheaper by $${Math.abs(m.price_diff)}</div>` : `<div class="text-muted">More expensive by $${Math.abs(m.price_diff)}</div>`;
              const bestBadge = (data.best_cheaper && data.best_cheaper.id == m.id) ? `<span class="badge bg-success ms-2">Best cheaper</span>` : '';

            const pct = m.similarity ? parseFloat(m.similarity).toFixed(2) : '0.00';
            const groupedBadge = (m.grouped && parseFloat(pct) >= 80) ? `<span class="badge bg-info ms-2">Grouped</span>` : '';

            // Build detailed match information
            let detailLines = [];
            if (m.match_details && Array.isArray(m.match_details)) {
                for (let detail of m.match_details) {
                    const matchIcon = detail.matches ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>';
                    const matchClass = detail.matches ? 'text-success' : 'text-danger';
                    detailLines.push(`<div class='small ${matchClass}'>${matchIcon} ${escapeHtml(detail.field)}: ${detail.percentage}%</div>`);
                }
            } else {
                // Fallback to old format
                const channelsText = m.channels_match_text ? m.channels_match_text : ((typeof m.shared !== 'undefined' && m.shared !== null)
                  ? (parseInt(m.shared) + ' amount of channels match')
                  : (m.channels_match ? 'Channels match' : ((m.shared || 0) + ' amount of channels')));
                const groupsText = m.groups_match_text ? m.groups_match_text : ((typeof m.shared_groups !== 'undefined' && m.shared_groups !== null)
                  ? (parseInt(m.shared_groups) + ' amount of groups match')
                  : (m.groups_match ? 'Groups match' : ((m.shared_groups || 0) + ' amount of groups')));
                detailLines.push(`<div class='small'><strong>${channelsText}</strong></div>`);
                detailLines.push(`<div class='small'><strong>${groupsText}</strong></div>`);
            }
            
            // Info lines: similarity, details
            let infoLines = [];
            infoLines.push(`<div class='small'><span class='fw-bold'>${pct}% similar</span> ${groupedBadge}</div>`);
            infoLines = infoLines.concat(detailLines);
            const progressClass = pct >= 80 ? 'bg-success' : pct >= 50 ? 'bg-warning' : 'bg-secondary';
            const simBar = infoLines.join('\n') + '<div class="progress mt-2" style="height:8px"><div class="progress-bar ' + progressClass + '" role="progressbar" style="width:' + pct + '%"></div></div>';
              out += `<div class="col-md-6">
                <div class="card h-100">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <div class="fw-bold">${escapeHtml(m.name)} ${bestBadge}</div>
                        <div class="text-muted small">${escapeHtml(m.link)}</div>
                        ${m.seller_source || m.seller_info ? `<div class="small text-muted mt-1">Seller: ${escapeHtml(m.seller_source || '')}${m.seller_info ? ' — ' + escapeHtml(m.seller_info) : ''}</div>` : ''}
                      </div>
                      <div class="text-end">
                        <div class="fw-bold">$${m.price} / year</div>
                        ${cheaperBadge}
                      </div>
                    </div>
                    <div class="mt-3">${simBar}</div>
                    <div class="mt-2">
                      <a class="btn btn-sm btn-primary" href="${escapeHtml(m.link)}" target="_blank">Visit</a>
                    </div>
                  </div>
                </div>
              </div>`;
            }
            out += '</div>';
            compEl.innerHTML = out;
          }
          }
        })
        .catch(err => { compEl.innerHTML = '<div class="alert alert-danger">Comparison failed</div>'; console.error(err); });
      detailsModal.show();
    }

    // M3U file support removed — no file uploads are used

    // Delegated event handlers for dynamic content
    document.addEventListener('click', function(e){
      const el = e.target.closest && e.target.closest('.view-details');
      if (el) { const id = el.getAttribute('data-id'); viewDetails(parseInt(id)); }
    });

    // Dynamically update seller details placeholder based on selected source
    (function(){
      const sellerSelect = document.querySelector('select[name="seller_source"]');
      const sellerInput = document.querySelector('input[name="seller_info"]');
      if (!sellerSelect || !sellerInput) return;
      const placeholders = {
        'iptv_website': 'e.g. https://provider.example.com or shop page',
        'z2u': 'Seller username or z2u listing URL',
        'g2g': 'Seller username or G2G listing URL',
        'made_in_china': 'Supplier name or product URL',
        'alibaba': 'Supplier name or Alibaba product URL',
        'independent_reseller': 'Seller name, contact or website',
        'reddit_seller': 'Reddit username (u/username) or thread URL',
        'discord_seller': 'Discord handle, ID or invite link',
        'other': 'Seller username, profile URL, or order reference'
      };
      function updatePlaceholder(){
        const val = sellerSelect.value || 'other';
        sellerInput.placeholder = placeholders[val] || placeholders['other'];
      }
      sellerSelect.addEventListener('change', updatePlaceholder);
      // initialize on load
      updatePlaceholder();
    })();

    // Xtream URL generator: show modal
    // Removed - now integrated into main form

    // Build Xtream/M3U URL helper (safe to call anytime)
    // Removed - now integrated into form submission

    // Use event delegation for modal buttons (works even if modal isn't in DOM at bind time)
    // Removed - modal no longer exists
    // Attempt to fetch the generated URL and prompt a download; on CORS/network fallback, open the link in a new tab
    // Removed - now integrated into form submission



    // Download: try fetching and prompting a download, fallback to opening the URL
    // (delegated handler calls attemptDownloadFromUrl when #xt_download is clicked)

  </script>

  <script>
    // Initialize modal instance once
    let detailsModal;

    // Notification functions for retry status
    let retryNotificationTimeout;
    function showRetryNotification(message, attempt, maxAttempts) {
      // Remove any existing notification
      hideRetryNotification();

      // Create notification element
      const notification = document.createElement('div');
      notification.id = 'retryNotification';
      notification.className = 'alert alert-warning alert-dismissible fade show position-fixed';
      notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.3);';
      notification.innerHTML = `
        <i class="bi bi-arrow-clockwise me-2"></i>
        <strong>Loading Data...</strong><br>
        <small>${message}</small>
        <button type="button" class="btn-close" onclick="hideRetryNotification()" aria-label="Close"></button>
      `;

      document.body.appendChild(notification);

      // Auto-hide after 5 seconds if this is not the last attempt
      if (attempt < maxAttempts) {
        retryNotificationTimeout = setTimeout(() => {
          if (document.getElementById('retryNotification')) {
            hideRetryNotification();
          }
        }, 5000);
      }
    }

    function hideRetryNotification() {
      const notification = document.getElementById('retryNotification');
      if (notification) {
        notification.remove();
      }
      if (retryNotificationTimeout) {
        clearTimeout(retryNotificationTimeout);
        retryNotificationTimeout = null;
      }
    }

    function showFinalErrorNotification(message) {
      // Remove any existing notifications
      hideRetryNotification();
      // Remove any existing notifications
      hideRetryNotification();

      // Create a neutral final notification (no alarming heading)
      const notification = document.createElement('div');
      notification.id = 'errorNotification';
      notification.className = 'alert alert-warning alert-dismissible fade show position-fixed';
      notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 420px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);';
      notification.innerHTML = `
        <div class="fw-semibold">Notice</div>
        <div class="small">${message}</div>
        <button type="button" class="btn-close" onclick="hideErrorNotification()" aria-label="Close"></button>
      `;

      document.body.appendChild(notification);

      // Auto-hide after 10 seconds
      setTimeout(() => { hideErrorNotification(); }, 10000);
    }

    function hideErrorNotification() {
      const notification = document.getElementById('errorNotification');
      if (notification) {
        notification.remove();
      }
    }

    // Fetch and animate submissions count on homepage (single attempt, no retries)
    async function loadSubmissionsCount(){
      const el = document.getElementById('submissionsCount');
      if (!el) return;

      try {
        const r = await fetch('get_counts.php?_=' + Date.now(), { cache: 'no-store' });
        if (!r.ok) { console.error('get_counts fetch failed', r.status); throw new Error('Network'); }
        const j = await r.json();
        const publicCount = (j && j.providers_public) ? j.providers_public : 0;
        const matchesCount = (j && j.providers_matches) ? j.providers_matches : 0;

        // Update UI with fetched values
        hideRetryNotification();
        animateCount(el, publicCount);
        const totalEl = document.getElementById('totalProvidersCount');
        if (totalEl) totalEl.textContent = ((j && j.providers_total !== undefined) ? Number(j.providers_total).toLocaleString() : '—');
        const recentEl = document.getElementById('recentCount'); if (recentEl) recentEl.textContent = ((j && j.providers_recent_7 !== undefined) ? Number(j.providers_recent_7).toLocaleString() : '—');
        const matchesEl = document.getElementById('matchesCount');
        if (matchesEl) matchesEl.textContent = ((j && j.providers_matches !== undefined) ? Number(j.providers_matches).toLocaleString() : '—');

        // Final check: if matches are zero, do not show a notification (silent)
      } catch (e) {
        console.error('loadSubmissionsCount error:', e);
        hideRetryNotification();
      }
    }
    function animateCount(el, target){
      const targetNum = Number(target) || 0;
      let cur = 0;
      const duration = 800; // ms
      const frames = Math.max(12, Math.round(duration / 16));
      const step = Math.max(1, Math.round(targetNum / frames));
      const tick = Math.max(16, Math.floor(duration / frames));
      const iv = setInterval(()=>{
        cur += step;
        if (cur >= targetNum){ cur = targetNum; clearInterval(iv); }
        el.textContent = cur.toLocaleString();
      }, tick);
    }
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize modal after Bootstrap is loaded
      detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'), {
        backdrop: 'static',
        keyboard: true
      });
      loadSubmissionsCount();
    });
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

  <!-- Details Modal -->
  <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content bg-dark text-light">
        <div class="modal-header">
          <h5 class="modal-title" id="modalProvider">Provider</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <dl class="row small">
            <dt class="col-sm-3">Link</dt><dd class="col-sm-9" id="modalLink"></dd>
            <dt class="col-sm-3">Price per year (USD/EUR/GBP/CAD/AUD)</dt><dd class="col-sm-9" id="modalPrice"></dd>
            <dt class="col-sm-3">Channels</dt><dd class="col-sm-9" id="modalChannels"></dd>
            <dt class="col-sm-3">Groups</dt><dd class="col-sm-9" id="modalGroups"></dd>
            <dt class="col-sm-3">Similarity</dt><dd class="col-sm-9" id="modalSimilarity"></dd>
          <hr>
          <h5 class="mt-3">Matches</h5>
          <div id="modalComparisons">Loading comparisons...</div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Xtream modal removed - now integrated into main form -->

  <div class="container mb-3 d-flex flex-column align-items-center">
    <div class="mb-2 stats-heading"><strong>Stats</strong></div>
    <div class="stats-grid" role="region" aria-label="Site stats">
      <div class="stat-card providers" id="card-providers">
        <div class="stat-count" id="submissionsCount">—</div>
        <div class="stat-label">Providers added</div>
      </div>
      <div class="stat-card total" id="card-total">
        <div class="stat-count" id="totalProvidersCount">—</div>
        <div class="stat-label">Total providers</div>
      </div>
      <div class="stat-card recent" id="card-recent">
        <div class="stat-count" id="recentCount">—</div>
        <div class="stat-label">Recent (7d)</div>
      </div>
      <div class="stat-card matches" id="card-matches">
        <div class="stat-count" id="matchesCount">—</div>
        <div class="stat-label">Matches found</div>
      </div>
    </div>
  </div>

  <footer class="mt-5 pt-4 pb-4 bg-dark text-light" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); border-top: 1px solid rgba(255,255,255,0.1);">
    <div class="container">
      <div class="row gy-3 align-items-start">
        <div class="col-md-4">
          <h6 class="mb-3 text-uppercase fw-bold" style="color: #00d4ff;"><i class="bi bi-info-circle-fill me-2"></i>About IPTV Detective</h6>
          <p class="small text-muted mb-2">A lightweight tool to compare IPTV provider packages and identify identical or highly similar offerings — useful for spotting resellers and price comparisons.</p>
          <p class="small text-muted mb-0"><i class="bi bi-discord me-1"></i>Contact: Discord — <span class="fw-bold" style="color: #7289da;">#micro3617</span></p>
        </div>

        <div class="col-md-4">
          <h6 class="mb-3 text-uppercase fw-bold" style="color: #00d4ff;"><i class="bi bi-link-45deg me-2"></i>Quick Links</h6>
          <ul class="list-unstyled small mb-0">
            <li class="mb-1"><a href="#" class="link-light text-decoration-none" data-bs-toggle="modal" data-bs-target="#helpModal" style="transition: color 0.3s;"><i class="bi bi-question-circle-fill me-2"></i>How it works</a></li>
            <li class="mb-1"><a href="#" class="link-light text-decoration-none" data-bs-toggle="modal" data-bs-target="#changelogModal" style="transition: color 0.3s;"><i class="bi bi-journal-text me-2"></i>Changelog</a></li>
          </ul>
        </div>

        <div class="col-md-4 text-md-end">
          <h6 class="mb-3 text-uppercase fw-bold" style="color: #00d4ff;"><i class="bi bi-code-slash me-2"></i>Version</h6>
          <div class="mb-1 fs-5">v <strong style="color: #00d4ff;">2.1</strong></div>
          <div class="small text-muted">build <span id="buildDate">2026-02-08</span></div>
          <div class="mt-3"><small class="text-muted">© 2024–2026 IPTV Detective</small></div>
        </div>
      </div>

      <div class="row mt-4">
        <div class="col-12">
          <hr class="border-secondary opacity-25" style="border-color: rgba(255,255,255,0.2) !important;">
          <div class="d-flex justify-content-between align-items-center flex-column flex-md-row">
            <div class="small text-muted"><i class="bi bi-lightbulb me-1"></i>Tip: Refresh the page if site verification prompts appear.</div>
            <div class="small text-muted"><i class="bi bi-shield-check me-1"></i>Credentials are used temporarily in-memory and never stored.</div>
          </div>
          <div class="d-flex justify-content-end mt-2">
            <a href="https://github.com/Nigel1992/IPTV-Detective-2.0" class="text-decoration-none small" target="_blank" rel="noopener" style="color:#00d4ff;"><i class="bi bi-github me-1"></i>Open Source on GitHub</a>
          </div>
        </div>
      </div>
    </div>
  </footer>
    </div>
  </div>
</footer>

<!-- Changelog Modal -->
<div class="modal fade" id="changelogModal" tabindex="-1" aria-labelledby="changelogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="changelogModalLabel">IPTV Detective — Changelog</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <strong>Version 2.1 — 2026-02-08</strong>
          <ul>
            <li>Added Seller Source and Seller Info to submissions and admin UI.</li>
            <li>Made Xtream credentials processing in-browser by default; improved privacy wording.</li>
            <li>Updated admin provider list to show seller and improved edit modal.</li>
            <li>Documentation and help updated to explain credential handling and comparison logic.</li>
          </ul>
        </div>
        <div class="mb-3">
          <strong>Version 2.0 — (previous)</strong>
          <ul>
            <li>Initial public release base features: provider submission, comparison, admin dashboard.</li>
          </ul>
        </div>
        <p class="small text-muted">This changelog is a short summary. For detailed history, check the repository tags or contact the site administrator.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="helpModalLabel">How IPTV Detective Works</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h2 class="mb-3">How IPTV Detective works — simple explanation</h2>
        <p>This site helps you check an IPTV provider's channel list and compare it with other public submissions to find identical or very similar packages. It's useful for spotting resellers (different shops selling the same package) and comparing prices.</p>

        <h4 class="mt-3">How it works (brief)</h4>
        <p>IPTV Detective analyzes a provider's playlist and compares it to public submissions to identify identical or highly similar packages — useful for spotting resellers and finding better prices.</p>

        <h5 class="mt-3">Fetch &amp; analyze in-browser</h5>
        <p>When you enter Xtream/portal credentials the browser fetches the playlist directly from the panel (using player API endpoints) and parses it locally. This avoids uploading full playlists to the server for most checks.</p>

        <h5 class="mt-3">What is sent to the server</h5>
        <p>Only small numeric metrics (counts) are sent to the server for comparison. Your Xtream credentials are not sent during the normal "Check &amp; Compare" flow. If you explicitly submit a provider for listing, optional fields (such as submitted raw links) may be stored for admin review; Xtream credentials are never stored and will not be saved with submissions.</p>

        <h5 class="mt-3">Comparison</h5>
        <p>The server compares submissions using available metrics (live streams, live categories, series, series categories, VOD categories) and/or legacy channel/group counts. The system prefers structured metrics when present and falls back to channel/group counts for older databases.</p>

        <h4 class="mt-3">What we show</h4>
        <ul>
          <li><strong>Counts &amp; summary</strong> — numbers of streams, categories and VOD counts.</li>
          <li><strong>Similarity score</strong> — a single percentage combining multiple metrics to indicate how close two packages are.</li>
          <li><strong>Matched providers</strong> — candidates with high similarity and a short summary including price comparison.</li>
        </ul>

        <h4 class="mt-3">Privacy &amp; storage</h4>
        <p>By default the playlist is parsed in your browser and only small summaries are uploaded. The server does not receive your plain-text Xtream password during the check flow. If you choose to "submit" a provider for the public database, that submission may be stored (including optional raw links or credentials if provided) — submissions are visible to admins and may be displayed publicly depending on site settings.</p>
        <div class="alert alert-warning mt-3" role="alert">
          <strong>Why we ask for Xtream credentials</strong>
          <p class="mb-0">We only request Xtream/portal credentials so the site can fetch the provider's playlist and calculate channel/group counts and other small metrics needed to compare packages. These credentials are used in-memory for that single request and are never stored on disk or saved in the database.</p>
          <p class="mb-0 mt-2"><strong>Concerned about privacy?</strong> If you don't want to provide live account credentials, create a temporary/trial IPTV account or use a provider URL that doesn't expose your real login — that will give the same comparison results without risking your primary credentials.</p>
        </div>

        <h4 class="mt-3">Tips</h4>
        <ul>
          <li>Use a provider website URL that starts with <code>https://</code> or <code>http://</code>.</li>
          <li>Large playlists take longer to fetch — use counts-only submission if fetch fails.</li>
          <li>Do not paste credentials into the provider URL; use the dedicated Xtream fields.</li>
          <li>Refreshing the page may be required if site verification (anti-bot) appears.</li>
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>
