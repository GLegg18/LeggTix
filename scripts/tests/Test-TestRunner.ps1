[CmdletBinding()]
param(
    [string[]] $PowerShellPaths = @()
)

# No Pester or Docker dependency: execute the actual wrapper against a native
# docker.cmd application in fresh child processes, then inspect its call log.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false

$harnessRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$harnessRunner = Join-Path $harnessRoot 'scripts/test.ps1'
$harnessCompose = Join-Path $harnessRoot 'docker-compose.test.yml'
$harnessFixture = Join-Path $PSScriptRoot 'MockDocker.ps1'
$harnessChild = Join-Path $PSScriptRoot 'RunWrapper.ps1'
$harnessTempBase = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$harnessTempRoot = Join-Path $harnessTempBase ('leggtix-wrapper-tests-' + [Guid]::NewGuid().ToString('N'))
$harnessFailures = New-Object 'System.Collections.Generic.List[string]'
$harnessProjects = New-Object 'System.Collections.Generic.List[string]'
$harnessPassed = 0

function Assert-Wrapper {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

function Get-AppConfigurationState {
    $state = @{}
    foreach ($relative in @('.env', '.gitignore', 'docker-compose.yml', 'phpunit.xml', 'bootstrap/cache/config.php')) {
        $path = Join-Path $harnessRoot $relative
        $state[$relative] = if (Test-Path -LiteralPath $path -PathType Leaf) {
            (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash
        } else {
            '<absent>'
        }
    }
    return $state
}

function Invoke-WrapperCase {
    param(
        [string] $Shell,
        [string] $Mode,
        [int] $ExpectedExit,
        [int] $ExpectedCalls,
        [bool] $ExpectRun,
        [string[]] $Arguments = @(),
        [string] $ExpectedMessage = ''
    )

    $caseDirectory = Join-Path $harnessTempRoot ([Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $caseDirectory | Out-Null
    $caseLog = Join-Path $caseDirectory 'calls.jsonl'
    $mockShell = $Shell

    if ($Mode -ne 'MissingDocker') {
        # Get-Command -CommandType Application resolves this native shim. Keeping
        # its path isolated prevents the real Docker CLI from being selected.
        $shim = '@echo off' + "`r`n" + '"' + $mockShell + '" -NoLogo -NoProfile -ExecutionPolicy Bypass -File "' + $harnessFixture + '" %*' + "`r`n" + 'exit /b %errorlevel%' + "`r`n"
        [System.IO.File]::WriteAllText((Join-Path $caseDirectory 'docker.cmd'), $shim, [System.Text.Encoding]::ASCII)
    }

    $oldPath = $env:PATH
    $oldMode = $env:LEGGTIX_WRAPPER_TEST_MODE
    $oldLog = $env:LEGGTIX_WRAPPER_TEST_LOG
    $oldMockPath = $env:LEGGTIX_WRAPPER_TEST_PATH
    try {
        # System32 can contain a real Docker CLI on hosted Windows runners.
        # Shell paths are absolute; the shim uses COMSPEC, so no host PATH is needed.
        $env:PATH = $caseDirectory
        $env:LEGGTIX_WRAPPER_TEST_MODE = $Mode
        $env:LEGGTIX_WRAPPER_TEST_LOG = $caseLog
        $env:LEGGTIX_WRAPPER_TEST_PATH = $env:PATH
        # Windows PowerShell wraps native stderr in ErrorRecords; capture the
        # child output without allowing expected failures to terminate harness.
        $ErrorActionPreference = 'Continue'
        $caseOutput = @(& $Shell -NoLogo -NoProfile -ExecutionPolicy Bypass -File $harnessChild @Arguments 2>&1)
        $caseExit = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
    } finally {
        $env:PATH = $oldPath
        $env:LEGGTIX_WRAPPER_TEST_MODE = $oldMode
        $env:LEGGTIX_WRAPPER_TEST_LOG = $oldLog
        $env:LEGGTIX_WRAPPER_TEST_PATH = $oldMockPath
    }

    $caseText = ($caseOutput | ForEach-Object { $_.ToString() }) -join "`n"
    Assert-Wrapper ($caseExit -eq $ExpectedExit) "${Mode}: expected exit $ExpectedExit, got $caseExit. $caseText"
    if ($ExpectedMessage) {
        Assert-Wrapper ($caseText.Contains($ExpectedMessage)) "${Mode}: missing diagnostic '$ExpectedMessage'. $caseText"
    }

    $calls = @()
    if (Test-Path -LiteralPath $caseLog) {
        $calls = @(Get-Content -LiteralPath $caseLog | ForEach-Object { ConvertFrom-Json $_ })
    }
    Assert-Wrapper ($calls.Count -eq $ExpectedCalls) "${Mode}: expected $ExpectedCalls Docker calls, got $($calls.Count). $caseText"

    if ($ExpectedCalls -gt 0) {
        Assert-Wrapper ((@($calls[0].arguments) -join '|') -eq 'compose|version') "${Mode}: Compose preflight was skipped."
    }
    if ($ExpectedCalls -gt 1) {
        Assert-Wrapper ((@($calls[1].arguments) -join '|') -eq 'info|--format|{{.OSType}}') "${Mode}: Linux-engine preflight was skipped."
    }

    if (-not $ExpectRun) {
        Assert-Wrapper (@($calls | Where-Object { $_.arguments -contains 'down' -or $_.arguments -contains 'run' }).Count -eq 0) "${Mode}: preflight failure must not create or clean up resources."
        return
    }

    $run = @($calls[2].arguments)
    $cleanup = @($calls[3].arguments)
    $project = $run[2]
    Assert-Wrapper ($project -match '^leggtix-test-[0-9a-f]{32}$') "${Mode}: project must have a unique disposable name."
    Assert-Wrapper (-not $harnessProjects.Contains($project)) "${Mode}: project was reused across invocations."
    $harnessProjects.Add($project)

    $prefix = @('compose', '--project-name', $project, '--project-directory', $harnessRoot, '--file', $harnessCompose)
    $expectedRun = @($prefix + @('run', '--rm', '--build', '-T', 'tests', 'php', 'artisan', 'test', '--compact') + $Arguments)
    $expectedCleanup = @($prefix + @('down', '--volumes', '--remove-orphans', '--rmi', 'local'))
    Assert-Wrapper (($run | ConvertTo-Json -Compress) -eq ($expectedRun | ConvertTo-Json -Compress)) "${Mode}: run arguments changed or escaped incorrectly: $($run | ConvertTo-Json -Compress)"
    Assert-Wrapper (($cleanup | ConvertTo-Json -Compress) -eq ($expectedCleanup | ConvertTo-Json -Compress)) "${Mode}: cleanup escaped its disposable project/file or omitted volumes/orphans."

    if ($Mode -in @('CleanupFailure', 'CleanupAfterTestFailure')) {
        Assert-Wrapper ($caseText.Contains("Cleanup failed for $project. Retry: docker compose --project-name $project")) "${Mode}: cleanup failure must print a scoped recovery command."
        Assert-Wrapper ($caseText.Contains($harnessCompose)) "${Mode}: recovery command must select the isolated Compose file."
    }
}

if ($PowerShellPaths.Count -eq 0) {
    $PowerShellPaths = @((Get-Process -Id $PID).Path)
}

$configurationBefore = Get-AppConfigurationState
New-Item -ItemType Directory -Path $harnessTempRoot | Out-Null
try {
    foreach ($shell in $PowerShellPaths) {
        Assert-Wrapper (Test-Path -LiteralPath $shell -PathType Leaf) "PowerShell executable not found: $shell"
        $shellLabel = Split-Path -Leaf $shell
        $cases = @(
            @{ Mode = 'Success'; ExpectedExit = 0; ExpectedCalls = 4; ExpectRun = $true },
            @{ Mode = 'SuccessStderr'; ExpectedExit = 0; ExpectedCalls = 4; ExpectRun = $true; ExpectedMessage = 'Simulated Docker progress on stderr' },
            @{ Mode = 'TestFailureStderr'; ExpectedExit = 23; ExpectedCalls = 4; ExpectRun = $true; ExpectedMessage = 'Simulated failing test on stderr' },
            @{ Mode = 'TestFailure'; ExpectedExit = 23; ExpectedCalls = 4; ExpectRun = $true; ExpectedMessage = 'Simulated failing PHPUnit test' },
            @{ Mode = 'BuildFailure'; ExpectedExit = 17; ExpectedCalls = 4; ExpectRun = $true; ExpectedMessage = 'Simulated Docker image build failure' },
            @{ Mode = 'StartFailure'; ExpectedExit = 19; ExpectedCalls = 4; ExpectRun = $true; ExpectedMessage = 'Simulated MySQL startup failure' },
            @{ Mode = 'MissingDocker'; ExpectedExit = 1; ExpectedCalls = 0; ExpectRun = $false; ExpectedMessage = 'Docker Desktop with Docker Compose v2 is required' },
            @{ Mode = 'ComposeUnavailable'; ExpectedExit = 1; ExpectedCalls = 1; ExpectRun = $false; ExpectedMessage = 'Docker Compose v2 is unavailable' },
            @{ Mode = 'EngineUnavailable'; ExpectedExit = 1; ExpectedCalls = 2; ExpectRun = $false; ExpectedMessage = 'select Linux containers' },
            @{ Mode = 'WindowsEngine'; ExpectedExit = 1; ExpectedCalls = 2; ExpectRun = $false; ExpectedMessage = 'select Linux containers' },
            @{ Mode = 'CleanupFailure'; ExpectedExit = 1; ExpectedCalls = 4; ExpectRun = $true },
            @{ Mode = 'CleanupAfterTestFailure'; ExpectedExit = 23; ExpectedCalls = 4; ExpectRun = $true },
            @{ Mode = 'Success'; ExpectedExit = 0; ExpectedCalls = 4; ExpectRun = $true; Arguments = @('--filter', 'AuthenticationTest::a case with spaces', '--stop-on-failure', '--exclude-group=slow group') },
            @{ Mode = 'Success'; ExpectedExit = 0; ExpectedCalls = 4; ExpectRun = $true }
        )
        foreach ($case in $cases) {
            try {
                Invoke-WrapperCase -Shell $shell @case
                $harnessPassed++
                Write-Host "PASS [$shellLabel] $($case.Mode)"
            } catch {
                $harnessFailures.Add("[$shellLabel] $($case.Mode): $($_.Exception.Message)")
                Write-Host "FAIL [$shellLabel] $($case.Mode): $($_.Exception.Message)" -ForegroundColor Red
            }
        }
    }

    $configurationAfter = Get-AppConfigurationState
    foreach ($relative in $configurationBefore.Keys) {
        Assert-Wrapper ($configurationBefore[$relative] -eq $configurationAfter[$relative]) "Application configuration was modified: $relative"
    }
    Write-Host 'PASS application configuration unchanged'
} finally {
    # Only delete the exact temporary directory created by this harness.
    $resolvedTempRoot = [System.IO.Path]::GetFullPath($harnessTempRoot)
    Assert-Wrapper ($resolvedTempRoot.StartsWith($harnessTempBase, [StringComparison]::OrdinalIgnoreCase)) 'Temporary cleanup target escaped its base directory.'
    Assert-Wrapper ((Split-Path -Leaf $resolvedTempRoot) -match '^leggtix-wrapper-tests-[0-9a-f]{32}$') 'Temporary cleanup target is not owned by this harness.'
    Remove-Item -LiteralPath $resolvedTempRoot -Recurse -Force
}

Write-Host "$harnessPassed wrapper cases passed; $($harnessFailures.Count) failed."
if ($harnessFailures.Count -gt 0) { exit 1 }
exit 0
