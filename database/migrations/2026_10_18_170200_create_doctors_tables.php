<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigram indexes make name search (ILIKE '%...%') fast in Arabic and English.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('doctors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('display_name_en', 150);
            $table->string('display_name_ar', 150)->nullable();
            $table->string('title', 30)->nullable();
            $table->string('gender', 10)->nullable();
            $table->text('bio_en')->nullable();
            $table->text('bio_ar')->nullable();
            $table->unsignedSmallInteger('years_of_experience')->nullable();
            $table->jsonb('languages')->default('[]');
            $table->string('professional_rank', 30)->nullable();

            $table->string('license_number', 50)->nullable();
            $table->string('licensing_authority', 30)->nullable();
            $table->date('license_expires_on')->nullable();

            $table->string('verification_status', 20)->default('pending');
            $table->timestamp('verification_status_changed_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Fees in halalas (SAR minor units). NULL = consultation type not offered; 0 = free.
            $table->unsignedInteger('video_fee_amount')->nullable();
            $table->unsignedInteger('chat_fee_amount')->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->boolean('accepting_patients')->default(true);

            // Maintained by the Reviews module.
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('verification_status');
            $table->index('video_fee_amount');
            $table->index('chat_fee_amount');
        });

        DB::statement("ALTER TABLE doctors ADD CONSTRAINT doctors_verification_status_check CHECK (verification_status IN ('pending','under_review','approved','rejected','suspended','expired'))");
        DB::statement("ALTER TABLE doctors ADD CONSTRAINT doctors_gender_check CHECK (gender IS NULL OR gender IN ('male','female'))");
        DB::statement("ALTER TABLE doctors ADD CONSTRAINT doctors_rank_check CHECK (professional_rank IS NULL OR professional_rank IN ('consultant','senior_specialist','specialist','general_practitioner','resident'))");
        // A licence can belong to only one doctor account.
        DB::statement('CREATE UNIQUE INDEX doctors_license_unique ON doctors (licensing_authority, license_number) WHERE license_number IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE INDEX doctors_name_en_trgm ON doctors USING gin (display_name_en gin_trgm_ops)');
        DB::statement('CREATE INDEX doctors_name_ar_trgm ON doctors USING gin (display_name_ar gin_trgm_ops)');
        DB::statement('CREATE INDEX doctors_languages_gin ON doctors USING gin (languages)');

        Schema::create('doctor_specialties', function (Blueprint $table) {
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignUuid('specialty_id')->constrained('specialties')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['doctor_id', 'specialty_id']);
            $table->index('specialty_id');
        });

        DB::statement('CREATE UNIQUE INDEX doctor_specialties_one_primary ON doctor_specialties (doctor_id) WHERE is_primary');

        Schema::create('doctor_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('title', 200);
            $table->string('issuer', 200)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            // Private object storage location; never publicly addressable.
            $table->string('file_path', 500);
            $table->string('file_name', 200);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('review_status', 20)->default('submitted');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['doctor_id', 'type']);
        });

        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_type_check CHECK (type IN ('professional_license','qualification','certification','identity_document','other'))");
        DB::statement("ALTER TABLE doctor_credentials ADD CONSTRAINT doctor_credentials_review_check CHECK (review_status IN ('submitted','accepted','rejected'))");

        // Append-only decision history for professional verification.
        Schema::create('doctor_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // NULL actor = automated (scheduler / integration).
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->string('source', 30)->default('manual');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['doctor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_verifications');
        Schema::dropIfExists('doctor_credentials');
        Schema::dropIfExists('doctor_specialties');
        Schema::dropIfExists('doctors');
    }
};
