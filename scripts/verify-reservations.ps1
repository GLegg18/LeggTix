[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$taskRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskFixture = $null
$taskFixtureJson = $null
$taskClient = $null
$taskRecoveryFile = $null
$taskFailed = $false
$taskCleanupFailed = $false

function Assert-Issue9 {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

function Invoke-Issue9Http {
    param(
        [string] $Label,
        [string] $EventId,
        [int] $Expected,
        [string] $Token = '',
        [string] $Body = '{}',
        [string] $ContentType = 'application/json',
        [string] $Code = '',
        [switch] $NoBody,
        [switch] $Multipart,
        [switch] $Quiet
    )
    $request = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Post, "http://localhost:8000/api/events/$EventId/reservations")
    $response = $null
    try {
        if ($Token -ne '') { $request.Headers.Authorization = [System.Net.Http.Headers.AuthenticationHeaderValue]::new('Bearer', $Token) }
        if ($Multipart) {
            # A fieldless multipart body exercises PHP's real request parsing.
            $request.Content = [System.Net.Http.MultipartFormDataContent]::new('leggtix-live-boundary')
        } elseif (-not $NoBody) {
            $request.Content = [System.Net.Http.StringContent]::new($Body, [System.Text.Encoding]::UTF8, $ContentType)
        }
        $response = $taskClient.SendAsync($request).GetAwaiter().GetResult()
        $status = [int] $response.StatusCode
        Assert-Issue9 ($status -eq $Expected) "$Label expected $Expected, got $status. Response bodies and bearer tokens are omitted from logs."
        $json = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult() | ConvertFrom-Json
        if ($Code -ne '') { Assert-Issue9 ($json.code -eq $Code) "$Label returned an unexpected machine code." }
        if (-not $Quiet) { Write-Host "PASS $Label HTTP$status $Code" }
        return [pscustomobject]@{ Json = $json; Status = $status; RetryAfter = $response.Headers.RetryAfter }
    } finally {
        if ($null -ne $response) { $response.Dispose() }
        $request.Dispose()
    }
}

function Invoke-Issue9Login {
    param([string] $Email, [int] $Expected)
    $taskLoginRequest = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Post, 'http://localhost:8000/api/login')
    $taskLoginResponse = $null
    try {
        $taskLoginBody = @{ email = $Email; password = 'wrong-password-for-review' } | ConvertTo-Json -Compress
        $taskLoginRequest.Content = [System.Net.Http.StringContent]::new($taskLoginBody, [System.Text.Encoding]::UTF8, 'application/json')
        $taskLoginResponse = $taskClient.SendAsync($taskLoginRequest).GetAwaiter().GetResult()
        Assert-Issue9 ([int]$taskLoginResponse.StatusCode -eq $Expected) "Login regression expected HTTP$Expected. Response bodies and bearer tokens are omitted from logs."
        if ($Expected -eq 429) {
            Assert-Issue9 ($null -ne $taskLoginResponse.Headers.RetryAfter) 'Login rate limiting must return Retry-After.'
        } else {
            $taskLoginJson = $taskLoginResponse.Content.ReadAsStringAsync().GetAwaiter().GetResult() | ConvertFrom-Json
            Assert-Issue9 ($taskLoginJson.errors.email.Count -gt 0) 'Incorrect credentials must return an email validation error.'
        }
    } finally {
        if ($null -ne $taskLoginResponse) { $taskLoginResponse.Dispose() }
        $taskLoginRequest.Dispose()
    }
}

