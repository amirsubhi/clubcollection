<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Welfare (sumbangan kebajikan) details, 1:1 with a welfare-type expense.
     * Personal fields are `encrypted` casts on App\Models\WelfareCase, so they
     * are text columns here.
     */
    public function up(): void
    {
        Schema::create('welfare_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->text('subject_name');
            $table->text('relationship');
            $table->text('recipient_name');
            $table->foreignId('certificate_sighted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('certificate_sighted_at')->nullable();
            $table->date('retention_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welfare_cases');
    }
};
