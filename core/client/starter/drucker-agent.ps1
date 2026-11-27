param([Parameter(Mandatory = $true)][string]$Liste)
# Kienzle-Agent: virtuelle Drucker der Module einrichten (läuft als SYSTEM, ohne Rückfragen).
# Ein vorhandener Drucker wird nie gelöscht: Hat er eine andere Adresse, wird nur sein Anschluss umgestellt; klappt das nicht,
# bleibt er unverändert (er druckt dann weiter wie bisher). Fehlende Drucker werden angelegt.
# Letzte Zeile der Ausgabe = Zusammenfassung für die Seite „Arbeitsplätze“.
$ErrorActionPreference = 'Continue'
$list = @(Get-Content -Raw $Liste | ConvertFrom-Json | Where-Object { $_.name -match '^[A-Za-z0-9_-]{1,40}$' -and $_.url -match '^http://[^\s/]+:631/printers/' })
$fehlt = @(); $alt = @(); $ok = 0; $grund = ''
# Erreichbarkeit vorab prüfen (als SYSTEM gelten dieselbe Namensauflösung und Firewall wie für alle Benutzer).
$erreichbar = ''
if ($list.Count) {
    $u = [Uri]$list[0].url
    try { $ip = ([System.Net.Dns]::GetHostAddresses($u.Host) | Select-Object -First 1).IPAddressToString } catch { $ip = '' }
    if (!$ip) { $erreichbar = 'Servername ' + $u.Host + ' an diesem PC unbekannt (Router oder hosts-Datei)' }
    else {
        $c = New-Object System.Net.Sockets.TcpClient
        try { $c.ConnectAsync($ip, $u.Port).Wait(4000) | Out-Null } catch { }
        if (!$c.Connected) { $erreichbar = 'Port ' + $u.Port + ' auf ' + $ip + ' nicht erreichbar (Firewall der Suite?)' }
        $c.Close()
    }
}
# Wie install-drucker.ps1 (manuell bewährt): nur wenn etwas fehlt oder umzustellen ist, Internetdrucken einschalten,
# Druckwarteschlange neu starten (erst dann kennt Windows http://-Anschlüsse), Treiber laden, dann je Drucker bis zu 4 Versuche.
$todo = @($list | Where-Object { $p = Get-Printer -Name $_.name -ErrorAction SilentlyContinue; !$p -or $p.PortName -ne $_.url })
$ok = $list.Count - $todo.Count
if ($todo.Count) {
    $f = Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart -ErrorAction SilentlyContinue
    if ($f -and $f.RestartNeeded) { Write-Output 'Drucker: Windows muss einmal neu gestartet werden (Internetdrucken eingeschaltet)'; exit 1 }
    Restart-Service Spooler -Force -ErrorAction SilentlyContinue
    try { (Get-Service Spooler).WaitForStatus('Running', '00:00:30') } catch { }
    Start-Sleep 3
    Add-PrinterDriver -Name 'Microsoft IPP Class Driver' -ErrorAction SilentlyContinue
}
foreach ($d in $todo) {
    $p = Get-Printer -Name $d.name -ErrorAction SilentlyContinue
    if ($p) {
        # Vorhanden mit anderer Adresse (z. B. früher von Hand mit der IP eingerichtet): nur umstellen, nie löschen.
        for ($i = 1; $i -le 4 -and (Get-Printer -Name $d.name -ErrorAction SilentlyContinue).PortName -ne $d.url; $i++) {
            if (!(Get-PrinterPort -Name $d.url -ErrorAction SilentlyContinue)) {
                try { Add-PrinterPort -Name $d.url -ErrorAction Stop } catch { if (!$grund) { $grund = 'Anschluss: ' + $_.Exception.Message } }
            }
            Start-Sleep 3
            try { Set-Printer -Name $d.name -PortName $d.url -ErrorAction Stop } catch { if (!$grund) { $grund = 'Umstellen: ' + $_.Exception.Message } }
        }
        if ((Get-Printer -Name $d.name -ErrorAction SilentlyContinue).PortName -eq $d.url) { $ok++ } else { $alt += $d.name }
        continue
    }
    for ($i = 1; $i -le 4 -and !(Get-Printer -Name $d.name -ErrorAction SilentlyContinue); $i++) {
        if (!(Get-PrinterPort -Name $d.url -ErrorAction SilentlyContinue)) {
            try { Add-PrinterPort -Name $d.url -ErrorAction Stop } catch { if (!$grund) { $grund = 'Anschluss: ' + $_.Exception.Message } }
        }
        Start-Sleep 3
        try { Add-Printer -Name $d.name -DriverName 'Microsoft IPP Class Driver' -PortName $d.url -ErrorAction Stop } catch { if (!$grund) { $grund = 'Drucker: ' + $_.Exception.Message } }
    }
    if (Get-Printer -Name $d.name -ErrorAction SilentlyContinue) { $ok++ } else { $fehlt += $d.name }
}
$text = 'Drucker: ' + $ok + ' von ' + $list.Count + ' eingerichtet'
if ($alt.Count) { $text += '; unverändert mit alter Adresse: ' + ($alt -join ', ') }
if ($fehlt.Count -or $alt.Count) {
    if ($fehlt.Count) { $text += '; fehlen: ' + ($fehlt -join ', ') }
    if ($erreichbar) { $text += '. ' + $erreichbar }
    if ($grund) { $text += '. Windows meldet: ' + (($grund -replace '\s+', ' ').Trim()) }
    if ($text.Length -gt 280) { $text = $text.Substring(0, 280) }
    Write-Output $text
    if ($fehlt.Count) { exit 1 }
    exit 0
}
Write-Output $text
