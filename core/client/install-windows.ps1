$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
# Idempotent: nur Fehlendes oder Geändertes wird angefasst, jede Zeile sagt „neu“, „aktualisiert“ oder „unverändert“.
function Say($state, $what) { Write-Host ('{0,-13} {1}' -f $state, $what) }
function Same($a, $b) { (Test-Path $b) -and ((Get-FileHash $a -Algorithm SHA256).Hash -eq (Get-FileHash $b -Algorithm SHA256).Hash) }
function Place($source, $dest, $label) {
    if (Same $source $dest) { Say 'unverändert' $label; return }
    $had = Test-Path $dest
    Copy-Item $source $dest -Force
    Say ($(if ($had) { 'aktualisiert' } else { 'neu' })) $label
}
if (!(Test-Path client.json) -or !(Test-Path server.crt) -or !(Test-Path schemas.txt)) { throw 'client.json, schemas.txt und server.crt aus dem Paket fehlen.' }
$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64' -or $env:PROCESSOR_ARCHITEW6432 -eq 'ARM64') { 'arm64' } else { 'amd64' }
$asset = "kienzle-starter-windows-$arch.exe"
if (!(Test-Path $asset) -or !(Test-Path SHA256SUMS)) { throw "$asset oder SHA256SUMS fehlt im Paket. Bitte das Paket auf dem Server neu laden." }
$line = Get-Content SHA256SUMS | Where-Object { ($_ -split '\s+')[1] -eq $asset }
if (!$line -or ((Get-FileHash $asset -Algorithm SHA256).Hash.ToLowerInvariant() -ne ($line -split '\s+')[0])) { throw 'Prüfsumme stimmt nicht.' }
$target = Join-Path $env:LOCALAPPDATA 'Kienzle-Starter'
New-Item -ItemType Directory -Force $target | Out-Null
Place $asset (Join-Path $target 'kienzle-starter.exe') 'Starterprogramm'
Place client.json (Join-Path $target 'client.json') 'Einstellungen und Schlüssel'
Place server.crt (Join-Path $target 'server.crt') 'Zertifikat (Datei)'
$sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe $target /inheritance:r /grant:r "*${sid}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Dateirechte konnten nicht gesetzt werden.' }
$cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2((Join-Path $target 'server.crt'))
if (Get-ChildItem Cert:\CurrentUser\Root | Where-Object { $_.Thumbprint -eq $cert.Thumbprint }) { Say 'unverändert' 'Zertifikat der Suite (vertrauenswürdig)' }
else { Import-Certificate -FilePath (Join-Path $target 'server.crt') -CertStoreLocation Cert:\CurrentUser\Root | Out-Null; Say 'neu' 'Zertifikat der Suite (vertrauenswürdig)' }
$command = '"' + (Join-Path $target 'kienzle-starter.exe') + '" --url "%1"'
foreach ($scheme in (Get-Content schemas.txt | Where-Object { $_ -match '^kienzle-[a-z0-9-]+$' })) {
    $reg = "HKCU:\Software\Classes\$scheme"
    $current = try { (Get-ItemProperty "$reg\shell\open\command" -ErrorAction Stop).'(default)' } catch { $null }
    if ($current -eq $command) { Say 'unverändert' "Aufruf $scheme"; continue }
    New-Item -Force $reg | Out-Null
    Set-Item $reg -Value "URL:$scheme"
    New-ItemProperty $reg -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    New-Item -Force "$reg\shell\open\command" | Out-Null
    Set-Item "$reg\shell\open\command" -Value $command
    Say ($(if ($current) { 'aktualisiert' } else { 'neu' })) "Aufruf $scheme"
}
# Virtuelle Drucker (nur wenn das Paket welche enthält): brauchen Administratorrechte, dafür fragt Windows einmal nach.
if ((Test-Path drucker.txt) -and (Test-Path install-drucker.ps1) -and (Get-Content drucker.txt | Where-Object { $_ -match '\|' })) {
    $admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if ($admin) { & (Join-Path $PSScriptRoot 'install-drucker.ps1') -NichtWarten }
    else {
        Write-Host 'Virtuelle Drucker: Windows fragt jetzt nach Administratorrechten.'
        try { Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + (Join-Path $PSScriptRoot 'install-drucker.ps1') + '"') }
        catch { Write-Warning 'Drucker nicht eingerichtet (Administratorfrage abgelehnt). Anleitung: Seite „Arbeitsplätze“ der Suite, Abschnitt „Virtuelle Drucker“.' }
    }
}
Write-Host 'Arbeitsplatz eingerichtet. Das Paket kann gelöscht werden.'
Read-Host 'Zum Schließen Enter drücken'
