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
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['bill_code', 'bill_amount', 'transaction_id']);
        });
    }
};
