<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique('users_email_unique');
            $table->string('password');
            $table->string('role', 20)->default('regular_user');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (BINARY role IN ('regular_user', 'organiser', 'admin'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
