$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
if (!(Test-Path client.json) -or !(Test-Path server.crt)) { throw 'client.json und server.crt aus der Serverinstallation fehlen.' }
$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64' -or $env:PROCESSOR_ARCHITEW6432 -eq 'ARM64') { 'arm64' } else { 'amd64' }
$asset = "kienzle-sumup-windows-$arch.exe"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if (!(Test-Path $asset)) {
    Invoke-WebRequest "https://github.com/thomaskien/t2med-sumup/releases/download/v1.0/$asset" -OutFile $asset -UseBasicParsing
    Invoke-WebRequest 'https://github.com/thomaskien/t2med-sumup/releases/download/v1.0/SHA256SUMS' -OutFile SHA256SUMS -UseBasicParsing
}
$line = Get-Content SHA256SUMS | Where-Object { ($_ -split '\s+')[1] -eq $asset }
if (!$line -or ((Get-FileHash $asset -Algorithm SHA256).Hash.ToLowerInvariant() -ne ($line -split '\s+')[0])) { throw 'Prüfsumme stimmt nicht.' }
$target = Join-Path $env:LOCALAPPDATA 'Kienzle-SumUp'
New-Item -ItemType Directory -Force $target | Out-Null
Copy-Item $asset (Join-Path $target 'kienzle-sumup.exe') -Force
Copy-Item client.json,server.crt $target -Force
$sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe $target /inheritance:r /grant:r "*${sid}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Dateirechte konnten nicht gesetzt werden.' }
Import-Certificate -FilePath (Join-Path $target 'server.crt') -CertStoreLocation Cert:\CurrentUser\Root | Out-Null
$reg = 'HKCU:\Software\Classes\kienzle-sumup'
New-Item -Force $reg | Out-Null
Set-Item $reg -Value 'URL:Kienzle-SumUp'
New-ItemProperty $reg -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
New-Item -Force "$reg\shell\open\command" | Out-Null
Set-Item "$reg\shell\open\command" -Value ('"' + (Join-Path $target 'kienzle-sumup.exe') + '" --url "%1"')
Write-Host 'Kienzle-SumUp eingerichtet. Kienzledoku bleibt separat registriert.'
Read-Host 'Zum Schließen Enter drücken'
