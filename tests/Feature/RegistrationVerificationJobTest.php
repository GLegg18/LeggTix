<?php

namespace Tests\Feature;

use App\Jobs\SendRegistrationVerificationEmail;
use App\Models\RegistrationEmailVerification;
use App\Services\RegistrationEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesRegistrationProofs;
use Tests\TestCase;

class RegistrationVerificationJobTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRegistrationProofs;

    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->transport = Mail::mailer()->getSymfonyTransport();
    }

    public function test_actual_verification_mime_contains_code_expiry_and_challenge_but_job_contains_only_ids(): void
    {
        app(RegistrationEmailVerificationService::class)->request('customer@example.test');
        $row = RegistrationEmailVerification::sole();
        $code = Crypt::decryptString($row->encrypted_code);
        $job = unserialize(serialize(new SendRegistrationVerificationEmail($row->id, $row->challenge_id)));
        $before = DB::table('registration_email_verifications')->first();

        $job->handle();

        $this->assertCount(1, $this->transport->messages());
        $mail = $this->transport->messages()->sole()->getOriginalMessage();
        $this->assertSame('Verify your email for LeggTix', $mail->getSubject());
        $this->assertSame('customer@example.test', $mail->getTo()[0]->getAddress());
        foreach ([$mail->getHtmlBody(), $mail->getTextBody()] as $part) {
            foreach ([$code, $row->challenge_id, $row->expires_at->format('j F Y, H:i:s').' UTC'] as $value) {
                $this->assertStringContainsString($value, $part);
            }
        }
        foreach ([$row->encrypted_code, $row->code_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $mail->toString());
        }
        $this->assertEquals($before, DB::table('registration_email_verifications')->first());
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff());
        $this->assertSame(30, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    #[DataProvider('staleStates')]
    public function test_stale_job_is_suppressed_without_mutating_verification_or_inventory(string $state): void
    {
        app(RegistrationEmailVerificationService::class)->request('customer@example.test');
        $row = RegistrationEmailVerification::sole();
        $job = new SendRegistrationVerificationEmail($row->id, $row->challenge_id);
        if ($state === 'deleted') {
            $row->delete();
        } elseif ($state === 'rotated') {
            app(RegistrationEmailVerificationService::class)->request('customer@example.test');
        } elseif ($state === 'verified' || $state === 'consumed') {
            $proof = app(RegistrationEmailVerificationService::class)->verify($row->challenge_id, Crypt::decryptString($row->encrypted_code));
            if ($state === 'consumed') {
                DB::transaction(fn () => app(RegistrationEmailVerificationService::class)->consume('customer@example.test', $proof['registration_token']));
            }
        } elseif ($state === 'exhausted') {
            $row->forceFill(['attempts' => 5, 'code_hash' => null, 'encrypted_code' => null])->save();
        } else {
            DB::table('registration_email_verifications')->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        }
        $before = DB::table('registration_email_verifications')->orderBy('id')->get()->toJson();

        $job->handle();

        $this->assertCount(0, $this->transport->messages());
        $this->assertSame($before, DB::table('registration_email_verifications')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function staleStates(): array
    {
        return [['deleted'], ['rotated'], ['verified'], ['consumed'], ['exhausted'], ['expired']];
    }

    public function test_pruning_keeps_live_code_and_live_proof_after_old_code_expiry_and_is_repeatable(): void
    {
        app(RegistrationEmailVerificationService::class)->request('live-code@example.test');
        app(RegistrationEmailVerificationService::class)->request('expired-code@example.test');
        DB::table('registration_email_verifications')->where('email', 'expired-code@example.test')
            ->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        $this->registrationProof('live-proof@example.test');
        DB::table('registration_email_verifications')->where('email', 'live-proof@example.test')
            ->update(['expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE)')]);
        $this->registrationProof('expired-proof@example.test');
        DB::table('registration_email_verifications')->where('email', 'expired-proof@example.test')
            ->update(['token_expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        $proof = $this->registrationProof('consumed@example.test');
        DB::transaction(fn () => app(RegistrationEmailVerificationService::class)->consume('consumed@example.test', $proof));

        $this->artisan('registration-email:prune')->expectsOutput('Pruned 3 registration email verifications.')->assertExitCode(0);

        $this->assertEqualsCanonicalizing(['live-code@example.test', 'live-proof@example.test'],
            DB::table('registration_email_verifications')->pluck('email')->all());
        $this->artisan('registration-email:prune')->expectsOutput('Pruned 0 registration email verifications.')->assertExitCode(0);
    }
}
