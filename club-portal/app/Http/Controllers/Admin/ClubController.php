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
            'name'                      => 'required|string|max:255',
            'email'                     => 'nullable|email|max:255',
            'logo'                      => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'toyyibpay_secret_key'      => 'nullable|string|max:255',
            'toyyibpay_category_code'   => 'nullable|string|max:100',
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('clubs/logos', 'public');
        }

        // Store empty string as null so hasToyyibPayCredentials() works correctly
        $data['toyyibpay_secret_key']    = $data['toyyibpay_secret_key'] ?: null;
        $data['toyyibpay_category_code'] = $data['toyyibpay_category_code'] ?: null;

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
            'name'                      => 'required|string|max:255',
            'email'                     => 'nullable|email|max:255',
            'logo'                      => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'is_active'                 => 'boolean',
            'toyyibpay_secret_key'      => 'nullable|string|max:255',
            'toyyibpay_category_code'   => 'nullable|string|max:100',
        ]);

        if ($request->hasFile('logo')) {
            if ($club->logo) {
                Storage::disk('public')->delete($club->logo);
            }
            $data['logo'] = $request->file('logo')->store('clubs/logos', 'public');
        }

        $data['is_active'] = $request->boolean('is_active');

        // Preserve existing key if field was left blank (admin didn't intend to clear it)
        if (empty($data['toyyibpay_secret_key'])) {
            unset($data['toyyibpay_secret_key']);
        }
        $data['toyyibpay_category_code'] = $data['toyyibpay_category_code'] ?: null;

        $old = $club->only(['name', 'email', 'is_active']);
        $club->update($data);

        AuditService::log(
            'club.updated',
            "Club '{$club->name}' updated.",
            $club,
            $club->id,
            $old,
            $club->fresh()->only(['name', 'email', 'is_active'])
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
