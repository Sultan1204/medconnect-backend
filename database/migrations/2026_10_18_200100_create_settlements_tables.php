<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('executed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('provider_payout_id', 200)->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->string('failure_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'status']);
        });

        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('pending','processing','paid','failed','cancelled'))");
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_check CHECK (amount > 0)');

        // Immutable ledger. Balances are SUM(amount) per account - never stored.
        // Signed amounts: positive = owed to the account holder.
        Schema::create('settlement_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // doctor_payable (per doctor) or platform_revenue.
            $table->string('account', 30);
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->restrictOnDelete();
            $table->string('type', 40);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->restrictOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignUuid('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->foreignUuid('payout_id')->nullable()->constrained('payouts')->restrictOnDelete();
            $table->string('description', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['account', 'doctor_id', 'created_at']);
            $table->index('payment_id');
        });

        DB::statement("ALTER TABLE settlement_entries ADD CONSTRAINT settlement_entries_account_check CHECK (account IN ('doctor_payable','platform_revenue'))");
        DB::statement("ALTER TABLE settlement_entries ADD CONSTRAINT settlement_entries_doctor_check CHECK ((account = 'doctor_payable') = (doctor_id IS NOT NULL))");
        DB::statement("ALTER TABLE settlement_entries ADD CONSTRAINT settlement_entries_type_check CHECK (type IN ('consultation_earning','platform_commission','refund_reversal','commission_reversal','payout','payout_reversal','adjustment'))");
        // An earning is recorded once per payment and account.
        DB::statement("CREATE UNIQUE INDEX settlement_entries_once_per_payment ON settlement_entries (payment_id, account, type) WHERE type IN ('consultation_earning','platform_commission')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION settlement_entries_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'settlement_entries is an append-only ledger';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER settlement_entries_immutable
                BEFORE UPDATE OR DELETE ON settlement_entries
                FOR EACH ROW EXECUTE FUNCTION settlement_entries_block_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS settlement_entries_block_mutation()');
        Schema::dropIfExists('payouts');
    }
};
