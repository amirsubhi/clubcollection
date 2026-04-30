<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('payment_gateway', 20)->default('toyyibpay')->after('toyyibpay_category_code');
            // Encrypted at rest via Eloquent cast on the model
            $table->text('billplz_api_key')->nullable()->after('payment_gateway');
            $table->string('billplz_collection_id', 100)->nullable()->after('billplz_api_key');
            $table->text('billplz_x_signature_key')->nullable()->after('billplz_collection_id');
        });

        // Explicit backfill so existing rows match the default regardless of
        // database driver behaviour around DEFAULT on column add.
        DB::table('clubs')->update(['payment_gateway' => 'toyyibpay']);
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn([
                'payment_gateway',
                'billplz_api_key',
                'billplz_collection_id',
                'billplz_x_signature_key',
            ]);
        });
    }
};
