<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway', 20)->nullable()->after('bill_code');
        });

        // Existing payments with a bill_code came from the only gateway in use
        // before this migration. Backfill so reporting/audit queries can rely
        // on the column without ambiguous nulls.
        DB::table('payments')->whereNotNull('bill_code')->update(['gateway' => 'toyyibpay']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway');
        });
    }
};
