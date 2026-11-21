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
        // MySQL / MariaDB let a foreign key use any index that starts with its
        // column, and drop the key's own index once one is added above. Give
        // each key a plain index back first, or the drops below fail (1553).
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['payments' => ['club_id', 'user_id', 'recorded_by'], 'expenses' => ['club_id', 'recorded_by']] as $name => $columns) {
                // Indexes left once this migration's own are gone.
                $ours = ['payments_club_status_idx', 'payments_club_paid_date_idx', 'payments_user_id_idx', 'payments_recorded_by_idx', 'expenses_club_date_idx', 'expenses_recorded_by_idx'];
                $kept = collect(Schema::getIndexes($name))->reject(fn ($i) => in_array($i['name'], $ours, true));
                Schema::table($name, function (Blueprint $table) use ($columns, $kept) {
                    foreach ($columns as $column) {
                        if (! $kept->contains(fn ($i) => ($i['columns'][0] ?? null) === $column)) {
                            $table->index($column);
                        }
                    }
                });
            }
        }

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
