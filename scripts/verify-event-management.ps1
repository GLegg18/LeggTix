[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$taskRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskExitCode = 1

Push-Location $taskRoot
try {
    & docker compose exec -T app php scripts/support/verify-event-management.php
    $taskExitCode = $LASTEXITCODE
} catch {
    Write-Host 'Live event-management acceptance could not start. Check Docker Desktop and the local services.' -ForegroundColor Red
} finally {
    Pop-Location
}

exit $taskExitCode
