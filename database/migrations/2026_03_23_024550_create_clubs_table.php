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
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            // Billing plans members may choose when paying ahead (P1).
            $table->boolean('allow_monthly')->default(true);
            $table->boolean('allow_quarterly')->default(true);
            $table->boolean('allow_yearly')->default(true);
            $table->string('default_plan', 20)->default('yearly');
            // Who bears the gateway's per-bill fee: 'club' (absorbs) or 'member'
            // (added on top). gateway_fee_sen is the expected fee per bill.
            $table->string('gateway_fee_payer', 10)->default('club');
            $table->unsignedInteger('gateway_fee_sen')->default(100);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
