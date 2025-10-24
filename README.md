# 🎧 Live Audio Stream (Node.js + FFmpeg + PHP Web Player)

This project provides a **simple live audio streaming setup** using Node.js and FFmpeg.  
It is designed for use with tools like **Speaker.bot**, capturing audio from a virtual device (VB-Audio Hi-Fi Cable) and making it available as a continuous HTTP MP3 stream.

---

## 🚀 Features

- Real-time streaming of virtual audio via HTTP  
- Works perfectly with **Speaker.bot** for live TTS announcements  
- Low latency using FFmpeg’s `low_delay` and `nobuffer` flags  
- Automatically restarts FFmpeg if it crashes or stops  
- Supports multiple simultaneous listeners  
- Includes a secure PHP web player with token protection  
- Easy to install and run on Windows  

---

## 🧩 Requirements

- **Node.js** (v18 or later) → [https://nodejs.org](https://nodejs.org)  
- **FFmpeg** → [https://www.gyan.dev/ffmpeg/builds/](https://www.gyan.dev/ffmpeg/builds/)  
- **VB-Audio Hi-Fi Cable + ASIO Bridge** → [https://vb-audio.com/Cable/](https://vb-audio.com/Cable/)

> 💡 **Note:** Only install the *Hi-Fi Cable + ASIO Bridge* package.  
> It provides the virtual devices **Hi-Fi Cable Input** and **Hi-Fi Cable Output**, which are required for Speaker.bot and FFmpeg.  
> You do **not** need the basic “VB-Cable” package.

---

## ⚙️ Setup

### 1️⃣ Install dependencies

1. Install Node.js and make sure it’s available in your PATH (`node -v`).
2. Download and extract FFmpeg to:
   ```
   C:\Tools\ffmpeg\bin\ffmpeg.exe
   ```
3. Install **VB-Audio Hi-Fi Cable + ASIO Bridge** and set **Speaker.bot output device** to:  
   `VB-Audio Hi-Fi Cable Output`

---

### 2️⃣ Create the Node.js stream script

Save this as `C:\Tools\ffmpeg\live_stream.js`.

It captures the VB-Audio output using FFmpeg and serves it at:  
**http://127.0.0.1:8773/live.mp3**

---

### 3️⃣ Create a start script

Save this as `C:\Tools\start_live_stream.bat`:

```bat
@echo off
cd /d "C:\Tools"
echo [INFO] Starting Live Audio Stream...
node "C:\Tools\ffmpeg\live_stream.js"
```

You can register this batch file as a **Windows Service** (e.g. using `nssm`), so the stream starts automatically on boot.

---

## 🧱 Optional: Web Player (`index.php`)

If you want an easy way to listen to the live stream in a browser or on mobile,  
add this PHP file to your web server (for example under `https://yourdomain.com/speakerbot/`).

This page includes:

- **Token protection** (`?token=start123`)  
- An HTML5 `<audio>` player for `/live.mp3`  
- An autoplay workaround for mobile browsers  

You can access it like:  
👉 `https://yourdomain.com/speakerbot/?token=start123`

This URL can be used in Streambuddy etc. so the TTS will be played on your phone or on your bluetooth speaker.

---

## 🔊 How it works

1. **Speaker.bot** plays TTS messages → audio goes to *Hi-Fi Cable Output*
2. **FFmpeg** captures that virtual output → encodes it to MP3
3. **Node.js** serves the MP3 stream via HTTP at `/live.mp3`
4. **index.php** provides a secure player for browsers or mobile devices

---

## 🌐 Example Usage

- Local stream: [http://127.0.0.1:8773/live.mp3](http://127.0.0.1:8773/live.mp3)
- Via proxy (e.g., Apache/Nginx): `https://yourdomain.com/live.mp3`
- Web player (with token): `https://yourdomain.com/speakerbot/?token=start123`

---

## 🛠 Notes

- The Node.js script automatically restarts FFmpeg if it stops.  
- FFmpeg runs with minimal buffering to ensure low latency.  
- You can restart the service at any time without rebooting clients.  
- Designed for stable long-term streaming with multiple listeners.

---

## 📄 License

MIT License  
© 2025 romestylez
