<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->unique()->constrained('appointments')->restrictOnDelete();
            // Denormalised for fast participant checks (channel auth, policies).
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->string('consultation_type', 10);
            $table->string('status', 20)->default('scheduled');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('interrupted_at')->nullable();
            $table->unsignedSmallInteger('max_duration_minutes');

            // Patient-provided intake (encrypted at rest - clinical content).
            $table->text('intake_reason')->nullable();
            $table->text('intake_details')->nullable();
            $table->jsonb('intake_screening')->nullable();
            $table->timestampTz('intake_submitted_at')->nullable();

            $table->boolean('emergency_flagged')->default(false);
            $table->timestamps();

            $table->index(['doctor_id', 'status']);
            $table->index(['patient_id', 'status']);
        });

        DB::statement("ALTER TABLE consultations ADD CONSTRAINT consultations_status_check CHECK (status IN ('scheduled','active','completed','cancelled','interrupted'))");

        Schema::create('consultation_escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->foreignUuid('raised_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            // screening = automatic red-flag answers at intake; doctor = raised during consultation.
            $table->string('source', 20);
            $table->string('type', 30);
            $table->text('details')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['consultation_id', 'created_at']);
        });

        DB::statement("ALTER TABLE consultation_escalations ADD CONSTRAINT consultation_escalations_type_check CHECK (type IN ('emergency','urgent_in_person','in_person_referral'))");

        Schema::create('video_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_room_id', 200);
            $table->string('status', 20)->default('created');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('last_error', 300)->nullable();
            $table->timestamps();

            $table->index(['consultation_id', 'status']);
        });

        DB::statement("ALTER TABLE video_sessions ADD CONSTRAINT video_sessions_status_check CHECK (status IN ('created','active','ended','failed'))");

        // Technical telemetry only (joins, leaves, failures) - never media or clinical content.
        Schema::create('video_session_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_session_id')->constrained('video_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['video_session_id', 'created_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->foreignUuid('sender_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('sender_role', 20);
            $table->string('type', 20)->default('text');
            // Encrypted at rest. Never logged.
            $table->text('body')->nullable();
            // Client-generated id: lets clients retry after reconnecting without duplicates.
            $table->string('client_message_id', 100)->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['consultation_id', 'sender_user_id', 'client_message_id']);
        });

        // Strictly increasing order for pagination cursors and receipts
        // (created_at has one-second precision and UUIDs are not monotonic).
        DB::statement('ALTER TABLE messages ADD COLUMN seq BIGSERIAL NOT NULL UNIQUE');
        DB::statement('CREATE INDEX messages_consultation_seq ON messages (consultation_id, seq)');
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('text','attachment','system'))");

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('messages')->restrictOnDelete();
            $table->string('file_path', 500);
            $table->string('file_name', 200);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('video_session_events');
        Schema::dropIfExists('video_sessions');
        Schema::dropIfExists('consultation_escalations');
        Schema::dropIfExists('consultations');
    }
};
