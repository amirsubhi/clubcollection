<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // payments: most-filtered columns
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['club_id', 'status'],     'payments_club_status_idx');
            $table->index(['club_id', 'paid_date'],  'payments_club_paid_date_idx');
            $table->index('user_id',                 'payments_user_id_idx');
            $table->index('recorded_by',             'payments_recorded_by_idx');
        });

        // expenses: most-filtered columns
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['club_id', 'expense_date'], 'expenses_club_date_idx');
            $table->index('recorded_by',               'expenses_recorded_by_idx');
        });

        // club_user pivot: job_level used for filtering payments by level
        Schema::table('club_user', function (Blueprint $table) {
            $table->index('job_level', 'club_user_job_level_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_club_status_idx');
            $table->dropIndex('payments_club_paid_date_idx');
            $table->dropIndex('payments_user_id_idx');
            $table->dropIndex('payments_recorded_by_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_club_date_idx');
            $table->dropIndex('expenses_recorded_by_idx');
        });

        Schema::table('club_user', function (Blueprint $table) {
            $table->dropIndex('club_user_job_level_idx');
        });
    }
};
