<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    use AuthorizesClubResource;
    public function index(Club $club)
    {
        $discounts = $club->discounts()->latest()->paginate(20);
        return view('admin.discounts.index', compact('club', 'discounts'));
    }

    public function create(Club $club)
    {
        return view('admin.discounts.create', compact('club'));
    }

    public function store(Request $request, Club $club)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'type'       => 'required|in:fixed,percentage',
            'value'      => 'required|numeric|min:0',
            'valid_from' => 'required|date',
            'valid_to'   => 'nullable|date|after_or_equal:valid_from',
            'is_active'  => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $club->discounts()->create($data);

        return redirect()->route('admin.discounts.index', $club)
            ->with('success', 'Discount created.');
    }

    public function edit(Discount $discount)
    {
        $this->authorizeClubAdmin($discount->club);
        return view('admin.discounts.edit', compact('discount'));
    }

    public function update(Request $request, Discount $discount)
    {
        $this->authorizeClubAdmin($discount->club);
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'type'       => 'required|in:fixed,percentage',
            'value'      => 'required|numeric|min:0',
            'valid_from' => 'required|date',
            'valid_to'   => 'nullable|date|after_or_equal:valid_from',
            'is_active'  => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $discount->update($data);

        return redirect()->route('admin.discounts.index', $discount->club)
            ->with('success', 'Discount updated.');
    }

    public function destroy(Discount $discount)
    {
        $this->authorizeClubAdmin($discount->club);
        $club = $discount->club;
        $discount->delete();
        return redirect()->route('admin.discounts.index', $club)
            ->with('success', 'Discount deleted.');
    }
}
