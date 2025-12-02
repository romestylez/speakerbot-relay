<?php
// ──────────────────────────────────────────────────────────────
// .env laden
// ──────────────────────────────────────────────────────────────
$envPath = realpath(__DIR__ . '/../.env');
if ($envPath && file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = $value;
    }
}

// ──────────────────────────────────────────────────────────────
// Konfiguration
// ──────────────────────────────────────────────────────────────
// ──────────────────────────────────────────────────────────────
// Konfiguration (ohne Fallbacks – alles MUSS in .env stehen)
// ──────────────────────────────────────────────────────────────
if (
    !isset($_ENV['VALID_TOKEN']) ||
    !isset($_ENV['TTS_FOLDER']) ||
    !isset($_ENV['STATE_FILE']) ||
    !isset($_ENV['PORT']) ||
    !isset($_ENV['CLEANUP_DAYS']) ||
    !isset($_ENV['CLEANUP_ENABLED'])
) {
    http_response_code(500);
    exit("❌ ERROR: Fehlende Konfiguration in .env");
}

$validToken     = $_ENV['VALID_TOKEN'];
$ttsFolder      = rtrim($_ENV['TTS_FOLDER'], '/\\');
$stateFile      = $_ENV['STATE_FILE'];
$apiPort        = (int)$_ENV['PORT'];
$cleanupDays    = (int)$_ENV['CLEANUP_DAYS'];
$cleanupEnabled = ($_ENV['CLEANUP_ENABLED'] === '1');

$logFile        = dirname($ttsFolder) . '/log/tts_log.json';

// ──────────────────────────────────────────────────────────────
// Zugriff prüfen
// ──────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token !== $validToken) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$action = $_GET['action'] ?? '';

// ──────────────────────────────
// Server-Log laden / löschen
// ──────────────────────────────
if ($action === 'getlog') {
    header('Content-Type: application/json');
    echo file_exists($logFile) ? file_get_contents($logFile) : json_encode([]);
    exit;
}
if ($action === 'clearlog') {
    @file_put_contents($logFile, json_encode([]));
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ──────────────────────────────
// Pause / Resume
// ──────────────────────────────
if (!file_exists($stateFile)) {
    @file_put_contents($stateFile, json_encode(['paused' => false]));
}
if ($action === 'pause' || $action === 'resume') {
    $paused = ($action === 'pause');
    @file_put_contents($stateFile, json_encode(['paused' => $paused]));
    header("Location: status.php?token=$token");
    exit;
}

// ──────────────────────────────
// Replay & .bak Verwaltung
// ──────────────────────────────
if ($action === 'replay') {
    $file = basename($_GET['file'] ?? '');
    if (!$file) { http_response_code(400); exit('Missing file'); }
    $src = $ttsFolder . "/.bak/" . $file;
    if (!is_file($src)) { http_response_code(404); exit('Not found'); }
    $dst = $ttsFolder . "/REPLAY_" . $file;
    if (!@rename($src, $dst)) { http_response_code(500); exit('Move failed'); }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'file' => basename($dst)]);
    exit;
}
if ($action === 'clearbak') {
    $bakDir = $ttsFolder . "/.bak";
    $deleted = 0;
    if (is_dir($bakDir)) {
        foreach (glob($bakDir . "/*", GLOB_NOSORT) as $f) {
            if (is_file($f)) { @unlink($f); $deleted++; }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'deleted' => $deleted]);
    exit;
}

// ──────────────────────────────
// Node-Status laden
// ──────────────────────────────
$apiUrl = "http://127.0.0.1:{$apiPort}/speakerbot/status?token=$token";
$json   = @file_get_contents($apiUrl);
$status = json_decode($json, true);

$state = json_decode(@file_get_contents($stateFile), true);
if (!is_array($status)) $status = [];
$status['paused'] = $state['paused'] ?? false;

// ──────────────────────────────
// .bak Aufräumen
// ──────────────────────────────
if ($cleanupEnabled) {
    $bakDir = $ttsFolder . "/.bak";
    if (is_dir($bakDir)) {
        $now = time();
        foreach (glob($bakDir . "/*.{mp3,wav}", GLOB_BRACE) as $f) {
            if ((($now - filemtime($f)) / 86400) > $cleanupDays) @unlink($f);
        }
    }
}

