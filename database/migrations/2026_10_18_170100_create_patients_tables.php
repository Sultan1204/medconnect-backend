<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patient identity/profile data. Clinical data (history, allergies,
     * medications) lives in the MedicalRecords module's tables.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null for dependants (e.g. children) managed by a guardian without their own login.
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->string('full_name', 150);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            // Encrypted at rest (Laravel `encrypted` cast) - therefore text.
            $table->text('emergency_contact_name')->nullable();
            $table->text('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relationship', 50)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_gender_check CHECK (gender IS NULL OR gender IN ('male','female'))");

        Schema::create('patient_guardians', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('guardian_user_id')->constrained('users')->restrictOnDelete();
            $table->string('relationship', 30);
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'guardian_user_id']);
            $table->index(['guardian_user_id', 'status']);
        });

        DB::statement("ALTER TABLE patient_guardians ADD CONSTRAINT patient_guardians_status_check CHECK (status IN ('pending','active','rejected','revoked'))");
        DB::statement("ALTER TABLE patient_guardians ADD CONSTRAINT patient_guardians_relationship_check CHECK (relationship IN ('parent','legal_guardian','other'))");

        // Append-only: each grant or withdrawal is a new row; the latest row per type is current.
        Schema::create('patient_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('consent_type', 50);
            $table->string('version', 20);
            $table->boolean('granted');
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patient_id', 'consent_type', 'created_at']);
        });

        // PDPL data-subject requests (access, correction, deletion, restriction).
        Schema::create('patient_data_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('open');
            $table->text('details')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE patient_data_requests ADD CONSTRAINT patient_data_requests_type_check CHECK (type IN ('access','correction','deletion','restriction'))");
        DB::statement("ALTER TABLE patient_data_requests ADD CONSTRAINT patient_data_requests_status_check CHECK (status IN ('open','in_progress','completed','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_data_requests');
        Schema::dropIfExists('patient_consents');
        Schema::dropIfExists('patient_guardians');
        Schema::dropIfExists('patients');
    }
};
