<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    use AuthorizesClubResource;

    public function index(Club $club)
    {
        $categories = $club->expenseCategories()->withCount('expenses')->orderBy('name')->get();
        return view('admin.expense-categories.index', compact('club', 'categories'));
    }

    public function store(Request $request, Club $club)
    {
        $request->validate(['name' => 'required|string|max:100']);
        $club->expenseCategories()->create(['name' => $request->name]);
        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $this->authorizeClubAdmin($expenseCategory->club);
        $request->validate(['name' => 'required|string|max:100']);
        $expenseCategory->update(['name' => $request->name]);
        return back()->with('success', 'Category updated.');
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        $this->authorizeClubAdmin($expenseCategory->club);
        $club = $expenseCategory->club;
        if ($expenseCategory->expenses()->exists()) {
            return back()->with('error', 'Cannot delete category with existing expenses.');
        }
        $expenseCategory->delete();
        return redirect()->route('admin.expense-categories.index', $club)
            ->with('success', 'Category deleted.');
    }
}
