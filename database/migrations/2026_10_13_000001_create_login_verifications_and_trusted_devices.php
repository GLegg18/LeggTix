<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_email_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique('login_verifications_user_unique');
            $table->foreign('user_id', 'login_verifications_user_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->char('challenge_id', 64)->charset('ascii')->collation('ascii_bin')->unique('login_verifications_challenge_unique');
            $table->char('credential_fingerprint', 64);
            $table->char('code_hash', 64)->nullable();
            $table->text('encrypted_code')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('issued_at', 6);
            $table->dateTime('expires_at', 6)->index('login_verifications_expiry_index');
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index('trusted_devices_user_index');
            $table->foreign('user_id', 'trusted_devices_user_foreign')->references('id')->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->char('selector', 32)->charset('ascii')->collation('ascii_bin')->unique('trusted_devices_selector_unique');
            $table->char('validator_hash', 64);
            $table->char('credential_fingerprint', 64);
            $table->dateTime('expires_at', 6)->index('trusted_devices_expiry_index');
            $table->dateTime('revoked_at', 6)->nullable();
            $table->dateTime('last_used_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE login_email_verifications ADD CONSTRAINT login_verifications_attempts_check CHECK (attempts <= 5)');
            DB::statement('ALTER TABLE login_email_verifications ADD CONSTRAINT login_verifications_consumed_check CHECK (consumed_at IS NULL OR (code_hash IS NULL AND encrypted_code IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('login_email_verifications');
    }
};
