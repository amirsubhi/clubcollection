<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Discount;
use App\Services\AuditService;
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
        $discount = $club->discounts()->create($data);

        AuditService::log(
            'discount.created',
            "Discount '{$data['name']}' ({$data['type']} {$data['value']}) created.",
            $discount,
            $club->id
        );

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
        $old = $discount->only(['name', 'type', 'value', 'is_active']);
        $discount->update($data);

        AuditService::log(
            'discount.updated',
            "Discount '{$discount->name}' updated.",
            $discount,
            $discount->club_id,
            $old,
            $discount->fresh()->only(['name', 'type', 'value', 'is_active'])
        );

        return redirect()->route('admin.discounts.index', $discount->club)
            ->with('success', 'Discount updated.');
    }

    public function destroy(Discount $discount)
    {
        $this->authorizeClubAdmin($discount->club);
        $club    = $discount->club;
        $clubId  = $club->id;
        $old     = $discount->only(['name', 'type', 'value']);
        $discount->delete();

        AuditService::log('discount.deleted', "Discount '{$old['name']}' deleted.", null, $clubId, $old);

        return redirect()->route('admin.discounts.index', $club)
            ->with('success', 'Discount deleted.');
    }
}
