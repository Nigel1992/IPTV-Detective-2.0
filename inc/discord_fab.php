<?php
if (defined('DISCORD_FAB_INCLUDED')) return; define('DISCORD_FAB_INCLUDED', 1);
// If buffer-based injection is active, do not echo here to avoid duplicates
if (defined('DISCORD_FAB_BUFFER_STARTED')) return;
$invite = 'https://discord.gg/zxUq3afdn8';
echo <<<'HTML'

<!-- Discord Floating Button (inline include) -->
<style>
.discord-fab{position:fixed;right:18px;bottom:18px;z-index:99999}
.discord-fab button{background:#5865F2;color:#fff;border:0;border-radius:50%;width:56px;height:56px;box-shadow:0 6px 18px rgba(88,101,242,.28);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:24px}
.discord-fab button:active{transform:scale(.98)}
.discord-fab .label{position:absolute;right:72px;bottom:22px;background:rgba(0,0,0,0.75);color:#fff;padding:6px 10px;border-radius:6px;font-size:13px;white-space:nowrap;opacity:0;transition:opacity .18s ease}
.discord-fab:hover .label{opacity:1}
.discord-fab .pulse{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:56px;height:56px;border-radius:50%;box-shadow:0 0 0 0 rgba(88,101,242,0.6);animation:dfab-pulse 2s infinite}
@keyframes dfab-pulse{0%{box-shadow:0 0 0 0 rgba(88,101,242,0.35)}70%{box-shadow:0 0 0 18px rgba(88,101,242,0)}100%{box-shadow:0 0 0 0 rgba(88,101,242,0)}}
</style>
<div class="discord-fab" aria-hidden="false">
  <div class="pulse" aria-hidden="true"></div>
  <a href="{$invite}" target="_blank" rel="noopener noreferrer" title="Join our Discord to discuss and report issues">
    <button aria-label="Join Discord" type="button">
      <svg width="22" height="22" viewBox="0 0 245 240" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#ffffff" d="M104.4 104.4c0 6.5-5.3 11.8-11.8 11.8s-11.8-5.3-11.8-11.8 5.3-11.8 11.8-11.8 11.8 5.3 11.8 11.8zm63 0c0 6.5-5.3 11.8-11.8 11.8s-11.8-5.3-11.8-11.8 5.3-11.8 11.8-11.8 11.8 5.3 11.8 11.8z"/><path fill="#ffffff" d="M189.5 20H55.5C43.4 20 33.8 29.6 33.8 41.7v119.3c0 12.1 9.6 21.7 21.7 21.7h114.3l-5.4-18.7 13.1 11.9 12.3 11.4 21.9 19.9V41.7C211.2 29.6 201.6 20 189.5 20z"/></svg>
    </button>
  </a>
  <div class="label">Join our Discord — report issues & chat</div>
</div>
<script>
document.addEventListener('keydown', function(e){ if(e.key==="Escape"){ var el=document.querySelector('.discord-fab'); if(el) el.style.display='none'; }});
</script>
<!-- End Discord FAB -->
HTML;

?>
