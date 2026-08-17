<?php
/**
 * almightypush.php — Live deployment status dashboard.
 *
 * Polls deploy_info.json (written by .github/workflows/deploy.yml on every
 * push to main) every 10s. Plays an audio chime + fires a browser push
 * notification when a new deployment is detected.
 *
 * Gated behind the admin session, same as everything under /admin — this
 * shows commit messages and who pushed, which isn't attendee-facing info.
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['is_admin'])) {
    header('Location: admin/login.php?next=' . urlencode('/almightypush.php'));
    exit;
}

$brand = COMPANY_NAME;

if (isset($_GET['data'])) {
    header('Content-Type: application/json');
    $f    = __DIR__ . '/deploy_info.json';
    $info = is_file($f) ? (json_decode(file_get_contents($f), true) ?? []) : [];
    $tz   = new DateTimeZone('Africa/Lagos');
    $now  = new DateTime('now', $tz);
    $dateStr = $timeStr = $agoStr = '—';
    $rawTs = 0;
    if (!empty($info['deployed_at'])) {
        $dt      = new DateTime($info['deployed_at'], $tz);
        $rawTs   = $dt->getTimestamp();
        $dateStr = $dt->format('l, F j, Y');
        $timeStr = $dt->format('g:i:s A') . ' WAT';
        $diff    = $now->getTimestamp() - $rawTs;
        if      ($diff < 60)    $agoStr = $diff . 's ago';
        elseif  ($diff < 3600)  $agoStr = floor($diff/60) . 'm ago';
        elseif  ($diff < 86400) $agoStr = floor($diff/3600) . 'h ago';
        else                    $agoStr = floor($diff/86400) . 'd ago';
    }
    echo json_encode([
        'date'       => $dateStr,
        'time'       => $timeStr,
        'ago'        => $agoStr,
        'branch'     => $info['branch']      ?? 'main',
        'hash'       => substr($info['commit_hash'] ?? 'N/A', 0, 7),
        'msg'        => $info['commit_msg']  ?? 'N/A',
        'pushed_by'  => $info['pushed_by']   ?? 'GitHub Actions',
        'server_now' => $now->format('H:i:s') . ' WAT',
        'raw_ts'     => $rawTs,
    ]);
    exit;
}

$f    = __DIR__ . '/deploy_info.json';
$info = is_file($f) ? (json_decode(file_get_contents($f), true) ?? []) : [];
$tz   = new DateTimeZone('Africa/Lagos');
$now  = new DateTime('now', $tz);
$dateStr = $timeStr = $agoStr = '—';
$initRawTs  = 0;
$commitHash = $info['commit_hash'] ?? 'N/A';
$commitMsg  = $info['commit_msg']  ?? 'No deployments yet';
$pushedBy   = $info['pushed_by']   ?? 'GitHub Actions';
$branch     = $info['branch']      ?? 'main';
if (!empty($info['deployed_at'])) {
    $dt        = new DateTime($info['deployed_at'], $tz);
    $initRawTs = $dt->getTimestamp();
    $dateStr   = $dt->format('l, F j, Y');
    $timeStr   = $dt->format('g:i:s A') . ' WAT';
    $diff      = $now->getTimestamp() - $initRawTs;
    if      ($diff < 60)    $agoStr = $diff . ' seconds ago';
    elseif  ($diff < 3600)  $agoStr = floor($diff/60) . ' minutes ago';
    elseif  ($diff < 86400) $agoStr = floor($diff/3600) . ' hours ago';
    else                    $agoStr = floor($diff/86400) . ' days ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deployment Status — <?= htmlspecialchars($brand) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --green:#16a34a; --green-l:#22c55e; --red:#dc2626; --amber:#f59e0b;
  --bg:#070b16; --card:rgba(16,22,40,.92); --border:rgba(92,107,192,.3);
  --text:#e8ecff; --muted:rgba(232,236,255,.5); --mono:'Courier New',monospace;
}
body { font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text);
  min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px 16px; }
.bg-blobs { position:fixed; inset:0; z-index:0; pointer-events:none; }
.blob { position:absolute; border-radius:50%; filter:blur(90px); opacity:.16; animation:float 9s ease-in-out infinite; }
.blob-1 { width:520px; height:520px; background:#5c6bc0; top:-150px; left:-120px; }
.blob-2 { width:400px; height:400px; background:#13deb9; bottom:-100px; right:-80px; animation-delay:3.5s; }
.blob-3 { width:280px; height:280px; background:#7c4dff; top:45%; left:55%; animation-delay:1.8s; }
@keyframes float { 0%,100%{transform:translateY(0) scale(1)} 50%{transform:translateY(-28px) scale(1.04)} }
.alert-bar { position:relative; z-index:2; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
  gap:10px; width:100%; max-width:640px; background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.35);
  border-radius:12px; padding:10px 18px; margin-bottom:10px; font-size:.82rem; color:#fcd34d; }
.alert-bar.granted { background:rgba(34,197,94,.08); border-color:rgba(34,197,94,.3); color:var(--green-l); }
.btn-enable-alerts { background:rgba(245,158,11,.15); border:1px solid rgba(245,158,11,.5);
  color:#fcd34d; padding:6px 16px; border-radius:8px; font-size:.8rem; font-weight:700; cursor:pointer; white-space:nowrap; transition:background .2s; }
.btn-enable-alerts:hover { background:rgba(245,158,11,.28); }
.btn-enable-alerts.granted { background:rgba(34,197,94,.12); border-color:rgba(34,197,94,.4); color:var(--green-l); cursor:default; }
.live-bar { position:relative; z-index:2; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
  gap:10px; width:100%; max-width:640px; background:rgba(92,107,192,.08); border:1px solid rgba(92,107,192,.25);
  border-radius:12px; padding:10px 18px; margin-bottom:14px; font-size:.8rem; }
.live-pill { display:flex; align-items:center; gap:7px; color:var(--green-l); font-weight:700; letter-spacing:.5px; }
.live-dot { width:8px; height:8px; border-radius:50%; background:var(--green-l); animation:blink 1.4s ease-in-out infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }
.server-clock { color:var(--muted); font-family:var(--mono); }
.card { position:relative; z-index:1; background:var(--card); border:1px solid var(--border); border-radius:28px;
  padding:48px 44px; max-width:640px; width:100%; backdrop-filter:blur(28px); -webkit-backdrop-filter:blur(28px);
  box-shadow:0 32px 80px rgba(0,0,0,.55), 0 0 0 1px rgba(255,255,255,.04) inset; text-align:center; }
.icon-wrap { width:88px; height:88px; background:linear-gradient(135deg,rgba(92,107,192,.25),rgba(92,107,192,.06));
  border:2px solid rgba(92,107,192,.45); border-radius:50%; display:flex; align-items:center; justify-content:center;
  margin:0 auto 26px; animation:pulse-ring 2.5s ease-in-out infinite; }
.icon-wrap svg { width:40px; height:40px; stroke:var(--green-l); }
@keyframes pulse-ring { 0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.35)} 50%{box-shadow:0 0 0 16px rgba(34,197,94,0)} }
.badge { display:inline-flex; align-items:center; gap:6px; background:rgba(92,107,192,.12); border:1px solid rgba(92,107,192,.35);
  color:#aab4ff; font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase;
  padding:5px 14px; border-radius:999px; margin-bottom:16px; }
h1 { font-size:2rem; font-weight:900; line-height:1.2; color:#fff; margin-bottom:10px; }
.subtitle { color:var(--muted); font-size:.92rem; line-height:1.65; margin-bottom:34px; }
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:28px; }
.info-box { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:14px;
  padding:16px 15px; text-align:left; transition:border-color .3s, background .3s; }
.info-box.updated { border-color:rgba(34,197,94,.5); }
.info-box .label { font-size:.67rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.info-box .value { font-size:.9rem; font-weight:700; color:var(--text); }
.info-box .value.green{color:var(--green-l);} .info-box .value.purple{color:#a78bfa;}
.info-box.full { grid-column:1/-1; }
hr.divider { border:none; border-top:1px solid rgba(255,255,255,.07); margin:0 0 26px; }
.timeline { display:flex; flex-direction:column; gap:9px; text-align:left; margin-bottom:28px; }
.tl-row { display:flex; align-items:center; gap:12px; padding:11px 15px; background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.06); border-radius:11px; }
.tl-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.tl-dot.green{background:var(--green-l);box-shadow:0 0 8px rgba(34,197,94,.6);}
.tl-dot.purple{background:#a78bfa;box-shadow:0 0 8px rgba(167,139,250,.6);}
.tl-dot.blue{background:#60a5fa;box-shadow:0 0 8px rgba(96,165,250,.6);}
.tl-text { font-size:.875rem; color:var(--text); flex:1; }
.tl-time { font-size:.75rem; color:var(--green-l); white-space:nowrap; font-weight:600; }
@keyframes flash-update { 0%{background:rgba(34,197,94,.22);} 100%{background:rgba(255,255,255,.04);} }
.flash { animation:flash-update .9s ease-out forwards; }
.footer-text { font-size:.78rem; color:var(--muted); line-height:1.6; }
.footer-text strong { color:var(--green-l); }
#toast { position:fixed; bottom:28px; right:28px; z-index:999; background:rgba(16,22,40,.97);
  border:1px solid rgba(34,197,94,.4); border-radius:12px; padding:13px 20px; font-size:.85rem; color:var(--green-l);
  font-weight:600; display:flex; align-items:center; gap:10px; box-shadow:0 8px 30px rgba(0,0,0,.5);
  opacity:0; transform:translateY(12px); transition:opacity .3s, transform .3s; pointer-events:none; }
#toast.show { opacity:1; transform:translateY(0); }
@media (max-width:500px) { .card{padding:32px 20px;} .info-grid{grid-template-columns:1fr;} .info-box.full{grid-column:1;} h1{font-size:1.55rem;} .live-bar,.alert-bar{font-size:.75rem;} }
</style>
</head>
<body>
<div class="bg-blobs"><div class="blob blob-1"></div><div class="blob blob-2"></div><div class="blob blob-3"></div></div>
<div class="alert-bar" id="alertBar">
  <span id="alertBarText">🔔 Enable audio + push alerts to be notified of new deployments</span>
  <button class="btn-enable-alerts" id="btnEnableAlerts" onclick="enableAlerts()">Enable Alerts</button>
</div>
<div class="live-bar">
  <div class="live-pill"><span class="live-dot"></span> LIVE — polls every 10 s</div>
  <span class="server-clock" id="serverClock">Loading…</span>
</div>
<div class="card">
  <div class="icon-wrap">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  </div>
  <div class="badge">All Systems Go</div>
  <h1>Deployment Status</h1>
  <p class="subtitle">Tracking the latest push to Hostinger.<br>Audio + browser alerts fire automatically on new deployments.</p>
  <div class="info-grid">
    <div class="info-box" id="box-date"><div class="label">📅 Date</div><div class="value" id="val-date"><?= htmlspecialchars($dateStr) ?></div></div>
    <div class="info-box" id="box-time"><div class="label">🕐 Time</div><div class="value purple" id="val-time"><?= htmlspecialchars($timeStr) ?></div></div>
    <div class="info-box" id="box-ago"><div class="label">⏱ Last Push</div><div class="value green" id="val-ago"><?= htmlspecialchars($agoStr) ?></div></div>
    <div class="info-box" id="box-branch"><div class="label">🌿 Branch</div><div class="value" id="val-branch"><?= htmlspecialchars($branch) ?></div></div>
    <div class="info-box full" id="box-commit"><div class="label">📝 Commit</div>
      <div class="value" id="val-commit" style="font-size:.85rem;font-family:var(--mono);">
        <span style="color:var(--green-l);margin-right:8px;"><?= htmlspecialchars(substr($commitHash,0,7)) ?></span><?= htmlspecialchars($commitMsg) ?>
      </div>
    </div>
  </div>
  <hr class="divider">
  <div class="timeline">
    <div class="tl-row"><div class="tl-dot green"></div><div class="tl-text">Code pushed to GitHub</div><div class="tl-time">✓ Complete</div></div>
    <div class="tl-row"><div class="tl-dot purple"></div><div class="tl-text">GitHub Actions workflow triggered</div><div class="tl-time">✓ Complete</div></div>
    <div class="tl-row"><div class="tl-dot blue"></div><div class="tl-text">Files synced to Hostinger via SSH/rsync</div><div class="tl-time">✓ Complete</div></div>
    <div class="tl-row"><div class="tl-dot green"></div><div class="tl-text">Live site updated</div><div class="tl-time">✓ Active</div></div>
  </div>
  <p class="footer-text">
    Deployed by <strong id="val-pusher"><?= htmlspecialchars($pushedBy) ?></strong> &nbsp;·&nbsp;
    <?= htmlspecialchars($brand) ?> &nbsp;·&nbsp; Auto-deploy via GitHub Actions
  </p>
</div>
<div id="toast">🚀 New deployment detected!</div>
<script>
(function () {
  'use strict';
  let lastTs = <?= (int) $initRawTs ?>;
  let audioUnlocked = false;
  let audioCtx = null;
  const els = {
    date:document.getElementById('val-date'), time:document.getElementById('val-time'),
    ago:document.getElementById('val-ago'), branch:document.getElementById('val-branch'),
    commit:document.getElementById('val-commit'), pusher:document.getElementById('val-pusher'),
    clock:document.getElementById('serverClock'),
  };
  const boxes = {
    date:document.getElementById('box-date'), time:document.getElementById('box-time'),
    ago:document.getElementById('box-ago'), branch:document.getElementById('box-branch'),
    commit:document.getElementById('box-commit'),
  };
  const toast = document.getElementById('toast');
  const alertBar = document.getElementById('alertBar');
  const alertText = document.getElementById('alertBarText');
  const btnEnable = document.getElementById('btnEnableAlerts');

  function getAudioCtx() {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    return audioCtx;
  }
  function playChime() {
    try {
      const ctx = getAudioCtx();
      if (ctx.state === 'suspended') ctx.resume();
      [{freq:523.25,start:0.00,dur:0.50},{freq:659.25,start:0.13,dur:0.50},
       {freq:783.99,start:0.26,dur:0.50},{freq:1046.50,start:0.40,dur:0.75}]
      .forEach(function(n) {
        var osc=ctx.createOscillator(), gain=ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.type='sine'; osc.frequency.value=n.freq;
        var t=ctx.currentTime+n.start;
        gain.gain.setValueAtTime(0,t);
        gain.gain.linearRampToValueAtTime(0.32,t+0.04);
        gain.gain.exponentialRampToValueAtTime(0.001,t+n.dur);
        osc.start(t); osc.stop(t+n.dur+0.05);
      });
      audioUnlocked=true;
    } catch(e) { console.warn('Audio chime failed:',e); }
  }
  function unlockAudio() {
    if (audioUnlocked) return;
    try {
      var ctx=getAudioCtx();
      if (ctx.state==='suspended') ctx.resume();
      var osc=ctx.createOscillator(), gain=ctx.createGain();
      osc.connect(gain); gain.connect(ctx.destination);
      gain.gain.setValueAtTime(0,ctx.currentTime);
      osc.start(ctx.currentTime); osc.stop(ctx.currentTime+0.001);
      audioUnlocked=true;
    } catch(e) {}
  }
  function showPushNotification(data) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      var n = new Notification('🚀 New Deployment — '+(data.branch||'main'), {
        body: (data.hash||'')+' · '+(data.msg||'')+'\n'+(data.time||'')+' · '+(data.ago||''),
        icon: window.location.origin+'/favicon.ico',
        tag: 'deploy-'+(data.raw_ts||Date.now()),
        requireInteraction: false,
      });
      setTimeout(function(){ n.close(); }, 8000);
    } catch(e) {}
  }
  window.enableAlerts = function() {
    unlockAudio();
    if (!('Notification' in window)) { alertText.textContent='⚠️ Your browser does not support push notifications.'; return; }
    if (Notification.permission==='granted') { markGranted(); return; }
    Notification.requestPermission().then(function(perm) {
      if (perm==='granted') {
        markGranted(); playChime();
        showPushNotification({branch:'test',hash:'abc1234',msg:'Alerts are now active!',time:'Just now',ago:'just now',raw_ts:Date.now()});
      } else { alertText.textContent='🔕 Notifications blocked. Allow them in browser site settings.'; }
    }).catch(function(){ alertText.textContent='⚠️ Could not request notification permission.'; });
  };
  function markGranted() {
    alertBar.classList.add('granted');
    alertText.textContent='✅ Audio + push alerts are active — you will be notified of new deployments.';
    btnEnable.textContent='Enabled ✓'; btnEnable.classList.add('granted'); btnEnable.disabled=true;
  }
  if ('Notification' in window && Notification.permission==='granted') markGranted();
  function flashBox(el){ el.classList.remove('flash','updated'); void el.offsetWidth; el.classList.add('flash','updated'); setTimeout(function(){ el.classList.remove('updated'); },4000); }
  function showToast(msg){ toast.textContent='🚀 '+msg; toast.classList.add('show'); setTimeout(function(){ toast.classList.remove('show'); },5000); }
  function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function poll() {
    fetch('almightypush.php?data=1&_='+Date.now()).then(function(r){ return r.json(); }).then(function(d) {
      els.clock.textContent='Server: '+d.server_now;
      els.ago.textContent=d.ago;
      if (d.raw_ts && d.raw_ts !== lastTs) {
        lastTs=d.raw_ts;
        els.date.textContent=d.date; els.time.textContent=d.time; els.branch.textContent=d.branch;
        els.commit.innerHTML='<span style="color:var(--green-l);margin-right:8px;">'+escHtml(d.hash)+'</span>'+escHtml(d.msg);
        els.pusher.textContent=d.pushed_by;
        Object.values(boxes).forEach(flashBox);
        showToast('New deployment detected — '+d.ago);
        playChime();
        showPushNotification(d);
      }
    }).catch(function(){ els.clock.textContent='polling…'; });
  }
  poll(); setInterval(poll, 10000);
  document.addEventListener('click', unlockAudio, { once: true });
}());
</script>
</body>
</html>
