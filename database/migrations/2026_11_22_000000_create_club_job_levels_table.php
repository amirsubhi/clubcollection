<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Job levels become per club (club_job_levels). Every club that already exists gets the five levels the portal
 * used to hard-code, with the same codes and labels, so its members and fee rates read exactly as before (the
 * admins can rename, reorder or deactivate them afterwards). New clubs start with one level, "Ahli"
 * (Club::createDefaultJobLevel()).
 *
 * down() drops the table: level names, order and active flags are lost; members and fee rates keep their codes.
 */
return new class extends Migration
{
    /** The codes and labels the portal hard-coded (FeeRate) before levels were per club. */
    private const LEGACY_LEVELS = [
        'gm' => 'GM',
        'agm' => 'AGM',
        'manager' => 'Manager',
        'executive' => 'Executive',
        'non_exec' => 'Non-Executive',
    ];

    public function up(): void
    {
        Schema::create('club_job_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['club_id', 'code']);
        });

        $this->backfill();
    }

    /** Give every club that has no job levels the five legacy ones (public for the test: no DDL). */
    public function backfill(): void
    {
        $now = now();
        $without = DB::table('clubs')->whereNotExists(fn ($q) => $q->from('club_job_levels')->whereColumn('club_job_levels.club_id', 'clubs.id'));
        foreach ($without->orderBy('id')->pluck('id') as $clubId) {
            $rows = [];
            foreach (array_keys(self::LEGACY_LEVELS) as $i => $code) {
                $rows[] = [
                    'club_id' => $clubId,
                    'code' => $code,
                    'name' => self::LEGACY_LEVELS[$code],
                    'sort_order' => $i + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('club_job_levels')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('club_job_levels');
    }
};
