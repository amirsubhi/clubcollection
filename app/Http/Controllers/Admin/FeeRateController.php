<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\FeeRate;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FeeRateController extends Controller
{
    use AuthorizesClubResource;

    public function index(Club $club)
    {
        $rates = $club->feeRates()->orderByDesc('effective_from')->get()
            ->groupBy('job_level');
        // Active levels, then inactive ones (or a code with no level row) that still have rate history.
        $active = $club->activeJobLevelNames();
        $jobLevels = $active;
        foreach ($rates->keys() as $code) {
            $jobLevels[$code] ??= $club->jobLevelName($code);
        }
        $inactive = array_keys(array_diff_key($jobLevels, $active));

        return view('admin.fee-rates.index', compact('club', 'rates', 'jobLevels', 'inactive'));
    }

    public function create(Club $club)
    {
        // Only active levels get new rates: a deactivated level is no longer offered.
        $jobLevels = $club->activeJobLevelNames();
        return view('admin.fee-rates.create', compact('club', 'jobLevels'));
    }

    public function store(Request $request, Club $club)
    {
        $names = $club->activeJobLevelNames();
        $request->validate([
            'rates'                    => 'required|array',
            'rates.*.job_level'        => ['required', 'string', 'distinct', Rule::in(array_keys($names))],
            'rates.*.monthly_amount'   => 'required|numeric|min:0',
            'rates.*.effective_from'   => 'required|date',
        ]);

        $newRates = [];
        DB::transaction(function () use ($request, $club, $names, &$newRates) {
            foreach ($request->rates as $rate) {
                // The new rate takes over from its start date: every rate of the level still in force then (open,
                // or ending on/after the day before) now ends the day before. That keeps the old rate until a
                // future-dated one starts (no gap), and a backdated correction ("RM 8 since October") wins over a
                // rate saved earlier that starts after it: such a row ends before it starts and is kept only as
                // history (FeeRates ignores it).
                $closeOn = Carbon::parse($rate['effective_from'])->startOfDay()->subDay()->toDateString();
                $club->feeRates()
                    ->where('job_level', $rate['job_level'])
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $closeOn))
                    ->update(['effective_to' => $closeOn]);

                $club->feeRates()->create([
                    'job_level'      => $rate['job_level'],
                    'monthly_amount' => $rate['monthly_amount'],
                    'effective_from' => $rate['effective_from'],
                    'effective_to'   => null,
                ]);
                $newRates[] = "{$names[$rate['job_level']]}: RM {$rate['monthly_amount']}";
            }
        });

        AuditService::log(
            'fee_rate.updated',
            'Fee rates updated for club: ' . implode(', ', $newRates) . '.',
            null,
            $club->id,
            [],
            ['rates' => $newRates]
        );

        return redirect()->route('admin.clubs.fee-rates.index', $club)
            ->with('success', 'Fee rates updated successfully.');
    }

    /**
     * Shallow route (/admin/fee-rates/{fee_rate}): there is no {club} segment,
     * so the club comes from the rate itself and is authorized here (the old
     * signature bound an empty Club and refused every delete).
     */
    public function destroy(FeeRate $feeRate)
    {
        $club = $feeRate->club;
        $this->authorizeClubAdmin($club);

        $old = $feeRate->only(['job_level', 'monthly_amount', 'effective_from', 'effective_to']);
        $feeRate->delete();

        AuditService::log(
            'fee_rate.deleted',
            "Fee rate for '{$club->jobLevelName($old['job_level'])}' (RM {$old['monthly_amount']}, from {$old['effective_from']}) deleted.",
            null,
            $club->id,
            $old
        );

        return redirect()->route('admin.clubs.fee-rates.index', $club)
            ->with('success', 'Fee rate deleted.');
    }
}
