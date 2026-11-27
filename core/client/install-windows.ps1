$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
# Praxisherz-Agent einrichten (einmal je PC; erneutes Ausführen repariert). Danach hält der Agent Starter, Aufrufe aus T2med,
# Zertifikat und virtuelle Drucker selbst aktuell. Jede Zeile sagt „neu“, „aktualisiert“ oder „unverändert“.
function Say($state, $what) { Write-Host ('{0,-13} {1}' -f $state, $what) }
function Same($a, $b) { (Test-Path $b) -and ((Get-FileHash $a -Algorithm SHA256).Hash -eq (Get-FileHash $b -Algorithm SHA256).Hash) }
function Place($source, $dest, $label) {
    if (Same $source $dest) { Say 'unverändert' $label; return }
    $had = Test-Path $dest
    Copy-Item $source $dest -Force
    Say ($(if ($had) { 'aktualisiert' } else { 'neu' })) $label
}
# Systemrechte nötig (Dienst, Zertifikat für alle Benutzer, Drucker): Windows fragt einmal nach.
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (!$admin) {
    Write-Host 'Für die Einrichtung fragt Windows jetzt nach Administratorrechten.'
    Start-Process powershell.exe -Verb RunAs -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $PSCommandPath + '"')
    exit 0
}
if (!(Test-Path client.json) -or !(Test-Path schemas.txt) -or !(Test-Path ca.crt)) { throw 'client.json, schemas.txt und ca.crt aus dem Paket fehlen. Bitte das Paket auf dem Server neu laden.' }
$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64' -or $env:PROCESSOR_ARCHITEW6432 -eq 'ARM64') { 'arm64' } else { 'amd64' }
$asset = "kienzle-starter-windows-$arch.exe"
if (!(Test-Path $asset) -or !(Test-Path SHA256SUMS)) { throw "$asset oder SHA256SUMS fehlt im Paket. Bitte das Paket auf dem Server neu laden." }
$line = Get-Content SHA256SUMS | Where-Object { ($_ -split '\s+')[1] -eq $asset }
if (!$line -or ((Get-FileHash $asset -Algorithm SHA256).Hash.ToLowerInvariant() -ne ($line -split '\s+')[0])) { throw 'Prüfsumme stimmt nicht.' }

# Laufenden Agent anhalten, damit das Programm ersetzt werden kann.
$task = 'Kienzle-Agent'
if (Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue }
Get-Process kienzle-starter -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

# Programm: C:\Program Files\Praxisherz (nur Administratoren dürfen ändern). Bis zur Umbenennung hieß der Ordner „Kienzle“.
$prog = Join-Path $env:ProgramFiles 'Praxisherz'
$progAlt = Join-Path $env:ProgramFiles 'Kienzle'
New-Item -ItemType Directory -Force $prog | Out-Null
$exe = Join-Path $prog 'kienzle-starter.exe'
Place $asset $exe 'Programm (Starter und Agent)'
# Aus dem Browser heruntergeladene Dateien tragen eine Kennzeichnung „aus dem Internet“ (Zone.Identifier), die beim
# Kopieren erhalten bleibt: Windows würde sonst bei jedem Start des Symbols (bei jeder Anmeldung) erneut fragen, ob das
# unsignierte Programm ausgeführt werden darf. Einmal entfernen reicht; harmlos, wenn die Kennzeichnung schon fehlt.
Unblock-File $exe -ErrorAction SilentlyContinue

# Daten: C:\ProgramData\Praxisherz (früher „Kienzle“). Benutzer dürfen nur lesen (der Agent läuft mit Systemrechten und führt dort ein Skript aus).
$daten = Join-Path $env:ProgramData 'Praxisherz'
$datenAlt = Join-Path $env:ProgramData 'Kienzle'
New-Item -ItemType Directory -Force $daten | Out-Null
& icacls.exe $daten /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Dateirechte konnten nicht gesetzt werden.' }
Place client.json (Join-Path $daten 'client.json') 'Einstellungen und Schlüssel'
Place ca.crt (Join-Path $daten 'ca.crt') 'Zertifizierungsstelle der Suite (Datei)'
if (Test-Path server.crt) { Copy-Item server.crt (Join-Path $daten 'server.crt') -Force }

# Zertifizierungsstelle für alle Benutzer vertrauenswürdig (Browser zeigen dann kein „Nicht sicher“).
$cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2((Join-Path $daten 'ca.crt'))
if (Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $cert.Thumbprint }) { Say 'unverändert' 'Zertifikat der Suite (vertrauenswürdig)' }
else { Import-Certificate -FilePath (Join-Path $daten 'ca.crt') -CertStoreLocation Cert:\LocalMachine\Root | Out-Null; Say 'neu' 'Zertifikat der Suite (vertrauenswürdig)' }

