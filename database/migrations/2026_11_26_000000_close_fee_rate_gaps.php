<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only: close the gaps the old fee-rate form left. It ended the previous rate on the day a new one was
 * entered, even when the new rate started later, so the months in between (e.g. December, for a rate entered in
 * November that starts on 1 January) had no rate and Generate fees / pay ahead refused them.
 *
 * Per club + job level, rates in effective_from order (then id): when a rate ends before the day before the next
 * rate starts, it is extended to that day. Rows that end before they start (replaced by a backdated rate) are
 * history and are skipped. Dates are worked out in PHP (Carbon), so it runs the same on SQLite, MariaDB and MySQL.
 * Running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('fee_rates')
            ->orderBy('club_id')->orderBy('job_level')->orderBy('effective_from')->orderBy('id')
            ->get(['id', 'club_id', 'job_level', 'effective_from', 'effective_to']);

        $date = fn ($v) => $v === null ? null : Carbon::parse($v)->toDateString();

        foreach ($rows->groupBy(fn ($r) => $r->club_id.'|'.$r->job_level) as $levelRows) {
            $chain = $levelRows
                ->map(fn ($r) => (object) ['id' => $r->id, 'from' => $date($r->effective_from), 'to' => $date($r->effective_to)])
                ->reject(fn ($r) => $r->to !== null && $r->to < $r->from)
                ->values();

            for ($i = 0; $i < $chain->count() - 1; $i++) {
                $rate = $chain[$i];
                $dayBeforeNext = Carbon::parse($chain[$i + 1]->from)->subDay()->toDateString();
                if ($rate->to !== null && $rate->to < $dayBeforeNext) {
                    DB::table('fee_rates')->where('id', $rate->id)->update(['effective_to' => $dayBeforeNext]);
                }
            }
        }
    }

    /**
     * No-op: the old end dates are not recorded, and they only ever made months unbillable (a gap). Rolling back
     * the code does not need them either: the old code reads effective_to the same way.
     */
    public function down(): void {}
};
