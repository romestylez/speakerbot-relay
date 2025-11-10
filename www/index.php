<?php
// ──────────────────────────────────────────────────────────────
// .env manuell laden (liegt eine Ebene über "www")
// ──────────────────────────────────────────────────────────────
$envPath = realpath(__DIR__ . '/../.env');
if ($envPath && file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue; // Kommentare/Leerzeilen überspringen
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = $value;
    }
}

// Token aus der .env lesen
$validToken = $_ENV['VALID_TOKEN'] ?? '';

// ──────────────────────────────────────────────────────────────
// Zugriff prüfen
// ──────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token !== $validToken) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TTS Auto Player</title>
<style>
  body {
    background: #000;
    color: #fff;
    font-family: sans-serif;
    text-align: center;
    margin-top: 50px;
  }
  h2 { margin-bottom: 0; }
  p { color: #bbb; }
</style>
</head>
<body>
  <h2>TTS Auto Player</h2>
  <p>Diese Seite spielt automatisch neue TTS-Nachrichten ab.</p>

<script>
const token = "<?= $validToken ?>";

// Der Rest bleibt unverändert …
let lastFile = "";
let isPlaying = false;
let cooldown = false;
let ctx = null;
let currentSrc = null;
const FORCE_MONO = true;

function toMonoBuffer(ctx, buffer) {
  if (buffer.numberOfChannels === 1 || !FORCE_MONO) return buffer;
  const len = buffer.length;
  const rate = buffer.sampleRate;
  const chL = buffer.getChannelData(0);
  const chR = buffer.getChannelData(1);
  const mono = ctx.createBuffer(1, len, rate);
  const out = mono.getChannelData(0);
  for (let i = 0; i < len; i++) out[i] = (chL[i] + chR[i]) * 0.5;
  return mono;
}

async function playFile(filename) {
  if (!filename) return;
  if (cooldown || isPlaying || filename === lastFile) return;
  console.log("▶️ Starte Wiedergabe:", filename);
  lastFile = filename;
  isPlaying = true;

  try {
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (currentSrc) {
      try { currentSrc.stop(0); } catch {}
      currentSrc.disconnect();
      currentSrc = null;
    }

    const url = `/speakerbot/mp3.php?file=${encodeURIComponent(filename)}&token=${token}&_=${Date.now()}`;
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const arrayBuf = await res.arrayBuffer();

    const decoded = await ctx.decodeAudioData(arrayBuf.slice(0));
    const mono = toMonoBuffer(ctx, decoded);

    const src = ctx.createBufferSource();
    src.buffer = mono;
    src.connect(ctx.destination);
    currentSrc = src;

    src.onended = () => {
      console.log("✅ Fertig:", filename);
      isPlaying = false;
      currentSrc = null;
      cooldown = true;
      setTimeout(() => cooldown = false, 1000);
    };

    src.start(0);

  } catch (err) {
    console.warn("❌ Wiedergabefehler:", err);
    isPlaying = false;
    currentSrc = null;
  }
}

async function checkForNewTTS() {
  try {
    const res = await fetch(`/speakerbot/status.php?mode=json&list=1&token=${token}&_=${Date.now()}`, { cache: "no-store" });
    if (!res.ok) return;
    const data = await res.json();
    if (data && data.paused) return;

    if (data && Array.isArray(data.files_asc) && data.files_asc.length) {
      const oldest = data.files_asc[0];
      if (!isPlaying && oldest && oldest !== lastFile) {
        await playFile(oldest);
      }
      return;
    }

    if (data && data.latest_file && data.latest_file !== lastFile && !isPlaying) {
      await playFile(data.latest_file);
    }
  } catch (err) {
    console.warn("⚠️ checkForNewTTS Fehler:", err);
  }
}

setInterval(checkForNewTTS, 1500);

async function startHeartbeat() {
  while (true) {
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 2000);

      await fetch(`/speakerbot/ping?token=${token}&_=${Date.now()}`, {
        cache: "no-store",
        signal: controller.signal
      });

      clearTimeout(timeout);
    } catch {
      // Ping-Fehler werden still ignoriert
    }

    await new Promise(r => setTimeout(r, 5000));
  }
}
startHeartbeat();


</script>

</body>
</html>
