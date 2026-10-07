# Initialize PATH inside each child as Windows PowerShell can inherit a
# different PATH spelling/value from PowerShell 7 hosts or restricted shells.
$env:PATH = $env:LEGGTIX_WRAPPER_TEST_PATH
& (Join-Path $PSScriptRoot '../test.ps1') @args
exit $LASTEXITCODE
