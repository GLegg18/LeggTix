<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANT = '2026-10-07 12:00:00.123456';

    private function timestamps(): array
    {
        return ['created_at' => self::INSTANT, 'updated_at' => self::INSTANT];
    }

    private function eventType(array $changes = []): int
    {
        return DB::table('event_types')->insertGetId(array_replace([
            'slug' => 'concert-'.uniqid(), 'name' => 'Concert', 'is_active' => true,
        ], $this->timestamps(), $changes));
    }

    private function event(array $changes = []): int
    {
        return DB::table('events')->insertGetId($this->eventRow($changes));
    }

    private function eventRow(array $changes = []): array
    {
        return array_replace([
            'owner_id' => User::factory()->organiser()->create()->id,
            'event_type_id' => $this->eventType(),
            'name' => 'Test concert', 'description' => 'Local test event', 'venue' => 'Test hall',
            'starts_at' => '2026-12-01 19:00:00.123456', 'ends_at' => null, 'timezone' => 'Europe/London',
            'capacity' => 2, 'confirmed_count' => 0, 'status' => 'draft',
        ], $this->timestamps(), $changes);
    }

    private function attemptRow(string $table, array $changes = []): array
    {
        $attributes = [
            'event_id' => $this->event(), 'user_id' => User::factory()->create()->id,
            'status' => $table === 'reservations' ? 'confirmed' : 'waiting',
            'cancelled_at' => null,
        ];

        if ($table === 'waitlist_entries') {
            $attributes += ['joined_at' => self::INSTANT, 'promoted_at' => null];
        }

        return array_replace($attributes, $this->timestamps(), $changes);
    }

    private function assertDatabaseRejects(callable $operation, int $mysqlError, ?string $constraint = null): void
    {
        try {
            $operation();
            $this->fail('MySQL must reject the invalid write.');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlError, $exception->errorInfo[1], $exception->getMessage());

            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
        }
    }

    public function test_five_domain_tables_have_utc_microsecond_datetime_fields_and_expected_storage(): void
    {
        $this->assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS timezone')->timezone);
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);

        $fields = [
            'users' => [], 'event_types' => [],
            'events' => ['starts_at', 'ends_at'],
            'reservations' => ['cancelled_at'],
            'waitlist_entries' => ['joined_at', 'promoted_at', 'cancelled_at'],
        ];
        foreach ($fields as $table => $additional) {
            $storage = DB::selectOne('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->assertSame('InnoDB', $storage->ENGINE, $table);
            $this->assertSame('utf8mb4_0900_ai_ci', $storage->TABLE_COLLATION, $table);
            foreach (array_merge(['created_at', 'updated_at'], $additional) as $field) {
                $column = DB::selectOne('SELECT DATA_TYPE, DATETIME_PRECISION, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $field]);
                $this->assertSame('datetime', $column->DATA_TYPE, "$table.$field");
                $this->assertSame(6, (int) $column->DATETIME_PRECISION, "$table.$field");
                if (in_array($field, ['created_at', 'updated_at', 'starts_at', 'joined_at'], true)) {
                    $this->assertSame('NO', $column->IS_NULLABLE, "$table.$field");
                }
            }
            $id = DB::selectOne('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'id']);
            $this->assertSame('bigint unsigned', $id->COLUMN_TYPE);
        }

        $id = $this->event(['starts_at' => '2099-12-01 19:00:00.123456']);
        $this->assertSame('2099-12-01 19:00:00.123456', DB::table('events')->find($id)->starts_at);
        $this->assertSame(self::INSTANT, DB::table('events')->find($id)->created_at);
    }

    public function test_database_role_default_and_all_exact_values_are_valid(): void
    {
        $attributes = array_replace(['name' => 'Default', 'email' => 'default@example.test', 'password' => 'a-test-hash'], $this->timestamps());
        $id = DB::table('users')->insertGetId($attributes);
        $this->assertSame('regular_user', DB::table('users')->find($id)->role);
        foreach (['regular_user', 'organiser', 'admin'] as $role) {
            DB::table('users')->where('id', $id)->update(['role' => $role]);
            $this->assertSame($role, DB::table('users')->find($id)->role);
        }
    }

    #[DataProvider('invalidRoles')]
    public function test_mysql_rejects_invalid_and_collation_equivalent_roles(string $role): void
    {
        $this->assertDatabaseRejects(fn () => DB::table('users')->insert(array_replace([
            'name' => 'Invalid role', 'email' => 'invalid@example.test', 'password' => 'a-test-hash', 'role' => $role,
        ], $this->timestamps())), 3819, 'users_role_check');
    }

    public static function invalidRoles(): array
    {
        return [[''], ['customer'], ['ADMIN'], ['Admin'], ['admin '], ['ádmin'], [' organiser']];
    }

    #[DataProvider('invalidEventValues')]
    public function test_mysql_rejects_invalid_event_status_capacity_count_and_schedule(array $changes, int $code, ?string $constraint): void
    {
        $row = $this->eventRow($changes);
        $this->assertDatabaseRejects(fn () => DB::table('events')->insert($row), $code, $constraint);
        $this->assertDatabaseCount('events', 0);
    }

    public static function invalidEventValues(): array
    {
        return [
            'unknown status' => [['status' => 'invalid'], 3819, 'events_status_check'],
            'uppercase status' => [['status' => 'PUBLISHED'], 3819, 'events_status_check'],
            'trailing space' => [['status' => 'draft '], 3819, 'events_status_check'],
            'accent equivalent' => [['status' => 'dräft'], 3819, 'events_status_check'],
            'zero capacity' => [['capacity' => 0], 3819, 'events_capacity_positive_check'],
            'negative capacity' => [['capacity' => -1], 1264, null],
            'overflow capacity' => [['capacity' => 4294967296], 1264, null],
            'exceeded capacity' => [['confirmed_count' => 3], 3819, 'events_confirmed_count_check'],
            'negative count' => [['confirmed_count' => -1], 1264, null],
            'end before start' => [['ends_at' => '2026-12-01 18:59:59.123456'], 3819, 'events_ends_after_starts_check'],
            'end equals start' => [['ends_at' => '2026-12-01 19:00:00.123456'], 3819, 'events_ends_after_starts_check'],
        ];
    }

    public function test_event_boundary_values_and_all_exact_statuses_are_accepted(): void
    {
        foreach (['draft', 'published', 'cancelled', 'completed'] as $status) {
            $id = $this->event(['status' => $status, 'capacity' => 1, 'confirmed_count' => 1, 'ends_at' => '2026-12-01 19:00:00.123457']);
            $this->assertSame($status, DB::table('events')->find($id)->status);
        }
        $id = $this->event(['capacity' => 4294967295, 'confirmed_count' => 4294967295]);
        $this->assertSame(4294967295, DB::table('events')->find($id)->capacity);
    }

    public function test_event_type_boolean_and_slug_uniqueness_are_enforced(): void
    {
        $this->eventType(['slug' => 'concert', 'is_active' => 0]);
        $this->assertDatabaseRejects(fn () => $this->eventType(['slug' => 'CONCERT']), 1062, 'event_types_slug_unique');
        $this->assertDatabaseRejects(fn () => $this->eventType(['is_active' => 2]), 3819, 'event_types_is_active_check');
        $this->assertDatabaseRejects(fn () => $this->eventType(['is_active' => -1]), 3819, 'event_types_is_active_check');
    }

    public function test_email_unique_index_rejects_case_equivalent_accounts(): void
    {
        User::factory()->create(['email' => 'customer@example.test']);
        $this->assertDatabaseRejects(fn () => DB::table('users')->insert(array_replace([
            'name' => 'Duplicate', 'email' => 'CUSTOMER@EXAMPLE.TEST', 'password' => 'a-test-hash',
        ], $this->timestamps())), 1062, 'users_email_unique');
    }

    #[DataProvider('invalidAttempts')]
    public function test_mysql_rejects_invalid_attempt_statuses_and_contradictory_timestamps(string $table, array $changes, ?string $constraint): void
    {
        $row = $this->attemptRow($table, $changes);
        $this->assertDatabaseRejects(fn () => DB::table($table)->insert($row), 3819, $constraint);
        $this->assertDatabaseCount($table, 0);
    }

    public static function invalidAttempts(): array
    {
        return [
            // Invalid statuses can violate both checks; MySQL may report either.
            'reservation unknown' => ['reservations', ['status' => 'waiting'], null],
            'reservation uppercase' => ['reservations', ['status' => 'CONFIRMED'], 'reservations_status_check'],
            'reservation trailing' => ['reservations', ['status' => 'confirmed '], null],
            'reservation accented' => ['reservations', ['status' => 'cónfirmed'], 'reservations_status_check'],
            'confirmed cancellation time' => ['reservations', ['cancelled_at' => self::INSTANT], 'reservations_cancelled_at_check'],
            'cancelled missing time' => ['reservations', ['status' => 'cancelled'], 'reservations_cancelled_at_check'],
            'waitlist unknown' => ['waitlist_entries', ['status' => 'left'], 'waitlist_status_check'],
            'waitlist uppercase' => ['waitlist_entries', ['status' => 'WAITING'], 'waitlist_status_check'],
            'waitlist trailing' => ['waitlist_entries', ['status' => 'waiting '], 'waitlist_status_check'],
            'waitlist accented' => ['waitlist_entries', ['status' => 'wáiting'], 'waitlist_status_check'],
            'waiting promoted time' => ['waitlist_entries', ['promoted_at' => self::INSTANT], 'waitlist_terminal_timestamps_check'],
            'waiting cancelled time' => ['waitlist_entries', ['cancelled_at' => self::INSTANT], 'waitlist_terminal_timestamps_check'],
            'promoted missing time' => ['waitlist_entries', ['status' => 'promoted'], 'waitlist_terminal_timestamps_check'],
            'cancelled missing time' => ['waitlist_entries', ['status' => 'cancelled'], 'waitlist_terminal_timestamps_check'],
            'promoted with both times' => ['waitlist_entries', ['status' => 'promoted', 'promoted_at' => self::INSTANT, 'cancelled_at' => self::INSTANT], 'waitlist_terminal_timestamps_check'],
            'cancelled with both times' => ['waitlist_entries', ['status' => 'cancelled', 'promoted_at' => self::INSTANT, 'cancelled_at' => self::INSTANT], 'waitlist_terminal_timestamps_check'],
        ];
    }

    public function test_reservations_preserve_cancelled_history_but_allow_one_active_per_event_and_user(): void
    {
        $row = $this->attemptRow('reservations');
        $first = DB::table('reservations')->insertGetId($row);
        $this->assertSame($row['user_id'], DB::table('reservations')->find($first)->active_user_id);
        $this->assertDatabaseRejects(fn () => DB::table('reservations')->insert($row), 1062, 'reservations_event_active_unique');
        DB::table('reservations')->where('id', $first)->update(['status' => 'cancelled', 'cancelled_at' => self::INSTANT]);
        $this->assertNull(DB::table('reservations')->find($first)->active_user_id);
        DB::table('reservations')->insert(array_replace($row, ['status' => 'cancelled', 'cancelled_at' => self::INSTANT]));
        $active = DB::table('reservations')->insertGetId($row);
        $this->assertDatabaseRejects(fn () => DB::table('reservations')->where('id', $first)->update([
            'status' => 'confirmed', 'cancelled_at' => null,
        ]), 1062, 'reservations_event_active_unique');
        DB::table('reservations')->insert(array_replace($row, ['event_id' => $this->event()]));
        DB::table('reservations')->insert(array_replace($row, ['user_id' => User::factory()->create()->id]));
        $this->assertSame($row['user_id'], DB::table('reservations')->find($active)->active_user_id);
        $this->assertSame(1, DB::table('reservations')->where('event_id', $row['event_id'])->where('active_user_id', $row['user_id'])->count());
        $this->assertDatabaseCount('reservations', 5);
    }

    public function test_waitlist_preserves_terminal_history_but_allows_one_waiting_attempt_per_event_and_user(): void
    {
        $row = $this->attemptRow('waitlist_entries');
        $first = DB::table('waitlist_entries')->insertGetId($row);
        $this->assertSame($row['user_id'], DB::table('waitlist_entries')->find($first)->waiting_user_id);
        $this->assertDatabaseRejects(fn () => DB::table('waitlist_entries')->insert($row), 1062, 'waitlist_event_waiting_unique');
        DB::table('waitlist_entries')->where('id', $first)->update(['status' => 'promoted', 'promoted_at' => self::INSTANT]);
        $this->assertNull(DB::table('waitlist_entries')->find($first)->waiting_user_id);
        DB::table('waitlist_entries')->insert(array_replace($row, ['status' => 'cancelled', 'cancelled_at' => self::INSTANT]));
        DB::table('waitlist_entries')->insert(array_replace($row, ['status' => 'promoted', 'promoted_at' => self::INSTANT]));
        $active = DB::table('waitlist_entries')->insertGetId($row);
        $this->assertDatabaseRejects(fn () => DB::table('waitlist_entries')->where('id', $first)->update([
            'status' => 'waiting', 'promoted_at' => null,
        ]), 1062, 'waitlist_event_waiting_unique');
        DB::table('waitlist_entries')->insert(array_replace($row, ['event_id' => $this->event()]));
        DB::table('waitlist_entries')->insert(array_replace($row, ['user_id' => User::factory()->create()->id]));
        $this->assertSame($row['user_id'], DB::table('waitlist_entries')->find($active)->waiting_user_id);
        $this->assertSame(1, DB::table('waitlist_entries')->where('event_id', $row['event_id'])->where('waiting_user_id', $row['user_id'])->count());
        $this->assertDatabaseCount('waitlist_entries', 6);
    }

    #[DataProvider('generatedKeys')]
    public function test_generated_active_keys_cannot_be_client_written(string $table, string $key): void
    {
        $row = $this->attemptRow($table);
        $this->assertDatabaseRejects(fn () => DB::table($table)->insert(array_replace($row, [$key => 999])), 3105, $key);
        $id = DB::table($table)->insertGetId($row);
        $this->assertDatabaseRejects(fn () => DB::table($table)->where('id', $id)->update([$key => 999]), 3105, $key);
        $this->assertSame($row['user_id'], DB::table($table)->find($id)->{$key});
    }

    public static function generatedKeys(): array
    {
        return [['reservations', 'active_user_id'], ['waitlist_entries', 'waiting_user_id']];
    }

    #[DataProvider('foreignKeys')]
    public function test_missing_parents_are_rejected(string $table, string $key, string $constraint): void
    {
        $row = $table === 'events' ? $this->eventRow() : $this->attemptRow($table);
        $this->assertDatabaseRejects(fn () => DB::table($table)->insert(array_replace($row, [$key => 999999999])), 1452, $constraint);
    }

    public static function foreignKeys(): array
    {
        return [
            ['events', 'owner_id', 'events_owner_foreign'],
            ['events', 'event_type_id', 'events_type_foreign'],
            ['reservations', 'event_id', 'reservations_event_foreign'],
            ['reservations', 'user_id', 'reservations_user_foreign'],
            ['waitlist_entries', 'event_id', 'waitlist_event_foreign'],
            ['waitlist_entries', 'user_id', 'waitlist_user_foreign'],
        ];
    }

    #[DataProvider('referencedParents')]
    public function test_domain_history_restricts_parent_deletion_and_id_changes(string $table, string $parent, string $key): void
    {
        $row = $table === 'events' ? $this->eventRow() : $this->attemptRow($table);
        DB::table($table)->insert($row);
        $parentId = $row[$key];
        $this->assertDatabaseRejects(fn () => DB::table($parent)->where('id', $parentId)->delete(), 1451);
        $this->assertDatabaseRejects(fn () => DB::table($parent)->where('id', $parentId)->update(['id' => 999999999]), 1451);
        $this->assertDatabaseHas($parent, ['id' => $parentId]);
        $this->assertDatabaseHas($table, [$key => $parentId]);
    }

    public static function referencedParents(): array
    {
        return [
            ['events', 'users', 'owner_id'], ['events', 'event_types', 'event_type_id'],
            ['reservations', 'events', 'event_id'], ['reservations', 'users', 'user_id'],
            ['waitlist_entries', 'events', 'event_id'], ['waitlist_entries', 'users', 'user_id'],
        ];
    }

    public function test_expected_lookup_indexes_and_enforced_checks_exist(): void
    {
        $expected = [
            'users_email_unique' => ['users', ['email'], true],
            'event_types_slug_unique' => ['event_types', ['slug'], true],
            'events_status_starts_id_index' => ['events', ['status', 'starts_at', 'id'], false],
            'events_owner_status_starts_id_index' => ['events', ['owner_id', 'status', 'starts_at', 'id'], false],
            'events_type_status_starts_id_index' => ['events', ['event_type_id', 'status', 'starts_at', 'id'], false],
            'reservations_event_active_unique' => ['reservations', ['event_id', 'active_user_id'], true],
            'reservations_event_status_id_index' => ['reservations', ['event_id', 'status', 'id'], false],
            'reservations_user_created_id_index' => ['reservations', ['user_id', 'created_at', 'id'], false],
            'waitlist_event_waiting_unique' => ['waitlist_entries', ['event_id', 'waiting_user_id'], true],
            'waitlist_event_status_joined_id_index' => ['waitlist_entries', ['event_id', 'status', 'joined_at', 'id'], false],
            'waitlist_user_created_id_index' => ['waitlist_entries', ['user_id', 'created_at', 'id'], false],
        ];
        foreach ($expected as $name => [$table, $columns, $unique]) {
            $actual = DB::select('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$table, $name]);
            $this->assertSame($columns, array_column($actual, 'COLUMN_NAME'), $name);
            $this->assertSame($unique ? 0 : 1, (int) $actual[0]->NON_UNIQUE, $name);
        }
        $constraints = DB::select("SELECT CONSTRAINT_NAME, ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'");
        $this->assertCount(10, $constraints);
        foreach ($constraints as $constraint) {
            $this->assertSame('YES', $constraint->ENFORCED, $constraint->CONSTRAINT_NAME);
        }
        $foreignKeys = DB::select('SELECT UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()');
        $this->assertCount(6, $foreignKeys);
        foreach ($foreignKeys as $foreignKey) {
            $this->assertSame('RESTRICT', $foreignKey->UPDATE_RULE);
            $this->assertSame('RESTRICT', $foreignKey->DELETE_RULE);
        }
    }

    public function test_a_failed_transaction_rolls_back_inventory_and_attempt_rows(): void
    {
        $event = $this->event();
        $row = $this->attemptRow('reservations', ['event_id' => $event]);
        try {
            DB::transaction(function () use ($event, $row): void {
                DB::table('events')->where('id', $event)->update(['confirmed_count' => 1]);
                DB::table('reservations')->insert($row);
                throw new RuntimeException('Simulated workflow interruption');
            });
            $this->fail('The simulated interruption must leave the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated workflow interruption', $exception->getMessage());
        }
        $this->assertDatabaseHas('events', ['id' => $event, 'confirmed_count' => 0]);
        $this->assertDatabaseCount('reservations', 0);
    }
}
