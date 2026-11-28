$ErrorActionPreference = 'Continue'
# Praxisherz-Agent von diesem PC entfernen (aufgerufen über Einstellungen → Apps → Praxisherz-Agent → Deinstallieren,
# oder von Hand). Entfernt Aufgabe, Symbol, Autostart, Aufrufe aus T2med, Zertifikat und die Ordner; die virtuellen Drucker
# nur nach Rückfrage. Den Eintrag des PCs auf dem Server löscht ein Admin unter Einrichten → Arbeitsplätze.
function Say($state, $what) { Write-Host ('{0,-13} {1}' -f $state, $what) }

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (!$admin) {
    Write-Host 'Für die Deinstallation fragt Windows jetzt nach Administratorrechten.'
    # Aus einer Kopie im Temp-Ordner starten: das Skript löscht seinen eigenen Ordner.
    $kopie = Join-Path $env:TEMP 'praxisherz-deinstallieren.ps1'
    Copy-Item $PSCommandPath $kopie -Force
    Start-Process powershell.exe -Verb RunAs -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $kopie + '"')
    exit 0
}

$prog = Join-Path $env:ProgramFiles 'Praxisherz'
$daten = Join-Path $env:ProgramData 'Praxisherz'
Write-Host 'Praxisherz-Agent wird von diesem PC entfernt.'
Write-Host ''

# Virtuelle Drucker dieses Servers: Anschluss zeigt auf <Servername oder IP>:631/printers/… (wie beim Einrichten).
$drucker = @()
$cfgDatei = Join-Path $daten 'client.json'
if (Test-Path $cfgDatei) {
    try {
        $cfg = Get-Content $cfgDatei -Raw | ConvertFrom-Json   # erst in eine Variable (PowerShell 5.1)
        $server = ([Uri]$cfg.server_url).Host
        $ziele = @($server)
        try { $ziele += @([System.Net.Dns]::GetHostAddresses($server) | ForEach-Object { $_.ToString() }) } catch { }
        $alle = @(Get-Printer -ErrorAction SilentlyContinue)
        foreach ($p in $alle) {
            foreach ($z in $ziele) {
                if ($p.PortName -match ('^(ipp|https?)://' + [regex]::Escape($z) + ':631/printers/')) { $drucker += $p; break }
            }
        }
    } catch { Say 'Hinweis' 'Einstellungen nicht lesbar, virtuelle Drucker werden nicht gesucht.' }
}
$druckerWeg = $false
if ($drucker.Count) {
    Write-Host 'Diese virtuellen Drucker von Praxisherz sind eingerichtet:'
    $drucker | ForEach-Object { Write-Host ('  - ' + $_.Name) }
    $antwort = Read-Host 'Sollen sie ebenfalls entfernt werden? (j/n)'
    $druckerWeg = $antwort -match '^\s*(j|ja|y|yes)\s*$'
    Write-Host ''
}

# Agent und Symbol anhalten, Aufgabe entfernen.
$t = 'Praxisherz-Agent'
if (Get-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $t -Confirm:$false -ErrorAction SilentlyContinue
    Say 'entfernt' "Aufgabe $t"
}
Get-Process praxisherz-starter -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
if (Get-ItemProperty 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'Praxisherz' -ErrorAction SilentlyContinue) {
    Remove-ItemProperty 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'Praxisherz' -ErrorAction SilentlyContinue
    Say 'entfernt' 'Symbol in der Taskleiste (Autostart)'
}

# Aufrufe aus T2med (praxisherz-…://), nur wenn sie auf unser Programm zeigen.
Get-ChildItem 'HKLM:\Software\Classes' -ErrorAction SilentlyContinue | Where-Object { $_.PSChildName -match '^praxisherz-[a-z0-9-]+$' } | ForEach-Object {
    $befehl = try { (Get-ItemProperty ($_.PSPath + '\shell\open\command') -ErrorAction Stop).'(default)' } catch { '' }
    if ($befehl -match 'praxisherz-starter\.exe') {
        Remove-Item -Recurse -Force $_.PSPath -ErrorAction SilentlyContinue
        Say 'entfernt' ('Aufruf ' + $_.PSChildName)
    }
}

# Zertifizierungsstelle aus „Vertrauenswürdige Stammzertifizierungsstellen“ (nur genau diese).
$caDatei = Join-Path $daten 'ca.crt'
if (Test-Path $caDatei) {
    try {
        $ca = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($caDatei)
        Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $ca.Thumbprint } | Remove-Item -ErrorAction Stop
        Say 'entfernt' 'Zertifikat von Praxisherz'
    } catch { Say 'Hinweis' 'Zertifikat ließ sich nicht entfernen.' }
}

if ($druckerWeg) {
    foreach ($p in $drucker) {
        try {
            Remove-Printer -Name $p.Name -ErrorAction Stop
            Remove-PrinterPort -Name $p.PortName -ErrorAction SilentlyContinue
            Say 'entfernt' ('Drucker ' + $p.Name)
        } catch { Say 'Hinweis' ('Drucker ' + $p.Name + ' ließ sich nicht entfernen (Druckaufträge offen?).') }
    }
} elseif ($drucker.Count) { Say 'behalten' 'virtuelle Drucker' }

# Ordner und Eintrag in der App-Liste.
foreach ($o in @($prog, $daten, 'C:\Praxisherz')) {
    if (Test-Path $o) {
        Remove-Item -Recurse -Force $o -ErrorAction SilentlyContinue
        if (Test-Path $o) { Say 'Hinweis' "$o ließ sich nicht ganz entfernen (später von Hand löschen)" } else { Say 'entfernt' $o }
    }
}
Remove-Item -Recurse -Force 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\Praxisherz-Agent' -ErrorAction SilentlyContinue
Write-Host ''
Write-Host 'Praxisherz-Agent ist entfernt. Den Eintrag dieses PCs auf dem Server löscht ein Admin unter Einrichten → Arbeitsplätze.'
Read-Host 'Zum Schließen Enter drücken'
