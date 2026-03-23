<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    use AuthorizesClubResource;
    public function index(Club $club)
    {
        $category = request('category');
        $month    = request('month', now()->format('Y-m'));

        $query = $club->expenses()->with(['category', 'recordedBy'])
            ->orderByDesc('expense_date');

        if ($category) {
            $query->where('expense_category_id', $category);
        }

        if ($month) {
            $query->whereRaw("strftime('%Y-%m', expense_date) = ?", [$month]);
        }

        $expenses   = $query->paginate(20)->withQueryString();
        $categories = $club->expenseCategories()->orderBy('name')->get();

        $totalThisMonth = $club->expenses()
            ->whereRaw("strftime('%Y-%m', expense_date) = ?", [now()->format('Y-m')])
            ->sum('amount');

        return view('admin.expenses.index', compact('club', 'expenses', 'categories', 'totalThisMonth', 'category', 'month'));
    }

    public function create(Club $club)
    {
        $categories = $club->expenseCategories()->orderBy('name')->get();
        return view('admin.expenses.create', compact('club', 'categories'));
    }

    public function store(Request $request, Club $club)
    {
        $data = $request->validate([
            'expense_category_id' => [
                'required',
                'exists:expense_categories,id',
                // Ensure category belongs to this club
                function ($attribute, $value, $fail) use ($club) {
                    if (!$club->expenseCategories()->where('id', $value)->exists()) {
                        $fail('The selected category does not belong to this club.');
                    }
                },
            ],
            'description'         => 'required|string|max:500',
            'amount'              => 'required|numeric|min:0.01',
            'expense_date'        => 'required|date',
            'receipt'             => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($request->hasFile('receipt')) {
            $data['receipt'] = $request->file('receipt')->store('clubs/' . $club->id . '/receipts', 'public');
        }

        $data['club_id']     = $club->id;
        $data['recorded_by'] = auth()->id();

        Expense::create($data);

        return redirect()->route('admin.expenses.index', $club)
            ->with('success', 'Expense recorded successfully.');
    }

    public function show(Expense $expense)
    {
        $this->authorizeClubAdmin($expense->club);
        $expense->load(['category', 'recordedBy', 'club']);
        return view('admin.expenses.show', compact('expense'));
    }

    public function edit(Expense $expense)
    {
        $this->authorizeClubAdmin($expense->club);
        $club       = $expense->club;
        $categories = $club->expenseCategories()->orderBy('name')->get();
        return view('admin.expenses.edit', compact('expense', 'club', 'categories'));
    }

    public function update(Request $request, Expense $expense)
    {
        $this->authorizeClubAdmin($expense->club);
        $data = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'description'         => 'required|string|max:500',
            'amount'              => 'required|numeric|min:0.01',
            'expense_date'        => 'required|date',
            'receipt'             => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($request->hasFile('receipt')) {
            if ($expense->receipt) {
                Storage::disk('public')->delete($expense->receipt);
            }
            $data['receipt'] = $request->file('receipt')->store('clubs/' . $expense->club_id . '/receipts', 'public');
        }

        $expense->update($data);

        return redirect()->route('admin.expenses.show', $expense)
            ->with('success', 'Expense updated.');
    }

    public function destroy(Expense $expense)
    {
        $this->authorizeClubAdmin($expense->club);
        $club = $expense->club;
        if ($expense->receipt) {
            Storage::disk('public')->delete($expense->receipt);
        }
        $expense->delete();

        return redirect()->route('admin.expenses.index', $club)
            ->with('success', 'Expense deleted.');
    }
}
