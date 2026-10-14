<?php

namespace Tests\Feature;

use App\Actions\ReserveEventAction;
use App\Jobs\SendReservationConfirmationEmail;
use App\Jobs\SendWelcomeEmail;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class TransactionalEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $this->transport);
    }

    public function test_welcome_job_reads_current_recipient_and_renders_safe_multipart_content_without_secrets(): void
    {
        $user = User::factory()->create(['name' => 'Original Customer', 'email' => 'old@example.test']);
        $job = unserialize(serialize(new SendWelcomeEmail($user->id)));
        $currentName = '<img src=x onerror=alert(1)> Ada & "Friends"';
        $user->forceFill(['name' => $currentName, 'email' => 'current@example.test'])->save();
        $token = $user->createToken('must-not-appear')->plainTextToken;
        $before = $this->snapshot();

        $job->handle();

        $mail = $this->onlyMessage();
        $this->assertSame('Welcome to LeggTix', $mail->getSubject());
        $this->assertSame(['current@example.test'], array_map(fn ($address): string => $address->getAddress(), $mail->getTo()));
        $this->assertStringContainsString(e($currentName), $mail->getHtmlBody());
        $this->assertStringNotContainsString('<img src=x', $mail->getHtmlBody());
        $this->assertStringContainsString($currentName, $mail->getTextBody());
        foreach ([$user->password, $token, 'must-not-appear'] as $secret) {
            $this->assertStringNotContainsString($secret, $mail->toString());
        }
        $this->assertSame($before, $this->snapshot(), 'Email delivery must not mutate users, tokens or ticket inventory.');
    }

    public function test_deleted_signup_recipient_is_suppressed_at_job_execution(): void
    {
        $user = User::factory()->create();
        $job = new SendWelcomeEmail($user->id);
        $user->delete();
        $before = $this->snapshot();

        $job->handle();

        $this->assertCount(0, $this->transport->messages());
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('optionalEndTimes')]
    public function test_booking_job_reads_current_holder_event_details_and_local_times_with_escaped_html(bool $hasEnd): void
    {
        [$actor, $event, $reservation] = $this->booking();
        $job = unserialize(serialize(new SendReservationConfirmationEmail($reservation->id)));
        $actor->forceFill(['name' => '<b>Updated & Customer</b>', 'email' => 'updated-holder@example.test'])->save();
        $event->forceFill([
            'name' => '<script>alert(1)</script> Live & "Loud"',
            'venue' => '<img src=x onerror=alert(2)> Hall & "Garden"',
            'starts_at' => '2099-07-14 18:30:00',
            'ends_at' => $hasEnd ? '2099-07-14 20:00:00' : null,
            'timezone' => 'Europe/London',
        ])->save();
        $before = $this->snapshot();

        $job->handle();

        $mail = $this->onlyMessage();
        $this->assertSame('Your LeggTix reservation is confirmed', $mail->getSubject());
        $this->assertSame(['updated-holder@example.test'], array_map(fn ($address): string => $address->getAddress(), $mail->getTo()));
        foreach ([$actor->name, $event->name, $event->venue] as $value) {
            $this->assertStringContainsString(e($value), $mail->getHtmlBody());
            $this->assertStringContainsString($value, $mail->getTextBody());
        }
        $this->assertStringNotContainsString('<script>', $mail->getHtmlBody());
        $this->assertStringNotContainsString('<img src=x', $mail->getHtmlBody());
        foreach ([$mail->getHtmlBody(), $mail->getTextBody()] as $body) {
            $this->assertStringContainsString('LT-'.$reservation->id, $body);
            $this->assertStringContainsString('19:30 BST (+01:00)', $body);
            $this->assertStringContainsString('14 July 2099', $body);
            $this->assertStringContainsString('Europe/London', $body);
            if ($hasEnd) {
                $this->assertStringContainsString('21:00 BST (+01:00)', $body);
            } else {
                $this->assertStringNotContainsString('Ends', $body);
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function optionalEndTimes(): array
    {
        return ['has end' => [true], 'no end' => [false]];
    }

    #[DataProvider('staleBookings')]
    public function test_stale_booking_confirmation_is_suppressed_without_inventory_writes(string $scenario): void
    {
        [$actor, $event, $reservation] = $this->booking();
        $job = new SendReservationConfirmationEmail($reservation->id);
        if ($scenario === 'deleted attempt') {
            $reservation->delete();
            DB::table('events')->where('id', $event->id)->update(['confirmed_count' => 0]);
        } elseif ($scenario === 'cancelled attempt') {
            $reservation->forceFill(['status' => 'cancelled', 'cancelled_at' => now('UTC')])->save();
            DB::table('events')->where('id', $event->id)->update(['confirmed_count' => 0]);
        } elseif ($scenario === 'started event') {
            DB::table('events')->where('id', $event->id)->update([
                'starts_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)'),
            ]);
        } else {
            $event->forceFill(['status' => $scenario])->save();
        }
        $before = $this->snapshot();

        $job->handle();

        $this->assertCount(0, $this->transport->messages());
        $this->assertSame($before, $this->snapshot());
    }

    public static function staleBookings(): array
    {
        return array_map(fn (string $scenario): array => [$scenario], [
            'deleted attempt', 'cancelled attempt', 'draft', 'cancelled', 'completed', 'started event',
        ]);
    }

    public function test_retrying_cancelled_attempt_does_not_confirm_a_later_rebooking(): void
    {
        [$actor, $event, $old] = $this->booking();
        $oldJob = new SendReservationConfirmationEmail($old->id);
        $old->forceFill(['status' => 'cancelled', 'cancelled_at' => now('UTC')])->save();
        DB::table('events')->where('id', $event->id)->update(['confirmed_count' => 0]);
        $new = app(ReserveEventAction::class)->execute($actor, $event->id);
        $before = $this->snapshot();

        $oldJob->handle();
        $this->assertCount(0, $this->transport->messages());
        (new SendReservationConfirmationEmail($new->id))->handle();

        $mail = $this->onlyMessage();
        $this->assertStringContainsString('LT-'.$new->id, $mail->getTextBody());
        $this->assertStringNotContainsString('LT-'.$old->id, $mail->getTextBody());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertDatabaseCount('reservations', 2);
    }

    #[DataProvider('jobKinds')]
    public function test_repeated_job_execution_cannot_allocate_or_change_any_business_record(string $kind): void
    {
        [$actor, $event, $reservation] = $this->booking();
        $job = $kind === 'welcome' ? new SendWelcomeEmail($actor->id) : new SendReservationConfirmationEmail($reservation->id);
        $before = $this->snapshot();

        $job->handle();
        $job->handle();

        $this->assertCount(2, $this->transport->messages(), 'Repeated job execution may repeat SMTP delivery.');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $event->fresh()->confirmed_count);
        $this->assertSame(1, $event->reservations()->where('status', 'confirmed')->count());
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([10, 30], $job->backoff());
        $this->assertTrue($job->failOnTimeout);
    }

    #[DataProvider('jobKinds')]
    public function test_transport_failure_is_retryable_and_never_changes_committed_business_records(string $kind): void
    {
        [$actor, $event, $reservation] = $this->booking();
        $job = $kind === 'welcome' ? new SendWelcomeEmail($actor->id) : new SendReservationConfirmationEmail($reservation->id);
        $before = $this->snapshot();
        $failedTransport = Mockery::mock(TransportInterface::class);
        $failedTransport->shouldReceive('send')->once()->andThrow(new RuntimeException('Injected SMTP outage'));
        Mail::mailer()->setSymfonyTransport($failedTransport);

        $this->assertThrows(fn () => $job->handle(), RuntimeException::class);
        $this->assertSame($before, $this->snapshot());
        Mail::mailer()->setSymfonyTransport($this->transport);
        $job->handle();

        $this->assertCount(1, $this->transport->messages());
        $this->assertSame($before, $this->snapshot());
    }

    public static function jobKinds(): array
    {
        return ['welcome' => ['welcome'], 'confirmation' => ['confirmation']];
    }

    private function onlyMessage(): Email
    {
        $this->assertCount(1, $this->transport->messages());
        $mail = $this->transport->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $mail);
        $this->assertNotEmpty($mail->getHtmlBody());
        $this->assertNotEmpty($mail->getTextBody());

        return $mail;
    }

    private function booking(): array
    {
        $actor = User::factory()->create(['email' => 'holder@example.test']);
        $event = Event::factory()->published()->create(['capacity' => 1]);
        $reservation = app(ReserveEventAction::class)->execute($actor, $event->id);

        return [$actor, $event, $reservation];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'personal_access_tokens', 'events', 'event_types', 'reservations', 'waitlist_entries'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
