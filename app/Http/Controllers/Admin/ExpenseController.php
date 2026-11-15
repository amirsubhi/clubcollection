<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Expense;
use Illuminate\Http\RedirectResponse;

/**
 * Legacy Expenses URLs. Expenses are now managed in Fund Usage
 * (FundUsageController); these keep old links and bookmarks working.
 */
class ExpenseController extends Controller
{
    use AuthorizesClubResource;

    public function index(Club $club): RedirectResponse
    {
        return redirect()->route('admin.clubs.fund-usage.index', $club);
    }

    public function create(Club $club): RedirectResponse
    {
        return redirect()->route('admin.clubs.fund-usage.create', $club);
    }

    public function show(Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        return redirect()->route('admin.fund-usage.show', $expense);
    }

    public function edit(Expense $expense): RedirectResponse
    {
        $this->authorizeClubAdmin($expense->club);

        return redirect()->route('admin.fund-usage.edit', $expense);
    }
}
