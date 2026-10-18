<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amounts are integer minor units (halalas). No card data is ever stored:
     * checkout happens on the provider's hosted page.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('payer_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->char('currency', 3);
            // Platform commission in basis points, snapshotted at checkout (2000 = 20%).
            $table->unsignedSmallInteger('commission_rate_bps');
            $table->string('status', 20)->default('pending');
            $table->string('provider', 30);
            $table->string('provider_payment_id', 200)->nullable();
            $table->string('idempotency_key', 100);
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->string('failure_code', 100)->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['payer_user_id', 'idempotency_key']);
            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['appointment_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','captured','failed','cancelled','refunded','partially_refunded','review_required'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refund_check CHECK (refunded_amount <= amount)');
        // At most one successful charge per appointment.
        DB::statement("CREATE UNIQUE INDEX payments_one_capture_per_appointment ON payments (appointment_id) WHERE status IN ('captured','partially_refunded','refunded')");

        // Every webhook received, verified or not, de-duplicated by provider event id.
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 30);
            $table->string('provider_event_id', 200);
            $table->string('type', 100);
            $table->boolean('signature_valid');
            // Minimal normalised fields - never card data or full raw payloads.
            $table->jsonb('payload');
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->string('processing_error', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['provider', 'provider_event_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->string('reason', 50);
            $table->string('note', 500)->nullable();
            $table->string('provider_refund_id', 200)->nullable();
            // Deterministic for system refunds (e.g. "cancellation:<appointment>").
            $table->string('idempotency_key', 150)->unique();
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('succeeded_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending','succeeded','failed'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');

        // Outbound/inbound integration calls (metadata only) for operations and audit.
        Schema::create('integration_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('integration', 30);
            $table->string('operation', 60);
            $table->string('direction', 10);
            $table->string('status', 20);
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('external_reference', 200)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['integration', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};
