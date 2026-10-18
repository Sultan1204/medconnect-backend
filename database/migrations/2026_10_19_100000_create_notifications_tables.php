<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notification feed. `params` holds identifiers and non-clinical
        // values only (never clinical content) - the text is rendered from
        // language files at read/delivery time.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 60);
            $table->string('category', 30);
            $table->jsonb('params')->default('{}');
            // Makes producers idempotent (e.g. "reminder:<appointment>:24h").
            $table->string('dedupe_key', 150)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'read_at']);
        });

        DB::statement('CREATE UNIQUE INDEX notifications_dedupe_unique ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_category_check CHECK (category IN ('appointments','payments','messages','consultations','prescriptions','pharmacy','security','support'))");

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('channel', 10);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'category', 'channel']);
        });

        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_channel_check CHECK (channel IN ('sms','email','push'))");

        // One row per outbound channel attempt chain. Stores outcome only - never
        // the rendered message or the destination.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_reference', 200)->nullable();
            // Exception class / provider code - not the message, which may echo the destination.
            $table->string('error_code', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'channel']);
            $table->index(['status', 'channel']);
        });

        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_channel_check CHECK (channel IN ('sms','email','push'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('pending','sent','failed','skipped'))");

        // Mobile/web push registrations. The token is a credential for pushing to
        // a device, so it is stored encrypted; the hash enforces uniqueness.
        Schema::create('push_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform', 10);
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('device_name', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        DB::statement("ALTER TABLE push_devices ADD CONSTRAINT push_devices_platform_check CHECK (platform IN ('android','ios','web'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
