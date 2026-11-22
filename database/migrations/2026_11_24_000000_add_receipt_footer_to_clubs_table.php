<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings phase 2: an optional plain-text footer (a line or a few) the club prints at the foot of its official
 * receipts and receipt emails, under "computer-generated". Copied into payments.issuer_snapshot when a fee
 * becomes paid, so an issued receipt keeps the footer it was issued with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('receipt_footer', 300)->nullable()->after('financial_year_start_month');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('receipt_footer');
        });
    }
};
