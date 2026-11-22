<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Club profile (settings phase 1): the details members see on invoices and receipts, and the month the club's
 * financial year starts (1 = January, the calendar year: what every club had before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('registration_no', 60)->nullable()->after('email');
            $table->string('address', 500)->nullable()->after('registration_no');
            $table->unsignedTinyInteger('financial_year_start_month')->default(1)->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['registration_no', 'address', 'financial_year_start_month']);
        });
    }
};
