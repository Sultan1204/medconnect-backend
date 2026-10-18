<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prescription workflow records. A prescription only becomes legally
     * meaningful when issued through an authorised e-prescribing service;
     * nothing in these tables is a prescription by itself.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->constrained('consultations')->restrictOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('prescriber_doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignUuid('prescriber_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->text('clinical_indication')->nullable();
            $table->string('safety_check_status', 20)->default('not_run');
            $table->text('safety_check_result')->nullable();
            $table->text('warnings_justification')->nullable();
            $table->timestampTz('warnings_acknowledged_at')->nullable();
            $table->string('gateway', 30)->nullable();
            $table->string('external_reference', 200)->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['prescriber_doctor_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_status_check CHECK (status IN ('draft','pending_validation','pending_external_approval','issued','rejected','cancelled','expired','dispensed'))");
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_safety_check CHECK (safety_check_status IN ('not_run','passed','warnings','blocked'))");
        DB::statement('CREATE UNIQUE INDEX prescriptions_external_reference_unique ON prescriptions (gateway, external_reference) WHERE external_reference IS NOT NULL');

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            // Coded medication from an authoritative catalogue (e.g. the national drug list).
            $table->string('catalog_system', 30);
            $table->string('catalog_code', 60);
            $table->string('medication_name', 200);
            $table->string('strength', 60)->nullable();
            $table->string('dosage_form', 60)->nullable();
            $table->string('dose', 60);
            $table->string('route', 40);
            $table->string('frequency', 60);
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedSmallInteger('quantity');
            $table->string('quantity_unit', 30);
            $table->text('instructions')->nullable();
            $table->boolean('is_controlled')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['prescription_id', 'position']);
        });

        Schema::create('prescription_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->restrictOnDelete();
            $table->string('type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['prescription_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prescription_events_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'prescription_events is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prescription_events_immutable
                BEFORE UPDATE OR DELETE ON prescription_events
                FOR EACH ROW EXECUTE FUNCTION prescription_events_block_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_events');
        DB::unprepared('DROP FUNCTION IF EXISTS prescription_events_block_mutation()');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
    }
};
