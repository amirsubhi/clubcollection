<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\Payments\MonthlyFeeGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * "Generate fees for a month": preview who will be billed (GET), then create the fees (POST). The POST works the
 * list out again and refuses when it no longer matches what the admin saw (count, total, or who is billed what:
 * MonthlyFeeGenerator::fingerprint()).
 */
class FeeGenerationController extends Controller
{
    use AuthorizesClubResource;

    public function __construct(private MonthlyFeeGenerator $generator) {}

    public function preview(Request $request, Club $club)
    {
        $this->authorizeClubAdmin($club);
        if ($refusal = $this->refuseInactive($club)) {
            return $refusal;
        }

        $months = MonthlyFeeGenerator::allowedMonths();
        $month = MonthlyFeeGenerator::parseMonth($request->query('month', array_key_first($months)));
        if ($month === null) {
            return redirect()->route('admin.clubs.payments.generate', $club)
                ->with('error', 'Fees can be generated for this month or next month only.');
        }

        $due = MonthlyFeeGenerator::defaultDueDate($month);
        $dueError = null;
        // A due date is kept only for the month it was chosen for (due_for): switching the month starts again
        // from that month's default, so December's fees don't inherit a November date.
        if ($request->filled('due_date') && $request->query('due_for') === $month->format('Y-m')) {
            [$parsed, $dueError] = $this->parseDue($request->query('due_date'), $month);
            $due = $parsed ?? $due;
        }

        $preview = $this->generator->preview($club, $month);

        return view('admin.payments.generate', [
            'club' => $club,
            'months' => $months,
            'month' => $month,
            'due' => $due,
            'dueError' => $dueError,
            // The field shows what was typed when it was refused (then there is nothing to confirm).
            'dueInput' => $dueError ? (string) $request->query('due_date') : $due->toDateString(),
            'earliestDue' => MonthlyFeeGenerator::earliestDueDate($month),
            'latestDue' => MonthlyFeeGenerator::latestDueDate($month),
            'preview' => $preview,
            'fingerprint' => MonthlyFeeGenerator::fingerprint($preview, $month, $due),
            'labels' => MonthlyFeeGenerator::SKIP_LABELS,
        ]);
    }

    public function store(Request $request, Club $club)
    {
        $this->authorizeClubAdmin($club);
        if ($refusal = $this->refuseInactive($club)) {
            return $refusal;
        }

        $data = $request->validate([
            'month' => ['required', 'string'],
            'due_date' => ['required', 'string'],
            'expected_count' => ['required', 'integer', 'min:1'],
            'expected_total_sen' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string'],
        ]);
        $month = MonthlyFeeGenerator::parseMonth($data['month']);
        if ($month === null) {
            return redirect()->route('admin.clubs.payments.generate', $club)
                ->with('error', 'Fees can be generated for this month or next month only.');
        }
        [$due, $dueError] = $this->parseDue($data['due_date'], $month);
        $back = route('admin.clubs.payments.generate', [$club, 'month' => $month->format('Y-m'), 'due_date' => $data['due_date'], 'due_for' => $month->format('Y-m')]);
        if ($due === null) {
            return redirect($back)->with('error', $dueError);
        }

        // Work the list out again: members, rates or fees may have changed since the preview.
        $preview = $this->generator->preview($club, $month);
        if ($preview['count'] !== (int) $data['expected_count'] || $preview['totalSen'] !== (int) $data['expected_total_sen']
            || ! hash_equals(MonthlyFeeGenerator::fingerprint($preview, $month, $due), $data['fingerprint'])) {
            return redirect($back)->with('error', 'The list changed since you previewed it (it now bills '.$preview['count']
                .' '.($preview['count'] === 1 ? 'member' : 'members').', RM '.number_format($preview['totalSen'] / 100, 2)
                .'). Nothing was created: review it again and confirm.');
        }

        $result = $this->generator->generate($club, $month, $due, $request->user(), $preview);

        $message = "Created {$result['created']} ".($result['created'] === 1 ? 'fee' : 'fees').' for '.$month->format('M Y')
            .', total RM '.number_format($result['totalSen'] / 100, 2).', due '.$due->format('j M Y').'.';
        if ($result['skipped']) {
            $message .= ' Skipped: '.collect($result['skipped'])
                ->map(fn ($n, $k) => strtolower(MonthlyFeeGenerator::SKIP_LABELS[$k]).' ('.$n.')')->implode(', ').'.';
        }

        return redirect()->route('admin.clubs.payments.index', [$club, 'month' => $month->format('Y-m')])
            ->with('success', $message);
    }

    /** A deactivated club bills nobody (the super admin can still open it). */
    private function refuseInactive(Club $club)
    {
        return $club->is_active ? null : redirect()->route('admin.clubs.payments.index', $club)
            ->with('error', 'This club is inactive, so fees can\'t be generated for it.');
    }

    /**
     * A due date from today (a past one would have the overdue reminder email everyone at once) and not before
     * the billed month's 1st, up to the end of the month after the billed month.
     *
     * @return array{0: ?Carbon, 1: ?string}
     */
    private function parseDue(mixed $value, Carbon $month): array
    {
        $due = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::createFromFormat('!Y-m-d', $value) : false;
        if (! $due || $due->format('Y-m-d') !== $value) {
            return [null, 'Enter the due date as a date.'];
        }
        if ($due->lt(today())) {
            return [null, 'The due date can\'t be in the past.'];
        }
        $earliest = MonthlyFeeGenerator::earliestDueDate($month);
        if ($due->lt($earliest)) {
            return [null, 'The due date can\'t be before the month it bills starts ('.$earliest->format('j M Y').').'];
        }
        $latest = MonthlyFeeGenerator::latestDueDate($month);
        if ($due->gt($latest)) {
            return [null, 'The due date can be at most '.$latest->format('j M Y').'.'];
        }

        return [$due, null];
    }
}
