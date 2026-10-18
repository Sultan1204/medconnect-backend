<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One review per consultation - enforced by the database, not only by PHP.
            $table->foreignUuid('consultation_id')->unique()->constrained('consultations')->restrictOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->text('comment')->nullable();
            $table->string('status', 15)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('moderated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_note', 500)->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'status', 'published_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('pending','published','hidden','rejected'))");
        // A published review has a publication time; a review that never went live has none.
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_published_check CHECK (status <> 'published' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_pending_check CHECK (status <> 'pending' OR published_at IS NULL)");

        Schema::create('review_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained('reviews')->restrictOnDelete();
            $table->foreignUuid('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 30);
            $table->string('details', 500)->nullable();
            $table->string('status', 15)->default('open');
            $table->foreignUuid('handled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('handling_note', 500)->nullable();
            $table->timestamps();

            // A user can report a review once.
            $table->unique(['review_id', 'reporter_user_id']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE review_reports ADD CONSTRAINT review_reports_reason_check CHECK (reason IN ('inaccurate','abusive','contains_personal_info','spam','other'))");
        DB::statement("ALTER TABLE review_reports ADD CONSTRAINT review_reports_status_check CHECK (status IN ('open','actioned','dismissed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
        Schema::dropIfExists('reviews');
    }
};
