<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only security and data-access audit trail.
     * A trigger rejects UPDATE/DELETE so entries cannot be rewritten through
     * the application connection; retention purges must use a separate,
     * privileged maintenance role (see docs/security.md).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No FK: audit entries must outlive and never block account changes.
            $table->uuid('actor_id')->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();
            // Patient whose data was touched, for "who accessed my record" reports.
            $table->uuid('patient_id')->nullable();
            // Why the access happened, e.g. consultation:<uuid>, treatment, dispensing.
            $table->string('purpose', 100)->nullable();
            // Field names (and non-clinical values) that changed. Never clinical content.
            $table->jsonb('changes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('outcome', 20)->default('success');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['patient_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_immutable
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_block_mutation()');
    }
};
