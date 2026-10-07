<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20)->default('waiting');
            $table->dateTime('joined_at', 6);
            $table->dateTime('promoted_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->unsignedBigInteger('waiting_user_id')->nullable()->storedAs("CASE WHEN status = 'waiting' THEN user_id ELSE NULL END");
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['event_id', 'waiting_user_id'], 'waitlist_event_waiting_unique');
            $table->index(['event_id', 'status', 'joined_at', 'id'], 'waitlist_event_status_joined_id_index');
            $table->index(['user_id', 'created_at', 'id'], 'waitlist_user_created_id_index');
            $table->foreign('event_id', 'waitlist_event_foreign')->references('id')->on('events')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'waitlist_user_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE waitlist_entries
                    ADD CONSTRAINT waitlist_status_check CHECK (BINARY status IN ('waiting', 'promoted', 'cancelled')),
                    ADD CONSTRAINT waitlist_terminal_timestamps_check CHECK (
                        (status = 'waiting' AND promoted_at IS NULL AND cancelled_at IS NULL)
                        OR (status = 'promoted' AND promoted_at IS NOT NULL AND cancelled_at IS NULL)
                        OR (status = 'cancelled' AND promoted_at IS NULL AND cancelled_at IS NOT NULL)
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