Push-Location $taskRoot
try {
    Add-Type -AssemblyName System.Net.Http
    $taskClient = [System.Net.Http.HttpClient]::new()
    $taskClient.Timeout = [TimeSpan]::FromSeconds(15)
    $taskHealth = $taskClient.GetAsync('http://localhost:8000/up').GetAwaiter().GetResult()
    Assert-Issue9 ([int]$taskHealth.StatusCode -eq 200) 'The local app is not healthy. Follow docs/reservation-manual-test-plan.md step 1.'
    $taskHealth.Dispose()
    $taskFixtureJson = & docker compose exec -T app php scripts/support/verify-reservations.php create
    Assert-Issue9 ($LASTEXITCODE -eq 0) 'Local fixture creation failed.'
    $taskFixture = $taskFixtureJson | ConvertFrom-Json
    Assert-Issue9 ($taskFixture.tag -match '^[a-f0-9]{24}$') 'Fixture recovery tag is invalid.'
    $taskRecoveryDirectory = Join-Path $taskRoot 'storage/app/private'
    $null = New-Item -ItemType Directory -Force -Path $taskRecoveryDirectory
    $taskRecoveryFile = Join-Path $taskRecoveryDirectory ("issue9-live-$($taskFixture.tag).json")
    [pscustomobject]@{
        tag = $taskFixture.tag; events = $taskFixture.events; users = $taskFixture.users; type_id = $taskFixture.type_id
    } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $taskRecoveryFile -Encoding UTF8
    Write-Host "Local runtime PHP$($taskFixture.versions.php) MySQL$($taskFixture.versions.mysql) cache=$($taskFixture.versions.cache)"
    $taskAvailable = [string]$taskFixture.events.available

    $taskActorEmail = "issue9.actor.$($taskFixture.tag)@example.test"
    # Keep the script ASCII-compatible with Windows PowerShell's file decoding.
    $taskActorAlias = 'issue9.{0}ctor.{1}@example.test' -f [char]0x00E1, $taskFixture.tag
    foreach ($taskLoginEmail in @($taskActorEmail, $taskActorAlias, $taskActorEmail.ToUpperInvariant(), "  $taskActorAlias  ", $taskActorAlias)) {
        Invoke-Issue9Login -Email $taskLoginEmail -Expected 422
    }
    Invoke-Issue9Login -Email $taskActorEmail -Expected 429
    Invoke-Issue9Login -Email $taskActorAlias -Expected 429
    Invoke-Issue9Login -Email "issue9.other.$($taskFixture.tag)@example.test" -Expected 422
    Write-Host 'PASS Redis-login-throttle: equivalent MySQL spellings share five attempts; another account remains independent.'

    $null = Invoke-Issue9Http -Label guest -EventId $taskAvailable -Expected 401
    $null = Invoke-Issue9Http -Label invalid-token -EventId $taskAvailable -Expected 401 -Token 'invalid-token'
    $null = Invoke-Issue9Http -Label expired-token -EventId $taskAvailable -Expected 401 -Token $taskFixture.expired_token
    $null = Invoke-Issue9Http -Label holder-override -EventId $taskAvailable -Expected 422 -Token $taskFixture.token -Body (@{user_id=$taskFixture.users.other}|ConvertTo-Json -Compress)
    # Query strings are attached after the reservation path separately below.
    $taskQueryRequest = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Post, "http://localhost:8000/api/events/$taskAvailable/reservations?user_id=$($taskFixture.users.other)")
    $taskQueryRequest.Headers.Authorization = [System.Net.Http.Headers.AuthenticationHeaderValue]::new('Bearer', $taskFixture.token)
    $taskQueryRequest.Content = [System.Net.Http.StringContent]::new('{}', [System.Text.Encoding]::UTF8, 'application/json')
    $taskQueryResponse = $taskClient.SendAsync($taskQueryRequest).GetAwaiter().GetResult()
    Assert-Issue9 ([int]$taskQueryResponse.StatusCode -eq 422) 'Query holder override must be rejected.'
    $taskQueryResponse.Dispose(); $taskQueryRequest.Dispose()
    Write-Host 'PASS query-holder-override HTTP422'
    $null = Invoke-Issue9Http -Label malformed-json -EventId $taskAvailable -Expected 400 -Token $taskFixture.token -Body '{"user_id":'
    $null = Invoke-Issue9Http -Label array-json -EventId $taskAvailable -Expected 422 -Token $taskFixture.token -Body '[]'
    $null = Invoke-Issue9Http -Label plain-text -EventId $taskAvailable -Expected 415 -Token $taskFixture.token -Body 'user_id=999999' -ContentType 'text/plain'
    $null = Invoke-Issue9Http -Label real-fieldless-multipart -EventId $taskAvailable -Expected 415 -Token $taskFixture.token -Multipart

    $taskCreated = Invoke-Issue9Http -Label create -EventId $taskAvailable -Expected 201 -Token $taskFixture.token
    $taskFields = @($taskCreated.Json.data.PSObject.Properties.Name | Sort-Object)
    Assert-Issue9 (($taskFields -join ',') -eq 'cancelled_at,created_at,event_id,id,status,updated_at,user_id') 'Success resource fields differ from the public contract.'
    Assert-Issue9 ($taskCreated.Json.data.user_id -eq $taskFixture.users.actor -and $taskCreated.Json.data.event_id -eq $taskFixture.events.available) 'Success resource has the wrong holder or event.'
    Assert-Issue9 ($taskCreated.Json.data.status -eq 'confirmed' -and $null -eq $taskCreated.Json.data.cancelled_at) 'Reservation is not confirmed.'
    $null = Invoke-Issue9Http -Label replay -EventId $taskAvailable -Expected 409 -Token $taskFixture.token -Code already_reserved
    $null = Invoke-Issue9Http -Label full -EventId $taskAvailable -Expected 409 -Token $taskFixture.other_token -Code full
    $null = Invoke-Issue9Http -Label bodyless-no-content-type -EventId ([string]$taskFixture.events.bodyless) -Expected 201 -Token $taskFixture.token -NoBody
    $null = Invoke-Issue9Http -Label draft -EventId ([string]$taskFixture.events.draft) -Expected 409 -Token $taskFixture.token -Code event_not_bookable
    $null = Invoke-Issue9Http -Label past -EventId ([string]$taskFixture.events.past) -Expected 409 -Token $taskFixture.token -Code event_not_bookable
    $null = Invoke-Issue9Http -Label existing-waiter -EventId ([string]$taskFixture.events.waiting) -Expected 409 -Token $taskFixture.other_token -Code already_waitlisted
    $null = Invoke-Issue9Http -Label queue-priority -EventId ([string]$taskFixture.events.waiting) -Expected 409 -Token $taskFixture.token -Code queue_has_priority
    $null = Invoke-Issue9Http -Label missing-event -EventId 999999999999 -Expected 404 -Token $taskFixture.token
    $null = Invoke-Issue9Http -Label malformed-event-id -EventId not-an-event -Expected 404 -Token $taskFixture.token

    for ($taskAttempt = 0; $taskAttempt -lt 30; $taskAttempt++) {
        $null = Invoke-Issue9Http -Label throttle-fixture -EventId ([string]$taskFixture.events.draft) -Expected 409 -Token $taskFixture.throttle_token -Code event_not_bookable -Quiet
    }
    $taskLimited = Invoke-Issue9Http -Label Redis-actor-throttle -EventId ([string]$taskFixture.events.draft) -Expected 429 -Token $taskFixture.throttle_token
    Assert-Issue9 ($null -ne $taskLimited.RetryAfter) 'Rate limiting must return Retry-After.'

    $taskInspectionJson = $taskFixtureJson | & docker compose exec -T app php scripts/support/verify-reservations.php inspect
    Assert-Issue9 ($LASTEXITCODE -eq 0) 'Database invariant inspection failed.'
    $taskInspection = $taskInspectionJson | ConvertFrom-Json
    Assert-Issue9 ($taskInspection.Count -eq 5) 'Expected exactly five local fixture events.'
    foreach ($taskEvent in $taskInspection) {
        $taskExpectedCount = if ($taskEvent.id -in @($taskFixture.events.available, $taskFixture.events.bodyless)) { 1 } else { 0 }
        Assert-Issue9 ($taskEvent.confirmed_count -eq $taskExpectedCount -and $taskEvent.confirmed_rows -eq $taskExpectedCount -and $taskEvent.confirmed_count -le $taskEvent.capacity) 'Stored reservation capacity/count invariant failed.'
        if ($taskExpectedCount -eq 1) { Assert-Issue9 ($taskEvent.holder_ids.Count -eq 1 -and $taskEvent.holder_ids[0] -eq $taskFixture.users.actor) 'Stored holder differs from authenticated actor.' }
        $taskExpectedWaiting = if ($taskEvent.id -eq $taskFixture.events.waiting) { 1 } else { 0 }
        Assert-Issue9 ($taskEvent.waiting_count -eq $taskExpectedWaiting) 'Rejected booking changed waitlist state.'
    }
    Write-Host 'PASS database invariants: two confirmed places, exact holders, one preserved waiter, no count drift.'
} catch {
    $taskFailed = $true
    Write-Host "Live acceptance failed: $($_.Exception.Message)" -ForegroundColor Red
} finally {
    if ($null -ne $taskFixture) {
        try {
            $taskCleanupJson = $taskFixtureJson | & docker compose exec -T app php scripts/support/verify-reservations.php cleanup
            Assert-Issue9 ($LASTEXITCODE -eq 0) 'Fixture cleanup command failed.'
            $taskCleanup = $taskCleanupJson | ConvertFrom-Json
            foreach ($taskCount in $taskCleanup.PSObject.Properties) { Assert-Issue9 ($taskCount.Value -eq 0) 'Fixture cleanup left records behind.' }
            if ($null -ne $taskRecoveryFile) { Remove-Item -LiteralPath $taskRecoveryFile -ErrorAction Stop }
            Write-Host 'PASS cleanup: fixture events/users/type/reservations/waitlist entries/tokens all zero.'
        } catch {
            $taskCleanupFailed = $true
            Write-Host "Fixture cleanup failed: $($_.Exception.Message)" -ForegroundColor Red
            if ($null -ne $taskRecoveryFile -and (Test-Path -LiteralPath $taskRecoveryFile)) {
                $taskQuotedRecovery = $taskRecoveryFile.Replace("'", "''")
                Write-Host "Token-free recovery identities are saved. From the repository root run: Get-Content -LiteralPath '$taskQuotedRecovery' -Raw | docker compose exec -T app php scripts/support/verify-reservations.php cleanup"
                Write-Host "After it reports all zero counts, remove the recovery file: Remove-Item -LiteralPath '$taskQuotedRecovery'"
            }
        }
    }
    if ($null -ne $taskClient) { $taskClient.Dispose() }
    Pop-Location
}

if ($taskFailed -or $taskCleanupFailed) { exit 1 }
Write-Host 'Live reservation acceptance passed. Existing services and unrelated data were preserved.'
exit 0
