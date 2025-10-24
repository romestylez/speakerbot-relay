// live_stream.js
import { spawn } from 'child_process';
import express from 'express';

const app = express();
const PORT = 8773;
const ffmpegPath = 'C:/Tools/ffmpeg/bin/ffmpeg.exe';
const audioDevice = 'audio=Hi-Fi Cable Output (VB-Audio Hi-Fi Cable)';

let clients = [];
let ffmpegProcess = null;

// --- Helper function: cleanly disconnect all clients ---
function disconnectAllClients(reason = 'unknown') {
  if (clients.length > 0)
    console.log(`❌ Disconnecting ${clients.length} client(s) (${reason})...`);
  clients.forEach(res => {
    try { res.end(); } catch {}
  });
  clients = [];
}

// --- Start or restart FFmpeg process ---
function startFFmpeg() {
  console.log('[INFO] Starting FFmpeg audio capture...');

  // Stop any previous instance and disconnect clients
  disconnectAllClients('FFmpeg restart');
  if (ffmpegProcess) {
    try { ffmpegProcess.kill('SIGKILL'); } catch {}
  }

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

  // Forward FFmpeg output to all connected clients
  ffmpegProcess.stdout.on('data', chunk => {
    clients.forEach(res => res.write(chunk));
  });

  // Log only relevant FFmpeg errors
  ffmpegProcess.stderr.on('data', data => {
    const msg = data.toString();
    if (msg.toLowerCase().includes('error') || msg.includes('failed')) {
      console.error('[FFMPEG]', msg.trim());
    }
  });

  // When FFmpeg exits → reset everything and restart
  ffmpegProcess.on('close', code => {
    console.log(`⚠️ FFmpeg exited (Code ${code})`);
    disconnectAllClients('FFmpeg stopped');
    console.log('🔁 Restarting FFmpeg in 5 seconds...');
    setTimeout(startFFmpeg, 5000);
  });
}

// --- Audio stream endpoint ---
app.get('/live.mp3', (req, res) => {
  res.writeHead(200, {
    'Content-Type': 'audio/mpeg',
    'Cache-Control': 'no-store, no-cache, must-revalidate, proxy-revalidate',
    'Pragma': 'no-cache',
    'Expires': '0',
    'Connection': 'close'
  });

  clients.push(res);
  console.log(`🔊 Client connected (${clients.length})`);

  req.on('close', () => {
    clients = clients.filter(c => c !== res);
    console.log(`❌ Client disconnected (${clients.length})`);
  });
});

// --- Start Express server ---
app.listen(PORT, () => {
  console.log(`🎧 Live stream running at http://127.0.0.1:${PORT}/live.mp3`);
});

// --- Start initial FFmpeg capture ---
startFFmpeg();

// --- Safety restart every 6 hours (prevents memory leaks) ---
setInterval(() => {
  console.log('♻️ Routine FFmpeg restart...');
  startFFmpeg();
}, 6 * 60 * 60 * 1000);

// --- Graceful shutdown on Ctrl+C ---
process.on('SIGINT', () => {
  console.log('\n🧹 Stopping service...');
  disconnectAllClients('Manual stop');
  if (ffmpegProcess) ffmpegProcess.kill('SIGKILL');
  process.exit(0);
});
