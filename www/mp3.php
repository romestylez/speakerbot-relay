<?php
// /speakerbot/mp3.php?file=<name>.mp3&token=...

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

// ──────────────────────────────────────────────────────────────
// Konfigurationswerte aus .env laden
// ──────────────────────────────────────────────────────────────
$validToken = $_ENV['VALID_TOKEN'] ?? '';
$ttsFolder  = rtrim($_ENV['TTS_FOLDER'] ?? 'D:/OBS-LIVE/Tools/TTS-Relay-Server/mp3', '/\\');

// ──────────────────────────────────────────────────────────────
// Zugriff prüfen
// ──────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? '';
if ($token !== $validToken) {
    http_response_code(403);
    exit;
}

$file = $_GET['file'] ?? '';
if ($file === '') {
    http_response_code(400);
    exit('Missing file');
}

$base = basename($file);
$path = "$ttsFolder/$base";
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

// 🔍 Dateiendung prüfen → richtigen MIME-Type setzen
$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
switch ($ext) {
    case 'wav':
        header('Content-Type: audio/wav');
        break;
    case 'mp3':
    default:
        header('Content-Type: audio/mpeg');
        break;
}

header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Datei streamen
readfile($path);

// 📦 Nach erfolgreichem Versand verschieben oder löschen
if (file_exists($path)) {
    register_shutdown_function(function() use ($path, $base) {
        sleep(2); // kleine Verzögerung, damit Browser-Stream sauber beendet ist

        // 🔹 Wenn Replay-Datei → löschen
        if (str_starts_with($base, 'REPLAY_')) {
            if (@unlink($path)) {
                error_log("🗑️ Replay-Datei gelöscht: $base");
            } else {
                error_log("⚠️ Konnte Replay-Datei nicht löschen: $base");
            }
            return;
        }

        // 🔹 Normale Datei → nach .bak verschieben
        $bakDir = dirname($path) . DIRECTORY_SEPARATOR . '.bak';
        if (!is_dir($bakDir)) mkdir($bakDir, 0777, true);
        $dest = $bakDir . DIRECTORY_SEPARATOR . $base;

        if (@rename($path, $dest)) {
            error_log("📦 Datei verschoben nach .bak: $base");
        } else {
            error_log("⚠️ Konnte Datei nicht verschieben: $base");
        }
    });
}
