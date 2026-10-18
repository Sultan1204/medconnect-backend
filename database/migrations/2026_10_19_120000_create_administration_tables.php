<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Human-friendly, non-guessable reference quoted to the complainant.
            $table->string('reference', 20)->unique();
            $table->foreignUuid('complainant_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->string('subject_type', 20);
            $table->uuid('subject_id')->nullable();
            $table->string('category', 20);
            // May contain health information supplied by the complainant.
            $table->text('description');
            $table->string('status', 15)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->foreignUuid('assigned_to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignUuid('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'created_at']);
            $table->index(['complainant_user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_subject_check CHECK (subject_type IN ('consultation','dispense_request','payment','platform') AND ((subject_type = 'platform') = (subject_id IS NULL)))");
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_category_check CHECK (category IN ('service_quality','conduct','billing','privacy','technical','other'))");
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_status_check CHECK (status IN ('open','under_review','resolved','rejected'))");
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_priority_check CHECK (priority IN ('low','normal','high','urgent'))");
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_resolved_check CHECK ((status IN ('resolved','rejected')) = (resolved_at IS NOT NULL))");

        // Handling history. `visibility = internal` entries are never shown to the complainant.
        Schema::create('complaint_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('complaint_id')->constrained('complaints')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('from_status', 15)->nullable();
            $table->string('to_status', 15)->nullable();
            $table->text('note')->nullable();
            $table->string('visibility', 10)->default('internal');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['complaint_id', 'created_at']);
        });

        DB::statement("ALTER TABLE complaint_events ADD CONSTRAINT complaint_events_type_check CHECK (type IN ('submitted','assigned','status_changed','priority_changed','note'))");
        DB::statement("ALTER TABLE complaint_events ADD CONSTRAINT complaint_events_visibility_check CHECK (visibility IN ('internal','public'))");

        // Operator-adjustable settings. Only keys on the allow-list in
        // PlatformSettings can be stored; each is validated and applied over config.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('complaint_events');
        Schema::dropIfExists('complaints');
    }
};
