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
# Protokoll für Rückfragen; bei einem Fehler bleibt das Fenster offen (sonst schlösse es sich sofort und man sähe nichts).
$log = Join-Path $env:TEMP 'praxisherz-einrichtung.log'
try { Start-Transcript -Path $log -Force | Out-Null } catch { }
trap {
    Write-Host ''
    Write-Host ('FEHLER: ' + $_) -ForegroundColor Red
    Write-Host ('Protokoll: ' + $log)
    try { Stop-Transcript | Out-Null } catch { }
    Read-Host 'Zum Schließen Enter drücken'
    exit 1
}
Write-Host ('Praxisherz-Agent wird eingerichtet (Protokoll: ' + $log + ') …')
if (!(Test-Path client.json) -or !(Test-Path schemas.txt) -or !(Test-Path ca.crt)) { throw 'client.json, schemas.txt und ca.crt aus dem Paket fehlen. Bitte das Paket auf dem Server neu laden.' }
$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64' -or $env:PROCESSOR_ARCHITEW6432 -eq 'ARM64') { 'arm64' } else { 'amd64' }
$asset = "praxisherz-starter-windows-$arch.exe"
if (!(Test-Path $asset) -or !(Test-Path SHA256SUMS)) { throw "$asset oder SHA256SUMS fehlt im Paket. Bitte das Paket auf dem Server neu laden." }
$line = Get-Content SHA256SUMS | Where-Object { ($_ -split '\s+')[1] -eq $asset }
if (!$line -or ((Get-FileHash $asset -Algorithm SHA256).Hash.ToLowerInvariant() -ne ($line -split '\s+')[0])) { throw 'Prüfsumme stimmt nicht.' }

# Laufenden Agent anhalten, damit das Programm ersetzt werden kann.
$task = 'Praxisherz-Agent'
if (Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue }
Get-Process praxisherz-starter -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

# Programm: C:\Program Files\Praxisherz (nur Administratoren dürfen ändern).
$prog = Join-Path $env:ProgramFiles 'Praxisherz'
New-Item -ItemType Directory -Force $prog | Out-Null
$exe = Join-Path $prog 'praxisherz-starter.exe'
Place $asset $exe 'Programm (Starter und Agent)'
# Aus dem Browser heruntergeladene Dateien tragen eine Kennzeichnung „aus dem Internet“ (Zone.Identifier), die beim
# Kopieren erhalten bleibt: Windows würde sonst bei jedem Start des Symbols (bei jeder Anmeldung) erneut fragen, ob das
# unsignierte Programm ausgeführt werden darf. Einmal entfernen reicht; harmlos, wenn die Kennzeichnung schon fehlt.
Unblock-File $exe -ErrorAction SilentlyContinue

# Daten: C:\ProgramData\Praxisherz. Benutzer dürfen nur lesen (der Agent läuft mit Systemrechten und führt dort ein Skript aus).
$daten = Join-Path $env:ProgramData 'Praxisherz'
New-Item -ItemType Directory -Force $daten | Out-Null
& icacls.exe $daten /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Dateirechte konnten nicht gesetzt werden.' }
Place client.json (Join-Path $daten 'client.json') 'Einstellungen und Schlüssel'
Place ca.crt (Join-Path $daten 'ca.crt') 'Zertifizierungsstelle von Praxisherz (Datei)'
if (Test-Path server.crt) { Copy-Item server.crt (Join-Path $daten 'server.crt') -Force }

# Zertifizierungsstelle für alle Benutzer vertrauenswürdig (Browser zeigen dann kein „Nicht sicher“).
$cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2((Join-Path $daten 'ca.crt'))
if (Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $cert.Thumbprint }) { Say 'unverändert' 'Zertifikat von Praxisherz (vertrauenswürdig)' }
else { Import-Certificate -FilePath (Join-Path $daten 'ca.crt') -CertStoreLocation Cert:\LocalMachine\Root | Out-Null; Say 'neu' 'Zertifikat von Praxisherz (vertrauenswürdig)' }

# Aufrufe aus T2med für alle Benutzer (der Agent hält sie später selbst aktuell).
$command = '"' + $exe + '" --url "%1"'
foreach ($scheme in (Get-Content schemas.txt | Where-Object { $_ -match '^praxisherz-[a-z0-9-]+$' })) {
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
}

# Agent bei Praxisherz anmelden (eigener Zugang für diesen PC, nur für System und Administratoren lesbar).
$out = & $exe --anmelden 2>&1
if ($LASTEXITCODE -ne 0) { throw ('Anmelden bei Praxisherz fehlgeschlagen: ' + ($out -join ' ')) }
& icacls.exe (Join-Path $daten 'agent.json') /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null
Say 'ok' ($out -join ' ')

# Dienst: geplante Aufgabe mit Systemrechten beim Start des PCs, startet nach Ende oder Aktualisierung neu.
$action = New-ScheduledTaskAction -Execute $exe -Argument '--dienst'
# Beim Start des PCs und zusätzlich alle 5 Minuten (läuft der Agent schon, beendet sich der zweite Start sofort).
$trigger = @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)))
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 9999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Praxisherz: hält Starter, Zertifikat und Drucker aktuell, öffnet Akten in T2med.' -Force | Out-Null
Start-ScheduledTask -TaskName $task
# Eintrag unter Einstellungen → Apps mit „Deinstallieren“ (Skript liegt beim Programm).
Copy-Item deinstallieren.ps1 (Join-Path $prog 'deinstallieren.ps1') -Force
$ver = try { ((& $exe --version) -split '\s+')[1] } catch { '' }
$un = 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\Praxisherz-Agent'
New-Item -Force $un | Out-Null
$werte = @{ DisplayName = 'Praxisherz-Agent'; DisplayVersion = [string]$ver; Publisher = 'Praxisherz'; DisplayIcon = $exe; InstallLocation = $prog
    UninstallString = ('powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $prog 'deinstallieren.ps1') + '"') }
foreach ($k in $werte.Keys) { New-ItemProperty $un -Name $k -Value $werte[$k] -PropertyType String -Force | Out-Null }
foreach ($k in @('NoModify', 'NoRepair')) { New-ItemProperty $un -Name $k -Value 1 -PropertyType DWord -Force | Out-Null }
Say 'ok' 'Eintrag unter Einstellungen → Apps (dort auch „Deinstallieren“)'
Say 'läuft' 'Praxisherz-Agent (Drucker richtet er in den nächsten Minuten selbst ein)'
# Symbol in der Taskleiste (Statusfenster): startet künftig bei jeder Anmeldung (trägt der Agent ein), jetzt einmal sofort.
Start-Process -FilePath $exe -ArgumentList '--tray' | Out-Null  # läuft es schon, beendet sich der zweite Start sofort
Say 'läuft' 'Symbol in der Taskleiste (bei ausgeblendeten Symbolen über den Pfeil neben der Uhr)'
Write-Host ''
Write-Host 'Arbeitsplatz eingerichtet. Das Paket kann gelöscht werden; künftig aktualisiert sich alles selbst.'
Write-Host 'Für „Akte öffnen“: in T2med beim GDT-Gerät „Telefonaufruf“ als Importdatei C:\Praxisherz\GDT\AUFRUFi.gdt eintragen.'
try { Stop-Transcript | Out-Null } catch { }
Read-Host 'Zum Schließen Enter drücken'
