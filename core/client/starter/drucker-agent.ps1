param([Parameter(Mandatory = $true)][string]$Liste, [ValidateSet('einrichten', 'alt', 'entfernen')][string]$Modus = 'einrichten', [string]$Entfernen = '')
# Kienzle-Agent: virtuelle Drucker der Module einrichten (läuft als SYSTEM, ohne Rückfragen).
# Beim Einrichten wird nie ein Drucker gelöscht: Hat er eine andere Adresse, wird nur sein Anschluss umgestellt; klappt das nicht,
# bleibt er unverändert (er druckt dann weiter wie bisher). Fehlende Drucker werden angelegt.
# „alt“: Drucker, die auf diesen Server zeigen, deren Warteschlange es dort aber nicht mehr gibt (z. B. „Terminzettel“ vor der
# Umbenennung in vTermine). „entfernen“: nur solche alten Drucker löschen, und nur die, die am PC bestätigt wurden (Symbol in der Taskleiste).
# Letzte Zeile der Ausgabe = Zusammenfassung für die Seite „Arbeitsplätze“ (bei „alt“: JSON-Liste der Namen).
$ErrorActionPreference = 'Continue'
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }  # Umlaute in Namen und Windows-Meldungen
$list = @(Get-Content -Raw $Liste | ConvertFrom-Json | Where-Object { $_.name -match '^[A-Za-z0-9_-]{1,40}$' -and $_.url -match '^http://[^\s/]+:631/printers/' })

# Adressen des Servers: Name aus der Suite und seine IP-Adressen (IPv4 zuerst; IPv6 ohne Zonenangabe wie „%18“).
$hosts = @(); $ips = @()
if ($list.Count) {
    $hosts = @(([Uri]$list[0].url).Host.ToLower())
    try { $ips = @([System.Net.Dns]::GetHostAddresses(([Uri]$list[0].url).Host) | Sort-Object { $_.AddressFamily -ne 'InterNetwork' }) } catch { }
    $hosts += @($ips | ForEach-Object { ($_.IPAddressToString -replace '%.*$', '').ToLower() })
}
# Passt: Der Anschluss zeigt auf dieselbe Warteschlange dieses Servers, mit Namen oder IP-Adresse (z. B. früher von Hand mit
# http://<IP>:631/printers/vKasse eingerichtet). Solche Drucker funktionieren und bleiben unverändert.
function Passt($port, $d) {
    if ($port -eq $d.url) { return $true }
    if ([string]$port -notmatch '^(?i)https?://(?:\[([^\]]+)\]|([^/:\[]+))(:631)?/printers/([^/?#]+)/?$') { return $false }
    $h = $(if ($Matches[1]) { $Matches[1] } else { $Matches[2] })
    ($hosts -contains $h.ToLower()) -and ([Uri]::UnescapeDataString($Matches[4]).ToLower() -eq ([Uri]$d.url).Segments[-1].Trim('/').ToLower())
}

function Alte {
    if (!$list.Count) { return @() }
    $queues = @($list | ForEach-Object { ([Uri]$_.url).Segments[-1].Trim('/').ToLower() })
    $namen = @($list | ForEach-Object { $_.name.ToLower() })
    @(Get-Printer -ErrorAction SilentlyContinue | Where-Object {
        if ($_.PortName -notmatch '^(?i)(https?|ipp)://(?:\[([^\]]+)\]|([^/:\[]+))(:\d+)?/printers/([^/?#]+)') { return $false }
        $h = $(if ($Matches[2]) { $Matches[2] } else { $Matches[3] }).ToLower(); $q = [Uri]::UnescapeDataString($Matches[5]).ToLower()
        ($hosts -contains $h) -and ($queues -notcontains $q) -and ($namen -notcontains $_.Name.ToLower())
    } | ForEach-Object { $_.Name })
}

