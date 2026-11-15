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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            // Fund Usage workflow. type / status are strings validated in PHP
            // (App\Models\Expense::TYPES / STATUSES), never DB enums.
            $table->string('type', 20)->default('expense');      // expense | welfare
            $table->string('status', 20)->default('draft');      // draft | submitted | approved | void
            $table->string('description');
            $table->string('payee')->nullable();
            $table->string('invoice_no', 100)->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');                        // invoice date (welfare: committee approval date)
            $table->date('paid_date')->nullable();               // when the money actually left the account
            $table->string('minute_ref')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('return_reason')->nullable();
            // Two-step void: voided_by requests it, void_approved_by (a different admin) confirms.
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('void_requested_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('void_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status'], 'expenses_club_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
