# 🎧 Speakerbot Live Audio Stream (Node.js + PHP Web Control)

Modern lightweight replacement for the old HLS/FFmpeg setup.  
This version handles **on-demand MP3/WAV TTS messages** directly via Node.js and plays them **deterministically** in browsers or mobile clients.

---

## 🚀 Features

- 🔒 **Token-protected** system (no public access)  
- 🧠 **FIFO Queue Logic** – plays messages oldest-first after pause  
- ⏸️ **Pause / Resume** control via Web UI or API  
- 🔁 **Replay** any previously played `.mp3` or `.wav` file from `.bak`  
- 🧩 **Status API** showing connected clients and playback state  
- 🗑️ **Auto-cleanup** – old files in `.bak` deleted after X days  
- ⚡ **Low-latency direct streaming** (no FFmpeg, no HLS)  
- 🔄 **Heartbeat / Session tracking** (auto-disconnect inactive clients)

---

## 🧩 Components Overview

| Component | Purpose |
|------------|----------|
| `live_stream.js` | Node.js backend – handles file delivery, client sessions, and cleanup |
| `index.php` | Frontend player – plays new MP3/WAVs automatically via Web Audio API |
| `status.php` | Web dashboard – shows active clients, allows pause/resume/replay |
| `state.json` | Stores global pause/play state |
| `mp3.php` | Streams a single MP3/WAV file and moves it to `.bak` after playback |
| `mp3/` | Folder for generated audio files from Speaker.bot |

---

## ⚙️ Setup

### 1️⃣ Install Node.js

Ensure Node.js 18+ is installed.

### 2️⃣ Start the backend server

Run in your TTS relay folder:

```bash
node live_stream.js
```

Default URL:

```
http://127.0.0.1:8773/speakerbot
```

---

### 3️⃣ Upload PHP Frontend

Copy these files to your webserver (e.g. Apache, Nginx, IIS):

```
/speakerbot/index.php
/speakerbot/status.php
/speakerbot/mp3.php
```

Access URLs:

| Purpose | Example |
|----------|----------|
| 🎧 Web Player | `https://yourdomain.com/speakerbot/?token=YOURTOKEN` |
| 🧠 Status Dashboard | `https://yourdomain.com/speakerbot/status.php?token=YOURTOKEN` |
| 📊 JSON API | `https://yourdomain.com/speakerbot/status.php?token=YOURTOKEN&mode=json` |
| 📝 Text-only Status | `https://yourdomain.com/speakerbot/status.php?token=YOURTOKEN&mode=text` |

---

### 4️⃣ Configure Speaker.bot

In Speaker.bot, enable **"Save TTS to audio file"**  
and set the output folder to match the Node.js relay:

```
D:\OBS-LIVE\Tools\TTS-Relay-Server\mp3
```

This path is defined in your Node backend:

```js
const ttsFolder = path.resolve("D:/OBS-LIVE/Tools/TTS-Relay-Server/mp3");
```

Each new TTS message will be stored here and automatically played by connected clients.

---

## 🕹️ Control via API

| Action | URL Example |
|--------|--------------|
| ⏸ Pause | `https://yourdomain.com/speakerbot/status.php?token=YOURTOKEN&action=pause` |
| ▶ Resume | `https://yourdomain.com/speakerbot/status.php?token=YOURTOKEN&action=resume` |
| 🔁 Replay | `https://yourdomain.com/speakerbot/status.php?token=YOURTOKEN&action=replay&file=<filename>` |

---

## 🔊 Playback Logic

- Speaker.bot or any source writes `.mp3` or `.wav` files into `/mp3/`.
- The Node server serves the **newest** file on request.
- The browser client (`index.php`) decodes and plays the file.
- After playback:
  - Normal files are moved into `/mp3/.bak/`.
  - Replay files (`REPLAY_...`) are deleted automatically.
- Paused playback queues files FIFO.
- Old `.bak` files are auto-cleaned (configurable in `status.php`).

---

## ⚙️ Auto-Cleanup Configuration

In `status.php`, these settings control cleanup behavior:

```php
$cleanupDays = 1;        // Delete files older than 1 day
$cleanupEnabled = true;  // Enable or disable cleanup
```

You can change this anytime without restarting the system.

---

## ✅ Advantages

- No FFmpeg or HLS dependency  
- Works with both `.mp3` and `.wav`  
- Instant playback in all modern browsers  
- Replay & cleanup built-in  
- Secure via access token  
- Minimal CPU usage  

---

## 🧭 Folder Structure Example

```
D:\OBS-LIVE\Tools\TTS-Relay-Server\
│
├── live_stream.js
├── mp3\
│   ├── 20251109T204213-voice.mp3
│   ├── 20251109T204500-voice.wav
│   └── .bak\
│       ├── 20251109T204213-voice.mp3
│       └── 20251109T204500-voice.wav
├── state.json
└── web\
    ├── index.php
    ├── status.php
    └── mp3.php
```

---

## 📄 License

MIT License  
© 2025 romestylez
