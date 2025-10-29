// live_stream.js
import { spawn } from 'child_process';
import express from 'express';

const app = express();
const PORT = 8773;
const ffmpegPath = 'C:/Tools/ffmpeg/bin/ffmpeg.exe';
const audioDevice = 'audio=Hi-Fi Cable Output (VB-Audio Hi-Fi Cable)';

let clients = [];
let ffmpegProcess = null;

// --- Timestamp Helper ---
function ts() {
  return new Date().toISOString().replace('T', ' ').split('.')[0];
}

// --- Restart Control ---
let restarting = false;
let restartAttempts = 0;
const MAX_RESTART_ATTEMPTS = 5;
const RESTART_DELAY_MS = 5000;

// --- Helper: Disconnect all clients ---
function disconnectAllClients(reason = 'unknown') {
  if (clients.length > 0)
    console.log(`[${ts()}] ❌ Disconnecting ${clients.length} client(s) (${reason})...`);
  clients.forEach(entry => {
    try { entry.res.end(); } catch {}
  });
  clients = [];
}

// --- Safe FFmpeg restart ---
function scheduleRestart(reason = 'unknown') {
  if (restarting) return;
  restarting = true;
  restartAttempts++;

  console.warn(`[${ts()}] ⚠️ FFmpeg exited – scheduling restart (${restartAttempts}/${MAX_RESTART_ATTEMPTS}) [${reason}]`);

  if (restartAttempts > MAX_RESTART_ATTEMPTS) {
    console.error(`[${ts()}] 🚨 Too many restart attempts, waiting 1 minute before retry...`);
    restartAttempts = 0;
    setTimeout(() => {
      restarting = false;
      startFFmpeg();
    }, 60000);
    return;
  }

  setTimeout(() => {
    restarting = false;
    startFFmpeg();
  }, RESTART_DELAY_MS);
}

// --- Start or restart FFmpeg process ---
function startFFmpeg() {
  console.log(`[${ts()}] [INFO] Starting FFmpeg audio capture...`);

  if (ffmpegProcess) {
    try { ffmpegProcess.kill('SIGKILL'); } catch {}
    ffmpegProcess = null;
  }

  disconnectAllClients('FFmpeg restart');

  ffmpegProcess = spawn(ffmpegPath, [
    '-f', 'dshow',
    '-i', audioDevice,
    '-ac', '2',
    '-ar', '44100',
    '-b:a', '128k',
    '-flush_packets', '1',
    '-fflags', '+nobuffer',
    '-flags', 'low_delay',
    '-f', 'mp3',
    'pipe:1'
  ]);

  ffmpegProcess.stdout.on('data', chunk => {
    restartAttempts = 0;
    clients.forEach(entry => entry.res.write(chunk));
  });

  ffmpegProcess.stderr.on('data', data => {
    const msg = data.toString();
    if (msg.toLowerCase().includes('error') || msg.includes('failed')) {
      console.error(`[${ts()}] [FFMPEG] ${msg.trim()}`);
    }
  });

  ffmpegProcess.on('close', code => {
    console.warn(`[${ts()}] ⚠️ FFmpeg exited (code=${code})`);
    disconnectAllClients('FFmpeg stopped');
    scheduleRestart(`exit code ${code}`);
  });
}

// --- Audio stream endpoint ---
app.get('/live.mp3', (req, res) => {
  const clientIP = req.headers['x-forwarded-for'] || req.socket.remoteAddress;

  res.writeHead(200, {
    'Content-Type': 'audio/mpeg',
    'Cache-Control': 'no-store, no-cache, must-revalidate, proxy-revalidate',
    'Pragma': 'no-cache',
    'Expires': '0',
    'Connection': 'close'
  });

  clients.push({ res, clientIP });
  console.log(`[${ts()}] 🔊 Client connected (${clients.length}) - IP: ${clientIP}`);

  req.on('close', () => {
    clients = clients.filter(c => c.res !== res);
    console.log(`[${ts()}] ❌ Client disconnected (${clients.length}) - IP: ${clientIP}`);
  });
});

// --- Start Express server ---
app.listen(PORT, () => {
  console.log(`[${ts()}] 🎧 Live stream running at http://127.0.0.1:${PORT}/live.mp3`);
});

// --- Start initial FFmpeg capture ---
startFFmpeg();

// --- Routine restart every 6h to prevent memory leaks ---
setInterval(() => {
  console.log(`[${ts()}] ♻️ Routine FFmpeg restart...`);
  startFFmpeg();
}, 6 * 60 * 60 * 1000);

// --- Graceful shutdown ---
process.on('SIGINT', () => {
  console.log(`\n[${ts()}] 🧹 Stopping service...`);
  disconnectAllClients('Manual stop');
  if (ffmpegProcess) ffmpegProcess.kill('SIGKILL');
  process.exit(0);
});
