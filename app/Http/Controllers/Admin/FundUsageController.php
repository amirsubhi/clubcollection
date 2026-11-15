<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FundUsageRequest;
use App\Models\Club;
use App\Models\Expense;
use App\Models\WelfareCase;
use App\Services\FundUsageService;
use App\Support\Admin\AdminNav;
use App\Support\Admin\FundUsagePresenter as P;
use App\Support\Admin\FundUsageStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fund Usage (Penggunaan Dana): expenses and welfare payouts with
 * documents and maker-checker approval. Business rules live in
 * FundUsageService; this controller authorizes, validates and renders.
 */
class FundUsageController extends Controller
{
    use AuthorizesClubResource;

    private const TABS = ['all', 'expense', 'welfare', 'mine', 'void'];

    public function __construct(private FundUsageService $service) {}

    public function index(Request $request, Club $club): Response
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(Expense::STATUSES)],
            'category' => ['nullable', 'array'],
            'category.*' => ['integer'],
            'sort' => ['nullable', Rule::in(['date', 'amount'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per' => ['nullable', 'integer', Rule::in([10, 20, 50])],
        ]);
        $tab = $filters['tab'] ?? 'all';
        $me = (int) $request->user()->id;

        $query = $this->tabQuery($club, $tab, $me)
            ->with(['category:id,name', 'recordedBy:id,name', 'approver:id,name'])
            ->withCount('attachments');

        if ($q = trim($filters['q'] ?? '')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(function (Builder $w) use ($like, $q) {
                $w->where('description', 'like', $like)
                    ->orWhere('payee', 'like', $like)
                    ->orWhere('invoice_no', 'like', $like);
                if (preg_match('/^(?:FU-\d{4}-)?0*(\d+)$/i', $q, $m)) {
                    $w->orWhere('expenses.id', (int) $m[1]);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }
        if (! empty($filters['category'])) {
            $query->whereIn('expense_category_id', $filters['category']);
        }

        $dir = $filters['dir'] ?? 'desc';
        if (($filters['sort'] ?? 'date') === 'amount') {
            $query->orderBy('amount', $dir);
        } else {
            $query->orderByRaw('COALESCE(paid_date, expense_date) '.($dir === 'asc' ? 'asc' : 'desc'));
        }
        $query->orderBy('id', $dir);

        $totalSen = P::sen((clone $query)->reorder()->sum('amount'));
        $page = $query->paginate($filters['per'] ?? 10)->withQueryString();

        $stats = new FundUsageStats($club);
        $awaiting = $stats->awaiting($me);

        return Inertia::render('Admin/FundUsage/Index', [
            'nav' => AdminNav::props($club, $request->user(), $awaiting['mine']),
            'kpis' => [
                'collectedYtd' => $stats->collectedBetween(now()->startOfYear(), now()->endOfYear()),
                'spentYtd' => $stats->spentBetween(now()->startOfYear(), now()->endOfYear(), 'expense'),
                'welfareYtd' => $stats->spentBetween(now()->startOfYear(), now()->endOfYear(), 'welfare'),
                'welfareCountYtd' => $stats->welfareCountBetween(now()->startOfYear(), now()->endOfYear()),
                'balance' => $stats->balance(),
                'awaiting' => $awaiting['count'],
                'awaitingMine' => $awaiting['mine'],
                'year' => now()->year,
            ],
            'tabCounts' => collect(self::TABS)->mapWithKeys(fn ($t) => [$t => $this->tabQuery($club, $t, $me)->count()]),
            'filters' => [
                'tab' => $tab,
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? [],
                'category' => array_map('intval', $filters['category'] ?? []),
                'sort' => $filters['sort'] ?? 'date',
                'dir' => $dir,
                'per' => (int) ($filters['per'] ?? 10),
            ],
            'categories' => $club->expenseCategories()->orderBy('name')->get(['id', 'name']),
            'rows' => collect($page->items())->map(fn (Expense $e) => P::row($e, $me))->values(),
            'pagination' => [
                'page' => $page->currentPage(),
                'pages' => $page->lastPage(),
                'total' => $page->total(),
                'totalAmount' => $totalSen,
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'urls' => [
                'index' => route('admin.clubs.fund-usage.index', $club),
                'createExpense' => route('admin.clubs.fund-usage.create', $club),
                'createWelfare' => route('admin.clubs.fund-usage.create', [$club, 'type' => 'welfare']),
            ],
        ]);
    }

    public function create(Request $request, Club $club): Response
    {
        $type = $request->query('type') === 'welfare' ? 'welfare' : 'expense';

        return $this->form($request, $club, null, $type);
    }

    public function store(FundUsageRequest $request, Club $club): RedirectResponse
    {
        $uploads = $request->uploads();
        if ($request->wantsSubmit() && $uploads === [] && ! $request->certificateSighted()) {
            throw ValidationException::withMessages(['files' => $request->input('type') === 'welfare'
                ? 'Attach at least one document or record who sighted the certificate to submit. You can still save it as a draft.'
                : 'Attach at least one document (invoice, receipt or bank slip) to submit. You can still save it as a draft.']);
        }

        $expense = $this->service->create($club, $request->user(), $request->expenseData(), $uploads, $request->welfareData());

        if ($request->wantsSubmit()) {
            $this->service->submit($expense, $request->user());

            return redirect()->route('admin.fund-usage.show', $expense)->with('success', "{$expense->reference()} submitted for approval.");
        }

        return redirect()->route('admin.fund-usage.show', $expense)->with('success', "{$expense->reference()} saved as draft.");
    }

    public function show(Request $request, Expense $expense): Response
    {
        $this->authorizeClubAdmin($expense->club);
        $user = $request->user();
        $me = (int) $user->id;
        $expense->load(['club', 'category', 'recordedBy:id,name', 'approver:id,name', 'voidRequester:id,name', 'voidApprover:id,name', 'attachments', 'welfareCase.beneficiary:id,name', 'welfareCase.sightedBy:id,name']);

        $status = $expense->status;
        $isRecorder = (int) $expense->recorded_by === $me;
        $blocker = $this->service->approvalBlocker($expense, $user);
        $eligible = $this->service->eligibleApproverCount($expense);
        $stats = new FundUsageStats($expense->club);

        return Inertia::render('Admin/FundUsage/Show', [
            'nav' => AdminNav::props($expense->club, $user, $stats->awaiting($me)['mine']),
            'item' => P::detail($expense, $me),
            'events' => P::timeline($expense),
            'can' => [
                'edit' => $isRecorder && in_array($status, ['draft', 'submitted'], true),
                'submit' => $isRecorder && $status === 'draft',
                'delete' => $isRecorder && $status === 'draft',
                'approve' => $status === 'submitted' && $blocker === null,
                'return' => $status === 'submitted' && ! $isRecorder && ! $this->service->isBeneficiary($expense, $user),
                'requestVoid' => $status === 'approved' && $expense->voided_by === null,
                'confirmVoid' => $status === 'approved' && $expense->voided_by !== null && (int) $expense->voided_by !== $me,
            ],
            'approvalBlocker' => $status === 'submitted' ? $blocker : null,
            // No club admin other than the maker/beneficiary: only a super admin can approve.
            'needsSecondApprover' => $status === 'submitted' && ($eligible === 0 || $isRecorder),
            'eligibleApprovers' => $eligible,
            'urls' => [
                'index' => route('admin.clubs.fund-usage.index', $expense->club),
                'edit' => route('admin.fund-usage.edit', $expense),
                'submit' => route('admin.fund-usage.submit', $expense),
                'approve' => route('admin.fund-usage.approve', $expense),
                'return' => route('admin.fund-usage.return', $expense),
                'voidRequest' => route('admin.fund-usage.void-request', $expense),
                'voidConfirm' => route('admin.fund-usage.void-confirm', $expense),
                'destroy' => route('admin.fund-usage.destroy', $expense),
            ],
        ]);
    }

    public function edit(Request $request, Expense $expense): Response|RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);
        if ((int) $expense->recorded_by !== (int) $request->user()->id) {
            abort(403, 'Only the admin who recorded this can edit it.');
        }
        if (! in_array($expense->status, ['draft', 'submitted'], true)) {
            return redirect()->route('admin.fund-usage.show', $expense)->with('error', 'Approved or void records cannot be edited.');
        }

        return $this->form($request, $expense->club, $expense, $expense->type);
    }

    public function update(FundUsageRequest $request, Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        $expense = $this->service->update(
            $expense,
            $request->user(),
            $request->expenseData(),
            $request->uploads(),
            $request->removeIds(),
            $request->welfareData(),
        );

        if ($request->wantsSubmit()) {
            $this->service->submit($expense, $request->user());

            return redirect()->route('admin.fund-usage.show', $expense)->with('success', "{$expense->reference()} submitted for approval.");
        }

        return redirect()->route('admin.fund-usage.show', $expense)->with('success', "{$expense->reference()} saved.");
    }

    public function submit(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        return $this->act($expense, fn () => $this->service->submit($expense, $request->user()), "{$expense->reference()} submitted for approval.");
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        return $this->act($expense, function () use ($request, $expense) {
            $request->validate(['declaration' => ['accepted']], ['declaration.accepted' => 'Tick the conflict-of-interest declaration to approve.']);
            $this->service->approve($expense, $request->user(), $request->boolean('declaration'));
        }, "{$expense->reference()} approved. It now counts in balances.");
    }

    public function returnToDraft(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        return $this->act($expense, function () use ($request, $expense) {
            $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
            $this->service->returnToDraft($expense, $request->user(), $data['reason']);
        }, "{$expense->reference()} returned to draft.");
    }

    public function voidRequest(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        return $this->act($expense, function () use ($request, $expense) {
            $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
            $this->service->requestVoid($expense, $request->user(), $data['reason']);
        }, 'Void requested. Another admin must confirm it.');
    }

    public function voidConfirm(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        return $this->act($expense, fn () => $this->service->confirmVoid($expense, $request->user()), "{$expense->reference()} voided. It no longer counts in any total.");
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);
        $club = $expense->club;
        $ref = $expense->reference();
        try {
            $this->service->deleteDraft($expense, $request->user());
        } catch (ValidationException $e) {
            return redirect()->route('admin.fund-usage.show', $expense)->withErrors($e->errors());
        }

        return redirect()->route('admin.clubs.fund-usage.index', $club)->with('success', "Draft {$ref} deleted.");
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Run a workflow action; success and validation failures both land on the record's page. */
    private function act(Expense $expense, callable $fn, string $success): RedirectResponse
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return redirect()->route('admin.fund-usage.show', $expense)->withErrors($e->errors());
        }

        return redirect()->route('admin.fund-usage.show', $expense)->with('success', $success);
    }

    private function tabQuery(Club $club, string $tab, int $me): Builder
    {
        $q = Expense::query()->where('expenses.club_id', $club->id);

        return match ($tab) {
            'expense' => $q->where('type', 'expense')->where('status', '!=', 'void'),
            'welfare' => $q->where('type', 'welfare')->where('status', '!=', 'void'),
            'mine' => FundUsageStats::awaitingFor($q, $me),
            'void' => $q->where('status', 'void'),
            default => $q->where('status', '!=', 'void'),
        };
    }

    private function form(Request $request, Club $club, ?Expense $expense, string $type): Response
    {
        $expense?->load(['category', 'recordedBy:id,name', 'approver:id,name', 'attachments', 'welfareCase.beneficiary:id,name', 'welfareCase.sightedBy:id,name']);
        $stats = new FundUsageStats($club);

        $members = $club->members()->wherePivot('is_active', true)->orderBy('name')->get(['users.id', 'users.name']);

        return Inertia::render('Admin/FundUsage/Create', [
            'nav' => AdminNav::props($club, $request->user(), $stats->awaiting((int) $request->user()->id)['mine']),
            'type' => $type,
            'item' => $expense ? P::detail($expense, (int) $request->user()->id) : null,
            'categories' => $club->expenseCategories()->orderBy('name')->get(['id', 'name']),
            'members' => $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            // "Certificate sighted by" must be a different active admin than the maker.
            'admins' => $members->filter(fn ($m) => $m->pivot->role === 'admin' && (int) $m->id !== (int) ($expense?->recorded_by ?? $request->user()->id))->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'relationships' => WelfareCase::RELATIONSHIPS,
            'limits' => ['maxFiles' => \App\Models\Attachment::MAX_FILES, 'maxKb' => \App\Models\Attachment::MAX_KB],
            'urls' => [
                'index' => route('admin.clubs.fund-usage.index', $club),
                'action' => $expense ? route('admin.fund-usage.update', $expense) : route('admin.clubs.fund-usage.store', $club),
                'show' => $expense ? route('admin.fund-usage.show', $expense) : null,
                'createExpense' => route('admin.clubs.fund-usage.create', $club),
                'createWelfare' => route('admin.clubs.fund-usage.create', [$club, 'type' => 'welfare']),
            ],
        ]);
    }
}
