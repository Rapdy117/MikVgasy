$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$configPath = Join-Path $root 'config\license\agent_service.json'
$config = Get-Content $configPath -Raw | ConvertFrom-Json
$exe = Join-Path $PSScriptRoot 'backend-agent.exe'

if (-not (Test-Path $exe)) {
    throw "backend-agent.exe introuvable: $exe"
}

$uri = "$($config.url.TrimEnd('/'))/v1/health"
try {
    Invoke-RestMethod -Method Get -Uri $uri -TimeoutSec 2 | Out-Null
    Write-Host "backend-agent service deja actif: $($config.url)"
    exit 0
} catch {
}

$listen = ([Uri]$config.url).Authority
Start-Process -FilePath $exe -ArgumentList @(
    'serve',
    '--app-dir', $root,
    '--listen', $listen,
    '--token', $config.token
) -WorkingDirectory $root -WindowStyle Hidden

Start-Sleep -Milliseconds 700
Invoke-RestMethod -Method Get -Uri $uri -TimeoutSec 3 | Out-Null
Write-Host "backend-agent service actif: $($config.url)"
