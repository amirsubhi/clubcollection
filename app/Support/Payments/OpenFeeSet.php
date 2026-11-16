<?php

namespace App\Support\Payments;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Bayar semua" (R1b): the set of a member's fees still owed in one club,
 * and the check that it is still exactly the set the member confirmed.
 */
final class OpenFeeSet
{
    /** Fees still owed (pending or overdue; not paid, not being checked), oldest first. */
    public static function query(int $clubId, int $userId): Builder
    {
        return Payment::query()
            ->where('club_id', $clubId)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->orderBy('period_start')
            ->orderBy('id');
    }

    /**
     * The member confirmed exactly these fees at exactly this total (a set
     * is at least two fees).
     *
     * @param  Collection<int, Payment>  $fees
     * @param  array<int, int|string>  $ids
     */
    public static function matches(Collection $fees, array $ids, int $totalSen): bool
    {
        $have = $fees->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $want = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $fees->count() >= 2 && $have === $want
            && $fees->sum(fn (Payment $p) => $p->amountSen()) === $totalSen;
    }
}
