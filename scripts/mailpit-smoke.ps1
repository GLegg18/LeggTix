[CmdletBinding()]
param([switch] $KeepMessages)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$taskRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskExitCode = 1

Push-Location $taskRoot
try {
    $taskInbox = Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost:8025/' -TimeoutSec 10
    if ($taskInbox.StatusCode -ne 200) { throw 'Mailpit inbox did not return HTTP 200.' }
    Write-Host 'PASS inbox http://localhost:8025/ HTTP200'
    $taskArguments = @('compose', 'exec', '-T', 'app', 'php', 'scripts/support/mailpit-smoke.php')
    if ($KeepMessages) { $taskArguments += '--keep-messages' }
    & docker @taskArguments
    $taskExitCode = $LASTEXITCODE
} catch {
    Write-Host "Mailpit acceptance failed: $($_.Exception.Message)" -ForegroundColor Red
} finally {
    Pop-Location
}

exit $taskExitCode
