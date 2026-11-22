<?php

namespace App\Support\Payments;

use App\Models\Club;
use App\Models\FeeRate;
use App\Models\User;
use App\Support\Member\MemberPaymentPresenter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place a member's monthly fee is worked out (Generate fees, pay ahead).
 *
 * Rule: the member's job level in the club (club_user.job_level) → the club's rate for that level in force in
 * the month: effective_from on or before the month's last day, and effective_to empty or on/after the month's
 * first day. When several match (a rate changed during the month), the latest effective_from wins (then the
 * newest row). No rate, or a rate of RM 0, gives null: there is nothing to bill.
 */
final class FeeRates
{
    /**
     * The rate in force in $month for each of the club's job levels that has one, keyed by level code.
     *
     * @return Collection<string, FeeRate>
     */
    public static function inForce(Club|int $club, CarbonInterface $month, ?string $level = null): Collection
    {
        return self::pick(self::rows($club, $level), $month);
    }

    /**
     * The member's monthly fee in sen for each month given (keyed by the month's first day, Y-m-d), from one
     * level lookup and one rate query. A month without a rate (or with a zero rate) is null.
     *
     * @param  iterable<CarbonInterface>  $months
     * @return array<string, int|null>
     */
    public static function monthlySenByMonth(Club $club, User|int $user, iterable $months): array
    {
        $level = self::levelOf($club, $user);
        $rows = $level === null ? collect() : self::rows($club, $level);
        $out = [];
        foreach ($months as $month) {
            $out[$month->copy()->startOfMonth()->toDateString()] = $level === null ? null : self::sen(self::pick($rows, $month)->get($level));
        }

        return $out;
    }

    /** The member's monthly fee in sen for $month, or null (not a member of the club, no rate, or a zero rate). */
    public static function monthlySen(Club $club, User|int $user, CarbonInterface $month): ?int
    {
        return self::monthlySenByMonth($club, $user, [$month])[$month->copy()->startOfMonth()->toDateString()];
    }

    /** A rate's monthly amount in sen; null for no rate or a zero rate. */
    public static function sen(?FeeRate $rate): ?int
    {
        if ($rate === null) {
            return null;
        }
        $sen = MemberPaymentPresenter::sen($rate->monthly_amount);

        return $sen > 0 ? $sen : null;
    }

    /** The member's job level code in the club (club_user.job_level), null when not a member. */
    private static function levelOf(Club $club, User|int $user): ?string
    {
        $level = DB::table('club_user')
            ->where('club_id', $club->id)
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->value('job_level');

        return $level === null || $level === '' ? null : (string) $level;
    }

    /** @return Collection<int, FeeRate> */
    private static function rows(Club|int $club, ?string $level): Collection
    {
        return FeeRate::query()
            ->where('club_id', $club instanceof Club ? $club->id : $club)
            ->when($level !== null, fn ($q) => $q->where('job_level', $level))
            ->get();
    }

    /**
     * The rule itself, on loaded rows: in force in the month, latest effective_from (then newest row) per level.
     *
     * @param  Collection<int, FeeRate>  $rows
     * @return Collection<string, FeeRate>
     */
    private static function pick(Collection $rows, CarbonInterface $month): Collection
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        return $rows
            ->filter(fn (FeeRate $r) => $r->effective_from->toDateString() <= $end
                && ($r->effective_to === null || $r->effective_to->toDateString() >= $start))
            ->sort(fn (FeeRate $a, FeeRate $b) => [$b->effective_from->toDateString(), $b->id] <=> [$a->effective_from->toDateString(), $a->id])
            ->unique('job_level')
            ->keyBy('job_level');
    }
}
