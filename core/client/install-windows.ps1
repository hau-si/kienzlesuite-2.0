$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
if (!(Test-Path client.json) -or !(Test-Path server.crt) -or !(Test-Path schemas.txt)) { throw 'client.json, schemas.txt und server.crt aus dem Paket fehlen.' }
$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64' -or $env:PROCESSOR_ARCHITEW6432 -eq 'ARM64') { 'arm64' } else { 'amd64' }
$asset = "kienzle-starter-windows-$arch.exe"
if (!(Test-Path $asset) -or !(Test-Path SHA256SUMS)) { throw "$asset oder SHA256SUMS fehlt im Paket. Bitte das Paket auf dem Server neu laden." }
$line = Get-Content SHA256SUMS | Where-Object { ($_ -split '\s+')[1] -eq $asset }
if (!$line -or ((Get-FileHash $asset -Algorithm SHA256).Hash.ToLowerInvariant() -ne ($line -split '\s+')[0])) { throw 'Prüfsumme stimmt nicht.' }
$target = Join-Path $env:LOCALAPPDATA 'Kienzle-Starter'
New-Item -ItemType Directory -Force $target | Out-Null
Copy-Item $asset (Join-Path $target 'kienzle-starter.exe') -Force
Copy-Item client.json,server.crt $target -Force
$sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe $target /inheritance:r /grant:r "*${sid}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Dateirechte konnten nicht gesetzt werden.' }
Import-Certificate -FilePath (Join-Path $target 'server.crt') -CertStoreLocation Cert:\CurrentUser\Root | Out-Null
foreach ($scheme in (Get-Content schemas.txt | Where-Object { $_ -match '^kienzle-[a-z0-9-]+$' })) {
    $reg = "HKCU:\Software\Classes\$scheme"
    New-Item -Force $reg | Out-Null
    Set-Item $reg -Value "URL:$scheme"
    New-ItemProperty $reg -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    New-Item -Force "$reg\shell\open\command" | Out-Null
    Set-Item "$reg\shell\open\command" -Value ('"' + (Join-Path $target 'kienzle-starter.exe') + '" --url "%1"')
    Write-Host "Eingerichtet: $scheme"
}
Write-Host 'Kienzle-Starter eingerichtet.'
Read-Host 'Zum Schließen Enter drücken'
