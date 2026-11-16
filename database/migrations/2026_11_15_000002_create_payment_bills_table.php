<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every gateway bill ever opened for a fee (S1). payments.bill_code holds
     * only the current one; a member may still pay an older, superseded bill,
     * so the webhook looks bills up here and settles (or flags) them too.
     *
     * "Bayar semua" (R1b) reuses this table for a bill covering several fees:
     * payment_id is null and payment_bill_items lists the fees. A group sent
     * as one bank transfer slip is a row with method 'transfer' (no gateway,
     * no bill code); its slips are attached to the row.
     */
    public function up(): void
    {
        Schema::create('payment_bills', function (Blueprint $table) {
            $table->id();
            // The fee of a single bill; null for a group ("Bayar semua") bill.
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            // Owner of a group bill (single bills reach them through the fee).
            $table->foreignId('club_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // 'gateway' (FPX bill) or 'transfer' (one slip for a group of fees).
            $table->string('method', 10)->default('gateway');
            $table->string('gateway', 20)->nullable();
            $table->string('bill_code')->nullable();
            // The reference sent with the bill: the per-bill webhook token is an HMAC of it.
            $table->string('reference')->nullable();
            // The fee amount the bill was created for (without any gateway charge);
            // for a group, the sum of its items.
            $table->unsignedInteger('amount_sen');
            $table->string('fee_payer', 10)->nullable();
            $table->unsignedInteger('fee_sen')->nullable();
            // Set when a verified webhook settled the fee(s) with this bill, or
            // (transfer group) when an admin approved the slip.
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();
            // Last time the member was sent to pay this bill (created, or reused by
            // pressing pay again): an FPX payment counts as in progress from here (R2a).
            $table->timestamp('opened_at')->nullable();
            // Replaced by a newer bill, or (group) one of its fees changed or was
            // settled elsewhere, or (transfer group) the slip was rejected.
            $table->timestamp('superseded_at')->nullable();

            $table->unique(['gateway', 'bill_code']);
            $table->index('reference');
            $table->index('transaction_id');
            $table->index(['club_id', 'user_id', 'method']);
        });

        // The fees a group bill covers, with the amount each was billed for.
        Schema::create('payment_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_sen');

            $table->unique(['payment_bill_id', 'payment_id']);
            $table->index('payment_id');
        });

        // The group transfer ("Bayar semua" by slip) the fee's current slip
        // submission belongs to; null for a slip sent for the fee alone. Set on
        // a group submit, cleared on a single submit, a rejection or a revert.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('transfer_group_id')->nullable()->after('transfer_submitted_at')
                ->constrained('payment_bills')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_group_id');
        });
        Schema::dropIfExists('payment_bill_items');
        Schema::dropIfExists('payment_bills');
    }
};
