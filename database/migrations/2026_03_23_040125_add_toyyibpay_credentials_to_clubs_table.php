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
        Schema::table('clubs', function (Blueprint $table) {
            // Stored as encrypted text via Laravel's built-in encryption
            $table->text('toyyibpay_secret_key')->nullable()->after('is_active');
            $table->string('toyyibpay_category_code')->nullable()->after('toyyibpay_secret_key');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['toyyibpay_secret_key', 'toyyibpay_category_code']);
        });
    }
};
