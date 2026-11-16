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

    /** name: English (admin pages); name_ms: optional Bahasa Melayu name shown to members. */
    private const RULES = ['name' => 'required|string|max:100', 'name_ms' => 'nullable|string|max:100'];

    private static function clean(array $data): array
    {
        $ms = trim((string) ($data['name_ms'] ?? ''));

        return ['name' => $data['name'], 'name_ms' => $ms === '' ? null : $ms];
    }

    public function index(Club $club)
    {
        $categories = $club->expenseCategories()->withCount('expenses')->orderBy('name')->get();
        return view('admin.expense-categories.index', compact('club', 'categories'));
    }

    public function store(Request $request, Club $club)
    {
        $data = $request->validate(self::RULES);
        $club->expenseCategories()->create(self::clean($data));
        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $this->authorizeClubAdmin($expenseCategory->club);
        $data = $request->validate(self::RULES);
        $expenseCategory->update(self::clean($data));
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
        return redirect()->route('admin.clubs.expense-categories.index', $club)
            ->with('success', 'Category deleted.');
    }
}
