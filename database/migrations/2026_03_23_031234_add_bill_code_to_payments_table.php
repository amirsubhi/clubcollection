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
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['bill_code', 'bill_amount', 'bill_fee_payer', 'bill_fee_sen', 'transaction_id', 'paid_via', 'fee_absorbed_sen']);
        });
    }
};
