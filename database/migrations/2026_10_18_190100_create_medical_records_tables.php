<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clinical data. Every free-text clinical field is encrypted at rest by
     * the application (Laravel `encrypted` casts) and is therefore `text`.
     * Rows are never hard-deleted: errors are marked `entered_in_error`.
     */
    public function up(): void
    {
        // Medical history: conditions, surgeries, family / social history.
        Schema::create('medical_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('type', 30);
            $table->text('title');
            $table->text('details')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('onset_date')->nullable();
            $table->string('source', 20);
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('consultation_id')->nullable()->constrained('consultations')->restrictOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'type']);
        });

        DB::statement("ALTER TABLE medical_records ADD CONSTRAINT medical_records_type_check CHECK (type IN ('condition','chronic_condition','surgery','family_history','social_history','other'))");
        DB::statement("ALTER TABLE medical_records ADD CONSTRAINT medical_records_status_check CHECK (status IN ('active','resolved','entered_in_error'))");

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->text('allergen');
            $table->text('reaction')->nullable();
            $table->string('severity', 20)->default('unknown');
            $table->string('status', 20)->default('active');
            $table->string('source', 20);
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('consultation_id')->nullable()->constrained('consultations')->restrictOnDelete();
            $table->timestamps();

            $table->index('patient_id');
        });

        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_severity_check CHECK (severity IN ('mild','moderate','severe','unknown'))");
        DB::statement("ALTER TABLE patient_allergies ADD CONSTRAINT patient_allergies_status_check CHECK (status IN ('active','inactive','entered_in_error'))");

        Schema::create('patient_medications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->text('name');
            $table->text('dosage')->nullable();
            $table->text('frequency')->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('source', 20);
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('consultation_id')->nullable()->constrained('consultations')->restrictOnDelete();
            $table->timestamps();

            $table->index('patient_id');
        });

        DB::statement("ALTER TABLE patient_medications ADD CONSTRAINT patient_medications_status_check CHECK (status IN ('active','stopped','entered_in_error'))");

        foreach (['medical_records', 'patient_allergies', 'patient_medications'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_source_check CHECK (source IN ('patient_reported','clinician'))");
        }

        // One note per consultation. Draft content is editable; finalised
        // content lives only in immutable clinical_note_versions rows.
        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->unique()->constrained('consultations')->restrictOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('author_doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_version')->default(0);
            $table->text('draft_content')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'finalized_at']);
        });

        DB::statement("ALTER TABLE clinical_notes ADD CONSTRAINT clinical_notes_status_check CHECK (status IN ('draft','final','amended'))");

        Schema::create('clinical_note_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('clinical_note_id')->constrained('clinical_notes')->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            // Encrypted JSON: subjective, objective, assessment, plan, follow_up, referral.
            $table->text('content');
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('change_reason', 1000)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['clinical_note_id', 'version']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION clinical_note_versions_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'clinical_note_versions is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER clinical_note_versions_immutable
                BEFORE UPDATE OR DELETE ON clinical_note_versions
                FOR EACH ROW EXECUTE FUNCTION clinical_note_versions_block_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_note_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS clinical_note_versions_block_mutation()');
        Schema::dropIfExists('clinical_notes');
        Schema::dropIfExists('patient_medications');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('medical_records');
    }
};
