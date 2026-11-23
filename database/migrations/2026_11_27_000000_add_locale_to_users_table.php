<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Language phase 2: the member's saved language (a code of a lang/member/<code>.json file, e.g. "ms", "en"), set
 * by the member app's language switch. NULL = no choice saved: the portal's default language (Portal settings).
 * Read through App\Support\UserLocale, which ignores a code that is no longer available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
