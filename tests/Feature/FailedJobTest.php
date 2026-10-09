<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FailedJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_failed_jobs_succeeds_when_none_have_failed(): void
    {
        $this->artisan('queue:failed')
            ->expectsOutputToContain('No failed jobs found.')
            ->assertExitCode(0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_configured_provider_stores_lists_and_forgets_only_the_requested_failed_job(): void
    {
        $failer = app('queue.failer');
        $this->assertInstanceOf(DatabaseUuidFailedJobProvider::class, $failer);
        $uuid = (string) Str::uuid();
        $payload = $this->payload($uuid);

        $this->assertSame($uuid, $failer->log('redis', 'acceptance-failures', $payload, new RuntimeException('Synthetic acceptance failure')));
        $stored = $failer->find($uuid);
        $this->assertNotNull($stored);
        $this->assertSame($uuid, $stored->id);
        $this->assertSame('redis', $stored->connection);
        $this->assertSame('acceptance-failures', $stored->queue);
        $this->assertSame($payload, $stored->payload);
        $this->assertStringContainsString('Synthetic acceptance failure', $stored->exception);
        $this->assertNotEmpty($stored->failed_at);

        $otherUuid = (string) Str::uuid();
        $failer->log('redis', 'other-failures', $this->payload($otherUuid), new RuntimeException('Unrelated failure'));
        $this->assertCount(2, $failer->all());
        $this->assertSame([$uuid], $failer->ids('acceptance-failures'));
        $this->assertSame(0, Artisan::call('queue:failed'));
        $output = Artisan::output();
        $this->assertStringContainsString($uuid, $output);
        $this->assertStringContainsString('redis@acceptance-failures', $output);
        $this->assertStringContainsString('AcceptanceFailureProbe@handle', $output);

        $this->artisan('queue:forget', ['id' => $uuid])
            ->expectsOutputToContain('Failed job deleted successfully.')
            ->assertExitCode(0);
        $this->assertNull($failer->find($uuid));
        $this->assertSame($otherUuid, $failer->find($otherUuid)->id);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->artisan('queue:forget', ['id' => $uuid])
            ->expectsOutputToContain('No failed job matches the given ID.')
            ->assertExitCode(1);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_duplicate_failed_job_uuid_cannot_overwrite_the_original_record(): void
    {
        $failer = app('queue.failer');
        $uuid = (string) Str::uuid();
        $original = $this->payload($uuid);
        $failer->log('redis', 'acceptance-failures', $original, new RuntimeException('Original failure record'));

        $this->assertThrows(
            fn () => $failer->log('redis', 'replacement-queue', $this->payload($uuid, 'replacement'), new RuntimeException('Replacement failure')),
            UniqueConstraintViolationException::class,
        );

        $this->assertDatabaseCount('failed_jobs', 1);
        $stored = $failer->find($uuid);
        $this->assertSame($original, $stored->payload);
        $this->assertSame('acceptance-failures', $stored->queue);
        $this->assertStringContainsString('Original failure record', $stored->exception);
    }

    private function payload(string $uuid, string $marker = 'original'): string
    {
        return json_encode([
            'uuid' => $uuid,
            'job' => 'AcceptanceFailureProbe@handle',
            'data' => ['marker' => $marker],
        ], JSON_THROW_ON_ERROR);
    }
}
