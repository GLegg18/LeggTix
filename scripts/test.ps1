[CmdletBinding(PositionalBinding = $false)]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $TestArguments = @()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false

$taskRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskComposeFile = Join-Path $taskRoot 'docker-compose.test.yml'
$taskProject = 'leggtix-test-' + [Guid]::NewGuid().ToString('N')
$taskComposeArguments = @('compose', '--project-name', $taskProject, '--project-directory', $taskRoot, '--file', $taskComposeFile)
$taskExitCode = 1
$taskNeedsCleanup = $false

try {
    $taskDockerCommand = Get-Command docker -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1

    if ($null -eq $taskDockerCommand) {
        throw 'Docker Desktop with Docker Compose v2 is required. Install it and start Linux containers before running tests.'
    }

    $taskDocker = $taskDockerCommand.Source
    & $taskDocker compose version

    if ($LASTEXITCODE -ne 0) {
        throw 'Docker Compose v2 is unavailable. Check the Docker Desktop installation.'
    }

    $taskDockerOs = & $taskDocker info --format '{{.OSType}}'

    if ($LASTEXITCODE -ne 0 -or $taskDockerOs -ne 'linux') {
        throw 'Start Docker Desktop and select Linux containers before running tests.'
    }

    Write-Host "Running tests in disposable project $taskProject"
    $taskNeedsCleanup = $true
    & $taskDocker @taskComposeArguments run --rm --build -T tests php artisan test --compact @TestArguments
    $taskExitCode = $LASTEXITCODE
} catch {
    Write-Host "Test runner failed: $($_.Exception.Message)" -ForegroundColor Red
    $taskExitCode = 1
} finally {
    if ($taskNeedsCleanup) {
        try {
            & $taskDocker @taskComposeArguments down --volumes --remove-orphans --rmi local

            if ($LASTEXITCODE -ne 0) {
                throw "Docker Compose cleanup exited with code $LASTEXITCODE."
            }
        } catch {
            Write-Host "Cleanup failed for $taskProject. Retry: docker compose --project-name $taskProject --file `"$taskComposeFile`" down --volumes --remove-orphans --rmi local" -ForegroundColor Red

            if ($taskExitCode -eq 0) {
                $taskExitCode = 1
            }
        }
    }
}

exit $taskExitCode
