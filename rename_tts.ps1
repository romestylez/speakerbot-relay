param(
    [Parameter(Mandatory = $false)][AllowEmptyString()][string]$bits = "",
    [Parameter(Mandatory = $false)][AllowEmptyString()][string]$user = "unknown"
)

# .env aus Script-Verzeichnis laden
$envFile = Join-Path $PSScriptRoot ".env"

if (-not (Test-Path $envFile)) {
    throw "ERROR: .env file not found at $envFile"
}

# .env parsen
$envVars = Get-Content $envFile | Where-Object { $_ -match "=" } |
    ForEach-Object {
        $k, $v = $_ -split "=", 2
        [pscustomobject]@{ Key = $k.Trim(); Value = $v.Trim() }
    }

# TTS_FOLDER auslesen
$ttsFolder = ($envVars | Where-Object Key -eq "TTS_FOLDER").Value

if (-not $ttsFolder) {
    throw "ERROR: TTS_FOLDER is missing in .env"
}

# Pfade automatisch generieren
$base = $ttsFolder
$bak  = Join-Path $base ".bak"

$logDir = Join-Path (Split-Path $base -Parent) "log"
$log = Join-Path $logDir "copy.log"
$err = Join-Path $logDir "copy_error.log"


try {
    # Benutzername säubern
    $user = $user -replace '[^\w\.-]', '_'

    # Bits prüfen → wenn leer oder 0 → MOD
    if ($bits -and $bits -match '^\d+$' -and [int]$bits -gt 0) {
        $label = "$bits-Bits"
    } else {
        $label = "MOD"
    }

    # Alle WAV/MP3 im Hauptordner + .bak prüfen
    $files = @()
    $files += Get-ChildItem -Path $base -Filter *.wav -File
    $files += Get-ChildItem -Path $base -Filter *.mp3 -File 2>$null
    if (Test-Path $bak) {
        $files += Get-ChildItem -Path $bak -Filter *.wav -File
        $files += Get-ChildItem -Path $bak -Filter *.mp3 -File
}

    # Keine Dateien gefunden
    if (-not $files) {
        Add-Content $log "$(Get-Date -Format 'dd.MM.yyyy HH:mm:ss') - Keine Dateien gefunden"
        return
    }

    # Neueste Datei finden
    $f = $files | Sort-Object LastWriteTime -Descending | Select-Object -First 1

    # Datei zu frisch → Node könnte sie noch nicht kennen
    if ((Get-Date) - $f.LastWriteTime -lt [TimeSpan]::FromSeconds(2)) {
        Add-Content $log "$(Get-Date -Format 'dd.MM.yyyy HH:mm:ss') - Datei zu frisch, übersprungen: $($f.Name)"
        return
    }

    # NUR umbenennen → Verschieben macht Node
    $ts = Get-Date $f.LastWriteTime -Format 'dd.MM.yyyy-HH.mm.ss'
    $ext = $f.Extension

    if ($label -eq 'MOD') {
        $name = "$ts-MOD-$user$ext"
    } else {
        $name = "$ts-$label" + "_$user$ext"
    }

    # Wenn der Name bereits passt → nichts tun
    if ($f.Name -eq $name) {
        Add-Content $log "$(Get-Date -Format 'dd.MM.yyyy HH:mm:ss') - Bereits korrekt benannt: $($f.Name)"
        return
    }

    try {
        Rename-Item -LiteralPath $f.FullName -NewName $name -Force
        Add-Content $log "$(Get-Date -Format 'dd.MM.yyyy HH:mm:ss') - Umbenannt: $($f.Name) → $name"
    }
    catch {
        Add-Content $err "$(Get-Date -Format 'dd.MM.yyyy HH:mm:ss') - FEHLER beim Umbenennen $($f.Name): $($_.Exception.Message)"
    }

}
catch {
    Add-Content $err "$(Get-Date -Format 'dd.MM.yyyy HH:mm:ss') - GLOBALER FEHLER: $($_.Exception.Message)"
}
