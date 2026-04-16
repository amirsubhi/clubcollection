<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sessions.user_id was unconstrained — deleting a user left orphan
     * session rows that still authenticated as the deleted user (until
     * SESSION_LIFETIME expired). Add a real FK with cascade-on-delete.
     *
     * Skipped on SQLite, which doesn't support adding a foreign key to an
     * existing table without rebuilding it. Production deployments use
     * MySQL/Postgres and pick this up; the dev/test SQLite default still
     * benefits from the cleared session via Auth::logout().
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
