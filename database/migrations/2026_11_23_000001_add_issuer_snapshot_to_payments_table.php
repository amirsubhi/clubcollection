<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The club details a receipt was issued with (name, registration no., address, logo path, email), written once
 * when the fee becomes paid (Payment::booted). Older paid fees keep null and show the club as it is now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->json('issuer_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('issuer_snapshot');
        });
    }
};
