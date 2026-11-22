<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A set's receipt is found by club and number (MemberPaymentPresenter::partOfGroupReceipt, the cancel service).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_bills', function (Blueprint $table) {
            $table->index(['club_id', 'receipt_no'], 'payment_bills_club_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_bills', function (Blueprint $table) {
            $table->dropIndex('payment_bills_club_receipt_idx');
        });
    }
};
