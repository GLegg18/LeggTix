<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20)->default('confirmed');
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->unsignedBigInteger('active_user_id')->nullable()->storedAs("CASE WHEN status = 'confirmed' THEN user_id ELSE NULL END");
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['event_id', 'active_user_id'], 'reservations_event_active_unique');
            $table->index(['event_id', 'status', 'id'], 'reservations_event_status_id_index');
            $table->index(['user_id', 'created_at', 'id'], 'reservations_user_created_id_index');
            $table->foreign('event_id', 'reservations_event_foreign')->references('id')->on('events')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('user_id', 'reservations_user_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE reservations
                    ADD CONSTRAINT reservations_status_check CHECK (BINARY status IN ('confirmed', 'cancelled')),
                    ADD CONSTRAINT reservations_cancelled_at_check CHECK (
                        (status = 'confirmed' AND cancelled_at IS NULL)
                        OR (status = 'cancelled' AND cancelled_at IS NOT NULL)
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