# Aufrufe aus T2med für alle Benutzer (der Agent hält sie später selbst aktuell).
$command = '"' + $exe + '" --url "%1"'
foreach ($scheme in (Get-Content schemas.txt | Where-Object { $_ -match '^(praxisherz|kienzle)-[a-z0-9-]+$' })) {
    $reg = "HKLM:\Software\Classes\$scheme"
    $current = try { (Get-ItemProperty "$reg\shell\open\command" -ErrorAction Stop).'(default)' } catch { $null }
    if ($current -ne $command) {
        New-Item -Force $reg | Out-Null
        Set-Item $reg -Value "URL:$scheme"
        New-ItemProperty $reg -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
        New-Item -Force "$reg\shell\open\command" | Out-Null
        Set-Item "$reg\shell\open\command" -Value $command
        Say ($(if ($current) { 'aktualisiert' } else { 'neu' })) "Aufruf $scheme"
    } else { Say 'unverändert' "Aufruf $scheme" }
    # Alter Starter (nur für einen Benutzer eingerichtet) hätte Vorrang: entfernen.
    if (Test-Path "HKCU:\Software\Classes\$scheme") { Remove-Item -Recurse -Force "HKCU:\Software\Classes\$scheme"; Say 'entfernt' "alter Aufruf $scheme (nur dieser Benutzer)" }
}
$alt = Join-Path $env:LOCALAPPDATA 'Kienzle-Starter'
if (Test-Path $alt) { Remove-Item -Recurse -Force $alt -ErrorAction SilentlyContinue; Say 'entfernt' 'alter Starter dieses Benutzers' }

# Agent bei der Suite anmelden (eigener Zugang für diesen PC, nur für System und Administratoren lesbar).
$out = & $exe --anmelden 2>&1
if ($LASTEXITCODE -ne 0) { throw ('Anmelden bei der Suite fehlgeschlagen: ' + ($out -join ' ')) }
& icacls.exe (Join-Path $daten 'agent.json') /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null
Say 'ok' ($out -join ' ')

# Dienst: geplante Aufgabe mit Systemrechten beim Start des PCs, startet nach Ende oder Aktualisierung neu.
$action = New-ScheduledTaskAction -Execute $exe -Argument '--dienst'
# Beim Start des PCs und zusätzlich alle 5 Minuten (läuft der Agent schon, beendet sich der zweite Start sofort).
$trigger = @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)))
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 9999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew
# Umzug von „Kienzle“ nach „Praxisherz“: alte Ordner entfernen (der Agent ist angehalten, neu angemeldet und läuft gleich aus dem neuen Ordner).
foreach ($o in @($progAlt, $datenAlt)) {
    if (Test-Path $o) { Remove-Item -Recurse -Force $o -ErrorAction SilentlyContinue; if (Test-Path $o) { Say 'Hinweis' "$o ließ sich nicht ganz entfernen (später von Hand löschen)" } else { Say 'entfernt' "alter Ordner $o" } }
}
Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Praxisherz: hält Starter, Zertifikat und Drucker aktuell, öffnet Akten in T2med.' -Force | Out-Null
Start-ScheduledTask -TaskName $task
Say 'läuft' 'Praxisherz-Agent (Drucker richtet er in den nächsten Minuten selbst ein)'
# Symbol in der Taskleiste (Statusfenster): startet künftig bei jeder Anmeldung (trägt der Agent ein), jetzt einmal sofort.
Start-Process -FilePath $exe -ArgumentList '--tray' | Out-Null  # läuft es schon, beendet sich der zweite Start sofort
Say 'läuft' 'Symbol in der Taskleiste (bei ausgeblendeten Symbolen über den Pfeil neben der Uhr)'
Write-Host ''
Write-Host 'Arbeitsplatz eingerichtet. Das Paket kann gelöscht werden; künftig aktualisiert sich alles selbst.'
Write-Host 'Für „Akte öffnen“: in T2med beim GDT-Gerät „Telefonaufruf“ als Importdatei C:\Praxisherz\GDT\AUFRUFi.gdt eintragen.'
if (Test-Path 'C:\Kienzle\GDT') { Write-Host 'Bisher stand dort C:\Kienzle\GDT\AUFRUFi.gdt. Bitte in T2med umstellen; bis dahin schreibt der Agent die Datei in beide Ordner. Danach den Ordner C:\Kienzle löschen.' }
Read-Host 'Zum Schließen Enter drücken'
