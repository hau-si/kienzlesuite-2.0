param([Parameter(Mandatory = $true)][string]$Liste)
# Kienzle-Agent: virtuelle Drucker der Module einrichten (läuft als SYSTEM, ohne Rückfragen). Wie install-drucker.ps1.
# Letzte Zeile der Ausgabe = Zusammenfassung für die Seite „Arbeitsplätze“.
$ErrorActionPreference = 'Continue'
$list = @(Get-Content -Raw $Liste | ConvertFrom-Json | Where-Object { $_.name -match '^[A-Za-z0-9_-]{1,40}$' -and $_.url -match '^http://[^\s/]+:631/printers/' })
$todo = @($list | Where-Object { $p = Get-Printer -Name $_.name -ErrorAction SilentlyContinue; !$p -or $p.PortName -ne $_.url })
if (!$todo.Count) { Write-Output ('Drucker: ' + $list.Count + ' eingerichtet'); exit 0 }
$f = Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart -ErrorAction SilentlyContinue
if ($f -and $f.RestartNeeded) { Write-Output 'Drucker: Windows muss einmal neu gestartet werden'; exit 1 }
Add-PrinterDriver -Name 'Microsoft IPP Class Driver' -ErrorAction SilentlyContinue
$fehlt = @()
foreach ($d in $todo) {
    if (Get-Printer -Name $d.name -ErrorAction SilentlyContinue) { Remove-Printer -Name $d.name -ErrorAction SilentlyContinue }
    for ($i = 1; $i -le 3 -and !(Get-Printer -Name $d.name -ErrorAction SilentlyContinue); $i++) {
        if (!(Get-PrinterPort -Name $d.url -ErrorAction SilentlyContinue)) { Add-PrinterPort -Name $d.url -ErrorAction SilentlyContinue }
        Start-Sleep 2
        Add-Printer -Name $d.name -DriverName 'Microsoft IPP Class Driver' -PortName $d.url -ErrorAction SilentlyContinue
    }
    if (!(Get-Printer -Name $d.name -ErrorAction SilentlyContinue)) { $fehlt += $d.name }
}
if ($fehlt.Count) { Write-Output ('Drucker fehlen: ' + ($fehlt -join ', ') + ' (Port 631 in der Firewall der Suite frei?)'); exit 1 }
Write-Output ('Drucker: ' + $list.Count + ' eingerichtet')
