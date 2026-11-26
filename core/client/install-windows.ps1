$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
# Kienzle-Agent einrichten (einmal je PC; erneutes Ausführen repariert). Danach hält der Agent Starter, Aufrufe aus T2med,
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

# Programm: C:\Program Files\Kienzle (nur Administratoren dürfen ändern).
$prog = Join-Path $env:ProgramFiles 'Kienzle'
New-Item -ItemType Directory -Force $prog | Out-Null
$exe = Join-Path $prog 'kienzle-starter.exe'
Place $asset $exe 'Programm (Starter und Agent)'

# Daten: C:\ProgramData\Kienzle. Benutzer dürfen nur lesen (der Agent läuft mit Systemrechten und führt dort ein Skript aus).
$daten = Join-Path $env:ProgramData 'Kienzle'
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
foreach ($scheme in (Get-Content schemas.txt | Where-Object { $_ -match '^kienzle-[a-z0-9-]+$' })) {
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
$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 9999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Kienzle-Suite: hält Starter, Zertifikat und Drucker aktuell, öffnet Akten in T2med.' -Force | Out-Null
Start-ScheduledTask -TaskName $task
Say 'läuft' 'Kienzle-Agent (Drucker richtet er in den nächsten Minuten selbst ein)'
Write-Host ''
Write-Host 'Arbeitsplatz eingerichtet. Das Paket kann gelöscht werden; künftig aktualisiert sich alles selbst.'
Write-Host 'Für „Akte öffnen“: in T2med beim GDT-Gerät „Telefonaufruf“ als Importdatei C:\Kienzle\GDT\AUFRUFi.gdt eintragen.'
Read-Host 'Zum Schließen Enter drücken'
