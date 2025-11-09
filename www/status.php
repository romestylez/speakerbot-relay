<?php
// ──────────────────────────────────────────────────────────────
// .env manuell laden (liegt eine Ebene über "www")
// ──────────────────────────────────────────────────────────────
$envPath = realpath(__DIR__ . '/../.env');
if ($envPath && file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue; // Kommentare/Leerzeilen
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = $value;
    }
}

// ──────────────────────────────────────────────────────────────
// Konfiguration aus .env mit Defaults
// ──────────────────────────────────────────────────────────────
$validToken  = $_ENV['VALID_TOKEN']  ?? '';
$ttsFolder   = rtrim($_ENV['TTS_FOLDER']  ?? 'D:/OBS-LIVE/Tools/TTS-Relay-Server/mp3', '/\\');
$stateFile   = $_ENV['STATE_FILE']   ?? 'D:/OBS-LIVE/Tools/TTS-Relay-Server/state.json';
$apiPort     = (int)($_ENV['PORT']   ?? 8773);
$cleanupDays = (int)($_ENV['CLEANUP_DAYS'] ?? 1);
$cleanupEnabled = (($_ENV['CLEANUP_ENABLED'] ?? '1') === '1');

// ──────────────────────────────────────────────────────────────
// Zugriff prüfen
// ──────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token !== $validToken) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

// ──────────────────────────────
// Pause / Resume Zustand
// ──────────────────────────────
if (!file_exists($stateFile)) {
    @file_put_contents($stateFile, json_encode(['paused' => false]));
}

$action = $_GET['action'] ?? '';
if ($action === 'pause' || $action === 'resume') {
    $paused = ($action === 'pause');
    @file_put_contents($stateFile, json_encode(['paused' => $paused]));
    header("Location: status.php?token=$token"); // zurück zur Statusseite
    exit;
}

// ──────────────────────────────
// Replay: .bak → mp3 verschieben (mit REPLAY_-Prefix)
// ──────────────────────────────
if ($action === 'replay') {
    $file = basename($_GET['file'] ?? '');
    if ($file === '') { http_response_code(400); exit('Missing file'); }

    $src = $ttsFolder . "/.bak/" . $file;
    if (!is_file($src)) { http_response_code(404); exit('Not found'); }

    $dst = $ttsFolder . "/REPLAY_" . $file;
    if (!@rename($src, $dst)) { http_response_code(500); exit('Move failed'); }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'file' => basename($dst)]);
    exit;
}

