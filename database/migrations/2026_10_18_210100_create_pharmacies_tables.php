<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_en', 150);
            $table->string('name_ar', 150);
            $table->string('license_number', 60);
            $table->string('licensing_authority', 30);
            $table->date('license_expires_on');
            $table->string('commercial_registration', 30)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['licensing_authority', 'license_number']);
            $table->index('verification_status');
        });

        DB::statement("ALTER TABLE pharmacies ADD CONSTRAINT pharmacies_status_check CHECK (verification_status IN ('pending','approved','rejected','suspended'))");

        Schema::create('pharmacy_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            $table->string('name_en', 150);
            $table->string('name_ar', 150);
            $table->string('city', 80);
            $table->string('district', 80)->nullable();
            $table->string('address_line', 300);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('phone', 20)->nullable();
            $table->jsonb('opening_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_eprescriptions')->default(true);
            $table->timestamps();

            $table->index(['city', 'is_active']);
        });

        Schema::create('pharmacy_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained('pharmacies')->restrictOnDelete();
            // NULL = all branches of the pharmacy.
            $table->foreignUuid('branch_id')->nullable()->constrained('pharmacy_branches')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('user_id');
        });

        DB::statement("ALTER TABLE pharmacy_staff ADD CONSTRAINT pharmacy_staff_role_check CHECK (role IN ('manager','pharmacist','assistant'))");

        Schema::create('dispense_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->restrictOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('pharmacy_branches')->restrictOnDelete();
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('requested');
            $table->string('rejection_reason', 50)->nullable();
            // Message from the pharmacy to the patient (e.g. pickup instructions).
            $table->string('pharmacy_note', 500)->nullable();
            $table->foreignUuid('handled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('dispensed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['patient_id', 'status']);
        });

        DB::statement("ALTER TABLE dispense_requests ADD CONSTRAINT dispense_requests_status_check CHECK (status IN ('requested','received','under_review','ready','dispensed','rejected','cancelled'))");
        // Only one pharmacy works on a prescription at a time.
        DB::statement("CREATE UNIQUE INDEX dispense_requests_one_active ON dispense_requests (prescription_id) WHERE status IN ('requested','received','under_review','ready','dispensed')");

        Schema::create('dispense_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispense_request_id')->constrained('dispense_requests')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['dispense_request_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dispense_events_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'dispense_events is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER dispense_events_immutable
                BEFORE UPDATE OR DELETE ON dispense_events
                FOR EACH ROW EXECUTE FUNCTION dispense_events_block_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dispense_events');
        DB::unprepared('DROP FUNCTION IF EXISTS dispense_events_block_mutation()');
        Schema::dropIfExists('dispense_requests');
        Schema::dropIfExists('pharmacy_staff');
        Schema::dropIfExists('pharmacy_branches');
        Schema::dropIfExists('pharmacies');
    }
};
