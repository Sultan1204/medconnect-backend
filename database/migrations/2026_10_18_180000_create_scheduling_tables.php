<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Needed to combine "=" on doctor_id with "&&" on time ranges in one exclusion constraint.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('doctor_schedule_settings', function (Blueprint $table) {
            $table->foreignUuid('doctor_id')->primary()->constrained('doctors')->cascadeOnDelete();
            $table->unsignedSmallInteger('min_notice_minutes')->default(60);
            $table->unsignedSmallInteger('max_days_ahead')->default(30);
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->unsignedSmallInteger('max_daily_appointments')->nullable();
            // false = the doctor must accept each booking (status "pending").
            $table->boolean('auto_confirm')->default(true);
            // Cancellation policy, snapshotted onto each appointment at booking time.
            $table->unsignedSmallInteger('free_cancellation_hours')->default(24);
            $table->unsignedTinyInteger('late_cancellation_refund_percent')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE doctor_schedule_settings ADD CONSTRAINT schedule_refund_percent_check CHECK (late_cancellation_refund_percent <= 100)');

        // Recurring weekly windows in the doctor's local time (Asia/Riyadh).
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            // 0 = Sunday ... 6 = Saturday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_minutes');
            // [{"start": "12:00", "end": "13:00"}]
            $table->jsonb('breaks')->default('[]');
            $table->jsonb('consultation_types');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'day_of_week']);
        });

        DB::statement('ALTER TABLE availability_rules ADD CONSTRAINT availability_rules_day_check CHECK (day_of_week BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE availability_rules ADD CONSTRAINT availability_rules_time_check CHECK (end_time > start_time)');
        DB::statement('ALTER TABLE availability_rules ADD CONSTRAINT availability_rules_slot_check CHECK (slot_minutes BETWEEN 10 AND 240)');

        // Date-specific additions ("available") and time off ("blocked").
        Schema::create('availability_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->string('type', 10);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedSmallInteger('slot_minutes')->nullable();
            $table->jsonb('consultation_types')->nullable();
            // Private note for the doctor (never shown to patients).
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'starts_at']);
        });

        DB::statement("ALTER TABLE availability_slots ADD CONSTRAINT availability_slots_type_check CHECK (type IN ('available','blocked'))");
        DB::statement('ALTER TABLE availability_slots ADD CONSTRAINT availability_slots_time_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE availability_slots ADD CONSTRAINT availability_slots_available_check CHECK (type = 'blocked' OR (slot_minutes IS NOT NULL AND consultation_types IS NOT NULL))");

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignUuid('booked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('consultation_type', 10);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 20);

            // Immutable financial snapshot taken at booking time (halalas).
            $table->unsignedInteger('price_amount');
            $table->char('currency', 3);
            $table->jsonb('cancellation_policy');

            $table->timestampTz('payment_due_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancelled_by_role', 20)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            // Refund owed to the patient on cancellation, per the snapshotted policy.
            $table->unsignedTinyInteger('refund_percent')->nullable();
            $table->timestampTz('no_show_at')->nullable();

            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(['booked_by_user_id', 'idempotency_key']);
            $table->index(['doctor_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
            $table->index(['status', 'payment_due_at']);
        });

        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ('pending','awaiting_payment','confirmed','in_progress','completed','cancelled','expired','no_show'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_type_check CHECK (consultation_type IN ('video','chat'))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_time_check CHECK (ends_at > starts_at)');

        // The definitive double-booking guard: no two active appointments may
        // overlap for the same doctor - or for the same patient - even if two
        // requests race past every application-level check.
        $active = "status IN ('pending','awaiting_payment','confirmed','in_progress')";
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_no_doctor_overlap EXCLUDE USING gist (doctor_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE ({$active})");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_no_patient_overlap EXCLUDE USING gist (patient_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE ({$active})");

        // The agreed price, currency, time and policy can never be rewritten.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION appointments_protect_snapshot() RETURNS trigger AS $$
            BEGIN
                IF NEW.price_amount IS DISTINCT FROM OLD.price_amount
                   OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.cancellation_policy IS DISTINCT FROM OLD.cancellation_policy
                   OR NEW.starts_at IS DISTINCT FROM OLD.starts_at
                   OR NEW.ends_at IS DISTINCT FROM OLD.ends_at
                   OR NEW.doctor_id IS DISTINCT FROM OLD.doctor_id
                   OR NEW.patient_id IS DISTINCT FROM OLD.patient_id THEN
                    RAISE EXCEPTION 'appointment booking snapshot is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER appointments_snapshot_immutable
                BEFORE UPDATE ON appointments
                FOR EACH ROW EXECUTE FUNCTION appointments_protect_snapshot();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        DB::unprepared('DROP FUNCTION IF EXISTS appointments_protect_snapshot()');
        Schema::dropIfExists('availability_slots');
        Schema::dropIfExists('availability_rules');
        Schema::dropIfExists('doctor_schedule_settings');
    }
};
