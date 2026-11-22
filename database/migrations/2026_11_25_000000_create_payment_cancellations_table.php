<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Cancel payment" (App\Services\Payments\PaymentCancellationService): one row per fee taken back from paid, with
 * what the fee was paid with at that moment (receipt number, paid date and method, gateway transaction, bank
 * references, the issuer snapshot) and why. The fee itself is unpaid again; this table is the record.
 *
 * - payment_id has no foreign key, so the history survives a later delete of the fee (like
 *   receipt_allocation_payments).
 * - event_key: one uuid per cancel action; the fees of one set ("Bayar semua" bill or slip) share it.
 * - payment_bill_id: the set's bill or slip group, when the fee was paid in a set (no foreign key either: kept
 *   as the record even if the bill row is ever removed).
 * - Additive only (no change to existing tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payment_id');
            $table->uuid('event_key');
            $table->string('receipt_no', 32)->nullable();
            $table->unsignedBigInteger('amount_sen');
            $table->date('paid_date')->nullable();
            $table->string('paid_via', 20)->nullable();
            $table->string('gateway', 20)->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('transfer_bank_ref', 255)->nullable();
            $table->string('transfer_ref', 60)->nullable();
            $table->unsignedBigInteger('payment_bill_id')->nullable();
            $table->json('issuer_snapshot')->nullable();
            $table->string('reason_code', 32);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_id', 'payment_cxl_payment_idx');
            $table->index('event_key', 'payment_cxl_event_idx');
            $table->index('payment_bill_id', 'payment_cxl_bill_idx');
            $table->index('transaction_id', 'payment_cxl_txn_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_cancellations');
    }
};
