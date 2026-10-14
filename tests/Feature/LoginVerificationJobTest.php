<?php

namespace Tests\Feature;

use App\Jobs\SendLoginVerificationEmail;
use App\Models\LoginEmailVerification;
use App\Models\User;
use App\Services\LoginEmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginVerificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_actual_login_mime_contains_only_current_recipient_code_expiry_and_request_reference(): void
    {
        [$user, $row, $code] = $this->challenge();
        $job = unserialize(serialize(new SendLoginVerificationEmail($row->id, $row->challenge_id)));
        $before = $this->snapshot();

        app()->call([$job, 'handle']);

        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages->sole()->getOriginalMessage();
        $this->assertSame('Your LeggTix login code', $mail->getSubject());
        $this->assertSame($user->email, $mail->getTo()[0]->getAddress());
        foreach ([$mail->getHtmlBody(), $mail->getTextBody()] as $body) {
            $this->assertStringContainsString($code, $body);
            $this->assertStringContainsString($row->challenge_id, $body);
            $this->assertStringContainsString($row->expires_at->format('j F Y, H:i:s').' UTC', $body);
        }
        foreach ([$user->password, $row->encrypted_code, $row->code_hash, $row->credential_fingerprint] as $secret) {
            $this->assertStringNotContainsString($secret, $mail->toString());
        }
        foreach (['challenge_id', 'credential_fingerprint', 'code_hash', 'encrypted_code'] as $field) {
            $this->assertArrayNotHasKey($field, $row->toArray());
        }
        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([10, 30], $job->backoff());
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('staleStates')]
    public function test_stale_login_delivery_is_suppressed_without_database_side_effects(string $state): void
    {
        [$user, $row, $code] = $this->challenge();
        $job = new SendLoginVerificationEmail($row->id, $row->challenge_id);
        if ($state === 'deleted') {
            $row->delete();
            $user->delete();
        } elseif ($state === 'rotated') {
            $row->forceFill(['issued_at' => $row->issued_at->subMinute()->subSecond()])->save();
            app(LoginEmailVerificationService::class)->begin(['email' => $user->email, 'password' => 'password']);
        } elseif ($state === 'consumed') {
            app(LoginEmailVerificationService::class)->verify($row->challenge_id, $code);
        } elseif ($state === 'exhausted') {
            $row->forceFill(['attempts' => 5, 'code_hash' => null, 'encrypted_code' => null])->save();
        } elseif ($state === 'expired') {
            DB::table('login_email_verifications')->where('id', $row->id)->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        } else {
            $user->forceFill([$state => $state === 'email' ? 'changed@example.test' : Hash::make('changed-password')])->save();
        }
        $before = $this->snapshot();

        app()->call([$job, 'handle']);

        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertSame($before, $this->snapshot());
    }

    public static function staleStates(): array
    {
        return [['deleted'], ['rotated'], ['consumed'], ['exhausted'], ['expired'], ['password'], ['email']];
    }

    public function test_pruning_retains_recent_consumed_challenge_cooldown_and_live_devices_then_removes_only_terminal_rows(): void
    {
        [$recent, $recentRow, $recentCode] = $this->challenge();
        app(LoginEmailVerificationService::class)->verify($recentRow->challenge_id, $recentCode, true);
        [$old, $oldRow, $oldCode] = $this->challenge();
        app(LoginEmailVerificationService::class)->verify($oldRow->challenge_id, $oldCode, true);
        DB::table('login_email_verifications')->where('id', $oldRow->id)->update(['issued_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 61 SECOND)')]);
        DB::table('trusted_devices')->where('user_id', $old->id)->update(['revoked_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        [, $expiredRow] = $this->challenge();
        DB::table('login_email_verifications')->where('id', $expiredRow->id)->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);
        [$expiredTrust, $trustRow, $trustCode] = $this->challenge();
        app(LoginEmailVerificationService::class)->verify($trustRow->challenge_id, $trustCode, true);
        DB::table('trusted_devices')->where('user_id', $expiredTrust->id)->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);

        $this->artisan('login-email:prune')->expectsOutput('Pruned 2 login verifications and 2 trusted devices.')->assertExitCode(0);

        $this->assertDatabaseHas('login_email_verifications', ['id' => $recentRow->id]);
        $this->assertDatabaseHas('trusted_devices', ['user_id' => $recent->id, 'revoked_at' => null]);
        $this->assertDatabaseMissing('login_email_verifications', ['id' => $oldRow->id]);
        $this->assertDatabaseMissing('login_email_verifications', ['id' => $expiredRow->id]);
        $this->artisan('login-email:prune')->expectsOutput('Pruned 0 login verifications and 0 trusted devices.')->assertExitCode(0);
    }

    private function challenge(): array
    {
        $user = User::factory()->create();
        app(LoginEmailVerificationService::class)->begin(['email' => $user->email, 'password' => 'password']);
        $row = LoginEmailVerification::where('user_id', $user->id)->sole();

        return [$user, $row, Crypt::decryptString($row->encrypted_code)];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'login_email_verifications', 'trusted_devices', 'personal_access_tokens', 'events', 'reservations', 'waitlist_entries'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
