param([Parameter(Mandatory = $true)][string]$Liste, [ValidateSet('einrichten', 'alt', 'entfernen')][string]$Modus = 'einrichten', [string]$Entfernen = '', [string]$ServerIp = '')
# Praxisherz-Agent: virtuelle Drucker der Module einrichten (läuft als SYSTEM, ohne Rückfragen).
# Beim Einrichten wird nie ein Drucker gelöscht: Hat er eine andere Adresse, wird nur sein Anschluss umgestellt; klappt das nicht,
# bleibt er unverändert (er druckt dann weiter wie bisher). Fehlende Drucker werden angelegt.
# „alt“: Drucker, die auf diesen Server zeigen, deren Warteschlange es dort aber nicht mehr gibt (z. B. „Terminzettel“ vor der
# Umbenennung in vTermine). „entfernen“: nur solche alten Drucker löschen, und nur die, die am PC bestätigt wurden (Symbol in der Taskleiste).
# Letzte Zeile der Ausgabe = Zusammenfassung für die Seite „Arbeitsplätze“ (bei „alt“: JSON-Liste der Namen).
$ErrorActionPreference = 'Continue'
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }  # Umlaute in Namen und Windows-Meldungen
# Erst in eine Variable: Windows PowerShell gibt ein JSON-Array sonst als EIN Objekt weiter (dann stünden alle Namen in einem).
$roh = Get-Content -Raw $Liste | ConvertFrom-Json
$list = @($roh | Where-Object { $_.name -match '^[A-Za-z0-9_-]{1,40}$' -and $_.url -match '^http://[^\s/]+:631/printers/' })

