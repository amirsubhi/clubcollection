<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings phase 2: receipt numbers per club, {PREFIX}-{FY start year}-{NNNN} (App\Support\Payments\ReceiptNumbers).
 *
 * - clubs.receipt_prefix: 2-6 of A-Z / 0-9, default RCP. A change affects only receipts issued afterwards.
 * - receipt_counters: the last number issued per club and financial year. The primary key is the pair, so the
 *   row is created and advanced by one upsert (no "read, then insert if missing" race).
 * - receipt_allocations: every number ever issued, one row per receipt document (one fee, or one "Bayar semua"
 *   bill / slip: payment_bill_id). Never deleted, never reused: the unique indexes are the backstop.
 * - receipt_allocation_payments: which fees a number was issued to; voided_at when the fee is taken back from
 *   paid (the number stays spent). payment_id has no foreign key, so the log survives a deleted fee.
 * - payments.receipt_no / payment_bills.receipt_no: the number shown, copied from the allocation. Not unique on
 *   payments: the fees of one group receipt share it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('receipt_prefix', 6)->default('RCP')->after('financial_year_start_month');
        });

        Schema::create('receipt_counters', function (Blueprint $table) {
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fy_start_year');
            $table->unsignedInteger('last_no')->default(0);
            $table->primary(['club_id', 'fy_start_year']);
        });

        Schema::create('receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fy_start_year');
            $table->unsignedInteger('seq');
            $table->string('receipt_no', 32);
            $table->foreignId('payment_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['club_id', 'receipt_no']);
            $table->unique(['club_id', 'fy_start_year', 'seq']);
        });

        Schema::create('receipt_allocation_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_allocation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payment_id')->index();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['receipt_allocation_id', 'payment_id'], 'receipt_alloc_payments_alloc_payment_unique'); // MySQL/MariaDB cap index names at 64 characters
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_no', 32)->nullable()->index();
        });

        Schema::table('payment_bills', function (Blueprint $table) {
            $table->string('receipt_no', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_bills', function (Blueprint $table) {
            $table->dropColumn('receipt_no');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['receipt_no']);
            $table->dropColumn('receipt_no');
        });
        Schema::dropIfExists('receipt_allocation_payments');
        Schema::dropIfExists('receipt_allocations');
        Schema::dropIfExists('receipt_counters');
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('receipt_prefix');
        });
    }
};
