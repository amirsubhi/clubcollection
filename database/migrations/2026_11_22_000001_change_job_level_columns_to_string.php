<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * club_user.job_level and fee_rates.job_level hold a club's own level codes (club_job_levels.code), so they
 * become VARCHAR(40) instead of the old five-value enum. Values and the club_user_job_level_idx index are kept.
 *
 * down() puts the enum back only while every row still holds one of the five old codes. Otherwise it stops with
 * an error instead of letting MariaDB / MySQL truncate the custom codes: move those members and fee rates to an
 * old code first.
 */
return new class extends Migration
{
    private const TABLES = ['club_user', 'fee_rates'];

    private const LEGACY_CODES = ['gm', 'agm', 'manager', 'executive', 'non_exec'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('job_level', 40)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::table($name)->whereNotIn('job_level', self::LEGACY_CODES)->exists()) {
                throw new RuntimeException("Cannot roll back: {$name}.job_level holds club-defined job level codes. "
                    .'Move those rows to one of '.implode(', ', self::LEGACY_CODES).' first.');
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->enum('job_level', self::LEGACY_CODES)->change();
            });
        }
    }
};
