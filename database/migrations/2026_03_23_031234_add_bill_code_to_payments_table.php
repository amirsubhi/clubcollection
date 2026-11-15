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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('bill_code')->nullable()->after('reference');
            $table->string('transaction_id')->nullable()->after('bill_code');
            // Amount the open bill was created for; a bill is only reused while it matches.
            $table->decimal('bill_amount', 10, 2)->nullable()->after('bill_code');
            // Gateway-fee mode the open bill was created with: who bore the fee
            // ('club'|'member') and the fee per bill in sen. bill_amount stays the
            // fee amount (without any gateway charge). An open bill keeps its own
            // fee mode even if the club setting changes later.
            $table->string('bill_fee_payer', 10)->nullable()->after('bill_amount');
            $table->unsignedInteger('bill_fee_sen')->nullable()->after('bill_fee_payer');
            // Set when a verified gateway webhook marks the fee paid: the gateway
            // name, and the gateway fee the club absorbed on that bill (0 when the
            // member paid it). Null for fees settled by hand.
            $table->string('paid_via', 20)->nullable()->after('transaction_id');
            $table->unsignedInteger('fee_absorbed_sen')->nullable()->after('paid_via');
            // Manual bank / DuitNow transfer (P2). What the member typed when
            // uploading the slip, and who checked it. paid_via is 'transfer' once
            // a club admin approves the slip.
            $table->date('transfer_date')->nullable()->after('fee_absorbed_sen');
            $table->string('transfer_ref', 60)->nullable()->after('transfer_date');
            // The club's bank-statement reference the approving admin typed. Kept
            // apart from `reference`, which the webhook uses to find gateway bills.
            $table->string('transfer_bank_ref', 100)->nullable()->after('transfer_ref');
            $table->timestamp('transfer_submitted_at')->nullable()->after('transfer_ref');
            $table->foreignId('verified_by')->nullable()->after('transfer_submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('rejection_reason', 500)->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['transfer_date', 'transfer_ref', 'transfer_bank_ref', 'transfer_submitted_at', 'verified_at', 'rejection_reason']);
            $table->dropColumn(['bill_code', 'bill_amount', 'bill_fee_payer', 'bill_fee_sen', 'transaction_id', 'paid_via', 'fee_absorbed_sen']);
        });
    }
};