// .bak-Liste (JSON)
// ─────────────────
if (isset($_GET['list']) && $_GET['list'] === 'bak') {
    $bakDir = $ttsFolder . "/.bak";
    $files = [];
    if (is_dir($bakDir)) {
        foreach (glob($bakDir . "/*.{mp3,wav}", GLOB_BRACE) as $f) {
            $files[] = basename($f);
        }
        usort($files, fn($a,$b)=>filemtime("$bakDir/$b")<=>filemtime("$bakDir/$a")); // neueste zuerst
    }
    header('Content-Type: application/json');
    echo json_encode($files);
    exit;
}

// Status (JSON)
// ─────────────
if (isset($_GET['mode']) && $_GET['mode'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($status);
    exit;
}

// Status (Text) – für externe Tools / Bots
if (isset($_GET['mode']) && $_GET['mode'] === 'text') {
    $clients = $status['clients_count'] ?? 0;
    $paused  = $status['paused'] ?? false;

    $circle = ($clients > 0 && !$paused) ? "🟢" : "🔴";

    header('Content-Type: text/plain; charset=utf-8');

    if ($clients === 0) {
        echo "$circle Keine Clients verbunden";
    } else {
        echo "$circle {$clients} Client(s) verbunden";
    }
    exit;
}



?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>🎧 Speakerbot Live-Status</title>
<style>
body {
  background:#000; color:#0f0; font-family:Consolas,"Courier New",monospace;
  display:flex; justify-content:center; align-items:center; height:100vh; margin:0;
}
.box { border:1px solid #0f0; padding:25px 35px; border-radius:10px; box-shadow:0 0 6px #0f0; min-width:700px; text-align:center; }
h2 { margin-bottom:20px; }
a { text-decoration:none; }
.item { font-size:18px; margin:10px 0; display:flex; justify-content:center; align-items:center; gap:10px; }
.ok { color:#0f0; } .bad{color:#f33;} .disc{color:#ff9900;}
.btn { display:inline-block; padding:8px 18px; margin:6px; border:none; border-radius:6px; font-weight:bold; cursor:pointer; }
.pause{ background:#ff0; color:#000; } .resume{ background:#0f0; color:#000; }

table { width:100%; border-collapse:collapse; margin-top:12px; font-size:16px; }
th { text-align:left; border-bottom:1px solid #0f0; padding-bottom:6px; }
td { padding:6px 0; }

.replay-button button {
  background:linear-gradient(90deg,#0f0,#4dff4d); color:#000; border:none; font-weight:bold;
  border-radius:6px; padding:7px 14px; cursor:pointer; transition:all .25s ease; width:220px;
}
.replay-button button:hover { transform:scale(1.05); box-shadow:0 0 12px #4dff4d; }

.select {
  margin:10px 0; padding:6px 10px; border:1px solid #0f0; background:#000; color:#0f0; border-radius:6px; width:380px;
}
.logbox {
  margin-top:10px;
  background:#111;
  color:#0f0;
  padding:10px;
  border:1px solid #0f0;
  border-radius:8px;
  max-height:250px;
  overflow-y:auto;
  font-size:14px;
  line-height:1.3;              /* kompakter */
  white-space:normal !important;/* ✅ erlaubt Umbrüche überall */
  word-break:break-all;         /* ✅ bricht lange Wörter (Dateinamen) */
  overflow-wrap:anywhere;       /* ✅ moderne Browser */
  box-sizing:border-box;
}
/* Begrenzung und zentrierte Tabelle */
.box {
  max-width: 750px;           /* verhindert "über die ganze Seite" */
  margin: 0 auto;             /* mittig ausrichten */
}

/* Tabelle auf Box-Breite beschränken */
#clientTable {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}

/* Einheitliche Spaltenbreite + saubere Ausrichtung */
#clientTable th,
#clientTable td {
  text-align: center;
  padding: 6px 0;
  white-space: nowrap;
}

#clientTable th:nth-child(1),
#clientTable td:nth-child(1) {
  width: 35%;
  text-align: left;
  padding-left: 15px;
}

#clientTable th:nth-child(2),
#clientTable td:nth-child(2) {
  width: 30%;
  text-align: center;
}

#clientTable th:nth-child(3),
#clientTable td:nth-child(3) {
  width: 35%;
  text-align: right;
  padding-right: 15px;
}



</style>
</head>
<body>
<div class="box">
  <h2>🎧 Speakerbot Live-Status</h2>

  <div class="item">🔊 <b>Clients:</b> <span id="clientCount"><?= $status['clients_count'] ?? 0 ?></span></div>
  <div class="item">🕹️ <b>Status:</b> <span id="playState"><?= $status['paused'] ? "⏸️ Pausiert" : "▶️ Aktiv" ?></span></div>
  <div class="controls">
    <?php if($status['paused']): ?>
      <a href="?token=<?= $token ?>&action=resume" class="btn resume">▶️ Start</a>
    <?php else: ?>
      <a href="?token=<?= $token ?>&action=pause" class="btn pause">⏸️ Pause</a>
    <?php endif; ?>
  </div>

  <!-- Clients-Tabelle -->
  <table id="clientTable">
    <thead>
      <tr><th>IP</th><th>Status</th><th>Letzte Aktivität</th></tr>
    </thead>
    <tbody></tbody>
  </table>

  <hr style="margin:20px 0;border-color:#0f0;">

  <!-- Replay -->
  <div class="item" style="flex-direction:column;align-items:center;">
    <b>🔁 TTS erneut abspielen:</b>
    <select id="bakSelect" class="select"></select>
    <div class="replay-button"><button id="bakPlayBtn">▶️ Abspielen</button></div>
  </div>

  <div class="controls" style="margin-top:20px;">
    <button id="clearBakBtn" style="background:#f33;color:#fff;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;font-weight:bold;">🗑️ Delete Replay Files</button>
  </div>



<script>
const token = "<?= $token ?>";

// ───────── Clients live updaten (inkl. IP & Last Seen)
function renderClients(clients){
  const tbody = document.querySelector("#clientTable tbody");
  tbody.innerHTML = "";
  if(!Array.isArray(clients)) return;
  clients.forEach(c=>{
    const ago = parseInt(c.lastSeenAgoSec);
    const showTime = ago > 5;
    let cls="bad", st="🔴 getrennt";
    if(c.connected){
      if(ago<=5){cls="ok";st="🟢 aktiv";}
      else{cls="disc";st="🟠 inaktiv";}
    }
    const tf = showTime ? (ago>=60?`${Math.floor(ago/60)}m ${ago%60}s`:`${ago}s`) : "";
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${c.ip}</td><td class="${cls}">${st}</td><td class="${showTime?cls:""}">${tf}</td>`;
    tbody.appendChild(tr);
  });
}

async function updateStatus(){
  try{
    const res = await fetch(`status.php?token=${token}&mode=json`, {cache:"no-store"});
    const d = await res.json();
    document.getElementById('clientCount').textContent = d.clients_count ?? 0;
    document.getElementById('playState').textContent  = d.paused ? "⏸️ Pausiert" : "▶️ Aktiv";
    renderClients(d.clients || []);
  }catch(e){ console.warn(e); }
}

// ───────── .bak Dropdown flackerfrei aktualisieren
let lastBakList = [];
async function loadBakFiles(){
  const sel = document.getElementById('bakSelect');
  try{
    const r = await fetch(`status.php?token=${token}&list=bak`, {cache:"no-store"});
    let d = await r.json();
    if(!Array.isArray(d)) d = [];
    // ⚡ Reihenfolge wie vom Server übernehmen (bereits korrekt sortiert)

    // identisch? -> nichts tun (kein Flackern)
    if (JSON.stringify(d) === JSON.stringify(lastBakList)) return;

    const prev = sel.value;
    sel.innerHTML = d.length
      ? d.map(f=>`<option value="${f}">${f}</option>`).join("")
      : '<option>(leer)</option>';

    // Auswahl beibehalten, wenn noch vorhanden; sonst erste (neueste)
    if (d.length){
      if (d.includes(prev)) sel.value = prev;
      else sel.selectedIndex = 0;
    }
    lastBakList = d;
  }catch(e){
    sel.innerHTML = '<option>Fehler</option>';
  }
}

// Replay-Button
document.getElementById('bakPlayBtn').addEventListener('click', async ()=>{
  const f = document.getElementById('bakSelect').value;
  if(!f || f.startsWith('(')) return;
  const r = await fetch(`status.php?token=${token}&action=replay&file=${encodeURIComponent(f)}`, {cache:"no-store"});
  if (r.ok) {
    const d = await r.json();
    console.log("Replay:", d.file);
    // Nach Replay Liste sanft aktualisieren (Datei wandert aus .bak raus)
    loadBakFiles();
  }
});

// Delete Replay Files
document.getElementById('clearBakBtn').addEventListener('click', async ()=>{
  if(!confirm("Delete all Replay files?")) return;
  const r = await fetch(`status.php?token=${token}&action=clearbak`, {cache:"no-store"});
  const d = await r.json();
  alert(`🗑️ Deleted ${d.deleted} file(s).`);
  lastBakList = []; // Force-Refresh
  loadBakFiles();
});


// ───────── Intervalle
setInterval(updateStatus,   1000);
setInterval(loadBakFiles,   1000);
updateStatus();

loadBakFiles();
</script>
</body>
</html>