// ──────────────────────────────
// .bak-Verzeichnis komplett löschen
// ──────────────────────────────
if ($action === 'clearbak') {
    $bakDir = $ttsFolder . "/.bak";
    $deleted = 0;
    if (is_dir($bakDir)) {
        foreach (glob($bakDir . "/*", GLOB_NOSORT) as $f) {
            if (is_file($f)) {
                @unlink($f);
                $deleted++;
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'deleted' => $deleted]);
    exit;
}


// ──────────────────────────────
// Daten vom Node-Server holen
// ──────────────────────────────
$apiUrl = "http://127.0.0.1:{$apiPort}/speakerbot/status?token=$token";
$json = @file_get_contents($apiUrl);
$status = json_decode($json, true);

// Pause-Status laden
$state = json_decode(@file_get_contents($stateFile), true);
if (!is_array($status)) $status = [];
$status['paused'] = $state['paused'] ?? false;

// ──────────────────────────────
// Automatische Aufräumroutine (.bak)
// ──────────────────────────────
if ($cleanupEnabled) {
    $bakDir = $ttsFolder . "/.bak";
    if (is_dir($bakDir)) {
        $now = time();
        $deletedCount = 0;
        foreach (glob($bakDir . "/*.{mp3,wav}", GLOB_BRACE) as $f) {
            $ageDays = ($now - filemtime($f)) / 86400;
            if ($ageDays > $cleanupDays) {
                @unlink($f);
                $deletedCount++;
            }
        }
        if ($deletedCount > 0) {
            error_log("🧹 .bak-Aufräumroutine: $deletedCount alte Dateien entfernt");
        }
    }
}

// JSON/Text-Modus
if (isset($_GET['mode']) && $_GET['mode'] === 'text') {
    $clients = $status['clients_count'] ?? 0;
    echo $clients > 0 ? "🟢 Aktiv - Verbundene Clients: $clients" : "🔴 Keine Clients verbunden";
    exit;
}

// .bak-Liste abrufen
if (isset($_GET['list']) && $_GET['list'] === 'bak') {
    $bakDir = $ttsFolder . "/.bak";
    $files = [];
    if (is_dir($bakDir)) {
        foreach (glob($bakDir . "/*.{mp3,wav}", GLOB_BRACE) as $f) {
            $files[] = basename($f);
        }
        usort($files, fn($a, $b) => filemtime("$bakDir/$b") <=> filemtime("$bakDir/$a"));
    }
    header('Content-Type: application/json');
    echo json_encode($files);
    exit;
}

// JSON-Modus (Standard)
if (isset($_GET['mode']) && $_GET['mode'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($status);
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
    background:#000;
    color:#0f0;
    font-family:Consolas,"Courier New",monospace;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    margin:0;
  }
  .box {
    border:1px solid #0f0;
    padding:25px 35px;
    border-radius:10px;
    box-shadow:0 0 6px #0f0;
    min-width:450px;
  }
  h2 { font-size:26px; margin-bottom:20px; text-align:center; }
  table { width:100%; border-collapse:collapse; margin-top:10px; font-size:16px; }
  th { text-align:left; border-bottom:1px solid #0f0; padding-bottom:6px; }
  td { padding:4px 0; }
  .item { font-size:18px; margin:10px 0; display:flex; align-items:center; gap:10px; }
  .label { font-weight:bold; }
  .ok { color:#0f0; text-shadow:0 0 6px #0f0; }
  .bad { color:#f33; text-shadow:0 0 6px #f00; }
  .disc { color:#ff9900; text-shadow:0 0 6px #ff9900; }
  .controls { text-align:center; margin-top:15px; }
  .btn {
    display:inline-block;
    padding:8px 18px;
    margin:4px;
    border:none;
    border-radius:6px;
    font-size:16px;
    font-weight:bold;
    cursor:pointer;
    text-decoration:none;
  }
  .pause { background:#ff0; color:#000; }
  .resume { background:#0f0; color:#000; }
</style>
</head>

<body>
<div class="box">
  <h2>🎧 Speakerbot Live-Status</h2>

<?php if(!$status || isset($status['error'])): ?>
  <div class="item bad">❌ Verbindung zum Audio-Server verloren!</div>
<?php else: ?>
  <div class="item">
    🔊 <span class="label">Clients verbunden:</span>
    <span id="clientCount"><?= $status['clients_count'] ?? 0 ?></span>
  </div>

  <div class="item">
    🕹️ <span class="label">Status:</span>
    <span id="playState"><?= $status['paused'] ? "⏸️ Pausiert" : "▶️ Aktiv" ?></span>
  </div>

  <div class="controls">
    <?php if($status['paused']): ?>
      <a href="?token=<?= $token ?>&action=resume" class="btn resume">▶️ Start</a>
    <?php else: ?>
      <a href="?token=<?= $token ?>&action=pause" class="btn pause">⏸️ Pause</a>
    <?php endif; ?>
  </div>

  <?php if (!empty($status['clients'])): ?>
  <table>
    <tr><th>IP</th><th>Status</th><th>Letzte Aktivität</th></tr>
    <?php foreach($status['clients'] as $c):
      $ago = intval($c['lastSeenAgoSec']);
      $isActive = $c['connected'];
      $showTime = $ago > 5;
      $timeFormatted = "";
      if ($showTime) {
          if ($ago >= 60) {
              $m = floor($ago/60);
              $s = $ago%60;
              $timeFormatted = "{$m}m {$s}s";
          } else $timeFormatted = "{$ago}s";
      }
      if ($isActive) {
          if ($ago <= 5) { $statusText='🟢 aktiv'; $statusClass='ok'; }
          else { $statusText='🟠 inaktiv'; $statusClass='disc'; }
      } else { $statusText='🔴 getrennt'; $statusClass='bad'; }
    ?>
    <tr>
      <td><?= htmlspecialchars($c['ip']) ?></td>
      <td class="<?= $statusClass ?>"><?= $statusText ?></td>
      <td class="<?= $showTime ? $statusClass : '' ?>"><?= $timeFormatted ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php endif; ?>

<hr style="margin:20px 0; border-color:#0f0;">
<div class="item" style="flex-direction:column;align-items:flex-start;">
  <div>🔁 <span class="label">TTS erneut abspielen:</span></div>
  <div style="margin-top:6px;">
    <select id="bakSelect" style="background:#000;color:#0f0;border:1px solid #0f0;padding:4px 8px;border-radius:6px;width:100%;max-width:320px;"></select>
    <button id="bakPlayBtn" style="margin-top:8px;background:#000;color:#0f0;border:1px solid #0f0;border-radius:6px;padding:4px 8px;cursor:pointer;width:100%;max-width:320px;">▶️ Abspielen</button>
  </div>
</div>

<div class="controls" style="margin-top:20px;">
  <button id="clearBakBtn" style="background:#f33;color:#fff;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;font-weight:bold;">
    🗑️ Delete .bak Files
  </button>
</div>

</div>

<script>
async function updateStatus() {
  try {
    const res = await fetch("status.php?token=<?= $token ?>&mode=json",{cache:"no-store"});
    const data = await res.json();
    if (!data) return;

    const cc=document.getElementById('clientCount');
    if(cc) cc.textContent=data.clients_count;

    const ps=document.getElementById('playState');
    if(ps) ps.textContent=data.paused ? "⏸️ Pausiert" : "▶️ Aktiv";

    const table=document.querySelector("table");
    if(!table) return;
    table.querySelectorAll("tr:not(:first-child)").forEach(tr=>tr.remove());

    if(data.clients){
      data.clients.forEach(c=>{
        const ago=parseInt(c.lastSeenAgoSec);
        const showTime=ago>5;
        let cls="bad", st="🔴 getrennt";
        if(c.connected){
          if(ago<=5){cls="ok";st="🟢 aktiv";}
          else{cls="disc";st="🟠 inaktiv";}
        }
        const tf=showTime?(ago>=60?`${Math.floor(ago/60)}m ${ago%60}s`:`${ago}s`):"";
        const tr=document.createElement("tr");
        tr.innerHTML=`<td>${c.ip}</td><td class="${cls}">${st}</td><td class="${showTime?cls:""}">${tf}</td>`;
        table.appendChild(tr);
      });
    }
  }catch(e){console.warn("Update fehlgeschlagen:",e);}
}

async function loadBakFiles() {
  const sel = document.getElementById('bakSelect');
  if (!sel) return;
  sel.innerHTML = '<option>lade…</option>';
  try {
    const res = await fetch('status.php?token=<?= $token ?>&list=bak', { cache: "no-store" });
    const data = await res.json();
    sel.innerHTML = data.length
      ? data.map(f => `<option value="${f}">${f}</option>`).join("")
      : '<option>(leer)</option>';
  } catch (e) {
    sel.innerHTML = '<option>Fehler beim Laden</option>';
  }
}

  const clearBtn = document.getElementById('clearBakBtn');
  if (clearBtn) {
    clearBtn.addEventListener('click', async () => {
      if (!confirm("Delete all Replay files")) return;
      try {
        const res = await fetch(`status.php?token=<?= $token ?>&action=clearbak`, { cache: "no-store" });
        const data = await res.json();
        alert(`🗑️ Deleted ${data.deleted} file(s).`);
        loadBakFiles();
      } catch (err) {
        alert("❌ Error deleting .bak files");
      }
    });
  }


document.addEventListener("DOMContentLoaded", () => {
  const playBtn = document.getElementById('bakPlayBtn');
  const sel = document.getElementById('bakSelect');
  if (!playBtn || !sel) return;

  playBtn.addEventListener('click', async () => {
    const file = sel.value;
    if (!file || file.startsWith('(')) return;

    console.log('▶️ Replay angefordert:', file);
    try {
      const res = await fetch(`status.php?token=<?= $token ?>&action=replay&file=${encodeURIComponent(file)}`, { cache: "no-store" });
      if (!res.ok) return console.warn('❌ Replay-Request fehlgeschlagen');
      const data = await res.json();
      console.log('📦 Replay bereitgestellt als:', data.file);
    } catch (err) {
      console.error('⚠️ Fehler beim Replay:', err);
    }
  });

  loadBakFiles();
});

setInterval(updateStatus, 1000);
updateStatus();
</script>
</body>
</html>