# Adressen des Servers: Name aus Praxisherz und seine IP-Adressen (IPv4 zuerst; IPv6 ohne Zonenangabe wie „%18“).
# Kennt der PC den Namen nicht, nimmt das Skript die IP-Adresse, unter der der Agent den Server erreicht (-ServerIp), und richtet
# die Drucker mit dieser Adresse ein (wie früher von Hand mit http://<IP>:631/printers/<Name>).
$hosts = @(); $ips = @(); $perIp = $false
if ($list.Count) {
    $servername = ([Uri]$list[0].url).Host
    $hosts = @($servername.ToLower())
    try { $ips = @([System.Net.Dns]::GetHostAddresses($servername) | Sort-Object { $_.AddressFamily -ne 'InterNetwork' }) } catch { }
    if (!$ips.Count -and $ServerIp -match '^\d{1,3}(\.\d{1,3}){3}$') {
        try { $ips = @([System.Net.IPAddress]::Parse($ServerIp)) } catch { }
        if ($ips.Count) {
            $perIp = $true
            $list = @($list | ForEach-Object { [pscustomobject]@{ name = $_.name; url = ($_.url -replace ('^http://' + [regex]::Escape($servername) + ':'), ('http://' + $ServerIp + ':')) } })
        }
    }
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
    $wunschRoh = Get-Content -Raw $Entfernen | ConvertFrom-Json
    $wunsch = @($wunschRoh)
    $ziel = @(Alte | Where-Object { $wunsch -contains $_ })
    $weg = @(); $nicht = @(); $grund = ''
    if ($ziel.Count) {
        # Wie install-drucker.ps1 (manuell bewährt, siehe auch „Gelöst“ in AGENTS.md): Spooler vorher neu starten, sonst
        # hängt das Entfernen oft bei einem feststeckenden Druckauftrag oder ungültigen Handle ("Das Handle ist ungültig").
        Restart-Service Spooler -Force -ErrorAction SilentlyContinue
        try { (Get-Service Spooler).WaitForStatus('Running', '00:00:30') } catch { }
        Start-Sleep 3
    }
    foreach ($name in $ziel) {
        $port = (Get-Printer -Name $name -ErrorAction SilentlyContinue).PortName
        # Je Drucker in einem eigenen Hintergrundauftrag mit eigener Frist: Hängt ausgerechnet dieser eine (z. B. noch ein
        # Druckauftrag in seiner Warteschlange), sollen die übrigen trotzdem entfernt werden, statt dass alles stehen bleibt.
        $job = Start-Job -ScriptBlock { param($n) Remove-Printer -Name $n -ErrorAction Stop } -ArgumentList $name
        if (Wait-Job $job -Timeout 20) {
            Receive-Job $job -ErrorAction SilentlyContinue | Out-Null
            if (!(Get-Printer -Name $name -ErrorAction SilentlyContinue)) { $weg += $name } else { $nicht += $name; if (!$grund) { $grund = 'ließ sich nicht entfernen' } }
        } else {
            Stop-Job $job -ErrorAction SilentlyContinue
            $nicht += $name
            if (!$grund) { $grund = 'Zeitüberschreitung (Druckspooler reagiert nicht)' }
        }
        Remove-Job $job -Force -ErrorAction SilentlyContinue
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
        if (!$verbunden) { $erreichbar = 'Port ' + $u.Port + ' auf ' + $ips[0].IPAddressToString + ' nicht erreichbar (Firewall von Praxisherz?)' }
    }
}
# Wie install-drucker.ps1 (manuell bewährt): nur wenn etwas fehlt oder umzustellen ist, Internetdrucken einschalten,
# Druckwarteschlange neu starten (erst dann kennt Windows http://-Anschlüsse), Treiber laden, dann je Drucker mehrere Versuche.
# Anders als am Bildschirm hat der Agent nur 3 Minuten: Jeder Schritt, der bei einem festhängenden Druckspooler ewig warten
# kann, läuft daher in einem eigenen Hintergrundauftrag mit Frist (MitFrist), und die Gesamtzeit ist begrenzt.
$uhr = [Diagnostics.Stopwatch]::StartNew()
function MitFrist([scriptblock]$Block, [object[]]$Argumente = @(), [int]$Sekunden = 25) {
    $script:fristFehler = ''
    $job = if ($Argumente.Count) { Start-Job -ScriptBlock $Block -ArgumentList $Argumente } else { Start-Job -ScriptBlock $Block }
    if (Wait-Job $job -Timeout $Sekunden) {
        try { $r = Receive-Job $job -ErrorAction Stop } catch { $script:fristFehler = $_.Exception.Message; $r = $null }
        Remove-Job $job -Force -ErrorAction SilentlyContinue
        return $r
    }
    Stop-Job $job -ErrorAction SilentlyContinue; Remove-Job $job -Force -ErrorAction SilentlyContinue
    $script:fristFehler = 'Zeitüberschreitung (Druckspooler reagiert nicht)'
    return $null
}
# Druckwarteschlange neu starten; hängt sie, den Dienstprozess beenden und neu starten (sonst wartet Restart-Service ewig).
function SpoolerNeu {
    MitFrist { Restart-Service Spooler -Force -ErrorAction Stop } -Sekunden 40 | Out-Null
    if ($script:fristFehler) {
        Stop-Process -Name spoolsv -Force -ErrorAction SilentlyContinue
        Start-Sleep 2
        Start-Service Spooler -ErrorAction SilentlyContinue
    }
    try { (Get-Service Spooler).WaitForStatus('Running', '00:00:30') } catch { }
    Start-Sleep 3
}
$todo = @($list | Where-Object { $p = Get-Printer -Name $_.name -ErrorAction SilentlyContinue; !$p -or !(Passt $p.PortName $_) })
$ok = $list.Count - $todo.Count
if ($todo.Count) {
    # Internetdrucken nur einmal einschalten (DISM dauert als Systemdienst oft Minuten); danach merkt sich der Agent das.
    $merker = Join-Path (Split-Path -Parent $Liste) 'internetdrucken.ok'
    if (!(Test-Path $merker)) {
        $f = MitFrist { Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart -ErrorAction Stop } -Sekunden 100
        if ($f -and $f.RestartNeeded) { Write-Output 'Drucker: Windows muss einmal neu gestartet werden (Internetdrucken eingeschaltet)'; exit 1 }
        if ($f) { Set-Content -Path $merker -Value (Get-Date -Format s) -ErrorAction SilentlyContinue }
        elseif ($script:fristFehler -and !$grund) { $grund = 'Internetdrucken: ' + $script:fristFehler }
    }
    SpoolerNeu
    MitFrist { Add-PrinterDriver -Name 'Microsoft IPP Class Driver' -ErrorAction SilentlyContinue } -Sekunden 40 | Out-Null
}
foreach ($d in $todo) {
    if ($uhr.Elapsed.TotalSeconds -gt 150) { if (!$grund) { $grund = 'Zeit reicht nicht für alle Drucker, nächster Versuch folgt' }; $fehlt += $d.name; continue }
    if (!(Get-PrinterPort -Name $d.url -ErrorAction SilentlyContinue)) {
        MitFrist { param($u) Add-PrinterPort -Name $u -ErrorAction Stop } @($d.url) | Out-Null
        if ($script:fristFehler -and !$grund) { $grund = 'Anschluss: ' + $script:fristFehler }
        Start-Sleep 3
    }
    $p = Get-Printer -Name $d.name -ErrorAction SilentlyContinue
    if ($p) {
        # Vorhanden mit anderer Adresse (z. B. früher von Hand mit der IP eingerichtet): nur umstellen, nie löschen.
        for ($i = 1; $i -le 2 -and !(Passt (Get-Printer -Name $d.name -ErrorAction SilentlyContinue).PortName $d); $i++) {
            MitFrist { param($n, $u) Set-Printer -Name ([string]$n) -PortName ([string]$u) -ErrorAction Stop } @($d.name, $d.url) | Out-Null
            if ($script:fristFehler -and !$grund) { $grund = 'Umstellen: ' + $script:fristFehler }
            if ($script:fristFehler -like 'Zeitüberschreitung*') { SpoolerNeu }
        }
        if (Passt (Get-Printer -Name $d.name -ErrorAction SilentlyContinue).PortName $d) { $ok++ } else { $alt += $d.name }
        continue
    }
    for ($i = 1; $i -le 2 -and !(Get-Printer -Name $d.name -ErrorAction SilentlyContinue); $i++) {
        if (!(Get-PrinterPort -Name $d.url -ErrorAction SilentlyContinue)) {
            MitFrist { param($u) Add-PrinterPort -Name $u -ErrorAction Stop } @($d.url) | Out-Null
            if ($script:fristFehler -and !$grund) { $grund = 'Anschluss: ' + $script:fristFehler }
            Start-Sleep 3
        }
        MitFrist { param($n, $u) Add-Printer -Name $n -DriverName 'Microsoft IPP Class Driver' -PortName $u -ErrorAction Stop } @($d.name, $d.url) -Sekunden 40 | Out-Null
        if ($script:fristFehler -and !$grund) { $grund = 'Drucker: ' + $script:fristFehler }
        if ($script:fristFehler -like 'Zeitüberschreitung*') { SpoolerNeu }
    }
    if (Get-Printer -Name $d.name -ErrorAction SilentlyContinue) { $ok++ } else { $fehlt += $d.name }
}
$text = 'Drucker: ' + $ok + ' von ' + $list.Count + ' eingerichtet'
if ($perIp) { $text += ' (Servername am PC unbekannt, Drucker über die IP-Adresse ' + $ServerIp + ')' }
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
