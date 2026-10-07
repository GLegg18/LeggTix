<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    public function test_migrations_can_roll_back_in_foreign_key_order_and_rerun(): void
    {
        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            $this->assertSame(6, DB::table('migrations')->count());
            $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);
            foreach (['personal_access_tokens', 'waitlist_entries', 'reservations', 'events', 'event_types', 'users'] as $table) {
                $this->assertFalse(Schema::hasTable($table), $table);
            }
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            foreach (['users', 'event_types', 'events', 'reservations', 'waitlist_entries', 'personal_access_tokens'] as $table) {
                $this->assertTrue(Schema::hasTable($table), $table);
            }
            $this->assertSame(6, DB::table('migrations')->count());
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $this->assertSame(6, DB::table('migrations')->count());
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
