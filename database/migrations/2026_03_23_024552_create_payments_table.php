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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('frequency', ['monthly', 'quarterly', 'yearly']);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->date('paid_date')->nullable();
            $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending');
            $table->string('reference')->nullable();
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
