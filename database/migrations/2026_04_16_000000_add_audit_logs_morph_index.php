<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * audit_logs is queried as `WHERE auditable_type = ? AND auditable_id = ?`
     * to surface the history for a single record. nullableMorphs() only
     * indexes the columns individually; add the composite so the planner
     * can use a single-pass lookup as the table grows.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_auditable_idx');
        });
    }
};
