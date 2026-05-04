param(
    [string]$PublicKey = $env:RM_AGENT_PUBLIC_KEY
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$out = Join-Path $root 'bin\agent'
New-Item -ItemType Directory -Force -Path $out | Out-Null

$ldflags = '-s -w'
if ($PublicKey) {
    $ldflags = "$ldflags -X 'radius-manager/windows-agent/internal/agentcore.DefaultPublicKeyBase64=$PublicKey'"
}

Push-Location $PSScriptRoot
try {
    $env:CGO_ENABLED = '0'
    $env:GOOS = 'windows'
    $env:GOARCH = 'amd64'
    go build -trimpath -ldflags $ldflags -o (Join-Path $out 'license-generator.exe') .\cmd\license-generator
    go build -trimpath -ldflags $ldflags -o (Join-Path $out 'activation-key.exe') .\cmd\activation-key
    go build -trimpath -ldflags $ldflags -o (Join-Path $out 'backend-agent.exe') .\cmd\backend-agent
}
finally {
    Pop-Location
}

Write-Host "Executables generes dans $out"
