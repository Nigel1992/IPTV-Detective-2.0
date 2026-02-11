<?php
if (defined('DISCORD_FAB_INCLUDED')) return; define('DISCORD_FAB_INCLUDED', 1);
// If buffer-based injection is active, do not echo here to avoid duplicates
if (defined('DISCORD_FAB_BUFFER_STARTED')) return;

// Do not show the Discord FAB to logged-in admins or on admin pages
if (php_sapi_name() !== 'cli') {
  if (session_status() === PHP_SESSION_NONE) {
    @session_start();
  }
  if (!empty($_SESSION['admin_user'])) {
    return;
  }
}

// Show only on homepage / index pages. Allow directory index (path ending with '/')
$req_path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
// If the current executing script is index.php, or the request is the site root or a directory path, show FAB
if (!(
  $script === 'index.php' ||
  $req_path === '/' ||
  substr($req_path, -1) === '/'
)) {
  return;
}

// Path where you can upload the provided Discord PNG (optional). If missing, SVG fallback displays.
$invite = 'https://discord.com/invite/zxUq3afdn8';
$img = 'static/discord-icon.png';
echo <<<HTML

<!-- Discord Floating Button (inline include) -->
<style>
/* FAB container */
.discord-fab{position:fixed;right:18px;bottom:18px;z-index:99999}
/* removed pulse animation */


/* Modern, subtle, beautiful Discord FAB */
.discord-link {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(88,101,242,0.92); /* Discord blurple, slightly translucent */
  color: #fff;
  padding: 9px 18px 9px 12px;
  border-radius: 999px;
  text-decoration: none;
  box-shadow: 0 4px 24px 0 rgba(30,34,90,0.13), 0 1.5px 6px 0 rgba(88,101,242,0.10);
  font-weight: 600;
  border: 1.5px solid rgba(255,255,255,0.13);
  transition: background 0.18s, box-shadow 0.18s, border 0.18s, filter 0.18s;
  filter: blur(0px) saturate(1.1);
  backdrop-filter: blur(2px);
  overflow: visible;
  opacity: 0.93;
}
.discord-link:hover, .discord-link:focus {
  background: rgba(88,101,242,1);
  box-shadow: 0 8px 32px 0 rgba(30,34,90,0.18), 0 2px 8px 0 rgba(88,101,242,0.16);
  border: 1.5px solid rgba(255,255,255,0.22);
  opacity: 1;
  filter: brightness(1.04) saturate(1.2);
}
.discord-link:active {
  transform: scale(.97);
  filter: brightness(0.98) saturate(1.1);
}
.discord-icon {
  width: 44px;
  height: 44px;
  min-width: 44px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: visible;
  background: rgba(255,255,255,0.10);
  box-shadow: 0 1.5px 6px 0 rgba(88,101,242,0.10);
  transition: background 0.18s, box-shadow 0.18s;
}
.discord-link:hover .discord-icon {
  background: rgba(255,255,255,0.18);
  box-shadow: 0 2.5px 10px 0 rgba(88,101,242,0.13);
}
.discord-icon img, .discord-icon svg {
  width: 34px;
  height: 34px;
  filter: drop-shadow(0 1px 2px rgba(30,34,90,0.10));
}
.discord-text {
  font-size: 15px;
  letter-spacing: 0.01em;
  opacity: 0.96;
  text-shadow: 0 1px 2px rgba(30,34,90,0.08);
}

/* small screens: hide text and show compact circle */
@media (max-width:480px){
  .discord-link{
    padding:6px;width:56px;min-width:56px;max-width:56px;border-radius:32px;justify-content:center;gap:0;
  }
  .discord-text{display:none}
  .discord-icon{width:36px;height:36px;min-width:36px}
  .discord-img{width:22px;height:22px}
  .discord-icon img,.discord-icon svg{width:22px;height:22px}
}
</style>
<div class="discord-fab" aria-hidden="false">
  <div class="pulse" aria-hidden="true"></div>
  <a href="{$invite}" class="discord-link" target="_blank" rel="noopener noreferrer" title="Join our Discord to discuss and report issues">
    <span class="discord-icon" aria-hidden="true">
      <img src="{$img}" alt="Discord" class="discord-img" style="width:22px;height:22px;display:block" />
    </span>
    <span class="discord-text">Join our Discord — report issues &amp; chat</span>
  </a>
</div>
<script>
</script>
<script>
document.addEventListener('keydown', function(e){ if(e.key==="Escape"){ var el=document.querySelector('.discord-fab'); if(el) el.style.display='none'; }});
// If the image is missing or fails, replace it with the SVG fallback so the icon is always visible
(function(){
  var img = document.querySelector('.discord-img');
  if(!img) return;
  img.addEventListener('error', function(){
    this.outerHTML = `
      <svg width="22" height="22" viewBox="0 0 245 240" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#ffffff" d="M104.4 104.4c0 6.5-5.3 11.8-11.8 11.8s-11.8-5.3-11.8-11.8 5.3-11.8 11.8-11.8 11.8 5.3 11.8 11.8zm63 0c0 6.5-5.3 11.8-11.8 11.8s-11.8-5.3-11.8-11.8 5.3-11.8 11.8-11.8 11.8 5.3 11.8 11.8z"/><path fill="#ffffff" d="M189.5 20H55.5C43.4 20 33.8 29.6 33.8 41.7v119.3c0 12.1 9.6 21.7 21.7 21.7h114.3l-5.4-18.7 13.1 11.9 12.3 11.4 21.9 19.9V41.7C211.2 29.6 201.6 20 189.5 20z"/></svg>
    `;
  });
})();
</script>
</script>
<!-- End Discord FAB -->
HTML;

?>
