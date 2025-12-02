<?php
// ──────────────────────────────────────────────────────────────
// .env manuell laden (liegt eine Ebene über "www")
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

$validToken = $_ENV['VALID_TOKEN'] ?? '';

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

let lastFile = "";
let lastFileBase = "";
let isPlaying = false;
let cooldown = false;
let ctx = null;
let currentSrc = null;
const FORCE_MONO = true;

// 🧾 Log (Browser + Server)
function addLogEntry(type, filename) {
  const log = JSON.parse(localStorage.getItem("tts_log") || "[]");
  const entry = {
    time: new Date().toLocaleTimeString(),
    type,
    filename
  };
  log.unshift(entry);
  if (log.length > 100) log.pop();
  localStorage.setItem("tts_log", JSON.stringify(log));
  sendLogToServer(type, filename);
}

async function sendLogToServer(type, filename) {
  try {
    await fetch("log_writer.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ type, filename })
    });
  } catch (e) {
    console.warn("⚠️ Serverlog fehlgeschlagen:", e);
  }
}

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

// ⏱ Timestamp normalisieren (beide Formate → gleicher Vergleichswert)
function extractBaseStamp(name) {
  const iso = name.match(/(\d{8}T\d{6})/);
  const human = name.match(/(\d{2}\.\d{2}\.\d{4}-\d{2}\.\d{2}\.\d{2})/);
  if (iso) {
    return iso[1].replace("T", ""); // z. B. 20251110133353
  } else if (human) {
    const parts = human[1].match(/(\d{2})\.(\d{2})\.(\d{4})-(\d{2})\.(\d{2})\.(\d{2})/);
    if (parts) {
      return parts[3] + parts[2] + parts[1] + parts[4] + parts[5] + parts[6]; // → 20251110133353
    }
  }
  return name;
}

async function playFile(filename) {
  if (!filename) return;

  const baseStamp = extractBaseStamp(filename);

  // ⛔ Wenn schon spielt oder Cooldown aktiv → abbrechen
  if (isPlaying || cooldown) {
    console.log("⏩ Läuft noch oder Cooldown aktiv, überspringe:", filename);
    addLogEntry("Übersprungen", filename);
    return;
  }

  // ⛔ Gleiche Datei wie zuletzt → nicht erneut abspielen
  if (!filename.startsWith("REPLAY_") && baseStamp === lastFileBase) {
    console.log("⏩ Bereits abgespielt:", filename);
    addLogEntry("Übersprungen", filename);
    return;
  }

  isPlaying = true;
  lastFile = filename;
  lastFileBase = baseStamp;

  console.log("▶️ Starte Wiedergabe:", filename);
  addLogEntry("Spiele ab", filename);

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
      addLogEntry("Fertig", filename);
      isPlaying = false;
      currentSrc = null;
      cooldown = true;
      setTimeout(() => cooldown = false, 1000); // 1s Cooldown
    };

    src.start(0);
  } catch (err) {
    console.warn("❌ Wiedergabefehler:", err);
    addLogEntry("Fehler", filename);
    isPlaying = false;
    currentSrc = null;
  }
}

async function checkForNewTTS() {
  // 🧠 Nur prüfen, wenn gerade nichts läuft
  if (isPlaying || cooldown) return;

  try {
    const res = await fetch(`/speakerbot/status.php?mode=json&list=1&token=${token}&_=${Date.now()}`, { cache: "no-store" });
    if (!res.ok) return;
    const data = await res.json();
    if (data && data.paused) return;

    const nextFile =
      (data.files_asc && data.files_asc.length && data.files_asc[0]) ||
      data.latest_file;

    if (nextFile) await playFile(nextFile);
  } catch (err) {
    console.warn("⚠️ checkForNewTTS Fehler:", err);
  }
}

// alle 1,5 s prüfen
setInterval(checkForNewTTS, 1500);

// Ping zum Server halten
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
    } catch {}
    await new Promise(r => setTimeout(r, 5000));
  }
}
startHeartbeat();

// 🧩 Testeintrag bei leerem Log
if (!localStorage.getItem("tts_log")) {
  addLogEntry("System", "TTS Auto Player gestartet");
  console.log("🧾 Testeintrag erstellt (Log war leer)");
}
</script>
</body>
</html>