if ($Modus -eq 'alt') {
    # Nicht-ASCII als \uXXXX, damit Namen mit Umlauten unabhängig von der Codepage genau ankommen.
    $j = ConvertTo-Json -Compress -InputObject @(Alte)
    Write-Output ([regex]::Replace($j, '[^\x00-\x7F]', { param($m) '\u{0:x4}' -f [int][char]$m.Value }))
    exit 0
}
if ($Modus -eq 'entfernen') {
    $wunsch = @(Get-Content -Raw $Entfernen | ConvertFrom-Json)
    $weg = @(); $nicht = @(); $grund = ''
    foreach ($name in @(Alte | Where-Object { $wunsch -contains $_ })) {
        $port = (Get-Printer -Name $name -ErrorAction SilentlyContinue).PortName
        try { Remove-Printer -Name $name -ErrorAction Stop; $weg += $name } catch { $nicht += $name; if (!$grund) { $grund = $_.Exception.Message } }
        if ($port -and !(Get-Printer -ErrorAction SilentlyContinue | Where-Object { $_.PortName -eq $port })) { Remove-PrinterPort -Name $port -ErrorAction SilentlyContinue }
    }
    $text = 'Alte Drucker entfernt: ' + $(if ($weg.Count) { $weg -join ', ' } else { 'keine' })
    if ($nicht.Count) { $text += '; nicht entfernt: ' + ($nicht -join ', ') + '. Windows meldet: ' + (($grund -replace '\s+', ' ').Trim()) }
    if ($text.Length -gt 280) { $text = $text.Substring(0, 280) }
    Write-Output $text
    if ($nicht.Count) { exit 1 }
    exit 0
}
$fehlt = @(); $alt = @(); $ok = 0; $grund = ''
# Erreichbarkeit vorab prüfen (als SYSTEM gelten dieselbe Namensauflösung und Firewall wie für alle Benutzer).
$erreichbar = ''
if ($list.Count) {
    $u = [Uri]$list[0].url
    if (!$ips.Count) { $erreichbar = 'Servername ' + $u.Host + ' an diesem PC unbekannt (Router oder hosts-Datei)' }
    else {
        # Alle Adressen versuchen, IPv4 zuerst (eine IPv6-Adresse wie fe80::… allein sagt nichts über die Firewall).
        $verbunden = $false
        foreach ($ip in $ips) {
            $c = New-Object System.Net.Sockets.TcpClient($ip.AddressFamily)
            try { $c.ConnectAsync($ip, $u.Port).Wait(4000) | Out-Null } catch { }
            if ($c.Connected) { $verbunden = $true }
            $c.Close()
            if ($verbunden) { break }
        }
        if (!$verbunden) { $erreichbar = 'Port ' + $u.Port + ' auf ' + $ips[0].IPAddressToString + ' nicht erreichbar (Firewall der Suite?)' }
    }
}
# Wie install-drucker.ps1 (manuell bewährt): nur wenn etwas fehlt oder umzustellen ist, Internetdrucken einschalten,
# Druckwarteschlange neu starten (erst dann kennt Windows http://-Anschlüsse), Treiber laden, dann je Drucker bis zu 4 Versuche.
$todo = @($list | Where-Object { $p = Get-Printer -Name $_.name -ErrorAction SilentlyContinue; !$p -or !(Passt $p.PortName $_) })
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
        for ($i = 1; $i -le 4 -and !(Passt (Get-Printer -Name $d.name -ErrorAction SilentlyContinue).PortName $d); $i++) {
            if (!(Get-PrinterPort -Name $d.url -ErrorAction SilentlyContinue)) {
                try { Add-PrinterPort -Name $d.url -ErrorAction Stop } catch { if (!$grund) { $grund = 'Anschluss: ' + $_.Exception.Message } }
            }
            Start-Sleep 3
            try { Set-Printer -Name ([string]$d.name) -PortName ([string]$d.url) -ErrorAction Stop } catch { if (!$grund) { $grund = 'Umstellen: ' + $_.Exception.Message } }
        }
        if (Passt (Get-Printer -Name $d.name -ErrorAction SilentlyContinue).PortName $d) { $ok++ } else { $alt += $d.name }
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
