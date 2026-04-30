<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClubController extends Controller
{
    public function index()
    {
        $clubs = Club::withCount('members')->latest()->paginate(15);

        return view('admin.clubs.index', compact('clubs'));
    }

    public function create()
    {
        return view('admin.clubs.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'payment_gateway' => 'nullable|in:toyyibpay,billplz',
            'toyyibpay_secret_key' => 'nullable|string|max:255',
            'toyyibpay_category_code' => 'nullable|string|max:100',
            'billplz_api_key' => 'nullable|string|max:255',
            'billplz_collection_id' => 'nullable|string|max:100',
            'billplz_x_signature_key' => 'nullable|string|max:255',
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('clubs/logos', 'public');
        }

        $data['payment_gateway'] = $data['payment_gateway'] ?? 'toyyibpay';

        // Store empty string as null so credential helpers work correctly.
        $data['toyyibpay_secret_key'] = ($data['toyyibpay_secret_key'] ?? null) ?: null;
        $data['toyyibpay_category_code'] = ($data['toyyibpay_category_code'] ?? null) ?: null;
        $data['billplz_api_key'] = ($data['billplz_api_key'] ?? null) ?: null;
        $data['billplz_collection_id'] = ($data['billplz_collection_id'] ?? null) ?: null;
        $data['billplz_x_signature_key'] = ($data['billplz_x_signature_key'] ?? null) ?: null;

        $club = Club::create($data);

        AuditService::log('club.created', "Club '{$club->name}' created.", $club);

        return redirect()->route('admin.clubs.index')->with('success', 'Club created successfully.');
    }

    public function show(Club $club)
    {
        $club->load(['members', 'feeRates']);

        return view('admin.clubs.show', compact('club'));
    }

    public function edit(Club $club)
    {
        return view('admin.clubs.edit', compact('club'));
    }

    public function update(Request $request, Club $club)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'is_active' => 'boolean',
            // Nullable so partial updates that omit the gateway selector
            // (e.g. legacy callers, tests) don't fail validation.
            'payment_gateway' => 'nullable|in:toyyibpay,billplz',
            'toyyibpay_secret_key' => 'nullable|string|max:255',
            'toyyibpay_category_code' => 'nullable|string|max:100',
            'billplz_api_key' => 'nullable|string|max:255',
            'billplz_collection_id' => 'nullable|string|max:100',
            'billplz_x_signature_key' => 'nullable|string|max:255',
        ]);

        // If the form omitted the gateway, leave the existing value alone.
        if (empty($data['payment_gateway'])) {
            unset($data['payment_gateway']);
        }

        if ($request->hasFile('logo')) {
            if ($club->logo) {
                Storage::disk('public')->delete($club->logo);
            }
            $data['logo'] = $request->file('logo')->store('clubs/logos', 'public');
        }

        $data['is_active'] = $request->boolean('is_active');

        // Preserve existing encrypted secrets if field was left blank
        // (admin didn't intend to clear it).
        foreach (['toyyibpay_secret_key', 'billplz_api_key', 'billplz_x_signature_key'] as $secret) {
            if (empty($data[$secret])) {
                unset($data[$secret]);
            }
        }
        // Public fields can be cleared by submitting blank.
        $data['toyyibpay_category_code'] = ($data['toyyibpay_category_code'] ?? null) ?: null;
        $data['billplz_collection_id'] = ($data['billplz_collection_id'] ?? null) ?: null;

        $old = $club->only(['name', 'email', 'is_active', 'payment_gateway']);
        $club->update($data);

        AuditService::log(
            'club.updated',
            "Club '{$club->name}' updated.",
            $club,
            $club->id,
            $old,
            $club->fresh()->only(['name', 'email', 'is_active', 'payment_gateway'])
        );

        return redirect()->route('admin.clubs.index')->with('success', 'Club updated successfully.');
    }

    public function destroy(Club $club)
    {
        $name = $club->name;
        if ($club->logo) {
            Storage::disk('public')->delete($club->logo);
        }
        $club->delete();

        AuditService::log('club.deleted', "Club '{$name}' deleted.");

        return redirect()->route('admin.clubs.index')->with('success', 'Club deleted.');
    }
}
