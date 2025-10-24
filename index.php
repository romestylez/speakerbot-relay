<?php
$token = $_GET['token'] ?? '';
$validToken = 'start123';

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
<title>TTS Audio Stream</title>
<style>
  body {
    background: #000;
    color: #fff;
    font-family: sans-serif;
    text-align: center;
    margin-top: 60px;
  }
  audio {
    width: 90%;
    max-width: 400px;
    outline: none;
  }
</style>
</head>
<body>
  <h2>TTS Audio Stream läuft...</h2>
  <audio id="player" controls autoplay muted playsinline>
    <source src="/live.mp3" type="audio/mpeg">
    Dein Browser unterstützt kein Audio.
  </audio>

  <script>
    // 🔊 Autoplay workaround: Unmute automatically after a short time
    const player = document.getElementById('player');
    player.volume = 1.0;
    setTimeout(() => {
      player.muted = false;
      player.play().catch(() => {});
    }, 500);
  </script>
</body>
</html>
