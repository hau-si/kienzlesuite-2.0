param([switch]$NichtWarten)
# Virtuelle Drucker der Module einrichten (drucker.txt: Name|Adresse|Papier je Zeile). Braucht Administratorrechte.
$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot
function Say($state, $what) { Write-Host ('{0,-13} {1}' -f $state, $what) }
if (!([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Write-Warning 'Bitte als Administrator ausführen.'; if (!$NichtWarten) { Read-Host 'Enter' }; exit 1 }
$list = @(Get-Content drucker.txt -ErrorAction SilentlyContinue | Where-Object { $_ -match '\|' } | ForEach-Object { $p = $_ -split '\|'; [pscustomobject]@{ Name = $p[0]; Url = $p[1] } })
if (!$list.Count) { Write-Host 'Keine virtuellen Drucker im Paket.'; exit 0 }
$todo = @($list | Where-Object { $p = Get-Printer -Name $_.Name -ErrorAction SilentlyContinue; !$p -or $p.PortName -ne $_.Url })
foreach ($d in $list) { if ($todo.Name -notcontains $d.Name) { Say 'unverändert' ('Drucker ' + $d.Name) } }
if ($todo.Count) {
    $f = Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart
    if ($f.RestartNeeded) { Write-Warning 'Windows bitte neu starten und dieses Skript danach noch einmal ausführen.' }
    Restart-Service Spooler -Force
    (Get-Service Spooler).WaitForStatus('Running', '00:00:30'); Start-Sleep 3
    Add-PrinterDriver -Name 'Microsoft IPP Class Driver' -ErrorAction SilentlyContinue
    foreach ($d in $todo) {
        $had = [bool](Get-Printer -Name $d.Name -ErrorAction SilentlyContinue)
        if ($had) { Remove-Printer -Name $d.Name -ErrorAction SilentlyContinue }
        $portFehler = ''; $druckerFehler = ''
        for ($i = 1; $i -le 4 -and !(Get-Printer -Name $d.Name -ErrorAction SilentlyContinue); $i++) {
            if (!(Get-PrinterPort -Name $d.Url -ErrorAction SilentlyContinue)) { try { Add-PrinterPort -Name $d.Url -ErrorAction Stop } catch { $portFehler = $_.Exception.Message } }
            Start-Sleep 3
            try { Add-Printer -Name $d.Name -DriverName 'Microsoft IPP Class Driver' -PortName $d.Url -ErrorAction Stop } catch { $druckerFehler = $_.Exception.Message }
        }
        $fehler = if ($portFehler) { 'Anschluss: ' + $portFehler + ' | Drucker: ' + $druckerFehler } else { $druckerFehler }
        if (Get-Printer -Name $d.Name -ErrorAction SilentlyContinue) { Say ($(if ($had) { 'aktualisiert' } else { 'neu' })) ('Drucker ' + $d.Name) }
        else {
            $server = ''
            try { Invoke-WebRequest -Uri $d.Url -UseBasicParsing -TimeoutSec 10 | Out-Null } catch { $server = ' Die Adresse ' + $d.Url + ' antwortet nicht (' + $_.Exception.Message + '): Gibt es den Drucker auf dem Server (Verwaltung → Drucker, ggf. „Virtuelle Drucker der Module abgleichen“) und ist Port 631 in der Firewall frei?' }
            Write-Warning ('Drucker ' + $d.Name + ' nicht angelegt: ' + $fehler + '.' + $server + ' (Anleitung auf der Seite „Arbeitsplätze“ von Praxisherz)')
        }
    }
}
if (!$NichtWarten) { Read-Host 'Zum Schließen Enter drücken' }
