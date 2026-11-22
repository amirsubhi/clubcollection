<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\AuditService;
use App\Services\LogoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClubController extends Controller
{
    public function __construct(private LogoService $logos) {}

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
            'name' => 'required|string|max:255|not_regex:/[\\x00-\\x1F\\x7F]/u',
            'email' => 'nullable|email|max:255',
            'logo' => LogoService::rules(),
            'payment_gateway' => 'nullable|in:toyyibpay,billplz',
            'toyyibpay_secret_key' => 'nullable|string|max:255',
            'toyyibpay_category_code' => 'nullable|string|max:100',
            'billplz_api_key' => 'nullable|string|max:255',
            'billplz_collection_id' => 'nullable|string|max:100',
            'billplz_x_signature_key' => 'nullable|string|max:255',
        ], LogoService::messages());

        unset($data['logo']);
        if ($request->hasFile('logo')) {
            $data['logo'] = $this->logos->store($request->file('logo'), 'club-logos');
        }

        $data['payment_gateway'] = $data['payment_gateway'] ?? 'toyyibpay';

        // Store empty string as null so credential helpers work correctly.
        $data['toyyibpay_secret_key'] = ($data['toyyibpay_secret_key'] ?? null) ?: null;
        $data['toyyibpay_category_code'] = ($data['toyyibpay_category_code'] ?? null) ?: null;
        $data['billplz_api_key'] = ($data['billplz_api_key'] ?? null) ?: null;
        $data['billplz_collection_id'] = ($data['billplz_collection_id'] ?? null) ?: null;
        $data['billplz_x_signature_key'] = ($data['billplz_x_signature_key'] ?? null) ?: null;

        $club = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $club = Club::create($data);
            // One starting job level ("Ahli"); the club admins add and rename levels on the Job levels page.
            $club->createDefaultJobLevel();

            return $club;
        });

        AuditService::log('club.created', "Club '{$club->name}' created.", $club);

        return redirect()->route('admin.clubs.index')->with('success', 'Club created successfully.');
    }

    public function show(Club $club)
    {
        $club->load(['members', 'feeRates', 'jobLevels']);

        return view('admin.clubs.show', compact('club'));
    }

    public function edit(Club $club)
    {
        return view('admin.clubs.edit', compact('club'));
    }

    public function update(Request $request, Club $club)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|not_regex:/[\\x00-\\x1F\\x7F]/u',
            'email' => 'nullable|email|max:255',
            'logo' => LogoService::rules(),
            'is_active' => 'boolean',
            // Nullable so partial updates that omit the gateway selector
            // (e.g. legacy callers, tests) don't fail validation.
            'payment_gateway' => 'nullable|in:toyyibpay,billplz',
            'toyyibpay_secret_key' => 'nullable|string|max:255',
            'toyyibpay_category_code' => 'nullable|string|max:100',
            'billplz_api_key' => 'nullable|string|max:255',
            'billplz_collection_id' => 'nullable|string|max:100',
            'billplz_x_signature_key' => 'nullable|string|max:255',
        ], LogoService::messages());

        // If the form omitted the gateway, leave the existing value alone.
        if (empty($data['payment_gateway'])) {
            unset($data['payment_gateway']);
        }

        // Same handling as the club profile page (LogoService). The old file is kept: receipts issued with it
        // still show it (payments.issuer_snapshot).
        unset($data['logo']);
        if ($request->hasFile('logo')) {
            $data['logo'] = $this->logos->store($request->file('logo'), 'club-logos');
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

        $old = $club->only(['name', 'email', 'is_active', 'payment_gateway', 'logo']);
        $club->update($data);

        AuditService::log(
            'club.updated',
            "Club '{$club->name}' updated.",
            $club,
            $club->id,
            $old,
            $club->fresh()->only(['name', 'email', 'is_active', 'payment_gateway', 'logo'])
        );

        return redirect()->route('admin.clubs.index')->with('success', 'Club updated successfully.');
    }

    public function destroy(Club $club)
    {
        // The database cascade would wipe approved records and the audit trail's
        // context: only a club with no members or admins, fees, bills, expenses or
        // documents can be deleted. Anything else is deactivated instead.
        if ($club->hasRecords()) {
            return redirect()->route('admin.clubs.index')
                ->with('error', 'This club has records (members or admins, fees, payment bills, expenses or documents). Deactivate it instead: Edit → untick Active.');
        }

        $name = $club->name;
        if ($club->logo) {
            Storage::disk('public')->delete($club->logo);
        }
        $clubId = $club->id;
        $club->delete();
        // The database cascade removes the club's attachment rows (fees' transfer
        // slips, expense documents); remove their encrypted files too.
        Storage::disk(\App\Services\AttachmentService::DISK)->deleteDirectory("clubs/{$clubId}/attachments");

        AuditService::log('club.deleted', "Club '{$name}' deleted.");

        return redirect()->route('admin.clubs.index')->with('success', 'Club deleted.');
    }
}
