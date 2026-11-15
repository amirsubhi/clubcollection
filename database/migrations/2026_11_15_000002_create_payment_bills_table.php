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
     */
    public function up(): void
    {
        Schema::create('payment_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->string('bill_code');
            // The reference sent with the bill: the per-bill webhook token is an HMAC of it.
            $table->string('reference')->nullable();
            // The fee amount the bill was created for (without any gateway charge).
            $table->unsignedInteger('amount_sen');
            $table->string('fee_payer', 10)->nullable();
            $table->unsignedInteger('fee_sen')->nullable();
            // Set when a verified webhook settled the fee with this bill.
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->unique(['gateway', 'bill_code']);
            $table->index('reference');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_bills');
    }
};
