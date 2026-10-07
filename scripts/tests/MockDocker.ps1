# A native docker.cmd shim invokes this script in a child PowerShell process.
# It never contacts Docker or opens a database connection.
$mockArguments = @($args)
$mockCall = @{ arguments = $mockArguments; mode = $env:LEGGTIX_WRAPPER_TEST_MODE }
[System.IO.File]::AppendAllText(
    $env:LEGGTIX_WRAPPER_TEST_LOG,
    ($mockCall | ConvertTo-Json -Depth 4 -Compress) + [Environment]::NewLine
)

if ($mockArguments[0] -eq 'compose' -and $mockArguments[1] -eq 'version') {
    if ($env:LEGGTIX_WRAPPER_TEST_MODE -eq 'ComposeUnavailable') { exit 41 }
    Write-Output 'Docker Compose version v2.mock'
    exit 0
}

if ($mockArguments[0] -eq 'info') {
    if ($env:LEGGTIX_WRAPPER_TEST_MODE -eq 'EngineUnavailable') { exit 42 }
    if ($env:LEGGTIX_WRAPPER_TEST_MODE -eq 'WindowsEngine') {
        Write-Output 'windows'
    } else {
        Write-Output 'linux'
    }
    exit 0
}

if ($mockArguments -contains 'run') {
    switch ($env:LEGGTIX_WRAPPER_TEST_MODE) {
        'TestFailure' { Write-Output 'Simulated failing PHPUnit test'; exit 23 }
        'BuildFailure' { Write-Output 'Simulated Docker image build failure'; exit 17 }
        'StartFailure' { Write-Output 'Simulated MySQL startup failure'; exit 19 }
        'CleanupAfterTestFailure' { Write-Output 'Simulated failing PHPUnit test'; exit 23 }
        'SuccessStderr' { [Console]::Error.WriteLine('Simulated Docker progress on stderr'); exit 0 }
        'TestFailureStderr' { [Console]::Error.WriteLine('Simulated failing test on stderr'); exit 23 }
    }
    Write-Output 'Simulated PHPUnit success'
    exit 0
}

if ($mockArguments -contains 'down') {
    if ($env:LEGGTIX_WRAPPER_TEST_MODE -in @('CleanupFailure', 'CleanupAfterTestFailure')) {
        Write-Output 'Simulated Docker cleanup failure'
        exit 29
    }
    exit 0
}

Write-Output 'Unexpected Docker invocation in regression fixture'
exit 99
