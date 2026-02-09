<?php
// test_local_submit.php
// Simple debug page to test "Fetch from my machine" flows and submit_provider.php
session_start();
// single-use token to allow test page to skip CAPTCHA verification
try { $_SESSION['test_skip_token'] = bin2hex(random_bytes(16)); } catch (Exception $e) { $_SESSION['test_skip_token'] = bin2hex(openssl_random_pseudo_bytes(16)); }
$__TEST_SKIP_TOKEN = $_SESSION['test_skip_token'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TEST: Local Submit Debug</title>
<link href="static/style.css" rel="stylesheet">
<style>body{padding:1rem;font-family:system-ui,Arial,sans-serif}label{font-weight:600} .dbg{background:#f8f9fa;border:1px solid #e9ecef;padding:0.75rem;margin-top:0.5rem;white-space:pre-wrap;font-family:monospace;}</style>
</head>
<body>
<h2>TEST: Local Fetch & Submit (Debug)</h2>
<form id="tform">
  <div>
    <label>Provider name</label><br>
    <input name="name" id="name" value="Test Provider" style="width:100%">
  </div>
  <!-- Provider link removed from test form -->
  <div style="display:flex;gap:10px;margin-top:0.5rem">
    <div style="flex:1">
      <label>Price per year</label><br>
      <input type="number" id="price" name="price" step="0.01" value="12.99" style="width:100%">
    </div>
    <div style="flex:1">
      <label>Xtream Host</label><br>
      <input id="xt_host" name="xt_host" value="line.trxdnscloud.ru" style="width:100%">
    </div>
    <div style="flex:0 0 120px">
      <label>Port</label><br>
      <input id="xt_port" name="xt_port" value="80" style="width:100%">
    </div>
  </div>
  <div style="display:flex;gap:10px;margin-top:0.5rem">
    <div style="flex:1">
      <label>Username</label><br>
      <input id="xt_user" name="xt_user" value="d8af97dfe8" style="width:100%">
    </div>
    <div style="flex:1">
      <label>Password</label><br>
      <input id="xt_pass" name="xt_pass" value="3280ada5c7b0" style="width:100%">
    </div>
  </div>
  <div style="margin-top:0.75rem">
    <button id="btnFetchLocal" type="button">Fetch from my machine</button>
    <button id="btnSubmit" type="button">Submit to test endpoint (no DB)</button>
  </div>
  <div style="margin-top:0.5rem;color:#333;font-size:0.95rem">
    <strong>Proxy:</strong> Using server <code>inc/proxy.php</code> with a <strong>60s</strong> timeout for fetches. Debug prints to console and page.<br>
    <strong>Submit:</strong> Using test-only endpoint <code>submit_provider_test.php</code> (does not use a database).
  </div>
  <div style="margin-top:0.5rem;color:#333;font-size:0.95rem" id="tokenDisplay">
    <strong>Test token:</strong> <?php echo htmlspecialchars($__TEST_SKIP_TOKEN); ?>
  </div>
</form>

<h3>Debug output</h3>
<div id="log" class="dbg">Ready.
</div>

<script>
function log(msg){
  const el = document.getElementById('log');
  const time = new Date().toISOString();
  el.textContent += `\n[${time}] ${msg}`;
  console.log(msg);
}

function makeApiUrl(action){
  // Trim inputs to avoid whitespace-only values
  const hostEl = document.getElementById('xt_host'); hostEl.value = hostEl.value.trim();
  const portEl = document.getElementById('xt_port'); portEl.value = portEl.value.trim();
  const userEl = document.getElementById('xt_user'); userEl.value = userEl.value.trim();
  const passEl = document.getElementById('xt_pass'); passEl.value = passEl.value.trim();
  const host = hostEl.value;
  const port = portEl.value;
  const user = userEl.value;
  const pass = passEl.value;
  const scheme = host.startsWith('http') ? '' : 'http://';
  const hostPort = port ? host + ':' + port : host;
  return `${scheme}${hostPort}/player_api.php?username=${encodeURIComponent(user)}&password=${encodeURIComponent(pass)}&action=${encodeURIComponent(action)}`;
}

async function fetchWithRetries(url, opts={}, retries=2, backoff=300){
  let attempt=0;
  // support per-request timeout in milliseconds via opts.timeoutMs (default 60000)
  const defaultTimeout = 60000;
  const timeoutMs = opts.timeoutMs || defaultTimeout;
  while(true){
    attempt++;
    // setup AbortController unless caller already provided a signal
    const externalSignal = opts.signal;
    const controller = externalSignal ? null : new AbortController();
    const signal = controller ? controller.signal : externalSignal;
    const fetchOpts = Object.assign({}, opts, { signal });
    let timer = null;
    if (controller) timer = setTimeout(()=>{ controller.abort(); }, timeoutMs);
    try{
      log(`fetch attempt ${attempt} => ${url} (timeout ${timeoutMs}ms)`);
      const r = await fetch(url, fetchOpts);
      if (timer) clearTimeout(timer);
      if (r.ok) return r;
      if ([404,429,502,503,504].includes(r.status) && retries>0){
        log(`Transient ${r.status}, retrying in ${backoff}ms`);
        await new Promise(r=>setTimeout(r,backoff)); retries--; backoff*=2; continue;
      }
      return r;
    }catch(e){
      if (timer) clearTimeout(timer);
      log(`fetch exception: ${e}`);
      if (e && e.name === 'AbortError') log('fetch aborted (timeout)');
      if (retries>0){ await new Promise(r=>setTimeout(r,backoff)); retries--; backoff*=2; continue; }
      throw e;
    }
  }
}

async function fetchActionLocal(action){
  const upstream = makeApiUrl(action);
  const proxyUrl = 'inc/proxy.php?url=' + encodeURIComponent(upstream) + '&timeout=60&full=1';
  log(`Proxy fetch ${action} -> ${proxyUrl} (upstream: ${upstream})`);
  try{
    const res = await fetchWithRetries(proxyUrl, {mode:'same-origin', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, timeoutMs:60000}, 2, 300);
    log(`Status ${res.status}`);
    const ctype = (res.headers.get('content-type')||'').toLowerCase();
    log(`Content-Type: ${ctype}`);
    const txt = await res.text();
    log(`Response length: ${txt.length} chars`);
    // show snippet for very large responses
    if (txt.length > 20000) {
      log('Response body (first 20000 chars):\n' + txt.slice(0,20000));
    } else {
      log('Response body:\n' + txt);
    }
    try{ return JSON.parse(txt);}catch(e){ return txt; }
  }catch(e){
    log('Proxy fetch error: '+(e.message||e));
    throw e;
  }
}

async function tryProxies(url){
  const proxies = [
    'inc/proxy.php?url=' + encodeURIComponent(url) + '&timeout=60&full=1',
    'https://api.codetabs.com/v1/proxy?quest=' + encodeURIComponent(url),
    'https://api.allorigins.win/raw?url=' + encodeURIComponent(url),
    'https://corsproxy.io/?' + encodeURIComponent(url)
  ];
  for(const p of proxies){
    try{
      log('Attempting proxy: ' + p);
      const r = await fetchWithRetries(p, {mode:'cors', timeoutMs:60000}, 2);
      if(!r.ok){ log('Proxy '+p+' returned status '+r.status); continue; }
      const txt = await r.text();
      log('Proxy returned length: ' + txt.length + ' chars');
      if (txt.length > 20000) log('Proxy response (first 20000 chars):\n' + txt.slice(0,20000));
      else log('Proxy response body:\n' + txt);
      try{ return JSON.parse(txt); } catch(e){ return txt; }
    }catch(e){ log('Proxy '+p+' failed: ' + (e.message||e)); continue; }
  }
  return { error: 'All proxies failed' };
}

async function doFetchLocal(){
  const actions=['get_live_categories','get_live_streams','get_series','get_series_categories','get_vod_categories'];
  const out = {};
  for(const a of actions){
    try{
      const r = await fetchActionLocal(a);
      out[a]=r;
    }catch(e){
      log('Local fetch failed for '+a+': '+e.message);
      // try proxy fallback automatically
      try{
        const url = makeApiUrl(a);
        const pr = await tryProxies(url);
        if(pr && pr.error){ out[a] = { error: 'Proxy fallback failed: ' + pr.error }; }
        else { out[a] = pr; log('Proxy fallback succeeded for '+a); }
      }catch(pe){ out[a]={ error: 'Proxy attempt failed: ' + pe.message }; }
    }
  }
  // Log the full results (note: may be large)
  log('Local fetch completed. Full results:\n' + JSON.stringify(out));
  return out;
}

async function doSubmitServer(countsMap){
  const form = new FormData();
  form.append('name', document.getElementById('name').value);
  form.append('link', document.getElementById('link').value);
  form.append('price', document.getElementById('price').value);
  // map counts
  form.append('live_categories_count', countsMap.get_live_categories?.count || 0);
  form.append('live_streams_count', countsMap.get_live_streams?.count || 0);
  form.append('series_count', countsMap.get_series?.count || 0);
  form.append('series_categories_count', countsMap.get_series_categories?.count || 0);
  form.append('vod_categories_count', countsMap.get_vod_categories?.count || 0);
  form.append('vod_streams_count', countsMap.get_vod_streams?.count || 0);
  // counts as legacy
  form.append('channel_count', countsMap.get_live_streams?.count || 0);
  form.append('group_count', countsMap.get_live_categories?.count || 0);
  // For test page: include the single-use skip token issued by the server session
  form.append('skip_token', '<?php echo $__TEST_SKIP_TOKEN; ?>');

  log('Submitting to test endpoint... (skip_token: <?php echo $__TEST_SKIP_TOKEN; ?>)');
  try{
    // Ensure session cookie is sent so the server can validate the single-use skip token
    const res = await fetch('submit_provider_test.php', {method:'POST', body:form, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
    const ctype = (res.headers.get('content-type')||'').toLowerCase();
    if (ctype.includes('application/json')){
      const j = await res.json(); log('Server response JSON: '+JSON.stringify(j));
    } else {
      const txt = await res.text(); log('Server response text: '+txt.slice(0,1000));
    }
  }catch(e){ log('Submit failed: '+(e.message||e)); }
}

document.getElementById('btnFetchLocal').addEventListener('click', async ()=>{
  try{ log('Starting local fetch...'); const res = await doFetchLocal(); window._lastLocal = res; log('Local fetch done.'); }catch(e){ log('Local fetch failed: '+e.message); }
});

document.getElementById('btnSubmit').addEventListener('click', async ()=>{
  const counts = window._lastLocal || {};
  await doSubmitServer(counts);
});

// initial info log
log('Configured to use server proxy: inc/proxy.php (timeout 60s)');
</script>
</body>
</html>