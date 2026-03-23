<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\FeeRate;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeeRateController extends Controller
{
    public function index(Club $club)
    {
        $rates = $club->feeRates()->orderByDesc('effective_from')->get()
            ->groupBy('job_level');
        $jobLevels = FeeRate::jobLevelLabels();
        return view('admin.fee-rates.index', compact('club', 'rates', 'jobLevels'));
    }

    public function create(Club $club)
    {
        $jobLevels = FeeRate::jobLevelLabels();
        return view('admin.fee-rates.create', compact('club', 'jobLevels'));
    }

    public function store(Request $request, Club $club)
    {
        $request->validate([
            'rates'                    => 'required|array',
            'rates.*.job_level'        => 'required|in:gm,agm,manager,executive,non_exec',
            'rates.*.monthly_amount'   => 'required|numeric|min:0',
            'rates.*.effective_from'   => 'required|date',
        ]);

        $newRates = [];
        DB::transaction(function () use ($request, $club, &$newRates) {
            foreach ($request->rates as $rate) {
                // Close previous active rate for this level
                $club->feeRates()
                    ->where('job_level', $rate['job_level'])
                    ->whereNull('effective_to')
                    ->update(['effective_to' => now()->toDateString()]);

                $club->feeRates()->create([
                    'job_level'      => $rate['job_level'],
                    'monthly_amount' => $rate['monthly_amount'],
                    'effective_from' => $rate['effective_from'],
                    'effective_to'   => null,
                ]);
                $newRates[] = "{$rate['job_level']}: RM {$rate['monthly_amount']}";
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

        return redirect()->route('admin.fee-rates.index', $club)
            ->with('success', 'Fee rates updated successfully.');
    }

    public function destroy(Club $club, FeeRate $feeRate)
    {
        if ($feeRate->club_id !== $club->id) {
            abort(403, 'This fee rate does not belong to this club.');
        }

        $old = $feeRate->only(['job_level', 'monthly_amount', 'effective_from', 'effective_to']);
        $feeRate->delete();

        AuditService::log(
            'fee_rate.deleted',
            "Fee rate for '{$old['job_level']}' (RM {$old['monthly_amount']}, from {$old['effective_from']}) deleted.",
            null,
            $club->id,
            $old
        );

        return redirect()->route('admin.fee-rates.index', $club)
            ->with('success', 'Fee rate deleted.');
    }
}
