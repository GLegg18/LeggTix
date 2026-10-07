<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('event_type_id');
            $table->string('name');
            $table->text('description');
            $table->string('venue');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->string('timezone', 64);
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->string('status', 20)->default('draft');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['status', 'starts_at', 'id'], 'events_status_starts_id_index');
            $table->index(['owner_id', 'status', 'starts_at', 'id'], 'events_owner_status_starts_id_index');
            $table->index(['event_type_id', 'status', 'starts_at', 'id'], 'events_type_status_starts_id_index');
            $table->foreign('owner_id', 'events_owner_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('event_type_id', 'events_type_foreign')->references('id')->on('event_types')->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE events
                    ADD CONSTRAINT events_status_check CHECK (BINARY status IN ('draft', 'published', 'cancelled', 'completed')),
                    ADD CONSTRAINT events_capacity_positive_check CHECK (capacity > 0),
                    ADD CONSTRAINT events_confirmed_count_check CHECK (confirmed_count <= capacity),
                    ADD CONSTRAINT events_ends_after_starts_check CHECK (ends_at IS NULL OR ends_at > starts_at)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
