<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identity only. Clinical and profile data live in module-specific tables
     * so that account records stay separate from medical information.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            // E.164, e.g. +9665XXXXXXXX. Nullable for staff created without a phone.
            $table->string('phone', 20)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            // Stored lower-cased by the model.
            $table->string('email', 191)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('preferred_locale', 5)->default('ar');
            $table->string('status', 20)->default('active');
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_reason', 500)->nullable();

            // TOTP multi-factor authentication (secret + recovery codes encrypted at rest).
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            // Last accepted TOTP time-step; prevents replaying a code within its window.
            $table->unsignedBigInteger('mfa_last_timestep')->nullable();

            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();

            $table->timestamps();
            // Accounts are never hard-deleted: clinical and financial history reference them.
            $table->softDeletes();

            $table->index('status');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended','deactivated'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (preferred_locale IN ('ar','en'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_contact_check CHECK (phone IS NOT NULL OR email IS NOT NULL)');

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
