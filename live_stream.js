// live_stream.js - Speakerbot MP3 Stream + Token + Heartbeat + Auto-Delete
import 'dotenv/config';
import express from "express";
import fs from "fs";
import path from "path";


const app = express();
const PORT = process.env.PORT || 8773;

const VALID_TOKEN = process.env.VALID_TOKEN;
const ttsFolder = path.resolve(process.env.TTS_FOLDER);

if (!fs.existsSync(ttsFolder)) fs.mkdirSync(ttsFolder);

// ───────────────────────────────────────────────────────────────────────────────
// Session Tracking (Heartbeat + Sweeper)
// ───────────────────────────────────────────────────────────────────────────────
const clientState = new Map();
const INACTIVITY_MS = 60 * 1000;
function nowMs() { return Date.now(); }
function getClientId(req) {
  return (req.headers["x-forwarded-for"] || req.ip || "unknown").toString();
}
function connectedCount() {
  let n = 0;
  for (const { connected } of clientState.values()) if (connected) n++;
  return n;
}
setInterval(() => {
  const t = nowMs();
  for (const [id, st] of clientState.entries()) {
    if (st.connected && t - st.lastSeen > INACTIVITY_MS) {
      st.connected = false;
      clientState.delete(id);
      console.log(`👤 End Session - ${formatTS(new Date())} → ${id} (active ${connectedCount()})`);
    }
  }
}, 5000);

function formatTS(d) {
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = d.getFullYear();
  const HH = String(d.getHours()).padStart(2, "0");
  const MM = String(d.getMinutes()).padStart(2, "0");
  const SS = String(d.getSeconds()).padStart(2, "0");
  return `${dd}.${mm}.${yyyy} ${HH}:${MM}:${SS}`;
}

// ───────────────────────────────────────────────────────────────────────────────
// Heartbeat / Ping
// ───────────────────────────────────────────────────────────────────────────────
app.get("/speakerbot/ping", (req, res) => {
  if (req.query.token !== VALID_TOKEN) return res.sendStatus(403);
  const id = getClientId(req);
  const st = clientState.get(id) || { lastSeen: 0, connected: false };
  st.lastSeen = nowMs();
  if (!st.connected) {
    st.connected = true;
    clientState.set(id, st);
    console.log(`👤 Start Session - ${formatTS(new Date())} → ${id} (active ${connectedCount()})`);
  }
  res.sendStatus(204);
});

// 🔊 MP3-Abspielroute → liefert immer die neueste Datei & löscht sie danach
app.get("/speakerbot", (req, res) => {
  if (req.query.token !== VALID_TOKEN) return res.sendStatus(403);
  const ip = getClientId(req);

  try {
    const mp3s = fs.readdirSync(ttsFolder)
      .filter(f => /\.(mp3|wav)$/i.test(f))
      .map(f => ({ f, t: fs.statSync(path.join(ttsFolder, f)).mtimeMs }))
      .sort((a, b) => b.t - a.t);

    if (!mp3s.length) return res.status(404).send("No MP3 files found");

    const newest = mp3s[0].f;
    const fullPath = path.join(ttsFolder, newest);
    const tempPath = fullPath + ".lock";

    // 🔒 Datei sofort sperren (rename), damit sie nicht erneut gefunden wird
    try {
      fs.renameSync(fullPath, tempPath);
      console.log(`🔒 Datei gesperrt: ${newest}`);
    } catch (err) {
      console.warn("⚠️ Konnte Datei nicht sperren:", err);
      return res.status(500).send("File lock failed");
    }

    console.log(`▶️ Starte Stream: ${newest} (Client: ${ip})`);

    const ext = path.extname(newest).toLowerCase();
res.setHeader("Content-Type", ext === ".wav" ? "audio/wav" : "audio/mpeg");
    res.setHeader("Cache-Control", "no-store, no-cache, must-revalidate, proxy-revalidate");
    res.setHeader("Pragma", "no-cache");
    res.setHeader("Expires", "0");

    const stream = fs.createReadStream(tempPath);
    stream.pipe(res);

    // 🔥 Datei nach Stream löschen
    res.on("close", () => {
  try {
    const bakDir = path.join(ttsFolder, ".bak");
    if (!fs.existsSync(bakDir)) fs.mkdirSync(bakDir, { recursive: true });

    const destPath = path.join(bakDir, newest);

    // 📦 .lock-Datei in .bak verschieben und wieder umbenennen
    fs.renameSync(tempPath, destPath);
    console.log(`📦 Verschoben nach .bak: ${newest}`);
  } catch (err) {
    console.warn("⚠️ Konnte Datei nicht in .bak verschieben:", err);
    try {
      fs.unlinkSync(tempPath);
      console.log("🗑️ Temporäre Datei entfernt.");
    } catch {}
  }
});



  } catch (err) {
    console.error("❌ Fehler bei MP3-Ausgabe:", err);
    res.status(500).send("Server error");
  }
});


// ───────────────────────────────────────────────────────────────────────────────
// Status → liefert aktuelle Clients und neueste MP3-Datei
// ───────────────────────────────────────────────────────────────────────────────
app.get("/speakerbot/status", (req, res) => {
  if (req.query.token !== VALID_TOKEN)
    return res.status(403).json({ error: "Access denied" });

  const clients = [];
  const t = nowMs();
  for (const [id, st] of clientState.entries()) {
    clients.push({
      ip: id,
      connected: st.connected,
      lastSeenAgoSec: Math.round((t - st.lastSeen) / 1000)
    });
  }

  let latestFile = null;
  try {
    // Nur Dateien mit Endung .mp3 und .wav (keine .playing)
const files = fs.readdirSync(ttsFolder)
  .filter(f => /\.(mp3|wav)$/i.test(f) && !f.includes(".lock"))
  .map(f => ({ f, t: fs.statSync(path.join(ttsFolder, f)).mtimeMs }))
  .sort((a, b) => b.t - a.t);

latestFile = files.length ? files[0].f : null;

  } catch {}

  res.json({
    timestamp: formatTS(new Date()),
    clients_count: clients.filter(c => c.connected).length,
    latest_file: latestFile,
    clients
  });
});

// ───────────────────────────────────────────────────────────────────────────────
// Start
// ───────────────────────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
  console.log("✅ Speakerbot Relay");
  console.log(`URL: /speakerbot/?token=${VALID_TOKEN}`);
  console.log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
});

// ───────────────────────────────────────────────────────────────────────────────
// Clean Exit
// ───────────────────────────────────────────────────────────────────────────────
process.on("SIGINT", () => {
  console.log("\n🛑 Stop…");
  process.exit(0);
});
