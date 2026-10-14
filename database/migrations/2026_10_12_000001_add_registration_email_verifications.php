<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('email_verified_at', 6)->nullable();
        });

        Schema::create('registration_email_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->collation('utf8mb4_bin')->unique('registration_verifications_email_unique');
            $table->char('challenge_id', 64)->collation('ascii_bin')->charset('ascii')
                ->unique('registration_verifications_challenge_unique');
            $table->char('code_hash', 64)->nullable();
            $table->text('encrypted_code')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at', 6)->index('registration_verifications_expiry_index');
            $table->dateTime('verified_at', 6)->nullable();
            $table->char('token_hash', 64)->nullable()->unique('registration_verifications_token_unique');
            $table->dateTime('token_expires_at', 6)->nullable()->index('registration_verifications_token_expiry_index');
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE registration_email_verifications ADD CONSTRAINT registration_verifications_attempts_check CHECK (attempts <= 5)');
            DB::statement('ALTER TABLE registration_email_verifications ADD CONSTRAINT registration_verifications_proof_check CHECK ((token_hash IS NULL AND token_expires_at IS NULL) OR (token_hash IS NOT NULL AND token_expires_at IS NOT NULL AND verified_at IS NOT NULL AND consumed_at IS NULL))');
            DB::statement('ALTER TABLE registration_email_verifications ADD CONSTRAINT registration_verifications_verified_check CHECK (verified_at IS NULL OR (code_hash IS NULL AND encrypted_code IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_email_verifications');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
