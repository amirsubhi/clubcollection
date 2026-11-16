<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            // Billing plans members may choose when paying ahead (P1).
            $table->boolean('allow_monthly')->default(true);
            $table->boolean('allow_quarterly')->default(true);
            $table->boolean('allow_yearly')->default(true);
            $table->string('default_plan', 20)->default('yearly');
            // Who bears the gateway's per-bill fee: 'club' (absorbs) or 'member'
            // (added on top). gateway_fee_sen is the expected fee per bill.
            $table->string('gateway_fee_payer', 10)->default('club');
            $table->unsignedInteger('gateway_fee_sen')->default(100);
            // Days after the due date before an unpaid fee is called overdue
            // ("Lewat") and reminded; until then it is just "due" (R2a).
            $table->unsignedSmallInteger('overdue_grace_days')->default(7);
            // Manual bank / DuitNow transfer (P2). Plain text on purpose: members
            // are shown these details so they can pay. See Club::transferReady().
            $table->boolean('transfer_enabled')->default(false);
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->string('bank_account_no', 30)->nullable();
            $table->string('duitnow_id', 100)->nullable();
            // A requested change to the bank / DuitNow details (S1). Members keep
            // seeing the live columns above until a different club admin (or the
            // super admin) approves it on the billing page. JSON of
            // transfer_enabled, bank_name, bank_account_name, bank_account_no, duitnow_id.
            $table->json('pending_bank_change')->nullable();
            $table->foreignId('bank_change_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('bank_change_requested_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
