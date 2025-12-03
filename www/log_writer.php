<?php
date_default_timezone_set('Europe/Berlin');

// log_writer.php — Serverlog für TTS-Aktionen
header('Content-Type: application/json; charset=utf-8');

$logDir = realpath(__DIR__ . '/../log');
if (!$logDir) $logDir = __DIR__ . '/log';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

$logFile = $logDir . DIRECTORY_SEPARATOR . 'tts_log.json';

// Eintrag empfangen
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['type'], $input['filename'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$entry = [
    'time' => date('d.m.Y H:i:s'),
    'type' => $input['type'],
    'filename' => $input['filename']
];

// Bestehendes Log lesen
$log = [];
if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    $log = json_decode($content, true);
    if (!is_array($log)) $log = [];
}

// Neuen Eintrag vorne hinzufügen
array_unshift($log, $entry);

// Max. 200 Einträge behalten
$log = array_slice($log, 0, 100);

// Datei speichern
file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode(['ok' => true]);
